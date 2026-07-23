<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AdminMemberAccess;
use App\Services\ActivityLogger;
use App\Services\AuthService;
use App\Services\BaseUrlService;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\MembershipMigrationService;
use App\Services\MembershipOrderService;
use App\Services\MembershipStatusService;
use App\Services\OrderRepository;
use App\Services\PasswordPolicyService;
use App\Services\RefundService;
use App\Services\NotificationService;
use App\Services\OrderAdminService;
use App\Services\SecurityPolicyService;
use App\Services\SettingsService;
use App\Services\LogViewerService;
use App\Services\MembershipService;
use App\Services\StripeService;
use App\Services\NotificationPreferenceService;
use App\Services\TwoFactorService;
use App\Services\VehicleRepository;

require_permission('admin.members.view');

// TEMPORARY — surface fatal errors and uncaught exceptions to admins so we
// can diagnose 500s on this endpoint (recent membership_order_refund +
// Mark-as-Paid work). Remove once the flow is verified working.
// Gated on the same permission the page already required, so this never
// shows secrets to non-admins.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        // No headers may have been sent if a fatal hit early — try to set 500.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "\n=== Fatal error on /admin/members/actions.php ===\n";
        echo "Type:    {$err['type']}\n";
        echo "Message: {$err['message']}\n";
        echo "File:    {$err['file']}:{$err['line']}\n";
        echo "POST action: " . ($_POST['action'] ?? '(none)') . "\n";
    }
});
set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "\n=== Uncaught exception on /admin/members/actions.php ===\n";
    echo "Class:   " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File:    " . $e->getFile() . ':' . $e->getLine() . "\n";
    echo "POST action: " . ($_POST['action'] ?? '(none)') . "\n";
    echo "POST keys:   " . implode(', ', array_keys($_POST)) . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit;
});

$user = current_user();
$allowedTabs = ['overview', 'profile', 'roles', 'settings', 'vehicles', 'orders', 'refunds', 'activity'];
$tab = $_POST['tab'] ?? 'overview';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

$memberId = (int) ($_POST['member_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/members/view.php');
    exit;
}

function redirectWithFlash(int $memberId, string $tab, string $message, string $type = 'success', array $extraParams = []): void
{
    $flash = ['type' => $type, 'message' => $message];
    if (isset($extraParams['flash_context'])) {
        $flash['context'] = $extraParams['flash_context'];
        unset($extraParams['flash_context']);
    }
    $_SESSION['members_flash'] = $flash;
    $params = array_merge(['id' => $memberId, 'tab' => $tab], array_filter($extraParams, static fn($value) => $value !== null));
    if (!isset($params['orders_section']) && !empty($_POST['orders_section'])) {
        $params['orders_section'] = $_POST['orders_section'];
    }
    header('Location: /admin/members/view.php?' . http_build_query($params));
    exit;
}

function redirectToMembersList(string $message, string $type = 'success'): void
{
    $_SESSION['members_flash'] = ['type' => $type, 'message' => $message];
    header('Location: /admin/members');
    exit;
}

function respondWithJson(array $payload, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function userCanInlineEdit(array $roles): bool
{
    $user = ['roles' => $roles];
    return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
}

function inlineStatusLabel(string $status): string
{
    if ($status === 'cancelled') {
        return 'Archived';
    }
    return ucfirst($status);
}

function fetchChapterDisplay(\PDO $pdo, ?int $chapterId): string
{
    if (!$chapterId) {
        return 'Unassigned';
    }
    $hasAbbreviation = \App\Services\ChapterRepository::hasColumn($pdo, 'abbreviation');
    $columns = $hasAbbreviation ? 'name, abbreviation, state' : 'name, state';
    $stmt = $pdo->prepare('SELECT ' . $columns . ' FROM chapters WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $chapterId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Unassigned';
    }
    $name = \App\Services\ChapterRepository::formatLabel($row['name'] ?? '', $row['abbreviation'] ?? null);
    $state = trim($row['state'] ?? '');
    $label = trim($name . ($state ? ' (' . $state . ')' : ''));
    return $label !== '' ? $label : 'Unassigned';
}

function parseMemberNumberString(string $value): ?array
{
    return MembershipService::parseMemberNumberString($value);
}

function mapMembershipTypeName(string $name): string
{
    $normalized = strtoupper(trim($name));
    if (str_contains($normalized, 'ASSOC')) {
        return 'ASSOCIATE';
    }
    if (str_contains($normalized, 'LIFE')) {
        return 'LIFE';
    }
    return 'FULL';
}

function isSafeIdentifier(string $value): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $value);
}

