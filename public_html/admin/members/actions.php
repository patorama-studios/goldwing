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
use App\Services\OrderRepository;
use App\Services\PasswordPolicyService;
use App\Services\RefundService;
use App\Services\NotificationService;
use App\Services\SecurityPolicyService;
use App\Services\SettingsService;
use App\Services\LogViewerService;
use App\Services\MembershipService;
use App\Services\StripeService;
use App\Services\NotificationPreferenceService;
use App\Services\TwoFactorService;
use App\Services\VehicleRepository;

require_permission('admin.members.view');

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
    $stmt = $pdo->prepare('SELECT name, state FROM chapters WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $chapterId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Unassigned';
    }
    $state = trim($row['state'] ?? '');
    $label = trim(($row['name'] ?? '') . ($state ? ' (' . $state . ')' : ''));
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

$action = $_POST['action'] ?? '';
$jsonActions = ['member_inline_update', 'bulk_member_action', 'associate_search', 'link_associate_member', 'unlink_associate_member'];
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
$requiresMemberContext = !in_array($action, ['member_inline_update', 'bulk_member_action'], true);
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

$sensitiveActions = ['save_profile', 'refund_submit', 'manual_order_fix', 'order_resync', 'send_reset_link', 'set_password', 'change_status', 'twofa_force', 'twofa_exempt', 'twofa_reset', 'member_number_update', 'member_archive', 'member_delete', 'send_migration_link', 'disable_migration_link', 'enable_migration_link', 'manual_membership_order', 'membership_renewal_update', 'membership_order_accept', 'membership_order_reject', 'membership_order_send_link', 'membership_order_note', 'resend_notification', 'roles_update', 'member_settings_update', 'twofa_toggle', 'chapter_request_decision', 'request_chapter', 'assign_chapter', 'bike_add', 'bike_update', 'bike_delete', 'impersonate_member'];
if (in_array($action, $sensitiveActions, true)) {
    require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members');
}