function fetchForeignKeys(\PDO $pdo, string $referencedTable): array
{
    $stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = :table');
    $stmt->execute(['table' => $referencedTable]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function tableExists(\PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function isMembershipApplicationApproved(\PDO $pdo, int $memberId): ?bool
{
    if ($memberId <= 0) {
        return null;
    }
    if (!tableExists($pdo, 'membership_applications')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT status FROM membership_applications WHERE member_id = :member_id ORDER BY created_at DESC LIMIT 1');
        $stmt->execute(['member_id' => $memberId]);
        $status = $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return null;
    }
    if ($status === false) {
        return null;
    }
    return strtoupper((string) $status) === 'APPROVED';
}

function memberBikeColumns(\PDO $pdo): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(\PDO::FETCH_COLUMN, 0);
    } catch (\Throwable $e) {
        $columns = [];
    }
    return $columns;
}

function memberBikeHasColumn(\PDO $pdo, string $column): bool
{
    return in_array($column, memberBikeColumns($pdo), true);
}

function chapterRequestHasReason(\PDO $pdo): bool
{
    static $hasReason = null;
    if ($hasReason !== null) {
        return $hasReason;
    }
    try {
        $hasReason = (bool) $pdo->query("SHOW COLUMNS FROM chapter_change_requests LIKE 'rejection_reason'")->fetch();
    } catch (\Throwable $e) {
        $hasReason = false;
    }
    return $hasReason;
}

function columnIsNullable(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $stmt->execute(['table' => $table, 'column' => $column]);
    return strtoupper((string) $stmt->fetchColumn()) === 'YES';
}

function deleteRowsByColumn(\PDO $pdo, string $table, string $column, int $value): void
{
    if (!isSafeIdentifier($table) || !isSafeIdentifier($column)) {
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$column` = :value");
    $stmt->execute(['value' => $value]);
}

function nullifyRowsByColumn(\PDO $pdo, string $table, string $column, int $value): void
{
    if (!isSafeIdentifier($table) || !isSafeIdentifier($column)) {
        return;
    }
    $stmt = $pdo->prepare("UPDATE `$table` SET `$column` = NULL WHERE `$column` = :value");
    $stmt->execute(['value' => $value]);
}

function deleteAiConversationData(\PDO $pdo, int $userId): void
{
    if (!tableExists($pdo, 'ai_conversations')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id FROM ai_conversations WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $conversationIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    if (!$conversationIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
    if (tableExists($pdo, 'ai_messages')) {
        $pdo->prepare("DELETE FROM ai_messages WHERE conversation_id IN ($placeholders)")->execute($conversationIds);
    }
    if (tableExists($pdo, 'ai_drafts')) {
        $pdo->prepare("DELETE FROM ai_drafts WHERE conversation_id IN ($placeholders)")->execute($conversationIds);
    }
    $pdo->prepare("DELETE FROM ai_conversations WHERE id IN ($placeholders)")->execute($conversationIds);
}

function fetchMembersForBulk(\PDO $pdo, array $memberIds): array
{
    $uniqueIds = array_values(array_unique($memberIds));
    if ($uniqueIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
    $stmt = $pdo->prepare("SELECT id, chapter_id, status, user_id, email FROM members WHERE id IN ($placeholders)");
    $stmt->execute($uniqueIds);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function deleteMemberPermanently(\PDO $pdo, array $user, array $member): bool
{
    $memberId = (int) ($member['id'] ?? 0);
    if ($memberId <= 0) {
        return false;
    }
    $userId = (int) ($member['user_id'] ?? 0);
    try {
        $pdo->beginTransaction();

        if ($userId > 0) {
            deleteAiConversationData($pdo, $userId);
        }

        foreach (fetchForeignKeys($pdo, 'members') as $fk) {
            $table = $fk['TABLE_NAME'] ?? '';
            $column = $fk['COLUMN_NAME'] ?? '';
            if ($table === 'users') {
                continue;
            }
            if ($table === 'members' && $column === 'full_member_id') {
                nullifyRowsByColumn($pdo, $table, $column, $memberId);
                continue;
            }
            deleteRowsByColumn($pdo, $table, $column, $memberId);
        }

        if ($userId > 0) {
            foreach (fetchForeignKeys($pdo, 'users') as $fk) {
                $table = $fk['TABLE_NAME'] ?? '';
                $column = $fk['COLUMN_NAME'] ?? '';
                if ($table === 'users') {
                    continue;
                }
                if (columnIsNullable($pdo, $table, $column)) {
                    nullifyRowsByColumn($pdo, $table, $column, $userId);
                } else {
                    deleteRowsByColumn($pdo, $table, $column, $userId);
                }
            }
        }

        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.deleted', [
            'user_id' => $member['user_id'] ?? null,
            'actor_roles' => $user['roles'] ?? [],
        ]);

        if ($userId > 0) {
            deleteRowsByColumn($pdo, 'users', 'id', $userId);
        }
        deleteRowsByColumn($pdo, 'members', 'id', $memberId);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Insert a bike row for a member, mirroring the column-detection used by the
 * `bike_add` action so optional columns (rego/image_url/colour/is_primary) are
 * only written when they exist. Returns false when make/model are missing.
 */
function insertMemberBike(\PDO $pdo, int $memberId, array $data): bool
{
    $make = trim((string) ($data['make'] ?? ''));
    $model = trim((string) ($data['model'] ?? ''));
    if ($make === '' || $model === '') {
        return false;
    }
    $year = (int) ($data['year'] ?? 0);
    $rego = trim((string) ($data['rego'] ?? ''));
    $imageUrl = trim((string) ($data['image_url'] ?? ''));
    $color = trim((string) ($data['color'] ?? ''));

    $columns = ['member_id', 'make', 'model', 'year', 'created_at'];
    $placeholders = [':member_id', ':make', ':model', ':year', 'NOW()'];
    $params = [
        'member_id' => $memberId,
        'make' => $make,
        'model' => $model,
        'year' => $year ?: null,
    ];
    if (memberBikeHasColumn($pdo, 'rego')) {
        $columns[] = 'rego';
        $placeholders[] = ':rego';
        $params['rego'] = $rego !== '' ? $rego : null;
    }
    if (memberBikeHasColumn($pdo, 'image_url')) {
        $columns[] = 'image_url';
        $placeholders[] = ':image_url';
        $params['image_url'] = $imageUrl !== '' ? $imageUrl : null;
    }
    if ($color !== '') {
        if (memberBikeHasColumn($pdo, 'color')) {
            $columns[] = 'color';
            $placeholders[] = ':color';
            $params['color'] = $color;
        } elseif (memberBikeHasColumn($pdo, 'colour')) {
            $columns[] = 'colour';
            $placeholders[] = ':colour';
            $params['colour'] = $color;
        }
    }
    if (memberBikeHasColumn($pdo, 'is_primary')) {
        $primaryStmt = $pdo->prepare('SELECT 1 FROM member_bikes WHERE member_id = :member_id AND is_primary = 1 LIMIT 1');
        $primaryStmt->execute(['member_id' => $memberId]);
        if (!$primaryStmt->fetchColumn()) {
            $columns[] = 'is_primary';
            $placeholders[] = ':is_primary';
            $params['is_primary'] = 1;
        }
    }
    $stmt = $pdo->prepare('INSERT INTO member_bikes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute($params);
    return true;
}

/**
 * Ensure the member has a login account and email them a "set your password"
 * welcome link (48h). Shared by the `send_welcome_email` action and the new
 * member wizard. Returns ['success' => bool, 'error' => ?string, 'user_id' => ?int]
 * instead of redirecting so callers control the flow.
 */
function sendWelcomeEmailForMember(\PDO $pdo, array $member, ?array $actor): array
{
    $memberId = (int) ($member['id'] ?? 0);
    if (empty($member['email'])) {
        return ['success' => false, 'error' => 'Member has no email address on record.'];
    }
    $actorUserId = $actor['id'] ?? null;
    $userId = $member['user_id'] ?? null;
    if (!$userId) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $member['email']]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            $userId = (int) $existingUser['id'];
            $stmt = $pdo->prepare('UPDATE members SET user_id = :user_id WHERE id = :member_id');
            $stmt->execute(['user_id' => $userId, 'member_id' => $memberId]);
        } else {
            $tempPassword = bin2hex(random_bytes(4));
            $stmt = $pdo->prepare('INSERT INTO users (member_id, name, email, password_hash, is_active, created_at) VALUES (:member_id, :name, :email, :hash, 1, NOW())');
            $stmt->execute([
                'member_id' => $memberId,
                'name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                'email' => $member['email'],
                'hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('UPDATE members SET user_id = :user_id WHERE id = :member_id');
            $stmt->execute(['user_id' => $userId, 'member_id' => $memberId]);
            $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = "member"');
            $stmt->execute(['user_id' => $userId]);
            ActivityLogger::log('admin', $actorUserId, $memberId, 'member.user_account_created', ['user_id' => $userId]);
        }
    }
    if (!$userId) {
        return ['success' => false, 'error' => 'Could not create a user account for this member.'];
    }
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, ip_address) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW(), :ip)');
    $stmt->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    $link = BaseUrlService::emailLink('/member/reset_password_confirm.php?token=' . urlencode($token));
    $sent = NotificationService::dispatch('member_set_password', [
        'primary_email' => $member['email'],
        'admin_emails' => NotificationService::getAdminEmails(),
        'reset_link' => NotificationService::escape($link),
        'first_name' => NotificationService::escape(trim((string) ($member['first_name'] ?? ''))),
    ]);
    if (!$sent) {
        return ['success' => false, 'error' => 'Welcome email could not be sent. Check email settings.', 'user_id' => (int) $userId];
    }
    ActivityLogger::log('admin', $actorUserId, $memberId, 'member.welcome_email_sent', ['user_id' => $userId]);
    return ['success' => true, 'user_id' => (int) $userId];
}

/**
 * Create a membership period + matching manual order for a member, mirroring
 * the `manual_membership_order` action. Shared by that action and the new
 * member wizard. Does NOT send any emails (callers do that). Returns a result
 * array; on failure ['success' => false, 'error' => string].
 *
 * $params keys: membership_type_id, cost, payment_method, membership_status
 * (active|pending|complimentary|lapsed), reference, start_date, renewal_date.
 * $forceMemberTypeCode overrides the FULL/ASSOCIATE/LIFE code derived from the
 * membership type name (used by the wizard where the admin picks it directly).
 */
function createMembershipForMember(\PDO $pdo, int $memberId, array $params, ?array $actor, ?string $forceMemberTypeCode = null): array
{
    $membershipTypeId = (int) ($params['membership_type_id'] ?? 0);
    $costValue = (float) ($params['cost'] ?? 0);
    $paymentMethod = trim((string) ($params['payment_method'] ?? 'Manual'));
    if ($paymentMethod === '') {
        $paymentMethod = 'Manual';
    }
    $membershipStatus = trim((string) ($params['membership_status'] ?? 'active'));
    $orderNotes = trim((string) ($params['reference'] ?? ''));
    $startDate = trim((string) ($params['start_date'] ?? ''));
    $renewalDate = trim((string) ($params['renewal_date'] ?? ''));
    // Term the admin selected in the wizard (years). Was previously ignored, which
    // silently forced every non-Life membership to 1Y — a 3-year join stored as 1Y
    // with a 1-year expiry fallback. Defaults to 1 when a caller omits it.
    $termYears = max(1, (int) ($params['term'] ?? 1));
    $actorUserId = $actor['id'] ?? null;

    $allowedStatus = ['active', 'pending', 'complimentary', 'lapsed'];
    if (!in_array($membershipStatus, $allowedStatus, true)) {
        return ['success' => false, 'error' => 'Invalid membership status.'];
    }

    $stmt = $pdo->prepare('SELECT id, name FROM membership_types WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $membershipTypeId]);
    $membershipType = $stmt->fetch();
    if (!$membershipType) {
        return ['success' => false, 'error' => 'Invalid membership type selected.'];
    }

    $memberTypeCode = $forceMemberTypeCode ?: mapMembershipTypeName((string) $membershipType['name']);
    $memberStatus = match ($membershipStatus) {
        'pending' => 'PENDING',
        'lapsed' => 'LAPSED',
        default => 'ACTIVE',
    };
    $periodStatus = match ($membershipStatus) {
        'pending' => 'PENDING_PAYMENT',
        'lapsed' => 'LAPSED',
        default => 'ACTIVE',
    };

    $startValue = DateTime::createFromFormat('Y-m-d', $startDate);
    $startDate = $startValue ? $startValue->format('Y-m-d') : date('Y-m-d');
    $endDate = null;
    if ($memberTypeCode !== 'LIFE') {
        $endValue = $renewalDate !== '' ? DateTime::createFromFormat('Y-m-d', $renewalDate) : null;
        // Rollover-aware, matching the paid new-join path: a 3-year add in the
        // Jun/Jul rollover window must land 3 whole membership years out, not
        // 2 (plain calculateExpiry anchored to the near-over current year).
        $endDate = $endValue ? $endValue->format('Y-m-d') : MembershipService::newJoinExpiry($startDate, $termYears * 12);
    }

    $updateFields = 'member_type = :member_type, status = :status, updated_at = NOW()';
    $updateParams = [
        'member_type' => $memberTypeCode,
        'status' => $memberStatus,
        'id' => $memberId,
    ];
    if (MemberRepository::hasMemberColumn($pdo, 'membership_type_id')) {
        $updateFields = 'membership_type_id = :membership_type_id, ' . $updateFields;
        $updateParams['membership_type_id'] = $membershipTypeId;
    }
    $stmt = $pdo->prepare('UPDATE members SET ' . $updateFields . ' WHERE id = :id');
    $stmt->execute($updateParams);

    $term = $memberTypeCode === 'LIFE' ? 'LIFE' : ($termYears . 'Y');
    $paidAt = ($periodStatus === 'ACTIVE') ? date('Y-m-d H:i:s') : null;
    $stmt = $pdo->prepare('INSERT INTO membership_periods (member_id, term, start_date, end_date, status, paid_at, created_at) VALUES (:member_id, :term, :start_date, :end_date, :status, :paid_at, NOW())');
    $stmt->execute([
        'member_id' => $memberId,
        'term' => $term,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'status' => $periodStatus,
        'paid_at' => $paidAt,
    ]);
    $periodId = (int) $pdo->lastInsertId();

    $paymentStatus = match ($membershipStatus) {
        'pending' => 'pending',
        'lapsed' => 'failed',
        default => 'accepted',
    };
    $fulfillmentStatus = match ($membershipStatus) {
        'pending' => 'pending',
        'lapsed' => 'expired',
        default => 'active',
    };
    $order = MembershipOrderService::createMembershipOrder($memberId, $periodId, max(0, $costValue), [
        'payment_method' => $paymentMethod,
        'payment_status' => $paymentStatus,
        'fulfillment_status' => $fulfillmentStatus,
        'actor_user_id' => $actorUserId,
        'term' => $term,
        'item_name' => ($membershipType['name'] ?? 'Membership') . ' membership',
        'admin_notes' => $orderNotes !== '' ? $orderNotes : null,
        'internal_notes' => $orderNotes !== '' ? $orderNotes : null,
    ]);
    if (!$order) {
        return ['success' => false, 'error' => 'Unable to create membership order.'];
    }

    $orderNumber = (string) ($order['order_number'] ?? '');
    if ($orderNumber !== '') {
        $stmt = $pdo->prepare('UPDATE membership_periods SET payment_id = :payment_id WHERE id = :id');
        $stmt->execute(['payment_id' => $orderNumber, 'id' => $periodId]);
    }

    ActivityLogger::log('admin', $actorUserId, $memberId, 'membership.manual_order_created', [
        'membership_type' => $memberTypeCode,
        'status' => $membershipStatus,
        'amount' => max(0, $costValue),
        'order_number' => $orderNumber !== '' ? $orderNumber : null,
        'actor_roles' => $actor['roles'] ?? [],
    ]);

    return [
        'success' => true,
        'order_number' => $orderNumber,
        'end_date' => $endDate,
        'period_id' => $periodId,
        'membership_type_name' => (string) ($membershipType['name'] ?? 'Member'),
        'member_type_code' => $memberTypeCode,
        'membership_status' => $membershipStatus,
        'payment_method' => $paymentMethod,
    ];
}

$action = $_POST['action'] ?? '';
$jsonActions = ['member_inline_update', 'bulk_member_action', 'associate_search', 'link_associate_member', 'unlink_associate_member', 'check_member_email'];
if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    if (in_array($action, $jsonActions, true)) {
        respondWithJson(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }
    if ($memberId > 0) {
        redirectWithFlash($memberId, $tab, 'Invalid CSRF token.', 'error');
    }
    $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    header('Location: /admin/members');
    exit;
}
$requiresMemberContext = !in_array($action, ['member_inline_update', 'bulk_member_action', 'create_member', 'check_member_email'], true);
$member = null;
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);

if ($requiresMemberContext) {
    $member = MemberRepository::findById($memberId);
    if (!$member) {
        $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Member not found.'];
        header('Location: /admin/members');
        exit;
    }
    if ($chapterRestriction !== null && ((int) ($member['chapter_id'] ?? 0)) !== $chapterRestriction) {
        $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Access denied for this chapter.'];
        header('Location: /admin/members');
        exit;
    }
}

$sensitiveActions = ['save_profile', 'refund_submit', 'manual_order_fix', 'order_resync', 'send_reset_link', 'set_password', 'change_status', 'twofa_force', 'twofa_exempt', 'twofa_reset', 'member_number_update', 'member_archive', 'member_delete', 'send_migration_link', 'disable_migration_link', 'enable_migration_link', 'manual_membership_order', 'membership_renewal_update', 'membership_order_accept', 'membership_order_reject', 'membership_order_send_link', 'membership_payment_request', 'membership_order_note', 'membership_order_void', 'membership_order_unvoid', 'membership_order_delete', 'membership_order_refund', 'store_order_void', 'store_order_unvoid', 'store_order_delete', 'resend_notification', 'roles_update', 'member_settings_update', 'member_avatar_update', 'twofa_toggle', 'chapter_request_decision', 'request_chapter', 'assign_chapter', 'bike_add', 'bike_update', 'bike_delete', 'impersonate_member', 'create_member'];
if (in_array($action, $sensitiveActions, true)) {
    require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members');
}

switch ($action) {
    case 'create_member':
        // Creating a member requires full member-edit access (the wizard sets
        // the full profile) AND manual-payment access (it always creates a
        // membership period + order). Welcome emails additionally need
        // user-edit access, checked separately below.
        if (!AdminMemberAccess::isFullAccess($user) || !AdminMemberAccess::canManualOrderFix($user)) {
            $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'You are not authorized to add members.'];
            header('Location: /admin/members');
            exit;
        }
        // Area-reps (chapter-restricted) cannot create members from the wizard.
        if ($chapterRestriction !== null) {
            $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Adding members is not available for chapter-restricted accounts.'];
            header('Location: /admin/members');
            exit;
        }

        $pdo = Database::connection();
        $redirectAddError = static function (string $message): void {
            $_SESSION['members_flash'] = ['type' => 'error', 'message' => $message];
            header('Location: /admin/members/add.php');
            exit;
        };

        // --- Step 2: contact (required) ---
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            $redirectAddError('First name and last name are required.');
        }
        // Email is optional (rare, but some members have neither phone nor
        // email); when provided it must be a valid address.
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $redirectAddError('The email address provided is not valid.');
        }

        // --- Step 1: member type + number ---
        $memberTypeStep = strtoupper(trim((string) ($_POST['member_type'] ?? 'FULL')));
        if (!in_array($memberTypeStep, ['FULL', 'ASSOCIATE', 'LIFE'], true)) {
            $memberTypeStep = 'FULL';
        }
        if (!MemberRepository::isEmailAvailable($email, null)) {
            // Households often share one email address. An associate may reuse
            // an existing member's email, but only after the admin confirms the
            // wizard's "link as associate" prompt (which sets shared_email_ok).
            if ($memberTypeStep !== 'ASSOCIATE' || empty($_POST['shared_email_ok'])) {
                $redirectAddError('That email address is already linked to another member.');
            }
        }
        $fullMemberId = (int) ($_POST['full_member_id'] ?? 0);
        $base = 0;
        $suffix = 0;
        if ($memberTypeStep === 'ASSOCIATE' && $fullMemberId > 0) {
            // Associate linked to a full member: reuse the full member's base,
            // allocate the next free suffix under that base.
            $stmt = $pdo->prepare('SELECT member_number_base FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $fullMemberId]);
            $linkedBase = $stmt->fetchColumn();
            if ($linkedBase === false) {
                $redirectAddError('The selected full member could not be found.');
            }
            $base = (int) $linkedBase;
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(member_number_suffix), 0) + 1 FROM members WHERE member_number_base = :base');
            $stmt->execute(['base' => $base]);
            $suffix = (int) $stmt->fetchColumn();
        } else {
            // Full / Life / unlinked associate: own base number, suffix 0.
            $fullMemberId = 0;
            $inputBase = trim((string) ($_POST['member_number_base'] ?? ''));
            if ($inputBase !== '' && ctype_digit($inputBase)) {
                $base = (int) $inputBase;
            } else {
                $memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
                $maxBase = (int) $pdo->query('SELECT MAX(member_number_base) FROM members')->fetchColumn();
                $base = max($maxBase, max($memberNumberStart, 1) - 1) + 1;
            }
            $suffix = 0;
        }
        if ($base <= 0) {
            $redirectAddError('A valid member number is required.');
        }
        $stmt = $pdo->prepare('SELECT id FROM members WHERE member_number_base = :base AND member_number_suffix = :suffix LIMIT 1');
        $stmt->execute(['base' => $base, 'suffix' => $suffix]);
        if ($stmt->fetchColumn()) {
            $redirectAddError('Member number ' . MembershipService::displayMembershipNumber($base, $suffix) . ' is already in use.');
        }

        $chapterId = isset($_POST['chapter_id']) && $_POST['chapter_id'] !== '' ? (int) $_POST['chapter_id'] : null;

        // --- Minimal INSERT (required columns); optional fields filled via
        // MemberRepository::update() which handles all the column mapping. ---
        $stmt = $pdo->prepare('INSERT INTO members (member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, first_name, last_name, email, created_at) VALUES (:member_type, :status, :base, :suffix, :full_member_id, :chapter_id, :first_name, :last_name, :email, NOW())');
        $stmt->execute([
            'member_type' => $memberTypeStep,
            'status' => 'PENDING',
            'base' => $base,
            'suffix' => $suffix,
            'full_member_id' => $fullMemberId > 0 ? $fullMemberId : null,
            'chapter_id' => $chapterId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);
        $newMemberId = (int) $pdo->lastInsertId();
        if ($newMemberId <= 0) {
            $redirectAddError('Failed to create the member record.');
        }

        // --- Steps 3 & 4: address + preferences (reuses existing field mapping) ---
        $profilePayload = [
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'address_line1' => trim((string) ($_POST['address_line1'] ?? '')),
            'address_line2' => trim((string) ($_POST['address_line2'] ?? '')),
            'suburb' => trim((string) ($_POST['suburb'] ?? '')),
            'state' => trim((string) ($_POST['state'] ?? '')),
            'postcode' => trim((string) ($_POST['postcode'] ?? '')),
            'country' => trim((string) ($_POST['country'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        $wp = strtolower(trim((string) ($_POST['wings_preference'] ?? 'digital')));
        if (in_array($wp, ['digital', 'print', 'both'], true)) {
            $profilePayload['wings_preference'] = $wp;
        }
        $pl = strtoupper(trim((string) ($_POST['privacy_level'] ?? 'A')));
        if (in_array($pl, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
            $profilePayload['privacy_level'] = $pl;
        }
        foreach (MemberRepository::directoryPreferences() as $letter => $info) {
            $profilePayload['directory_pref_' . $letter] = isset($_POST['directory_pref_' . $letter]) ? 1 : 0;
        }
        MemberRepository::update($newMemberId, $profilePayload);

        // --- Step 5: bike(s) (optional) ---
        $bikesInput = $_POST['bikes'] ?? [];
        $bikesAdded = 0;
        if (is_array($bikesInput)) {
            foreach ($bikesInput as $bikeRow) {
                if (!is_array($bikeRow)) {
                    continue;
                }
                if (insertMemberBike($pdo, $newMemberId, [
                    'make' => $bikeRow['make'] ?? '',
                    'model' => $bikeRow['model'] ?? '',
                    'year' => $bikeRow['year'] ?? 0,
                    'rego' => $bikeRow['rego'] ?? '',
                    'color' => $bikeRow['color'] ?? '',
                ])) {
                    $bikesAdded++;
                }
            }
        }

        // --- Step 6: membership + expiry + order (always created) ---
        $membershipResult = createMembershipForMember($pdo, $newMemberId, [
            'membership_type_id' => $_POST['membership_type_id'] ?? 0,
            'cost' => $_POST['membership_cost'] ?? 0,
            'payment_method' => $_POST['payment_method'] ?? 'Manual',
            'membership_status' => $_POST['membership_status'] ?? 'active',
            'reference' => $_POST['order_reference'] ?? '',
            'start_date' => $_POST['start_date'] ?? '',
            'renewal_date' => $_POST['renewal_date'] ?? '',
            'term' => $_POST['term'] ?? '',
        ], $user, $memberTypeStep);
        if (!$membershipResult['success']) {
            // Member row exists but membership failed — surface on the profile so
            // the admin can finish setting up the membership manually.
            redirectWithFlash($newMemberId, 'overview', 'Member created, but the membership could not be set up: ' . ($membershipResult['error'] ?? 'unknown error') . ' Please add it from the Orders tab.', 'error');
        }

        // Reload the full member record for email dispatch (name/email/user_id).
        $newMember = MemberRepository::findById($newMemberId) ?? [
            'id' => $newMemberId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'user_id' => null,
        ];

        // --- Step 7: finish actions (emails) ---
        $finishActions = $_POST['finish_actions'] ?? [];
        if (!is_array($finishActions)) {
            $finishActions = [$finishActions];
        }
        $sendWelcome = in_array('welcome', $finishActions, true);
        $sendPayment = in_array('payment', $finishActions, true);

        $emailNotes = [];
        if ($sendWelcome) {
            if (!AdminMemberAccess::canResetPassword($user)) {
                $emailNotes[] = 'welcome email skipped (not permitted)';
            } else {
                $welcomeResult = sendWelcomeEmailForMember($pdo, $newMember, $user);
                $emailNotes[] = $welcomeResult['success'] ? 'welcome email sent' : 'welcome email failed (' . ($welcomeResult['error'] ?? 'unknown') . ')';
            }
        }
        if ($sendPayment && $membershipResult['membership_status'] === 'pending' && !empty($newMember['email'])) {
            $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
            $bankInstructions = (string) SettingsService::getGlobal('payments.bank_transfer_instructions', '');
            $paymentSent = NotificationService::dispatch('membership_order_created', [
                'primary_email' => $newMember['email'],
                'admin_emails' => NotificationService::getAdminEmails(),
                'member_name' => trim(($newMember['first_name'] ?? '') . ' ' . ($newMember['last_name'] ?? '')),
                'order_number' => $membershipResult['order_number'] !== '' ? $membershipResult['order_number'] : '',
                'payment_link' => NotificationService::escape($paymentLink),
                'payment_method' => $membershipResult['payment_method'],
                'bank_transfer_instructions' => NotificationService::escape($bankInstructions),
            ]);
            $emailNotes[] = $paymentSent ? 'payment email sent' : 'payment email failed';
        } elseif ($sendPayment && $membershipResult['membership_status'] !== 'pending') {
            $emailNotes[] = 'payment email skipped (membership not pending)';
        }

        ActivityLogger::log('admin', $user['id'] ?? null, $newMemberId, 'member.created', [
            'member_type' => $memberTypeStep,
            'member_number' => MembershipService::displayMembershipNumber($base, $suffix),
            'membership_status' => $membershipResult['membership_status'],
            'finish_actions' => $finishActions,
            'actor_roles' => $user['roles'] ?? [],
        ]);

        $summary = 'Member ' . trim($firstName . ' ' . $lastName) . ' (#' . MembershipService::displayMembershipNumber($base, $suffix) . ') created.';
        if ($bikesAdded > 0) {
            $summary .= ' ' . $bikesAdded . ' bike' . ($bikesAdded === 1 ? '' : 's') . ' added.';
        }
        if ($emailNotes !== []) {
            $summary .= ' ' . ucfirst(implode('; ', $emailNotes)) . '.';
        }
        redirectWithFlash($newMemberId, 'overview', $summary);
        break;

    case 'impersonate_member':
        if (!AdminMemberAccess::canImpersonate($user)) {
            redirectWithFlash($memberId, $tab, 'You are not authorized to impersonate members.', 'error');
        }
        $pdo = Database::connection();
        $memberUser = null;
        if (!empty($member['user_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $member['user_id']]);
            $memberUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$memberUser) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE member_id = :member_id LIMIT 1');
            $stmt->execute(['member_id' => (int) $member['id']]);
            $memberUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$memberUser) {
            redirectWithFlash($memberId, $tab, 'This member does not have a login account.', 'error');
        }
        if ((int) ($memberUser['is_active'] ?? 0) !== 1) {
            redirectWithFlash($memberId, $tab, 'This member login is inactive.', 'error');
        }
        $memberUser['roles'] = AuthService::getUserRoles((int) $memberUser['id']);
        if (empty($memberUser['roles'])) {
            $memberUser['roles'] = ['member'];
        }
        $memberName = trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''));
        if ($memberName === '') {
            $memberName = (string) ($memberUser['name'] ?? 'Member');
        }
        $_SESSION['impersonation'] = [
            'admin_user' => $user,
            'admin_id' => (int) ($user['id'] ?? 0),
            'member_id' => (int) $member['id'],
            'member_user_id' => (int) $memberUser['id'],
            'member_name' => $memberName,
            'started_at' => time(),
            'return_member_id' => (int) $member['id'],
            'return_tab' => 'overview',
        ];
        $_SESSION['user'] = [
            'id' => (int) $memberUser['id'],
            'email' => $memberUser['email'],
            'name' => $memberUser['name'] ?? $memberName,
            'member_id' => (int) $member['id'],
            'roles' => $memberUser['roles'],
        ];
        ActivityLogger::log('admin', (int) ($user['id'] ?? 0), (int) $member['id'], 'impersonation.started', [
            'member_user_id' => (int) $memberUser['id'],
        ]);
        header('Location: /member/index.php');
        exit;
    case 'save_profile':
        if (!AdminMemberAccess::canEditProfile($user)) {
            redirectWithFlash($memberId, $tab, 'You are not authorized to update this profile.', 'error');
        }
        $targetMemberId = (int) ($_POST['profile_member_id'] ?? $memberId);
        if ($targetMemberId <= 0) {
            $targetMemberId = $memberId;
        }
        $targetMember = $targetMemberId === $memberId ? $member : MemberRepository::findById($targetMemberId);
        $redirectExtras = [];
        if ($targetMemberId !== $memberId) {
            $redirectExtras['profile_member_id'] = $targetMemberId;
        }
        if (!$targetMember) {
            redirectWithFlash($memberId, $tab, 'Profile record not found.', 'error', $redirectExtras);
        }
        $canEditLinked = $targetMemberId === $memberId;
        if (!$canEditLinked) {
            if (in_array($member['member_type'], ['FULL', 'LIFE'], true) && ((int) ($targetMember['full_member_id'] ?? 0)) === $memberId) {
                $canEditLinked = true;
            } elseif ($member['member_type'] === 'ASSOCIATE' && !empty($member['full_member_id']) && (int) $member['full_member_id'] === $targetMemberId) {
                $canEditLinked = true;
            }
        }
        if (!$canEditLinked) {
            redirectWithFlash($memberId, $tab, 'The selected profile is not linked to this member.', 'error', $redirectExtras);
        }

        $payload = [];
        $allowFullProfile = AdminMemberAccess::canEditFullProfile($user) && $targetMemberId === $memberId;
        if (AdminMemberAccess::canEditContact($user)) {
            $payload['first_name'] = trim($_POST['first_name'] ?? $targetMember['first_name']);
            $payload['last_name'] = trim($_POST['last_name'] ?? $targetMember['last_name']);
            $payload['email'] = trim($_POST['email'] ?? $targetMember['email']);
            $payload['phone'] = trim($_POST['phone'] ?? $targetMember['phone'] ?? '');
            $payload['date_of_birth'] = trim($_POST['date_of_birth'] ?? $targetMember['date_of_birth'] ?? '');
        }
        if (AdminMemberAccess::canEditAddress($user)) {
            $payload['address_line1'] = trim($_POST['address_line1'] ?? $targetMember['address_line1'] ?? '');
            $payload['address_line2'] = trim($_POST['address_line2'] ?? $targetMember['address_line2'] ?? '');
            $payload['suburb'] = trim($_POST['suburb'] ?? $targetMember['suburb'] ?? '');
            $payload['state'] = trim($_POST['state'] ?? $targetMember['state'] ?? '');
            $payload['postcode'] = trim($_POST['postcode'] ?? $targetMember['postal_code'] ?? '');
            $payload['country'] = trim($_POST['country'] ?? $targetMember['country'] ?? '');
        }
        if ($allowFullProfile) {
            if (array_key_exists('chapter_id', $_POST)) {
                $payload['chapter_id'] = $_POST['chapter_id'] !== '' ? (int) $_POST['chapter_id'] : null;
            }
            if (array_key_exists('membership_type_id', $_POST)) {
                $payload['membership_type_id'] = $_POST['membership_type_id'] !== '' ? (int) $_POST['membership_type_id'] : null;
            }
            if (array_key_exists('status', $_POST)) {
                $status = $_POST['status'];
                if (in_array($status, ['pending', 'active', 'expired', 'cancelled', 'suspended'], true)) {
                    $payload['status'] = $status;
                }
            }
            if (array_key_exists('wings_preference', $_POST)) {
                $wp = strtolower(trim((string) $_POST['wings_preference']));
                if (in_array($wp, ['digital', 'print', 'both'], true)) {
                    $payload['wings_preference'] = $wp;
                }
            }
            if (array_key_exists('australia_presort_code', $_POST)) {
                $payload['australia_presort_code'] = substr(trim((string) $_POST['australia_presort_code']), 0, 10);
            }
            if (array_key_exists('privacy_level', $_POST)) {
                $pl = strtoupper(trim((string) $_POST['privacy_level']));
                if (in_array($pl, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
                    $payload['privacy_level'] = $pl;
                }
            }
            // Directory preferences are checkboxes — only sync when the form section was actually submitted.
            $directoryFormPresent = false;
            foreach (MemberRepository::directoryPreferences() as $letter => $info) {
                if (array_key_exists('directory_pref_' . $letter, $_POST)) {
                    $directoryFormPresent = true;
                    break;
                }
            }
            $directoryMarkerPresent = !empty($_POST['directory_pref_submitted']);
            if ($directoryFormPresent || $directoryMarkerPresent) {
                foreach (MemberRepository::directoryPreferences() as $letter => $info) {
                    $payload['directory_pref_' . $letter] = isset($_POST['directory_pref_' . $letter]) ? 1 : 0;
                }
            }
            if (array_key_exists('notes', $_POST)) {
                $payload['notes'] = trim((string) $_POST['notes']);
            }
            if (!empty($_POST['admin_flags_submitted'])) {
                $payload['is_area_rep'] = isset($_POST['is_area_rep']) ? 1 : 0;
                $payload['is_committee'] = isset($_POST['is_committee']) ? 1 : 0;
                $payload['committee_role'] = trim((string) ($_POST['committee_role'] ?? ''));
            }
        }
        if ($allowFullProfile && $member['member_type'] === 'ASSOCIATE' && !empty($member['full_member_id'])) {
            unset($payload['chapter_id']);
        }

        if ($payload === []) {
            if (!AdminMemberAccess::canEditContact($user) && !AdminMemberAccess::canEditAddress($user) && !$allowFullProfile) {
                redirectWithFlash($memberId, $tab, "You don't have permission to edit this profile. Contact an administrator if you need to request changes.", 'error', $redirectExtras);
            }
            redirectWithFlash($memberId, $tab, 'No changes detected.', 'error', $redirectExtras);
        }
        if (array_key_exists('email', $payload) && !MemberRepository::isEmailAvailable($payload['email'], $targetMemberId)) {
            redirectWithFlash($memberId, $tab, 'That email address is already linked to another member.', 'error', $redirectExtras);
        }

        $before = [];
        foreach ($payload as $key => $value) {
            $before[$key] = $targetMember[$key] ?? null;
        }

        $updated = MemberRepository::update($targetMemberId, $payload);
        if ($updated && $allowFullProfile && array_key_exists('chapter_id', $payload) && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
            $chapterId = $payload['chapter_id'];
            $pdo = Database::connection();
            $stmt = $pdo->prepare('UPDATE members SET chapter_id = :chapter_id WHERE full_member_id = :full_member_id');
            $stmt->execute(['chapter_id' => $chapterId, 'full_member_id' => $memberId]);
        }
        if (!$updated) {
            redirectWithFlash($memberId, $tab, 'Failed to update profile.', 'error', $redirectExtras);
        }

        if (array_key_exists('email', $payload) && !empty($payload['email']) && $payload['email'] !== ($before['email'] ?? null)) {
            $syncPdo = Database::connection();
            $linkedUserId = (int) ($targetMember['user_id'] ?? 0);
            if ($linkedUserId > 0) {
                $syncStmt = $syncPdo->prepare('UPDATE users SET email = :email, updated_at = NOW() WHERE id = :id');
                $syncStmt->execute(['email' => $payload['email'], 'id' => $linkedUserId]);
            } elseif (!empty($before['email'])) {
                $syncStmt = $syncPdo->prepare('UPDATE users SET email = :new_email, updated_at = NOW() WHERE email = :old_email');
                $syncStmt->execute(['new_email' => $payload['email'], 'old_email' => $before['email']]);
            }
        }

        // Household address sync (committee request): copy the saved address to
        // the linked full/associate member(s) when the admin ticked the box.
        $addressSyncKeys = ['address_line1', 'address_line2', 'suburb', 'state', 'postcode', 'country'];
        $addressPayload = array_intersect_key($payload, array_flip($addressSyncKeys));
        $addressSyncedNames = [];
        if ($updated && !empty($_POST['sync_address_linked']) && $addressPayload !== []) {
            $familyFullId = 0;
            $targetType = strtoupper((string) ($targetMember['member_type'] ?? ''));
            if (in_array($targetType, ['FULL', 'LIFE'], true)) {
                $familyFullId = $targetMemberId;
            } elseif (!empty($targetMember['full_member_id'])) {
                $familyFullId = (int) $targetMember['full_member_id'];
            }
            if ($familyFullId > 0) {
                $stmt = Database::connection()->prepare('SELECT id, first_name, last_name FROM members WHERE (id = :full_id OR full_member_id = :full_id2) AND id <> :target');
                $stmt->execute(['full_id' => $familyFullId, 'full_id2' => $familyFullId, 'target' => $targetMemberId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linkedRow) {
                    if (MemberRepository::update((int) $linkedRow['id'], $addressPayload)) {
                        $addressSyncedNames[] = trim(($linkedRow['first_name'] ?? '') . ' ' . ($linkedRow['last_name'] ?? ''));
                        ActivityLogger::log('admin', $user['id'] ?? null, (int) $linkedRow['id'], 'member.updated', [
                            'changes' => ['address_synced_from_member_id' => $targetMemberId, 'fields' => array_keys($addressPayload)],
                            'actor_roles' => $user['roles'] ?? [],
                        ]);
                    }
                }
            }
        }

        // Sidebar / topbar / "Welcome <name>" reads from $_SESSION['user']['name']
        // which is set once at login from users.name. If the admin just edited
        // their OWN profile name (first_name or last_name), refresh the session
        // copy so the change appears immediately without re-logging-in.
        if ($updated
            && (array_key_exists('first_name', $payload) || array_key_exists('last_name', $payload))
            && !empty($targetMember['user_id'])
            && !empty($user['id'])
            && (int) $targetMember['user_id'] === (int) $user['id']
        ) {
            $newFirst = $payload['first_name'] ?? ($targetMember['first_name'] ?? '');
            $newLast  = $payload['last_name']  ?? ($targetMember['last_name']  ?? '');
            $newFull  = trim($newFirst . ' ' . $newLast);
            if ($newFull !== '' && isset($_SESSION['user'])) {
                $_SESSION['user']['name'] = $newFull;
            }
        }

        $changes = [];
        foreach ($payload as $key => $value) {
            $old = $before[$key] ?? null;
            if ($old != $value) {
                $changes[$key] = ['before' => $old, 'after' => $value];
            }
        }

        if ($changes !== []) {
            ActivityLogger::log('admin', $user['id'] ?? null, $targetMemberId, 'member.updated', [
                'changes' => $changes,
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        if (isset($changes['status'])) {
            ActivityLogger::log('admin', $user['id'] ?? null, $targetMemberId, 'member.status_changed', [
                'from' => $changes['status']['before'],
                'to' => $changes['status']['after'],
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        if (isset($changes['chapter_id'])) {
            ActivityLogger::log('admin', $user['id'] ?? null, $targetMemberId, 'member.chapter_updated', [
                'from' => $changes['chapter_id']['before'],
                'to' => $changes['chapter_id']['after'],
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        if (isset($changes['membership_type_id'])) {
            $stmt = Database::connection()->prepare('SELECT name FROM membership_types WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $changes['membership_type_id']['after']]);
            $newTypeName = $stmt->fetchColumn();
            ActivityLogger::log('admin', $user['id'] ?? null, $targetMemberId, 'member.membership_type_changed', [
                'from' => $targetMember['membership_type_name'] ?? null,
                'to' => $newTypeName,
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }

        $profileFlash = 'Member profile updated.';
        if ($addressSyncedNames) {
            $profileFlash .= ' Address also applied to ' . implode(', ', $addressSyncedNames) . '.';
        }
        redirectWithFlash($memberId, $tab, $profileFlash);
        break;

    case 'member_inline_update':
        if (!userCanInlineEdit($user['roles'] ?? [])) {
            respondWithJson(['success' => false, 'error' => 'Permission denied.'], 403);
        }
        $inlineMemberId = (int) ($_POST['inline_member_id'] ?? 0);
        $inlineField = trim((string) ($_POST['inline_field'] ?? ''));
        $inlineValue = trim((string) ($_POST['inline_value'] ?? ''));
        if ($inlineMemberId <= 0) {
            respondWithJson(['success' => false, 'error' => 'Invalid member selection.'], 400);
        }
        $pdo = Database::connection();
        $inlineMember = MemberRepository::findById($inlineMemberId);
        if (!$inlineMember) {
            respondWithJson(['success' => false, 'error' => 'Member not found.'], 404);
        }
        if ($chapterRestriction !== null && ((int) ($inlineMember['chapter_id'] ?? 0)) !== $chapterRestriction) {
            respondWithJson(['success' => false, 'error' => 'Access denied for this chapter.'], 403);
        }
        switch ($inlineField) {
            case 'chapter':
                if (!AdminMemberAccess::canEditFullProfile($user)) {
                    respondWithJson(['success' => false, 'error' => 'Chapter updates are restricted.'], 403);
                }
                $newChapterId = $inlineValue === '' ? null : (int) $inlineValue;
                $beforeChapter = $inlineMember['chapter_id'] ?? null;
                if ($beforeChapter === $newChapterId) {
                    respondWithJson(['success' => true, 'label' => fetchChapterDisplay($pdo, $newChapterId), 'value' => $newChapterId === null ? '' : (string) $newChapterId]);
                }
                if (!MemberRepository::update($inlineMemberId, ['chapter_id' => $newChapterId])) {
                    respondWithJson(['success' => false, 'error' => 'Could not save chapter.']);
                }
                ActivityLogger::log('admin', $user['id'] ?? null, $inlineMemberId, 'member.chapter_updated', [
                    'from' => $beforeChapter,
                    'to' => $newChapterId,
                    'actor_roles' => $user['roles'] ?? [],
                ]);
                respondWithJson(['success' => true, 'label' => fetchChapterDisplay($pdo, $newChapterId), 'value' => $newChapterId === null ? '' : (string) $newChapterId]);

            case 'status':
                if (!AdminMemberAccess::canEditFullProfile($user)) {
                    respondWithJson(['success' => false, 'error' => 'Status updates are restricted.'], 403);
                }
                $allowedStatuses = ['pending', 'active', 'expired', 'cancelled', 'suspended'];
                if (!in_array($inlineValue, $allowedStatuses, true)) {
                    respondWithJson(['success' => false, 'error' => 'Invalid status selected.'], 400);
                }
                if ($inlineMember['status'] === $inlineValue) {
                    respondWithJson(['success' => true, 'label' => inlineStatusLabel($inlineValue), 'value' => $inlineValue]);
                }
                try {
                    MembershipStatusService::applyAdminUpdate($inlineMemberId, ['status' => $inlineValue]);
                } catch (Throwable $e) {
                    respondWithJson(['success' => false, 'error' => 'Could not save status.']);
                }
                ActivityLogger::log('admin', $user['id'] ?? null, $inlineMemberId, 'member.status_updated', [
                    'from' => $inlineMember['status'],
                    'to' => $inlineValue,
                    'actor_roles' => $user['roles'] ?? [],
                ]);
                respondWithJson(['success' => true, 'label' => inlineStatusLabel($inlineValue), 'value' => $inlineValue]);

            case 'twofa':
                if (!AdminMemberAccess::canEditFullProfile($user)) {
                    respondWithJson(['success' => false, 'error' => '2FA toggles are restricted.'], 403);
                }
                $userId = (int) ($inlineMember['user_id'] ?? 0);
                if ($userId === 0) {
                    respondWithJson(['success' => false, 'error' => 'Missing linked user account.'], 400);
                }
                $desired = $inlineValue === '1' ? 'REQUIRED' : 'DEFAULT';
                $currentOverride = SecurityPolicyService::getTwoFaOverride($userId);
                if ($currentOverride === $desired) {
                    $label = $desired === 'REQUIRED' ? 'Required' : 'Optional';
                    respondWithJson(['success' => true, 'label' => $label, 'state' => $desired === 'REQUIRED' ? '1' : '0']);
                }
                SecurityPolicyService::setTwoFaOverride($userId, $desired);
                ActivityLogger::log('admin', $user['id'] ?? null, $inlineMemberId, 'security.2fa_requirement_updated', [
                    'from' => $currentOverride,
                    'to' => $desired,
                    'actor_roles' => $user['roles'] ?? [],
                ]);
                $label = $desired === 'REQUIRED' ? 'Required' : 'Optional';
                respondWithJson(['success' => true, 'label' => $label, 'state' => $desired === 'REQUIRED' ? '1' : '0']);

            default:
                respondWithJson(['success' => false, 'error' => 'Invalid inline field.'], 400);
        }
        break;

    case 'bulk_member_action':
        if (!userCanInlineEdit($user['roles'] ?? [])) {
            respondWithJson(['success' => false, 'error' => 'Permission denied.'], 403);
        }
        $memberIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['member_ids'] ?? [])), static fn($id) => $id > 0)));
        if ($memberIds === []) {
            respondWithJson(['success' => false, 'error' => 'No members selected.'], 400);
        }
        $actionKey = trim((string) ($_POST['bulk_action'] ?? ''));
        $allowedActions = ['archive', 'delete', 'assign_chapter', 'change_status', 'enable_2fa', 'send_reset_link', 'send_welcome_email'];
        if (!in_array($actionKey, $allowedActions, true)) {
            respondWithJson(['success' => false, 'error' => 'Unknown bulk action.'], 400);
        }

        $chapterId = isset($_POST['chapter_id']) ? (int) $_POST['chapter_id'] : 0;
        $statusValue = trim((string) ($_POST['status'] ?? ''));
        $reasonText = trim((string) ($_POST['reason'] ?? ''));
        $confirmText = trim((string) ($_POST['confirm'] ?? ''));

        if ($actionKey === 'assign_chapter' && $chapterId <= 0) {
            respondWithJson(['success' => false, 'error' => 'Select a chapter to assign.'], 400);
        }
        if ($actionKey === 'change_status') {
            $allowedStatuses = ['pending', 'active', 'expired', 'cancelled', 'suspended'];
            if (!in_array($statusValue, $allowedStatuses, true)) {
                respondWithJson(['success' => false, 'error' => 'Select a valid status.'], 400);
            }
            if ($reasonText === '') {
                respondWithJson(['success' => false, 'error' => 'Provide a reason for the status change.'], 400);
            }
        }
        if ($actionKey === 'delete' && strtoupper($confirmText) !== 'CONFIRM') {
            respondWithJson(['success' => false, 'error' => 'Type CONFIRM to delete members.'], 400);
        }

        $pdo = Database::connection();
        $members = fetchMembersForBulk($pdo, $memberIds);
        $membersById = [];
        foreach ($members as $row) {
            $membersById[(int) ($row['id'] ?? 0)] = $row;
        }

        $chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);
        $applied = 0;
        $skipped = [];
        $actorRoles = $user['roles'] ?? [];
        $window = (new \DateTimeImmutable('now'))->modify('-60 minutes')->format('Y-m-d H:i:s');
        $adminResetCount = 0;
        $memberResetStmt = null;
        if ($actionKey === 'send_reset_link') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'member.password_reset_link_sent' AND actor_type = 'admin' AND actor_id = :actor_id AND created_at >= :window");
            $stmt->execute(['actor_id' => $user['id'] ?? null, 'window' => $window]);
            $adminResetCount = (int) $stmt->fetchColumn();
            $memberResetStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'member.password_reset_link_sent' AND member_id = :member_id AND created_at >= :window");
        }

        foreach ($memberIds as $memberId) {
            $member = $membersById[$memberId] ?? null;
            if (!$member) {
                $skipped[] = ['member_id' => $memberId, 'reason' => 'Member not found'];
                continue;
            }
            if ($chapterRestriction !== null && ((int) ($member['chapter_id'] ?? 0)) !== $chapterRestriction) {
                $skipped[] = ['member_id' => $memberId, 'reason' => 'Chapter access restricted'];
                continue;
            }

            if ($actionKey === 'archive') {
                $currentStatus = $member['status'] ?? '';
                if ($currentStatus === 'cancelled') {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Already archived'];
                    continue;
                }
                try {
                    MembershipStatusService::applyAdminUpdate($memberId, ['status' => 'cancelled']);
                } catch (Throwable $e) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Could not save status'];
                    continue;
                }
                ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.archived', [
                    'from' => $currentStatus,
                    'to' => 'cancelled',
                    'actor_roles' => $actorRoles,
                ]);
                $applied++;
                continue;
            }

            if ($actionKey === 'assign_chapter') {
                $currentChapter = (int) ($member['chapter_id'] ?? 0);
                if ($currentChapter === $chapterId) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Already assigned to this chapter'];
                    continue;
                }
                if (!MemberRepository::update($memberId, ['chapter_id' => $chapterId])) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Could not save chapter'];
                    continue;
                }
                ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.chapter_updated', [
                    'from' => $currentChapter,
                    'to' => $chapterId,
                    'actor_roles' => $actorRoles,
                ]);
                $applied++;
                continue;
            }

            if ($actionKey === 'change_status') {
                $currentStatus = $member['status'] ?? '';
                if ($currentStatus === $statusValue) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Status is already ' . $statusValue];
                    continue;
                }
                try {
                    MembershipStatusService::applyAdminUpdate($memberId, ['status' => $statusValue]);
                } catch (Throwable $e) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Could not save status'];
                    continue;
                }
                ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.status_updated', [
                    'from' => $currentStatus,
                    'to' => $statusValue,
                    'reason' => $reasonText,
                    'actor_roles' => $actorRoles,
                ]);
                $applied++;
                continue;
            }

            if ($actionKey === 'enable_2fa') {
                $userId = (int) ($member['user_id'] ?? 0);
                if ($userId <= 0) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'User account missing'];
                    continue;
                }
                $currentOverride = SecurityPolicyService::getTwoFaOverride($userId);
                if ($currentOverride === 'REQUIRED') {
                    $skipped[] = ['member_id' => $memberId, 'reason' => '2FA is already required'];
                    continue;
                }
                SecurityPolicyService::setTwoFaOverride($userId, 'REQUIRED');
                ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.2fa_requirement_updated', [
                    'from' => $currentOverride,
                    'to' => 'REQUIRED',
                    'actor_roles' => $actorRoles,
                ]);
                $applied++;
                continue;
            }

            if ($actionKey === 'send_reset_link') {
                $userId = (int) ($member['user_id'] ?? 0);
                if ($userId <= 0 || empty($member['email'])) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'User account or email missing'];
                    continue;
                }
                $approved = isMembershipApplicationApproved($pdo, $memberId);
                if ($approved === false) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Application not approved'];
                    continue;
                }
                if ($adminResetCount >= 3) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Rate limit reached for admin'];
                    continue;
                }
                $memberResetStmt->execute(['member_id' => $memberId, 'window' => $window]);
                if ((int) $memberResetStmt->fetchColumn() >= 3) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Rate limit reached for member'];
                    continue;
                }
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, ip_address) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), :ip)');
                $stmt->execute([
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $link = BaseUrlService::emailLink('/member/reset_password_confirm.php?token=' . urlencode($token));
                NotificationService::dispatch('member_password_reset_admin', [
                    'primary_email' => $member['email'],
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'reset_link' => NotificationService::escape($link),
                ]);
                ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.password_reset_link_sent', [
                    'user_id' => $userId,
                    'actor_roles' => $actorRoles,
                ]);
                $adminResetCount++;
                $applied++;
                continue;
            }

            if ($actionKey === 'send_welcome_email') {
                $userId = (int) ($member['user_id'] ?? 0);
                if ($userId <= 0 || empty($member['email'])) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'User account or email missing'];
                    continue;
                }
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, ip_address) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW(), :ip)');
                $stmt->execute([
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $link = BaseUrlService::emailLink('/member/reset_password_confirm.php?token=' . urlencode($token));
                NotificationService::dispatch('member_set_password', [
                    'primary_email' => $member['email'],
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'reset_link' => NotificationService::escape($link),
                    'first_name' => NotificationService::escape(trim((string) ($member['first_name'] ?? ''))),
                ]);
                ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.welcome_email_sent', [
                    'user_id' => $userId,
                    'actor_roles' => $actorRoles,
                ]);
                $applied++;
                continue;
            }

            if ($actionKey === 'delete') {
                if (!deleteMemberPermanently($pdo, $user, $member)) {
                    $skipped[] = ['member_id' => $memberId, 'reason' => 'Delete failed'];
                    continue;
                }
                $applied++;
                continue;
            }

            $skipped[] = ['member_id' => $memberId, 'reason' => 'Unsupported action'];
        }

        respondWithJson(['success' => true, 'applied' => $applied, 'skipped' => $skipped]);
        break;

    case 'request_chapter':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Chapter change requests are restricted.', 'error');
        }
        $requestedChapter = (int) ($_POST['requested_chapter_id'] ?? ($_POST['chapter_id'] ?? 0));
        if ($requestedChapter <= 0) {
            redirectWithFlash($memberId, $tab, 'Select a chapter to request.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO chapter_change_requests (member_id, requested_chapter_id, status, requested_at) VALUES (:member_id, :chapter_id, "PENDING", NOW())');
        $stmt->execute(['member_id' => $memberId, 'chapter_id' => $requestedChapter]);
        redirectWithFlash($memberId, $tab, 'Chapter change request submitted.');
        break;

    case 'assign_chapter':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Chapter assignments are restricted.', 'error');
        }
        $requestedChapter = (int) ($_POST['requested_chapter_id'] ?? 0);
        if ($requestedChapter <= 0) {
            redirectWithFlash($memberId, $tab, 'Select a chapter to apply.', 'error');
        }
        $targetMemberId = $memberId;
        if (($member['member_type'] ?? '') === 'ASSOCIATE' && !empty($member['full_member_id'])) {
            $targetMemberId = (int) $member['full_member_id'];
        }
        $currentChapter = (int) ($member['chapter_id'] ?? 0);
        if ($targetMemberId !== $memberId) {
            $targetMember = MemberRepository::findById($targetMemberId);
            $currentChapter = (int) ($targetMember['chapter_id'] ?? 0);
        }
        if ($currentChapter === $requestedChapter && $targetMemberId === $memberId) {
            redirectWithFlash($memberId, $tab, 'Member is already assigned to this chapter.', 'error');
        }
        if (!MemberRepository::update($targetMemberId, ['chapter_id' => $requestedChapter])) {
            redirectWithFlash($memberId, $tab, 'Could not update chapter.', 'error');
        }
        if (in_array(($member['member_type'] ?? ''), ['FULL', 'LIFE'], true) || $targetMemberId !== $memberId) {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('UPDATE members SET chapter_id = :chapter_id WHERE full_member_id = :full_member_id');
            $stmt->execute(['chapter_id' => $requestedChapter, 'full_member_id' => $targetMemberId]);
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $targetMemberId, 'member.chapter_updated', [
            'from' => $currentChapter,
            'to' => $requestedChapter,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Chapter updated.');
        break;

    case 'chapter_request_decision':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Chapter change approvals are restricted.', 'error');
        }
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            redirectWithFlash($memberId, $tab, 'Invalid request action.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM chapter_change_requests WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $requestId, 'member_id' => $memberId]);
        $request = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$request) {
            redirectWithFlash($memberId, $tab, 'Chapter request not found.', 'error');
        }
        if (($request['status'] ?? '') !== 'PENDING') {
            redirectWithFlash($memberId, $tab, 'Chapter request already processed.', 'error');
        }
        if ($decision === 'approve') {
            $stmt = $pdo->prepare('UPDATE chapter_change_requests SET status = "APPROVED", approved_by = :approved_by, approved_at = NOW() WHERE id = :id');
            $stmt->execute(['approved_by' => $user['id'] ?? null, 'id' => $requestId]);
            $targetMember = MemberRepository::findById((int) $request['member_id']);
            $requestedChapterId = (int) ($request['requested_chapter_id'] ?? 0);
            if ($targetMember && $requestedChapterId > 0) {
                $fullMemberId = (int) ($targetMember['id'] ?? 0);
                if (($targetMember['member_type'] ?? '') === 'ASSOCIATE' && !empty($targetMember['full_member_id'])) {
                    $fullMemberId = (int) $targetMember['full_member_id'];
                }
                if ($fullMemberId > 0) {
                    MemberRepository::update($fullMemberId, ['chapter_id' => $requestedChapterId]);
                    $stmt = $pdo->prepare('UPDATE members SET chapter_id = :chapter_id WHERE full_member_id = :full_member_id');
                    $stmt->execute(['chapter_id' => $requestedChapterId, 'full_member_id' => $fullMemberId]);
                }
            }
            redirectWithFlash($memberId, $tab, 'Chapter change approved.');
        }
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            redirectWithFlash($memberId, $tab, 'Provide a rejection reason.', 'error');
        }
        if (chapterRequestHasReason($pdo)) {
            $stmt = $pdo->prepare('UPDATE chapter_change_requests SET status = "REJECTED", approved_by = :approved_by, approved_at = NOW(), rejection_reason = :reason WHERE id = :id');
            $stmt->execute([
                'approved_by' => $user['id'] ?? null,
                'reason' => $reason,
                'id' => $requestId,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE chapter_change_requests SET status = "REJECTED", approved_by = :approved_by, approved_at = NOW() WHERE id = :id');
            $stmt->execute(['approved_by' => $user['id'] ?? null, 'id' => $requestId]);
        }
        redirectWithFlash($memberId, $tab, 'Chapter change rejected.');
        break;

    case 'bike_add':
        if (!AdminMemberAccess::canManageVehicles($user)) {
            redirectWithFlash($memberId, $tab, 'Bike management not permitted.', 'error');
        }
        $pdo = Database::connection();
        $bikeAdded = insertMemberBike($pdo, $memberId, [
            'make' => $_POST['bike_make'] ?? '',
            'model' => $_POST['bike_model'] ?? '',
            'year' => $_POST['bike_year'] ?? 0,
            'rego' => $_POST['bike_rego'] ?? '',
            'image_url' => $_POST['bike_image_url'] ?? '',
            'color' => $_POST['bike_color'] ?? '',
        ]);
        if (!$bikeAdded) {
            redirectWithFlash($memberId, $tab, 'Make and model are required.', 'error');
        }
        redirectWithFlash($memberId, $tab, 'Bike added.');
        break;

    case 'bike_update':
        if (!AdminMemberAccess::canManageVehicles($user)) {
            redirectWithFlash($memberId, $tab, 'Bike management not permitted.', 'error');
        }
        $bikeId = (int) ($_POST['bike_id'] ?? 0);
        $make = trim($_POST['bike_make'] ?? '');
        $model = trim($_POST['bike_model'] ?? '');
        $year = (int) ($_POST['bike_year'] ?? 0);
        $rego = trim($_POST['bike_rego'] ?? '');
        $imageUrl = trim($_POST['bike_image_url'] ?? '');
        $color = trim($_POST['bike_color'] ?? '');
        if ($bikeId <= 0) {
            redirectWithFlash($memberId, $tab, 'Bike not found.', 'error');
        }
        if ($make === '' || $model === '') {
            redirectWithFlash($memberId, $tab, 'Make and model are required.', 'error');
        }
        $pdo = Database::connection();
        $fields = ['make = :make', 'model = :model', 'year = :year'];
        $params = [
            'id' => $bikeId,
            'member_id' => $memberId,
            'make' => $make,
            'model' => $model,
            'year' => $year ?: null,
        ];
        if (memberBikeHasColumn($pdo, 'rego')) {
            $fields[] = 'rego = :rego';
            $params['rego'] = $rego !== '' ? $rego : null;
        }
        if (memberBikeHasColumn($pdo, 'image_url')) {
            $fields[] = 'image_url = :image_url';
            $params['image_url'] = $imageUrl !== '' ? $imageUrl : null;
        }
        if (memberBikeHasColumn($pdo, 'color')) {
            $fields[] = 'color = :color';
            $params['color'] = $color !== '' ? $color : null;
        } elseif (memberBikeHasColumn($pdo, 'colour')) {
            $fields[] = 'colour = :colour';
            $params['colour'] = $color !== '' ? $color : null;
        }
        $hasPrimary = memberBikeHasColumn($pdo, 'is_primary');
        $setPrimary = $hasPrimary && isset($_POST['is_primary']) && $_POST['is_primary'] === '1';
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE member_bikes SET ' . implode(', ', $fields) . ' WHERE id = :id AND member_id = :member_id');
        $stmt->execute($params);
        if ($setPrimary) {
            $stmt = $pdo->prepare('UPDATE member_bikes SET is_primary = 0 WHERE member_id = :member_id');
            $stmt->execute(['member_id' => $memberId]);
            $stmt = $pdo->prepare('UPDATE member_bikes SET is_primary = 1 WHERE id = :id AND member_id = :member_id');
            $stmt->execute(['id' => $bikeId, 'member_id' => $memberId]);
        }
        $pdo->commit();
        redirectWithFlash($memberId, $tab, 'Bike updated.');
        break;

    case 'bike_delete':
        if (!AdminMemberAccess::canManageVehicles($user)) {
            redirectWithFlash($memberId, $tab, 'Bike management not permitted.', 'error');
        }
        $bikeId = (int) ($_POST['bike_id'] ?? 0);
        if ($bikeId <= 0) {
            redirectWithFlash($memberId, $tab, 'Bike not found.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM member_bikes WHERE id = :id AND member_id = :member_id');
        $stmt->execute(['id' => $bikeId, 'member_id' => $memberId]);
        redirectWithFlash($memberId, $tab, 'Bike removed.');
        break;

    case 'set_historic':
        // Toggle the member-level "has historic rego" flag (members.is_historic).
        // Same permission as bike management — admins who can manage vehicles can flag.
        if (!AdminMemberAccess::canManageVehicles($user)) {
            redirectWithFlash($memberId, $tab, 'Vehicle management not permitted.', 'error');
        }
        $newValue = isset($_POST['is_historic']) && $_POST['is_historic'] === '1' ? 1 : 0;
        $previousValue = (int) ($member['is_historic'] ?? 0);
        if ($newValue === $previousValue) {
            redirectWithFlash($memberId, $tab, $newValue ? 'Already flagged as historic.' : 'Historic flag already off.');
        }
        if (!MemberRepository::update($memberId, ['is_historic' => $newValue])) {
            redirectWithFlash($memberId, $tab, 'Could not update historic flag.', 'error');
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.historic_flag_updated', [
            'from' => $previousValue,
            'to' => $newValue,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, $newValue ? 'Marked as historic.' : 'Historic flag removed.');
        break;

    case 'send_migration_link':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Manual migration links are restricted.', 'error');
        }
        if (!SettingsService::getGlobal('membership.manual_migration_enabled', true)) {
            redirectWithFlash($memberId, $tab, 'Manual migration is disabled.', 'error');
        }
        if (!empty($member['manual_migration_disabled'])) {
            redirectWithFlash($memberId, $tab, 'Manual migration is disabled for this member.', 'error');
        }
        if (empty($member['email'])) {
            redirectWithFlash($memberId, $tab, 'Member email is required to send the migration link.', 'error');
        }
        $expiryDays = (int) SettingsService::getGlobal('membership.manual_migration_expiry_days', 14);
        $expiryDays = max(1, $expiryDays);
        $tokenData = MembershipMigrationService::createToken($memberId, $user['id'] ?? null, $expiryDays);
        $migrationLink = BaseUrlService::emailLink('/migrate.php?token=' . urlencode($tokenData['token']));
        $sent = NotificationService::dispatch('membership_migration_invite', [
            'primary_email' => $member['email'],
            'admin_emails' => NotificationService::getAdminEmails(),
            'migration_link' => NotificationService::escape($migrationLink),
            'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.migration_invite_sent', [
            'expires_at' => $tokenData['expires_at'] ?? null,
        ]);
        if ($sent) {
            redirectWithFlash($memberId, $tab, 'Manual migration link sent.');
        }
        redirectWithFlash($memberId, $tab, 'Manual migration link generated, but email failed to send.', 'error');
        break;

    case 'disable_migration_link':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Manual migration links are restricted.', 'error');
        }
        MembershipMigrationService::setMemberDisabled($memberId, true, $user['id'] ?? null);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.migration_disabled');
        redirectWithFlash($memberId, $tab, 'Manual migration disabled for this member.');
        break;

    case 'enable_migration_link':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Manual migration links are restricted.', 'error');
        }
        MembershipMigrationService::setMemberDisabled($memberId, false, $user['id'] ?? null);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.migration_enabled');
        redirectWithFlash($memberId, $tab, 'Manual migration re-enabled for this member.');
        break;

    case 'membership_renewal_update':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Renewal updates are restricted.', 'error');
        }
        $pdo = Database::connection();
        $renewalDate = trim($_POST['renewal_date'] ?? '');
        $normalizedRenewal = null;
        if ($renewalDate !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d', $renewalDate);
            if ($parsed) {
                $normalizedRenewal = $parsed->format('Y-m-d');
            }
        }
        if (strtoupper((string) ($member['member_type'] ?? '')) === 'LIFE') {
            $normalizedRenewal = null;
        }
        $stmt = $pdo->prepare('SELECT id, end_date FROM membership_periods WHERE member_id = :member_id ORDER BY start_date DESC, id DESC LIMIT 1');
        $stmt->execute(['member_id' => $memberId]);
        $period = $stmt->fetch();
        if (!$period) {
            redirectWithFlash($memberId, $tab, 'No membership period found.', 'error');
        }
        try {
            $changes = MembershipStatusService::applyAdminUpdate($memberId, ['end_date' => $normalizedRenewal]);
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.renewal_updated', [
                'from' => $period['end_date'] ?? null,
                'to' => $normalizedRenewal,
                'period_status_from' => $changes['period_status']['from'] ?? null,
                'period_status_to' => $changes['period_status']['to'] ?? null,
            ]);
            redirectWithFlash($memberId, $tab, 'Renewal date updated.');
        } catch (Throwable $e) {
            redirectWithFlash($memberId, $tab, 'Error updating renewal date: ' . $e->getMessage(), 'error');
        }
        break;

    case 'member_join_date_update':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Join date edits are restricted.', 'error');
        }
        $pdo = Database::connection();
        $joinDateRaw = trim($_POST['join_date'] ?? '');
        $normalizedJoin = null;
        if ($joinDateRaw !== '') {
            $parsedJoin = DateTime::createFromFormat('Y-m-d', $joinDateRaw);
            if (!$parsedJoin || $parsedJoin->format('Y-m-d') !== $joinDateRaw) {
                redirectWithFlash($memberId, $tab, 'Enter a valid join date (YYYY-MM-DD).', 'error');
            }
            $normalizedJoin = $parsedJoin->format('Y-m-d');
        }
        $oldJoin = $member['join_date'] ?? null;
        $stmt = $pdo->prepare('UPDATE members SET join_date = :join_date, updated_at = NOW() WHERE id = :member_id');
        $stmt->execute(['join_date' => $normalizedJoin, 'member_id' => $memberId]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.join_date_updated', [
            'from' => $oldJoin,
            'to' => $normalizedJoin,
        ]);
        redirectWithFlash($memberId, $tab, 'Join date updated.');
        break;

    case 'manual_membership_order':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Manual membership orders are restricted.', 'error');
        }
        $pdo = Database::connection();
        $membershipResult = createMembershipForMember($pdo, $memberId, [
            'membership_type_id' => $_POST['manual_membership_type_id'] ?? 0,
            'cost' => $_POST['manual_membership_cost'] ?? 0,
            'payment_method' => $_POST['manual_payment_method'] ?? 'Manual',
            'membership_status' => $_POST['manual_membership_status'] ?? 'active',
            'reference' => $_POST['manual_order_reference'] ?? '',
            'start_date' => $_POST['manual_start_date'] ?? '',
            'renewal_date' => $_POST['manual_renewal_date'] ?? '',
        ], $user);
        if (!$membershipResult['success']) {
            redirectWithFlash($memberId, $tab, $membershipResult['error'] ?? 'Unable to create membership order.', 'error');
        }

        $endDate = $membershipResult['end_date'];
        $orderNumber = $membershipResult['order_number'];
        $membershipStatus = $membershipResult['membership_status'];
        if ($membershipStatus === 'active' || $membershipStatus === 'complimentary') {
            NotificationService::dispatch('membership_activated_confirmation', [
                'primary_email' => $member['email'] ?? '',
                'admin_emails' => NotificationService::getAdminEmails(),
                'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                'membership_type' => $membershipResult['membership_type_name'],
                'renewal_date' => $endDate ?: 'N/A',
            ]);
        } elseif ($membershipStatus === 'pending' && !empty($member['email'])) {
            $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
            $bankInstructions = (string) SettingsService::getGlobal('payments.bank_transfer_instructions', '');
            NotificationService::dispatch('membership_order_created', [
                'primary_email' => $member['email'],
                'admin_emails' => NotificationService::getAdminEmails(),
                'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                'order_number' => $orderNumber !== '' ? $orderNumber : '',
                'payment_link' => NotificationService::escape($paymentLink),
                'payment_method' => $membershipResult['payment_method'],
                'bank_transfer_instructions' => NotificationService::escape($bankInstructions),
            ]);
        }

        redirectWithFlash($memberId, $tab, 'Manual membership order saved.');
        break;

    case 'membership_order_accept':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Order approvals are restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND member_id = :member_id AND order_type = "membership" LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $paymentReference = trim($_POST['payment_reference'] ?? '');
        $activated = MembershipOrderService::activateMembershipForOrder($order, [
            'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            'period_id' => $order['membership_period_id'] ?? null,
        ]);
        if (!$activated) {
            redirectWithFlash($memberId, $tab, 'Unable to activate membership for this order.', 'error');
        }
        if ($paymentReference !== '') {
            // Native PDO prepares forbid binding the same placeholder twice,
            // which is why this used to throw HY093 "Invalid parameter number".
            // Pass the same note value into two distinct placeholders instead.
            $stmt = $pdo->prepare('UPDATE orders SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = "" THEN :note_when ELSE CONCAT(internal_notes, "\n", :note_else) END, updated_at = NOW() WHERE id = :id');
            $noteValue = 'Payment reference: ' . $paymentReference;
            $stmt->execute([
                'note_when' => $noteValue,
                'note_else' => $noteValue,
                'id' => $orderId,
            ]);
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_accepted', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        NotificationService::dispatch('membership_order_approved', [
            'primary_email' => $member['email'] ?? '',
            'admin_emails' => NotificationService::getAdminEmails(),
            'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
            'order_number' => $order['order_number'] ?? '',
        ]);
        redirectWithFlash($memberId, $tab, 'Membership order approved.');
        break;

    case 'membership_order_reject':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Order approvals are restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $reason = trim($_POST['reject_reason'] ?? '');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND member_id = :member_id AND order_type = "membership" LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        MembershipOrderService::markOrderRejected($orderId, $reason !== '' ? $reason : null);
        if (!empty($order['membership_period_id'])) {
            $stmt = $pdo->prepare('UPDATE membership_periods SET status = "LAPSED" WHERE id = :id');
            $stmt->execute(['id' => (int) $order['membership_period_id']]);
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_rejected', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'reason' => $reason !== '' ? $reason : null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        NotificationService::dispatch('membership_order_rejected', [
            'primary_email' => $member['email'] ?? '',
            'admin_emails' => NotificationService::getAdminEmails(),
            'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
            'order_number' => $order['order_number'] ?? '',
            'rejection_reason' => $reason !== '' ? $reason : 'No reason provided.',
        ]);
        redirectWithFlash($memberId, $tab, 'Membership order rejected.');
        break;

    case 'membership_order_send_link':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Order actions are restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND member_id = :member_id AND order_type = "membership" LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
        $itemsStmt->execute(['order_id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $lineItems = [];
        foreach ($items as $item) {
            $lineItems[] = [
                'name' => $item['name'],
                'unit_amount' => (int) round(((float) $item['unit_price']) * 100),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'currency' => strtolower((string) ($order['currency'] ?? 'aud')),
            ];
        }
        if (!$lineItems) {
            $lineItems[] = [
                'name' => 'Membership',
                'unit_amount' => (int) round(((float) ($order['total'] ?? 0)) * 100),
                'quantity' => 1,
                'currency' => strtolower((string) ($order['currency'] ?? 'aud')),
            ];
        }
        // Land on the dashboard with ?renewed=1 (thank-you lightbox + confetti).
        $successUrl = BaseUrlService::buildUrl('/member/?renewed=1');
        $cancelUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&cancel=1');
        $metadata = [
            'order_id' => (string) $orderId,
            'order_type' => 'membership',
            'member_id' => (string) $memberId,
            'period_id' => (string) ($order['membership_period_id'] ?? ''),
            'channel_id' => (string) ($order['channel_id'] ?? ''),
        ];
        $session = StripeService::createCheckoutSessionWithLineItems($lineItems, $member['email'] ?? '', $successUrl, $cancelUrl, $metadata);
        if (!$session || empty($session['id'])) {
            redirectWithFlash($memberId, $tab, 'Unable to create a checkout link.', 'error');
        }
        $stmt = $pdo->prepare('UPDATE orders SET stripe_session_id = :session_id, payment_method = "stripe", payment_status = "pending", status = "pending", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['session_id' => $session['id'], 'id' => $orderId]);
        NotificationService::dispatch('membership_order_created', [
            'primary_email' => $member['email'] ?? '',
            'admin_emails' => NotificationService::getAdminEmails(),
            'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
            'order_number' => $order['order_number'] ?? '',
            'payment_link' => NotificationService::escape($session['url'] ?? ''),
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_payment_link_sent', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Checkout link sent.');
        break;

    case 'membership_payment_request':
        // Create a standalone charge order for any amount and send the member
        // a Stripe checkout link — used for top-up payments, corrections, etc.
        // Does NOT create a new membership period.
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Payment requests are restricted.', 'error');
        }
        $pdo = Database::connection();
        $reqAmountRaw = trim($_POST['req_amount'] ?? '');
        $reqDesc = trim($_POST['req_description'] ?? '');
        $reqAmount = $reqAmountRaw !== '' ? (float) $reqAmountRaw : 0.0;
        if ($reqAmount <= 0) {
            redirectWithFlash($memberId, $tab, 'Enter an amount greater than $0.00.', 'error');
        }
        if ($reqDesc === '') {
            $reqDesc = 'Membership payment';
        }
        $pricingCurrency = \App\Services\MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD';
        // Create order with no linked period (period_id = 0 → stored as NULL).
        $order = \App\Services\MembershipOrderService::createMembershipOrder($memberId, 0, $reqAmount, [
            'payment_method' => 'stripe',
            'payment_status' => 'pending',
            'fulfillment_status' => 'pending',
            'currency' => $pricingCurrency,
            'item_name' => $reqDesc,
            'admin_notes' => 'Admin payment request',
            'actor_user_id' => $user['id'] ?? null,
        ]);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Unable to create the payment order.', 'error');
        }
        $orderId = (int) ($order['id'] ?? 0);
        $amountCents = (int) round($reqAmount * 100);
        $lineItems = [[
            'name' => $reqDesc,
            'unit_amount' => $amountCents,
            'quantity' => 1,
            'currency' => strtolower($pricingCurrency),
        ]];
        $successUrl = \App\Services\BaseUrlService::buildUrl('/member/?renewed=1');
        $cancelUrl  = \App\Services\BaseUrlService::buildUrl('/member/index.php?page=billing&cancel=1');
        $metadata = [
            'order_id'   => (string) $orderId,
            'order_type' => 'membership',
            'member_id'  => (string) $memberId,
        ];
        $session = \App\Services\StripeService::createCheckoutSessionWithLineItems($lineItems, $member['email'] ?? '', $successUrl, $cancelUrl, $metadata);
        if (!$session || empty($session['id'])) {
            redirectWithFlash($memberId, $tab, 'Unable to create a Stripe checkout link.', 'error');
        }
        $stmt = $pdo->prepare('UPDATE orders SET stripe_session_id = :session_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['session_id' => $session['id'], 'id' => $orderId]);
        NotificationService::dispatch('membership_order_created', [
            'primary_email' => $member['email'] ?? '',
            'admin_emails'  => NotificationService::getAdminEmails(),
            'member_name'   => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
            'order_number'  => $order['order_number'] ?? '',
            'payment_link'  => NotificationService::escape($session['url'] ?? ''),
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.payment_request_sent', [
            'order_id'    => $orderId,
            'amount'      => $reqAmount,
            'description' => $reqDesc,
        ]);
        redirectWithFlash($memberId, $tab, 'Payment link sent to ' . ($member['email'] ?? 'member') . '. Order #' . ($order['order_number'] ?? '') . '.');
        break;

    case 'membership_order_note':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Order notes are restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($orderId <= 0 || $note === '') {
            redirectWithFlash($memberId, $tab, 'Order note requires text and an order.', 'error');
        }
        $pdo = Database::connection();
        // Native PDO prepares forbid binding the same placeholder twice
        // (error HY093). Use two distinct placeholders for the same value.
        $stmt = $pdo->prepare('UPDATE orders SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = "" THEN :note_when ELSE CONCAT(internal_notes, "\n", :note_else) END, updated_at = NOW() WHERE id = :id AND member_id = :member_id');
        $stmt->execute([
            'note_when' => $note,
            'note_else' => $note,
            'id' => $orderId,
            'member_id' => $memberId,
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_note_added', [
            'order_id' => $orderId,
            'note' => $note,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Order note saved.');
        break;

    case 'membership_order_void':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Voiding orders is restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $reason = trim((string) ($_POST['void_reason'] ?? ''));
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, order_number FROM orders WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        OrderAdminService::voidMembershipOrder($orderId, (int) ($user['id'] ?? 0), $reason !== '' ? $reason : null);
        OrderAdminService::sendOrderVoidedNotification($orderId, $reason !== '' ? $reason : null);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_voided', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'reason' => $reason !== '' ? $reason : null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Order voided.');
        break;

    case 'membership_order_unvoid':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Restoring orders is restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, order_number FROM orders WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        OrderAdminService::unvoidMembershipOrder($orderId);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_unvoided', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Order restored.');
        break;

    case 'membership_order_delete':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Deleting orders is restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $confirm = strtoupper(trim((string) ($_POST['delete_confirm'] ?? '')));
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        if ($confirm !== 'DELETE') {
            redirectWithFlash($memberId, $tab, 'Type DELETE to confirm permanent removal.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, order_number FROM orders WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        OrderAdminService::deleteMembershipOrder($orderId);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.order_deleted', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Order permanently deleted.');
        break;

    case 'membership_order_refund':
        if (!AdminMemberAccess::canRefund($user)) {
            redirectWithFlash($memberId, $tab, 'Refunds are restricted for your role.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $amountRaw = trim((string) ($_POST['refund_amount'] ?? ''));
        $reason = trim((string) ($_POST['refund_reason'] ?? ''));
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        if ($reason === '') {
            redirectWithFlash($memberId, $tab, 'Refund reason is required.', 'error');
        }
        // Resolve refund amount: blank means "refund whatever's left".
        // RefundService will catch over-refund attempts.
        if ($amountRaw === '') {
            $pdo = Database::connection();
            $totalStmt = $pdo->prepare('SELECT total FROM orders WHERE id = :id LIMIT 1');
            $totalStmt->execute(['id' => $orderId]);
            $totalCents = (int) round(((float) ($totalStmt->fetchColumn() ?: 0)) * 100);
            $alreadyCents = RefundService::getMembershipRefundedCents($orderId);
            $amountCents = max(0, $totalCents - $alreadyCents);
        } else {
            $amountCents = (int) round(((float) $amountRaw) * 100);
        }
        if ($amountCents <= 0) {
            redirectWithFlash($memberId, $tab, 'Refund amount must be greater than zero.', 'error');
        }
        // Membership refund path — operates on the unified `orders` table,
        // writes to the membership `refunds` table, issues the Stripe refund.
        $terminatePeriod = !empty($_POST['terminate_period']);
        try {
            RefundService::processMembershipRefund(
                $orderId,
                $amountCents,
                $reason,
                (int) ($user['id'] ?? 0)
            );

            // Optionally terminate the linked membership_period RIGHT NOW
            // regardless of partial/full refund. RefundService::processMembershipRefund
            // already auto-lapses on a FULL refund; the lightbox checkbox lets
            // the admin force termination for any refund size ("cancel &
            // refund pro-rata" UX). Uses the shared helper so all three paths
            // — full-refund auto-lapse, partial-refund + checkbox, and any
            // future paths — produce the same result.
            if ($terminatePeriod) {
                $pdo = Database::connection();
                $stmt = $pdo->prepare('SELECT id, member_id, membership_period_id FROM orders WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $orderId]);
                $orderRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $orderMemberId = (int) ($orderRow['member_id'] ?? 0);

                RefundService::terminateMembershipForOrder($pdo, $orderRow);

                ActivityLogger::log('admin', $user['id'] ?? null, $orderMemberId ?: $memberId, 'membership.terminated_on_refund', [
                    'order_id' => $orderId,
                    'period_id' => (int) ($orderRow['membership_period_id'] ?? 0),
                    'refund_amount_cents' => $amountCents,
                ]);
            }

            // Land back on the standalone order page rather than the member's
            // orders tab so the admin sees the freshly-updated state.
            $flashMessage = $terminatePeriod
                ? 'Refund processed and membership terminated.'
                : 'Refund processed.';
            $_SESSION['members_flash'] = ['type' => 'success', 'message' => $flashMessage];
            header('Location: /admin/membership-orders/view.php?id=' . $orderId);
            exit;
        } catch (\RuntimeException $e) {
            $_SESSION['members_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
            header('Location: /admin/membership-orders/view.php?id=' . $orderId);
            exit;
        }
        break;

    case 'store_order_void':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Voiding orders is restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $reason = trim((string) ($_POST['void_reason'] ?? ''));
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, order_number FROM store_orders WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Store order not found.', 'error');
        }
        OrderAdminService::voidStoreOrder($orderId, (int) ($user['id'] ?? 0), $reason !== '' ? $reason : null);
        OrderAdminService::sendStoreOrderVoidedNotification($orderId, $reason !== '' ? $reason : null);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'store.order_voided', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'reason' => $reason !== '' ? $reason : null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Store order voided.');
        break;

    case 'store_order_unvoid':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Restoring orders is restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, order_number FROM store_orders WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Store order not found.', 'error');
        }
        OrderAdminService::unvoidStoreOrder($orderId, (int) ($user['id'] ?? 0));
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'store.order_unvoided', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Store order restored.');
        break;

    case 'store_order_delete':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Deleting orders is restricted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $confirm = strtoupper(trim((string) ($_POST['delete_confirm'] ?? '')));
        if ($orderId <= 0) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        if ($confirm !== 'DELETE') {
            redirectWithFlash($memberId, $tab, 'Type DELETE to confirm permanent removal.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, order_number FROM store_orders WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $orderId, 'member_id' => $memberId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Store order not found.', 'error');
        }
        OrderAdminService::deleteStoreOrder($orderId);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'store.order_deleted', [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? null,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, $tab, 'Store order permanently deleted.');
        break;

    case 'roles_update':
        if (!current_admin_can('admin.roles.manage', $user)) {
            redirectWithFlash($memberId, 'roles', 'Role updates restricted.', 'error');
        }
        $userId = $member['user_id'] ?? null;
        if (!$userId) {
            redirectWithFlash($memberId, 'roles', 'Member does not have a linked user account.', 'error');
        }
        $requestedSystem = isset($_POST['roles_system_submitted']) ? array_filter(array_map('trim', (array) ($_POST['roles_system'] ?? []))) : [];
        $requestedAdmin = isset($_POST['roles_admin_submitted']) ? array_filter(array_map('trim', (array) ($_POST['roles_admin'] ?? []))) : [];
        $requested = array_values(array_unique(array_merge($requestedSystem, $requestedAdmin)));
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name FROM roles');
        $stmt->execute();
        $availableRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $roleMap = [];
        foreach ($availableRoles as $row) {
            $roleMap[$row['name']] = (int) $row['id'];
        }
        $allowed = array_values(array_intersect($requested, array_keys($roleMap)));
        $currentRoles = array_filter(array_map('trim', explode(',', $member['user_roles_csv'] ?? '')));
        $toAdd = array_values(array_diff($allowed, $currentRoles));
        $toRemove = array_values(array_diff($currentRoles, $allowed));
        foreach ($toAdd as $roleName) {
            $roleId = $roleMap[$roleName] ?? null;
            if ($roleId === null) {
                continue;
            }
            $stmt = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            $stmt->execute(['user_id' => $userId, 'role_id' => $roleId]);
        }
        foreach ($toRemove as $roleName) {
            $roleId = $roleMap[$roleName] ?? null;
            if ($roleId === null) {
                continue;
            }
            $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id');
            $stmt->execute(['user_id' => $userId, 'role_id' => $roleId]);
        }
        if ($toAdd) {
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'role.assigned', [
                'roles' => $toAdd,
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        if ($toRemove) {
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'role.removed', [
                'roles' => $toRemove,
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        redirectWithFlash($memberId, 'roles', 'Roles updated.');
        break;

    case 'member_avatar_update':
        // Avatar-only updater that works whether or not the member has a
        // linked user account. Avatar is stored on members.avatar_url; the
        // display layer falls back to settings_user.avatar_url for older
        // records.
        if (!AdminMemberAccess::isFullAccess($user)) {
            redirectWithFlash($memberId, $tab, 'Photo updates are restricted.', 'error');
        }
        $avatarUrl = trim($_POST['avatar_url'] ?? '');
        if ($avatarUrl !== '' && !preg_match('#^(https?:)?/#i', $avatarUrl)) {
            redirectWithFlash($memberId, $tab, 'Photo URL looks invalid.', 'error');
        }
        $pdoAvatar = Database::connection();
        $hasMemberAvatar = false;
        try {
            $hasMemberAvatar = (bool) $pdoAvatar->query("SHOW COLUMNS FROM members LIKE 'avatar_url'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasMemberAvatar = false;
        }
        $currentMemberAvatar = $hasMemberAvatar ? ($member['avatar_url'] ?? '') : '';
        $linkedUserId = (int) ($member['user_id'] ?? 0);
        $currentUserAvatar = $linkedUserId > 0 ? (string) SettingsService::getUser($linkedUserId, 'avatar_url', '') : '';
        $valueToStore = $avatarUrl === '' ? null : $avatarUrl;
        if ($hasMemberAvatar) {
            $stmt = $pdoAvatar->prepare('UPDATE members SET avatar_url = :u, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['u' => $valueToStore, 'id' => $memberId]);
        }
        if ($linkedUserId > 0) {
            // Keep settings_user in sync so existing display fallbacks see the same value.
            SettingsService::setUser($linkedUserId, 'avatar_url', $avatarUrl);
        }
        $previous = $currentMemberAvatar !== '' ? $currentMemberAvatar : $currentUserAvatar;
        if ($previous !== $avatarUrl) {
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'settings.avatar_updated', [
                'before' => $previous,
                'after' => $avatarUrl,
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        redirectWithFlash($memberId, $tab, 'Profile photo saved.');
        break;

    case 'member_settings_update':
        if (!AdminMemberAccess::isFullAccess($user)) {
            redirectWithFlash($memberId, $tab, 'Settings updates are restricted.', 'error');
        }
        $userId = $member['user_id'] ?? null;
        if (!$userId) {
            redirectWithFlash($memberId, $tab, 'Member does not have a linked user account.', 'error');
        }
        $timezone = trim($_POST['user_timezone'] ?? '');
        $avatarUrl = trim($_POST['avatar_url'] ?? '');
        $categoryPrefs = [];
        foreach (NotificationPreferenceService::categories() as $categoryKey => $categoryLabel) {
            $categoryPrefs[$categoryKey] = isset($_POST['notify_category'][$categoryKey]);
        }
        $preferences = [
            'master_enabled' => isset($_POST['notify_master_enabled']),
            'unsubscribe_all_non_essential' => isset($_POST['notify_unsubscribe_all']),
            'categories' => $categoryPrefs,
        ];
        $defaultTimezone = SettingsService::getGlobal('site.timezone', 'Australia/Sydney');
        $currentTimezone = SettingsService::getUser($userId, 'timezone', $defaultTimezone);
        $currentAvatar = SettingsService::getUser($userId, 'avatar_url', '');
        $currentPrefs = NotificationPreferenceService::load($userId);
        $normalizedTimezone = $timezone !== '' ? $timezone : $defaultTimezone;
        SettingsService::setUser($userId, 'timezone', $normalizedTimezone);
        SettingsService::setUser($userId, 'avatar_url', $avatarUrl);
        // Mirror avatar onto members.avatar_url so it shows for legacy displays
        // that don't have a user_id to join through. Best-effort — if the
        // column doesn't exist on this DB yet, just skip.
        try {
            $syncPdo = Database::connection();
            if ($syncPdo->query("SHOW COLUMNS FROM members LIKE 'avatar_url'")->fetchColumn()) {
                $syncStmt = $syncPdo->prepare('UPDATE members SET avatar_url = :u, updated_at = NOW() WHERE id = :id');
                $syncStmt->execute(['u' => $avatarUrl === '' ? null : $avatarUrl, 'id' => $memberId]);
            }
        } catch (\Throwable $e) {
            // Silent — avatar still saved to settings_user.
        }
        NotificationPreferenceService::save($userId, $preferences);
        $newPreferences = NotificationPreferenceService::load($userId);
        $changes = [];
        if ($currentTimezone !== $normalizedTimezone) {
            $changes['timezone'] = ['before' => $currentTimezone, 'after' => $normalizedTimezone];
        }
        if ($currentAvatar !== $avatarUrl) {
            $changes['avatar_url'] = ['before' => $currentAvatar, 'after' => $avatarUrl];
        }
        if ($currentPrefs !== $newPreferences) {
            $changes['notification_preferences'] = ['before' => $currentPrefs, 'after' => $newPreferences];
        }
        if ($changes) {
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'settings.updated', [
                'changes' => $changes,
                'actor_roles' => $user['roles'] ?? [],
            ]);
        }
        redirectWithFlash($memberId, $tab, 'Member settings saved.');
        break;

    case 'twofa_toggle':
        if (!current_admin_can('admin.users.edit', $user)) {
            redirectWithFlash($memberId, 'roles', '2FA overrides are restricted.', 'error');
        }
        $userId = $member['user_id'] ?? null;
        if (!$userId) {
            redirectWithFlash($memberId, 'roles', 'User account missing.', 'error');
        }
        $desired = trim((string) ($_POST['twofa_required'] ?? '0'));
        $override = $desired === '1' ? 'REQUIRED' : 'DEFAULT';
        $beforeOverride = SecurityPolicyService::getTwoFaOverride((int) $userId);
        SecurityPolicyService::setTwoFaOverride((int) $userId, $override);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.2fa_requirement_updated', [
            'from' => $beforeOverride,
            'to' => $override,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        redirectWithFlash($memberId, 'roles', '2FA requirement updated.');
        break;

    case 'manual_order_fix':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Manual order fixes not permitted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = OrderRepository::getById($orderId);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        if (!empty($order['member_id']) && (int) $order['member_id'] !== $memberId) {
            redirectWithFlash($memberId, $tab, 'Order belongs to another member.', 'error');
        }
        $metadata = ['order_id' => $orderId];
        $status = $_POST['status'] ?? '';
        if ($status) {
            OrderRepository::updateStatus($orderId, $status);
            $metadata['status'] = $status;
        }
        $note = trim($_POST['order_note'] ?? '');
        if ($note !== '') {
            OrderRepository::appendAdminNote($orderId, $note);
            $metadata['note'] = $note;
        }
        if (!empty($_POST['attach_to_member'])) {
            OrderRepository::attachToMember($orderId, $memberId);
            $metadata['attached'] = true;
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'order.updated', $metadata);
        redirectWithFlash($memberId, $tab, 'Order changes applied.');
        break;

    case 'order_resync':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Manual order fixes not permitted.', 'error');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order = OrderRepository::getById($orderId);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Order not found.', 'error');
        }
        if (!empty($order['member_id']) && (int) $order['member_id'] !== $memberId) {
            redirectWithFlash($memberId, $tab, 'Order belongs to another member.', 'error');
        }
        $intent = OrderRepository::refreshFromStripe($orderId);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'order.updated', ['order_id' => $orderId, 'action' => 'resync']);
        if ($intent) {
            redirectWithFlash($memberId, $tab, 'Order resynchronized with Stripe.');
        }
        redirectWithFlash($memberId, $tab, 'Stripe intent unavailable; nothing to resync.', 'error');
        break;

    case 'refund_submit':
        if (!AdminMemberAccess::canRefund($user)) {
            redirectWithFlash($memberId, $tab, 'Refunds are restricted for your role.', 'error');
        }
        $orderId = (int) ($_POST['refund_order_id'] ?? 0);
        $amountValue = (float) ($_POST['refund_amount'] ?? 0);
        $reason = trim($_POST['refund_reason'] ?? '');
        if (!$orderId || $amountValue <= 0 || $reason === '') {
            redirectWithFlash($memberId, $tab, 'Provide an order, amount, and reason.', 'error');
        }
        $amountCents = (int) round($amountValue * 100);
        try {
            RefundService::processRefund($orderId, $memberId, $amountCents, $reason, $user['id'] ?? null);
            redirectWithFlash($memberId, $tab, 'Refund processed.');
        } catch (\RuntimeException $e) {
            redirectWithFlash($memberId, $tab, $e->getMessage(), 'error');
        }
        break;

    case 'twofa_force':
        if (!current_admin_can('admin.users.edit', $user)) {
            redirectWithFlash($memberId, $tab, '2FA updates are restricted.', 'error');
        }
        if (empty($member['user_id'])) {
            redirectWithFlash($memberId, $tab, 'User account missing.', 'error');
        }
        SecurityPolicyService::setTwoFaOverride((int) $member['user_id'], 'REQUIRED');
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.2fa_override_required', ['user_id' => $member['user_id']]);
        redirectWithFlash($memberId, $tab, '2FA enrollment required for this user.');
        break;

    case 'twofa_exempt':
        if (!current_admin_can('admin.users.edit', $user)) {
            redirectWithFlash($memberId, $tab, '2FA updates are restricted.', 'error');
        }
        if (empty($member['user_id'])) {
            redirectWithFlash($memberId, $tab, 'User account missing.', 'error');
        }
        SecurityPolicyService::setTwoFaOverride((int) $member['user_id'], 'EXEMPT');
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.2fa_override_exempt', ['user_id' => $member['user_id']]);
        redirectWithFlash($memberId, $tab, 'User exempted from 2FA.');
        break;

    case 'twofa_reset':
        if (!current_admin_can('admin.users.edit', $user)) {
            redirectWithFlash($memberId, $tab, '2FA updates are restricted.', 'error');
        }
        if (empty($member['user_id'])) {
            redirectWithFlash($memberId, $tab, 'User account missing.', 'error');
        }
        TwoFactorService::reset((int) $member['user_id']);
        SecurityPolicyService::setTwoFaOverride((int) $member['user_id'], 'REQUIRED');
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.2fa_reset', ['user_id' => $member['user_id']]);
        redirectWithFlash($memberId, $tab, '2FA reset. User must re-enroll.');
        break;

    case 'send_welcome_email':
        $flashContext = ['flash_context' => 'account_access'];
        if (!AdminMemberAccess::canResetPassword($user)) {
            redirectWithFlash($memberId, $tab, 'Password reset links are restricted.', 'error', $flashContext);
        }
        $pdo = Database::connection();
        $welcomeResult = sendWelcomeEmailForMember($pdo, $member, $user);
        if (!$welcomeResult['success']) {
            if (($welcomeResult['error'] ?? '') === 'Welcome email could not be sent. Check email settings.') {
                LogViewerService::write('[Admin] Welcome email not sent for member #' . $memberId . '.');
                error_log('[Admin] Welcome email not sent for member #' . $memberId . '.');
            }
            redirectWithFlash($memberId, $tab, $welcomeResult['error'] ?? 'Welcome email could not be sent.', 'error', $flashContext);
        }
        LogViewerService::write('[Admin] Welcome email sent for member #' . $memberId . '.');
        redirectWithFlash($memberId, $tab, 'Welcome email sent.', 'success', $flashContext);
        break;

    case 'send_reset_link':
        $flashContext = ['flash_context' => 'account_access'];
        if (!AdminMemberAccess::canResetPassword($user)) {
            redirectWithFlash($memberId, $tab, 'Password reset links are restricted.', 'error', $flashContext);
        }
        $pdo = Database::connection();
        $userId = $member['user_id'] ?? null;
        if (!$userId || empty($member['email'])) {
            redirectWithFlash($memberId, $tab, 'Member is not linked to a user account.', 'error', $flashContext);
        }
        $approved = isMembershipApplicationApproved($pdo, $memberId);
        if ($approved === false) {
            redirectWithFlash($memberId, $tab, 'Password reset links are available after application approval.', 'error', $flashContext);
        }
        $rateLimitDisabled = SettingsService::getGlobal('advanced.disable_password_reset_rate_limit', false);
        if (!$rateLimitDisabled) {
            $window = (new DateTimeImmutable('now'))->modify('-60 minutes')->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'member.password_reset_link_sent' AND actor_type = 'admin' AND actor_id = :actor_id AND created_at >= :window");
            $stmt->execute(['actor_id' => $user['id'] ?? null, 'window' => $window]);
            if ((int) $stmt->fetchColumn() >= 3) {
                redirectWithFlash($memberId, $tab, 'Rate limit reached for password reset links.', 'error', $flashContext);
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'member.password_reset_link_sent' AND member_id = :member_id AND created_at >= :window");
            $stmt->execute(['member_id' => $memberId, 'window' => $window]);
            if ((int) $stmt->fetchColumn() >= 3) {
                redirectWithFlash($memberId, $tab, 'Member has reached the reset limit.', 'error', $flashContext);
            }
        }
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, ip_address) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), :ip)');
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $link = BaseUrlService::emailLink('/member/reset_password_confirm.php?token=' . urlencode($token));
        $sent = NotificationService::dispatch('member_password_reset_admin', [
            'primary_email' => $member['email'],
            'admin_emails' => NotificationService::getAdminEmails(),
            'reset_link' => NotificationService::escape($link),
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.password_reset_link_sent', ['user_id' => $userId]);
        if (!$sent) {
            LogViewerService::write('[Admin] Password reset email not sent for member #' . $memberId . '.');
            error_log('[Admin] Password reset email not sent for member #' . $memberId . '.');
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.password_reset_email_failed', ['user_id' => $userId]);
            redirectWithFlash($memberId, $tab, 'Password reset link could not be emailed. Check email settings.', 'error', $flashContext);
        }
        LogViewerService::write('[Admin] Password reset email sent for member #' . $memberId . '.');
        redirectWithFlash($memberId, $tab, 'Password reset email sent.', 'success', $flashContext);
        break;

    case 'set_password':
        $flashContext = ['flash_context' => 'account_access'];
        if (!AdminMemberAccess::canSetPassword($user)) {
            redirectWithFlash($memberId, $tab, 'Password changes are restricted.', 'error', $flashContext);
        }
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['new_password_confirm'] ?? '');
        if ($newPassword === '' || $confirm === '') {
            redirectWithFlash($memberId, $tab, 'Provide and confirm a new password.', 'error', $flashContext);
        }
        if ($newPassword !== $confirm) {
            redirectWithFlash($memberId, $tab, 'Passwords do not match.', 'error', $flashContext);
        }
        $policyErrors = PasswordPolicyService::validate($newPassword);
        if ($policyErrors) {
            redirectWithFlash($memberId, $tab, $policyErrors[0], 'error', $flashContext);
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo = Database::connection();
        $targetUserId = null;
        $userId = $member['user_id'] ?? null;
        if ($userId) {
            $stmt = $pdo->prepare('SELECT id, member_id, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $userRow = $stmt->fetch();
            if ($userRow) {
                $emailMatches = !empty($member['email']) && strcasecmp((string) $userRow['email'], (string) $member['email']) === 0;
                $memberMatches = !empty($userRow['member_id']) && (int) $userRow['member_id'] === $memberId;
                if ($emailMatches || $memberMatches) {
                    $targetUserId = (int) $userRow['id'];
                }
            }
        }
        if (!$targetUserId) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE member_id = :member_id LIMIT 1');
            $stmt->execute(['member_id' => $memberId]);
            $targetUserId = (int) $stmt->fetchColumn();
        }
        if (!$targetUserId && !empty($member['email'])) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $member['email']]);
            $targetUserId = (int) $stmt->fetchColumn();
        }
        if ($targetUserId <= 0) {
            redirectWithFlash($memberId, $tab, 'User account missing; cannot set password.', 'error', $flashContext);
        }
        try {
            $pdo->beginTransaction();
            if ((int) ($member['user_id'] ?? 0) !== $targetUserId) {
                $stmt = $pdo->prepare('UPDATE members SET user_id = :user_id WHERE id = :member_id');
                if (!$stmt->execute(['user_id' => $targetUserId, 'member_id' => $memberId])) {
                    throw new \RuntimeException('Unable to link user account.');
                }
            }
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
            if (!$stmt->execute(['hash' => $hash, 'id' => $targetUserId])) {
                throw new \RuntimeException('Unable to update user password.');
            }
            if (!empty($member['email'])) {
                $stmt = $pdo->prepare('UPDATE users SET email = :new_email, updated_at = NOW() WHERE id = :id AND email != :current_email');
                $stmt->execute(['new_email' => $member['email'], 'current_email' => $member['email'], 'id' => $targetUserId]);
            }
            $stmt = $pdo->query("SHOW TABLES LIKE 'member_auth'");
            $hasMemberAuth = (bool) $stmt->fetchColumn();
            if ($hasMemberAuth) {
                $stmt = $pdo->prepare('INSERT INTO member_auth (member_id, password_hash) VALUES (:member_id, :hash_insert) ON DUPLICATE KEY UPDATE password_hash = :hash_update, password_reset_token = NULL, password_reset_expires_at = NULL');
                if (!$stmt->execute(['member_id' => $memberId, 'hash_insert' => $hash, 'hash_update' => $hash])) {
                    $errorInfo = $stmt->errorInfo();
                    error_log('[Admin] Member auth update failed for member #' . $memberId . ': ' . ($errorInfo[2] ?? 'Unknown SQL error'));
                }
            }
            $hasPasswordResets = (bool) $pdo->query("SHOW TABLES LIKE 'password_resets'")->fetchColumn();
            if ($hasPasswordResets) {
                $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
                if (!$stmt->execute(['user_id' => $targetUserId])) {
                    $errorInfo = $stmt->errorInfo();
                    error_log('[Admin] Password reset log update failed for user #' . $targetUserId . ': ' . ($errorInfo[2] ?? 'Unknown SQL error'));
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Admin] Password reset failed for member #' . $memberId . ': ' . $e->getMessage());
            redirectWithFlash($memberId, $tab, 'Unable to reset password. Please try again.', 'error', $flashContext);
        }
        try {
            $hasSessions = (bool) $pdo->query("SHOW TABLES LIKE 'sessions'")->fetchColumn();
            if ($hasSessions) {
                $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id');
                $stmt->execute(['user_id' => $targetUserId]);
            }
        } catch (Throwable $e) {
            // Ignore if sessions table is unavailable.
        }
        try {
            $hasStepup = (bool) $pdo->query("SHOW TABLES LIKE 'stepup_tokens'")->fetchColumn();
            if ($hasStepup) {
                $stmt = $pdo->prepare('DELETE FROM stepup_tokens WHERE user_id = :user_id');
                $stmt->execute(['user_id' => $targetUserId]);
            }
        } catch (Throwable $e) {
            // Ignore if step-up tokens table is unavailable.
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.password_set_by_admin', ['user_id' => $targetUserId]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'security.password_reset_admin', ['user_id' => $targetUserId]);
        redirectWithFlash($memberId, $tab, 'Password updated. Active sessions have been signed out.', 'success', $flashContext);
        break;

    case 'change_status':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Status updates restricted.', 'error');
        }
        $newStatus = $_POST['new_status'] ?? '';
        if (!in_array($newStatus, ['pending', 'active', 'expired', 'cancelled', 'suspended'], true)) {
            redirectWithFlash($memberId, $tab, 'Invalid status selected.', 'error');
        }
        if ($newStatus === $member['status']) {
            redirectWithFlash($memberId, $tab, 'Status is already ' . $newStatus . '.', 'error');
        }
        try {
            $changes = MembershipStatusService::applyAdminUpdate($memberId, ['status' => $newStatus]);
        } catch (Throwable $e) {
            redirectWithFlash($memberId, $tab, 'Could not save status: ' . $e->getMessage(), 'error');
        }
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.status_changed', [
            'from' => $member['status'],
            'to' => $newStatus,
            'period_status_from' => $changes['period_status']['from'] ?? null,
            'period_status_to' => $changes['period_status']['to'] ?? null,
        ]);
        redirectWithFlash($memberId, $tab, 'Status updated.');
        break;

    case 'member_number_update':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Member ID changes are restricted.', 'error');
        }
        $rawNumber = trim($_POST['member_number'] ?? '');
        $parsed = parseMemberNumberString($rawNumber);
        if (!$parsed) {
            redirectWithFlash($memberId, $tab, 'Enter a valid member ID.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM members WHERE member_number_base = :base AND member_number_suffix = :suffix AND id <> :id LIMIT 1');
        $stmt->execute([
            'base' => $parsed['base'],
            'suffix' => $parsed['suffix'],
            'id' => $memberId,
        ]);
        if ($stmt->fetch()) {
            redirectWithFlash($memberId, $tab, 'That member ID is already in use.', 'error');
        }
        $updates = 'member_number_base = :base, member_number_suffix = :suffix';
        $params = [
            'base' => $parsed['base'],
            'suffix' => $parsed['suffix'],
            'id' => $memberId,
        ];
        if (MemberRepository::hasMemberNumberColumn($pdo)) {
            $updates .= ', member_number = :member_number';
            $params['member_number'] = $parsed['display'];
        }
        $updates .= ', updated_at = NOW()';
        $stmt = $pdo->prepare('UPDATE members SET ' . $updates . ' WHERE id = :id');
        $stmt->execute($params);
        $beforeDisplay = $member['member_number_display'] ?? '';
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.number_updated', [
            'from' => $beforeDisplay,
            'to' => $parsed['display'],
        ]);
        redirectWithFlash($memberId, $tab, 'Member ID updated.');
        break;

    case 'member_archive':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Archiving members is restricted.', 'error');
        }
        if ($member['status'] !== 'cancelled') {
            try {
                MembershipStatusService::applyAdminUpdate($memberId, ['status' => 'cancelled']);
            } catch (Throwable $e) {
                redirectWithFlash($memberId, $tab, 'Archive failed: ' . $e->getMessage(), 'error');
            }
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.archived', [
                'from' => $member['status'],
                'to' => 'cancelled',
            ]);
        }
        redirectWithFlash($memberId, $tab, 'Member archived.');
        break;

    case 'member_delete':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            redirectWithFlash($memberId, $tab, 'Deleting members is restricted.', 'error');
        }
        $pdo = Database::connection();
        if (!deleteMemberPermanently($pdo, $user, $member)) {
            redirectWithFlash($memberId, $tab, 'Delete failed. Please try again or contact support.', 'error');
        }
        redirectToMembersList('Member deleted permanently.');
        break;

    case 'check_member_email':
        // Add-member wizard: is this email already on a member? If so, return
        // the owner so the wizard can offer "link as associate, share email".
        if (!AdminMemberAccess::isFullAccess($user)) {
            respondWithJson(['success' => false, 'error' => 'Permission denied.'], 403);
        }
        $checkEmail = trim((string) ($_POST['email'] ?? ''));
        if ($checkEmail === '' || !filter_var($checkEmail, FILTER_VALIDATE_EMAIL)) {
            respondWithJson(['success' => false, 'error' => 'Invalid email address.'], 400);
        }
        $stmt = Database::connection()->prepare('SELECT id, first_name, last_name, member_type, full_member_id, member_number_base, member_number_suffix FROM members WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email' => $checkEmail]);
        $emailOwner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emailOwner) {
            respondWithJson(['success' => true, 'available' => true]);
        }
        // Link target for the new associate: the owner themselves if they are
        // the household's full/life member, otherwise the full member the
        // owner is already linked to (0 = no auto-link possible).
        $linkMemberId = in_array($emailOwner['member_type'], ['FULL', 'LIFE'], true)
            ? (int) $emailOwner['id']
            : (int) ($emailOwner['full_member_id'] ?? 0);
        respondWithJson(['success' => true, 'available' => false, 'owner' => [
            'id' => (int) $emailOwner['id'],
            'name' => trim(($emailOwner['first_name'] ?? '') . ' ' . ($emailOwner['last_name'] ?? '')),
            'member_number' => MembershipService::displayMembershipNumber((int) ($emailOwner['member_number_base'] ?? 0), (int) ($emailOwner['member_number_suffix'] ?? 0)),
            'member_type' => (string) $emailOwner['member_type'],
            'link_member_id' => $linkMemberId,
        ]]);
        break;

    case 'associate_search':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            respondWithJson(['success' => false, 'error' => 'Permission denied.'], 403);
        }
        try {
            $searchTerm = trim($_POST['query'] ?? '');
            $term = '%' . mb_strtolower($searchTerm) . '%';
            $numberTerm = '%' . ($searchTerm === '' ? '' : str_replace(' ', '', $searchTerm)) . '%';
            $stmt = Database::connection()->prepare('SELECT id, first_name, last_name, email, member_number_base, member_number_suffix FROM members WHERE member_type = "ASSOCIATE" AND (full_member_id IS NULL OR full_member_id = 0 OR full_member_id <> :member_id) AND (LOWER(CONCAT(first_name, " ", last_name)) LIKE :term OR LOWER(email) LIKE :term_email OR COALESCE(CONCAT(member_number_base, CASE WHEN member_number_suffix > 0 THEN CONCAT(".", member_number_suffix) ELSE "" END), "") LIKE :number) ORDER BY last_name ASC, first_name ASC LIMIT 12');
            $stmt->execute([
                'member_id' => $memberId,
                'term' => $term,
                'term_email' => $term,
                'number' => $numberTerm,
            ]);
            $results = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $firstName = trim($row['first_name'] ?? '');
                $lastName = trim($row['last_name'] ?? '');
                if ($lastName !== '' && $firstName !== '') {
                    $displayName = $lastName . ', ' . $firstName;
                } elseif ($lastName !== '') {
                    $displayName = $lastName;
                } elseif ($firstName !== '') {
                    $displayName = $firstName;
                } else {
                    $displayName = $row['email'] ?? 'Associate member';
                }
                $results[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'display_name' => $displayName,
                    'member_number' => MembershipService::displayMembershipNumber((int) ($row['member_number_base'] ?? 0), (int) ($row['member_number_suffix'] ?? 0)),
                    'email' => $row['email'] ?? '',
                ];
            }
            respondWithJson(['success' => true, 'results' => $results]);
        } catch (\Throwable $e) {
            error_log('[Admin] Associate search failed for member #' . $memberId . ': ' . $e->getMessage());
            respondWithJson(['success' => false, 'error' => 'Unable to search associates.']);
        }
        break;

    case 'link_associate_member':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            respondWithJson(['success' => false, 'error' => 'Permission denied.'], 403);
        }
        $associateId = (int) ($_POST['associate_member_id'] ?? 0);
        if ($associateId <= 0) {
            respondWithJson(['success' => false, 'error' => 'Invalid associate selection.'], 400);
        }
        if ($associateId === $memberId) {
            respondWithJson(['success' => false, 'error' => 'Cannot link the same member.'], 400);
        }
        $associate = MemberRepository::findById($associateId);
        if (!$associate || ($associate['member_type'] ?? '') !== 'ASSOCIATE') {
            respondWithJson(['success' => false, 'error' => 'Member is not an associate.'], 400);
        }
        $existingLink = (int) ($associate['full_member_id'] ?? 0);
        if ($existingLink > 0 && $existingLink !== $memberId) {
            respondWithJson(['success' => false, 'error' => 'Associate is already linked to another member.'], 400);
        }
        if ($existingLink === $memberId) {
            respondWithJson(['success' => true, 'message' => 'Associate already linked.']);
        }
        if (!MemberRepository::update($associateId, ['full_member_id' => $memberId])) {
            respondWithJson(['success' => false, 'error' => 'Unable to link associate.']);
        }
        $associateName = trim(($associate['first_name'] ?? '') . ' ' . ($associate['last_name'] ?? ''));
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.associate_added', [
            'associate_id' => $associateId,
            'associate_name' => $associateName,
            'associate_member_number' => MembershipService::displayMembershipNumber((int) ($associate['member_number_base'] ?? 0), (int) ($associate['member_number_suffix'] ?? 0)),
            'actor_roles' => $user['roles'] ?? [],
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $associateId, 'member.linked_to_full_member', [
            'full_member_id' => $memberId,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        respondWithJson([
            'success' => true,
            'message' => 'Associate linked. Reloading…',
            'associate' => [
                'id' => $associateId,
                'name' => $associateName,
                'member_number' => MembershipService::displayMembershipNumber((int) ($associate['member_number_base'] ?? 0), (int) ($associate['member_number_suffix'] ?? 0)),
                'email' => $associate['email'] ?? '',
            ],
        ]);
        break;

    case 'unlink_associate_member':
        if (!AdminMemberAccess::canEditFullProfile($user)) {
            respondWithJson(['success' => false, 'error' => 'Permission denied.'], 403);
        }
        $associateId = (int) ($_POST['associate_member_id'] ?? 0);
        if ($associateId <= 0) {
            respondWithJson(['success' => false, 'error' => 'Invalid associate selection.'], 400);
        }
        $associate = MemberRepository::findById($associateId);
        if (!$associate || ($associate['member_type'] ?? '') !== 'ASSOCIATE') {
            respondWithJson(['success' => false, 'error' => 'Member is not an associate.'], 400);
        }
        if ((int) ($associate['full_member_id'] ?? 0) !== $memberId) {
            respondWithJson(['success' => false, 'error' => 'Associate is not linked to this member.'], 400);
        }
        if (!MemberRepository::update($associateId, ['full_member_id' => null])) {
            respondWithJson(['success' => false, 'error' => 'Unable to unlink associate.']);
        }
        $associateName = trim(($associate['first_name'] ?? '') . ' ' . ($associate['last_name'] ?? ''));
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.associate_removed', [
            'associate_id' => $associateId,
            'associate_name' => $associateName,
            'associate_member_number' => MembershipService::displayMembershipNumber((int) ($associate['member_number_base'] ?? 0), (int) ($associate['member_number_suffix'] ?? 0)),
            'actor_roles' => $user['roles'] ?? [],
        ]);
        ActivityLogger::log('admin', $user['id'] ?? null, $associateId, 'member.unlinked_from_full_member', [
            'full_member_id' => $memberId,
            'actor_roles' => $user['roles'] ?? [],
        ]);
        respondWithJson(['success' => true, 'message' => 'Associate unlinked. Reloading…']);
        break;

    case 'resend_notification':
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        if ($activityId <= 0) {
            redirectWithFlash($memberId, $tab, 'Missing email activity.', 'error');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM activity_log WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute([
            'id' => $activityId,
            'member_id' => $memberId,
        ]);
        $entry = $stmt->fetch();
        if (!$entry || ($entry['action'] ?? '') !== 'email.sent') {
            redirectWithFlash($memberId, $tab, 'Email activity not found.', 'error');
        }
        $metadata = [];
        if (!empty($entry['metadata'])) {
            $decoded = json_decode((string) $entry['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        $notificationKey = $metadata['notification_key'] ?? '';
        $context = $metadata['context'] ?? [];
        $blockedKeys = ['security_email_otp'];
        if ($notificationKey === '' || !is_array($context) || in_array($notificationKey, $blockedKeys, true)) {
            redirectWithFlash($memberId, $tab, 'This email cannot be resent.', 'error');
        }
        $context['member_id'] = $memberId;
        $context['admin_emails'] = NotificationService::getAdminEmails();
        $sent = NotificationService::dispatch($notificationKey, $context, [
            'admin_override' => true,
            'actor_id' => (int) ($user['id'] ?? 0),
            'resend_of' => $activityId,
        ]);
        if ($sent) {
            redirectWithFlash($memberId, $tab, 'Email resent.');
        }
        redirectWithFlash($memberId, $tab, 'Unable to resend email.', 'error');
        break;

    default:
        redirectWithFlash($memberId, $tab, 'Unknown action.', 'error');
}