switch ($action) {
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
            $payload['chapter_id'] = isset($_POST['chapter_id']) && $_POST['chapter_id'] !== '' ? (int) $_POST['chapter_id'] : null;
            $payload['membership_type_id'] = isset($_POST['membership_type_id']) && $_POST['membership_type_id'] !== '' ? (int) $_POST['membership_type_id'] : null;
            $status = $_POST['status'] ?? $targetMember['status'];
            if (in_array($status, ['pending', 'active', 'expired', 'cancelled', 'suspended'], true)) {
                $payload['status'] = $status;
            }
            foreach (MemberRepository::directoryPreferences() as $letter => $info) {
                $payload['directory_pref_' . $letter] = isset($_POST['directory_pref_' . $letter]) ? 1 : 0;
            }
            $payload['notes'] = trim($_POST['notes'] ?? $targetMember['notes'] ?? '');
        }
        if ($allowFullProfile && $member['member_type'] === 'ASSOCIATE' && !empty($member['full_member_id'])) {
            unset($payload['chapter_id']);
        }

        if ($payload === []) {
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

        redirectWithFlash($memberId, $tab, 'Member profile updated.');
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
                if (!MemberRepository::update($inlineMemberId, ['status' => $inlineValue])) {
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
        $allowedActions = ['archive', 'delete', 'assign_chapter', 'change_status', 'enable_2fa', 'send_reset_link'];
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
                if (!MemberRepository::update($memberId, ['status' => 'cancelled'])) {
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
                if (!MemberRepository::update($memberId, ['status' => $statusValue])) {
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
        $make = trim($_POST['bike_make'] ?? '');
        $model = trim($_POST['bike_model'] ?? '');
        $year = (int) ($_POST['bike_year'] ?? 0);
        $rego = trim($_POST['bike_rego'] ?? '');
        $imageUrl = trim($_POST['bike_image_url'] ?? '');
        $color = trim($_POST['bike_color'] ?? '');
        if ($make === '' || $model === '') {
            redirectWithFlash($memberId, $tab, 'Make and model are required.', 'error');
        }
        $pdo = Database::connection();
        $columns = ['member_id', 'make', 'model', 'year', 'created_at'];
        $placeholders = [':member_id', ':make', ':model', ':year', 'NOW()'];
        $params = [
            'member_id' => $memberId,
            'make' => $make,
            'model' => $model,
            'year' => $year ?: null,
        ];
        $hasPrimary = memberBikeHasColumn($pdo, 'is_primary');
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
        if ($hasPrimary) {
            $primaryStmt = $pdo->prepare('SELECT 1 FROM member_bikes WHERE member_id = :member_id AND is_primary = 1 LIMIT 1');
            $primaryStmt->execute(['member_id' => $memberId]);
            $primaryExists = (bool) $primaryStmt->fetchColumn();
            if (!$primaryExists) {
                $columns[] = 'is_primary';
                $placeholders[] = ':is_primary';
                $params['is_primary'] = 1;
            }
        }
        $stmt = $pdo->prepare('INSERT INTO member_bikes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);
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
        $stmt = $pdo->prepare('SELECT id, end_date FROM membership_periods WHERE member_id = :member_id ORDER BY start_date DESC, end_date DESC LIMIT 1');
        $stmt->execute(['member_id' => $memberId]);
        $period = $stmt->fetch();
        if (!$period) {
            redirectWithFlash($memberId, $tab, 'No membership period found.', 'error');
        }
        try {
            $stmt = $pdo->prepare('UPDATE membership_periods SET end_date = :end_date WHERE id = :id');
            $stmt->execute(['end_date' => $normalizedRenewal, 'id' => $period['id']]);
            ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.renewal_updated', [
                'from' => $period['end_date'] ?? null,
                'to' => $normalizedRenewal,
            ]);
            redirectWithFlash($memberId, $tab, 'Renewal date updated.');
        } catch (Throwable $e) {
            redirectWithFlash($memberId, $tab, 'Error updating renewal date: ' . $e->getMessage(), 'error');
        }
        break;

    case 'manual_membership_order':
        if (!AdminMemberAccess::canManualOrderFix($user)) {
            redirectWithFlash($memberId, $tab, 'Manual membership orders are restricted.', 'error');
        }
        $pdo = Database::connection();
        $membershipTypeId = (int) ($_POST['manual_membership_type_id'] ?? 0);
        $costValue = (float) ($_POST['manual_membership_cost'] ?? 0);
        $paymentMethod = trim($_POST['manual_payment_method'] ?? 'Manual');
        $membershipStatus = trim($_POST['manual_membership_status'] ?? 'active');
        $orderNotes = trim($_POST['manual_order_reference'] ?? '');
        $startDate = trim($_POST['manual_start_date'] ?? '');
        $renewalDate = trim($_POST['manual_renewal_date'] ?? '');

        $allowedStatus = ['active', 'pending', 'complimentary', 'lapsed'];
        if (!in_array($membershipStatus, $allowedStatus, true)) {
            redirectWithFlash($memberId, $tab, 'Invalid membership status.', 'error');
        }

        $stmt = $pdo->prepare('SELECT id, name FROM membership_types WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $membershipTypeId]);
        $membershipType = $stmt->fetch();
        if (!$membershipType) {
            redirectWithFlash($memberId, $tab, 'Invalid membership type selected.', 'error');
        }

        $memberTypeCode = mapMembershipTypeName((string) $membershipType['name']);
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
            $endDate = $endValue ? $endValue->format('Y-m-d') : MembershipService::calculateExpiry($startDate, 1);
        }

        $stmt = $pdo->prepare('UPDATE members SET membership_type_id = :membership_type_id, member_type = :member_type, status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'membership_type_id' => $membershipTypeId,
            'member_type' => $memberTypeCode,
            'status' => $memberStatus,
            'id' => $memberId,
        ]);

        $term = $memberTypeCode === 'LIFE' ? 'LIFE' : '1Y';
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
            'actor_user_id' => $user['id'] ?? null,
            'term' => $term,
            'item_name' => ($membershipType['name'] ?? 'Membership') . ' membership',
            'admin_notes' => $orderNotes !== '' ? $orderNotes : null,
            'internal_notes' => $orderNotes !== '' ? $orderNotes : null,
        ]);
        if (!$order) {
            redirectWithFlash($memberId, $tab, 'Unable to create membership order.', 'error');
        }

        $orderNumber = (string) ($order['order_number'] ?? '');
        if ($orderNumber !== '') {
            $stmt = $pdo->prepare('UPDATE membership_periods SET payment_id = :payment_id WHERE id = :id');
            $stmt->execute([
                'payment_id' => $orderNumber,
                'id' => $periodId,
            ]);
        }

        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'membership.manual_order_created', [
            'membership_type' => $memberTypeCode,
            'status' => $membershipStatus,
            'amount' => max(0, $costValue),
            'order_number' => $orderNumber !== '' ? $orderNumber : null,
            'actor_roles' => $user['roles'] ?? [],
        ]);

        if ($membershipStatus === 'active' || $membershipStatus === 'complimentary') {
            NotificationService::dispatch('membership_activated_confirmation', [
                'primary_email' => $member['email'] ?? '',
                'admin_emails' => NotificationService::getAdminEmails(),
                'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                'membership_type' => $membershipType['name'] ?? 'Member',
                'renewal_date' => $endDate ?: 'N/A',
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
            $stmt = $pdo->prepare('UPDATE orders SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = "" THEN :note ELSE CONCAT(internal_notes, "\n", :note) END, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'note' => 'Payment reference: ' . $paymentReference,
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
        $successUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&success=1');
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
        $stmt = $pdo->prepare('UPDATE orders SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = "" THEN :note ELSE CONCAT(internal_notes, "\n", :note) END, updated_at = NOW() WHERE id = :id AND member_id = :member_id');
        $stmt->execute([
            'note' => $note,
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

    case 'member_settings_update':
        if (!AdminMemberAccess::isFullAccess($user)) {
            redirectWithFlash($memberId, 'settings', 'Settings updates are restricted.', 'error');
        }
        $userId = $member['user_id'] ?? null;
        if (!$userId) {
            redirectWithFlash($memberId, 'settings', 'Member does not have a linked user account.', 'error');
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
        redirectWithFlash($memberId, 'settings', 'Member settings saved.');
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
        MemberRepository::update($memberId, ['status' => $newStatus]);
        ActivityLogger::log('admin', $user['id'] ?? null, $memberId, 'member.status_changed', ['from' => $member['status'], 'to' => $newStatus]);
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
            MemberRepository::update($memberId, ['status' => 'cancelled']);
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
