<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\AdminMemberAccess;
use App\Services\MembershipService;
use App\Services\MembershipOrderService;
use App\Services\AuditService;
use App\Services\StripeService;
use App\Services\OrderService;
use App\Services\NoticeService;
use App\Services\EventService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\EmailService;
use App\Services\PaymentSettingsService;
use App\Services\StripeSettingsService;
use App\Services\SettingsService;
use App\Services\MemberRepository;
use App\Services\ChapterRepository;
use App\Services\DomSnapshotService;
use App\Services\BaseUrlService;

require_login();

function orders_member_column(\PDO $pdo): string
{
    static $column = null;
    if ($column !== null) {
        return $column;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'member_id'");
        if ($stmt && $stmt->fetchColumn()) {
            $column = 'member_id';
            return $column;
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'user_id'");
        if ($stmt && $stmt->fetchColumn()) {
            $column = 'user_id';
            return $column;
        }
    } catch (\Throwable $e) {
        // Ignore schema inspection errors.
    }
    $column = '';
    return $column;
}

function orders_payment_status_column(\PDO $pdo): string
{
    static $column = null;
    if ($column !== null) {
        return $column;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_status'");
        if ($stmt && $stmt->fetchColumn()) {
            $column = 'payment_status';
            return $column;
        }
    } catch (\Throwable $e) {
        // Ignore schema inspection errors.
    }
    $column = 'status';
    return $column;
}

$page = $_GET['page'] ?? 'dashboard';
$page = preg_replace('/[^a-z0-9-]/', '', strtolower($page));
$user = current_user();
$pdo = db();

$permissionMap = [
    'dashboard' => 'admin.dashboard.view',
    'applications' => 'admin.members.view',
    'payments' => 'admin.payments.view',
    'events' => 'admin.events.manage',
    'notices' => 'admin.pages.edit',
    'fallen-wings' => 'admin.pages.view',
    'wings' => 'admin.wings_magazine.manage',
    'media' => 'admin.media_library.manage',
    'reports' => 'admin.logs.view',
    'audit' => 'admin.logs.view',
];
$permissionKey = $permissionMap[$page] ?? 'admin.dashboard.view';
require_permission($permissionKey);

if ($page === 'menus') {
    header('Location: /admin/navigation.php');
    exit;
}
if ($page === 'pages') {
    header('Location: /admin/navigation.php');
    exit;
}
if ($page === 'ai-editor') {
    header('Location: /admin/page-builder');
    exit;
}

$alerts = [];
$baseUrlError = BaseUrlService::validationError();
if ($baseUrlError !== null) {
    $alerts[] = [
        'type' => 'error',
        'message' => 'Email dispatching is paused: ' . $baseUrlError,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        if ($page === 'applications' && isset($_POST['assign_chapter_id'], $_POST['member_id'])) {
            $memberId = (int) $_POST['member_id'];
            $chapterId = $_POST['assign_chapter_id'] !== '' ? (int) $_POST['assign_chapter_id'] : null;
            $stmt = $pdo->prepare('UPDATE members SET chapter_id = :chapter_id WHERE id = :id');
            $stmt->execute(['chapter_id' => $chapterId, 'id' => $memberId]);
            AuditService::log($user['id'], 'assign_chapter', 'Assigned chapter for member #' . $memberId . '.');
            $alerts[] = ['type' => 'success', 'message' => 'Chapter assignment updated.'];
        }
        if ($page === 'applications' && isset($_POST['approve_id'])) {
            $appId = (int) $_POST['approve_id'];
            $stmt = $pdo->prepare('SELECT status, member_id, member_type, notes FROM membership_applications WHERE id = :id');
            $stmt->execute(['id' => $appId]);
            $row = $stmt->fetch();
            if (!$row) {
                $alerts[] = ['type' => 'error', 'message' => 'Application not found.'];
            } elseif ($row['status'] === 'APPROVED') {
                $alerts[] = ['type' => 'error', 'message' => 'Application already approved.'];
            } else {
                $stmt = $pdo->prepare('UPDATE membership_applications SET status = "APPROVED", approved_by = :user_id, approved_at = NOW(), rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL WHERE id = :id');
                $stmt->execute(['user_id' => $user['id'], 'id' => $appId]);

                $notes = json_decode($row['notes'] ?? '', true);
                if (!is_array($notes)) {
                    $notes = [];
                }
                $term = '1Y';
                if ($row['member_type'] === 'ASSOCIATE') {
                    $term = strtoupper(trim((string) ($notes['membership']['associate']['period_key'] ?? $term)));
                } else {
                    $term = strtoupper(trim((string) ($notes['membership']['full']['period_key'] ?? $term)));
                }
                if ($term === '' && $row['member_type'] === 'LIFE') {
                    $term = 'LIFE';
                }
                $stmt = $pdo->prepare("SELECT id, stripe_payment_id FROM payments WHERE member_id = :member_id AND type = 'membership' AND status = 'PAID' ORDER BY created_at DESC LIMIT 1");
                $stmt->execute(['member_id' => $row['member_id']]);
                $paidPayment = $stmt->fetch();
                $paymentKey = '';
                if ($paidPayment) {
                    $paymentKey = trim((string) ($paidPayment['stripe_payment_id'] ?? ''));
                    if ($paymentKey === '' && !empty($paidPayment['id'])) {
                        $paymentKey = 'manual-' . $paidPayment['id'];
                    }
                }
                $periodId = MembershipService::createMembershipPeriod((int) $row['member_id'], $term, date('Y-m-d'));

                $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM members WHERE id = :id');
                $stmt->execute(['id' => $row['member_id']]);
                $memberRow = $stmt->fetch();
                $memberMeta = [];
                if ($memberRow) {
                    $stmt = $pdo->prepare('SELECT member_number_base, chapter_id, address_line1, address_line2, city, state, postal_code, country, privacy_level, assist_ute, assist_phone, assist_bed, assist_tools, exclude_printed, exclude_electronic FROM members WHERE id = :id');
                    $stmt->execute(['id' => $row['member_id']]);
                    $memberMeta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                }

                if (!empty($memberRow['email'])) {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                    $stmt->execute(['email' => $memberRow['email']]);
                    $existingUser = $stmt->fetch();
                    if (!$existingUser) {
                        $tempPassword = bin2hex(random_bytes(4));
                        $stmt = $pdo->prepare('INSERT INTO users (member_id, name, email, password_hash, is_active, created_at) VALUES (:member_id, :name, :email, :hash, 1, NOW())');
                        $stmt->execute([
                            'member_id' => $row['member_id'],
                            'name' => 'Member',
                            'email' => $memberRow['email'],
                            'hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
                        ]);
                        $newUserId = (int) $pdo->lastInsertId();
                        $stmt = $pdo->prepare('UPDATE members SET user_id = :user_id WHERE id = :member_id');
                        $stmt->execute(['user_id' => $newUserId, 'member_id' => $row['member_id']]);
                        $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = "member"');
                        $stmt->execute(['user_id' => $newUserId]);
                        if (SettingsService::getGlobal('accounts.audit_role_changes', true)) {
                            AuditService::log($user['id'], 'role_assign', 'Assigned member role to user #' . $newUserId);
                        }

                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);
                        $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, ip_address) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), :ip)');
                        $stmt->execute([
                            'user_id' => $newUserId,
                            'token_hash' => $tokenHash,
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ]);
                        $resetLink = BaseUrlService::emailLink('/member/reset_password_confirm.php?token=' . urlencode($token));
                        NotificationService::dispatch('member_set_password', [
                            'primary_email' => $memberRow['email'],
                            'admin_emails' => NotificationService::getAdminEmails(),
                            'reset_link' => NotificationService::escape($resetLink),
                        ]);
                    }
                }

                $paymentMethodRaw = strtolower(trim((string) ($notes['payment_method'] ?? 'card')));
                $paymentMethod = $paymentMethodRaw === 'bank_transfer' ? 'bank_transfer' : 'stripe';
                $currency = strtoupper((string) ($notes['membership']['currency'] ?? 'AUD'));
                $totalCents = (int) ($notes['membership']['total_cents'] ?? 0);
                if ($totalCents <= 0) {
                    $totalCents = (int) ($notes['membership']['full']['price_cents'] ?? 0);
                    $totalCents += (int) ($notes['membership']['associate']['price_cents'] ?? 0);
                }
                $amount = round($totalCents / 100, 2);
                $paymentRecorded = (bool) $paidPayment || $amount <= 0;
                $paymentStatus = $paymentRecorded ? 'accepted' : 'pending';
                $fulfillmentStatus = $paymentRecorded ? 'active' : 'pending';

                $items = [];
                $fullSelected = !empty($notes['membership']['full_selected']);
                $associateSelected = !empty($notes['membership']['associate_selected']) || !empty($notes['membership']['associate_add']);
                $fullTerm = $term;
                $associateTerm = $term;
                if ($fullSelected) {
                    $fullTerm = strtoupper((string) ($notes['membership']['full']['period_key'] ?? $term));
                    $fullAmount = (int) ($notes['membership']['full']['price_cents'] ?? 0);
                    $items[] = [
                        'product_id' => null,
                        'name' => 'Full membership ' . $fullTerm,
                        'quantity' => 1,
                        'unit_price' => round($fullAmount / 100, 2),
                        'is_physical' => 0,
                    ];
                }
                if ($associateSelected) {
                    $associateTerm = strtoupper((string) ($notes['membership']['associate']['period_key'] ?? $term));
                    $associateAmount = (int) ($notes['membership']['associate']['price_cents'] ?? 0);
                    $items[] = [
                        'product_id' => null,
                        'name' => 'Associate membership ' . $associateTerm,
                        'quantity' => 1,
                        'unit_price' => round($associateAmount / 100, 2),
                        'is_physical' => 0,
                    ];
                }
                if (!$items) {
                    $items[] = [
                        'product_id' => null,
                        'name' => 'Membership ' . $term,
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'is_physical' => 0,
                    ];
                }

                $associateMemberId = null;
                $associatePeriodId = null;
                $associateDetails = is_array($notes['associate'] ?? null) ? $notes['associate'] : [];
                $associateFirst = trim((string) ($associateDetails['first_name'] ?? ''));
                $associateLast = trim((string) ($associateDetails['last_name'] ?? ''));
                $associateEmail = trim((string) ($associateDetails['email'] ?? ''));
                $associatePhone = trim((string) ($associateDetails['phone'] ?? ''));
                $associateAddressDiff = strtolower(trim((string) ($associateDetails['address_diff'] ?? '')));
                $associateAddressLine1 = trim((string) ($associateDetails['address_line1'] ?? ''));
                $associateCity = trim((string) ($associateDetails['city'] ?? ''));
                $associateState = trim((string) ($associateDetails['state'] ?? ''));
                $associatePostalCode = trim((string) ($associateDetails['postal_code'] ?? ''));
                $associateCountry = trim((string) ($associateDetails['country'] ?? ''));
                $associateVehicles = $notes['vehicles']['associate'] ?? [];
                if (!is_array($associateVehicles)) {
                    $associateVehicles = [];
                }

                if ($associateSelected && $associateAdd === 'yes') {
                    if ($associateEmail === '' || !filter_var($associateEmail, FILTER_VALIDATE_EMAIL)) {
                        $alerts[] = ['type' => 'error', 'message' => 'Associate member email is required and must be unique.'];
                        $associateAdd = 'no';
                    } elseif (!MemberRepository::isEmailAvailable($associateEmail)) {
                        $alerts[] = ['type' => 'error', 'message' => 'Associate member email is already linked to another member.'];
                        $associateAdd = 'no';
                    }
                }

                if ($associateSelected && $associateAdd === 'yes' && ($associateFirst !== '' || $associateLast !== '')) {
                    $existingAssociateId = null;
                    $nameKey = strtolower(trim($associateFirst . ' ' . $associateLast));
                    if ($nameKey !== '') {
                        $stmt = $pdo->prepare('SELECT id FROM members WHERE full_member_id = :full_member_id AND LOWER(CONCAT(first_name, " ", last_name)) = :name LIMIT 1');
                        $stmt->execute([
                            'full_member_id' => $row['member_id'],
                            'name' => $nameKey,
                        ]);
                        $existingAssociateId = (int) ($stmt->fetchColumn() ?: 0);
                    }
                    if ($existingAssociateId <= 0 && $associateEmail !== '') {
                        $stmt = $pdo->prepare('SELECT id FROM members WHERE full_member_id = :full_member_id AND LOWER(email) = :email LIMIT 1');
                        $stmt->execute([
                            'full_member_id' => $row['member_id'],
                            'email' => strtolower($associateEmail),
                        ]);
                        $existingAssociateId = (int) ($stmt->fetchColumn() ?: 0);
                    }

                    if ($existingAssociateId > 0) {
                        $associateMemberId = $existingAssociateId;
                    } else {
                        $stmt = $pdo->prepare('SELECT MAX(member_number_suffix) as max_suffix FROM members WHERE full_member_id = :full_id');
                        $stmt->execute(['full_id' => $row['member_id']]);
                        $suffixRow = $stmt->fetch();
                        $maxSuffix = (int) ($suffixRow['max_suffix'] ?? 0);
                        $suffixStart = (int) SettingsService::getGlobal('membership.associate_suffix_start', 1);
                        $associateSuffix = max($maxSuffix, $suffixStart - 1) + 1;
                        $memberNumberBase = (int) ($memberMeta['member_number_base'] ?? 0);
                        if ($memberNumberBase <= 0) {
                            $stmt = $pdo->prepare('SELECT member_number_base FROM members WHERE id = :id');
                            $stmt->execute(['id' => $row['member_id']]);
                            $memberNumberBase = (int) ($stmt->fetchColumn() ?: 0);
                        }

                        $useAssociateAddress = $associateAddressDiff === 'yes';
                        $addressLine1 = $useAssociateAddress ? $associateAddressLine1 : (string) ($memberMeta['address_line1'] ?? '');
                        $addressLine2 = $useAssociateAddress ? '' : (string) ($memberMeta['address_line2'] ?? '');
                        $city = $useAssociateAddress ? $associateCity : (string) ($memberMeta['city'] ?? '');
                        $state = $useAssociateAddress ? $associateState : (string) ($memberMeta['state'] ?? '');
                        $postal = $useAssociateAddress ? $associatePostalCode : (string) ($memberMeta['postal_code'] ?? '');
                        $country = $useAssociateAddress ? $associateCountry : (string) ($memberMeta['country'] ?? '');
                        if ($country === '') {
                            $country = 'Australia';
                        }

                        $stmt = $pdo->prepare('INSERT INTO members (member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, first_name, last_name, email, phone, address_line1, address_line2, city, state, postal_code, country, privacy_level, assist_ute, assist_phone, assist_bed, assist_tools, exclude_printed, exclude_electronic, created_at) VALUES ("ASSOCIATE", "PENDING", :base, :suffix, :full_id, :chapter_id, :first_name, :last_name, :email, :phone, :address1, :address2, :city, :state, :postal, :country, :privacy, 0, 0, 0, 0, 0, 0, NOW())');
                        $stmt->execute([
                            'base' => $memberNumberBase,
                            'suffix' => $associateSuffix,
                            'full_id' => $row['member_id'],
                            'chapter_id' => $memberMeta['chapter_id'] ?? null,
                            'first_name' => $associateFirst,
                            'last_name' => $associateLast,
                            'email' => $associateEmail !== '' ? $associateEmail : ($memberRow['email'] ?? ''),
                            'phone' => $associatePhone !== '' ? $associatePhone : ($memberRow['phone'] ?? null),
                            'address1' => $addressLine1 !== '' ? $addressLine1 : null,
                            'address2' => $addressLine2 !== '' ? $addressLine2 : null,
                            'city' => $city !== '' ? $city : null,
                            'state' => $state !== '' ? $state : null,
                            'postal' => $postal !== '' ? $postal : null,
                            'country' => $country,
                            'privacy' => $memberMeta['privacy_level'] ?? 'A',
                        ]);
                        $associateMemberId = (int) $pdo->lastInsertId();
                        if ($associateMemberId > 0 && MemberRepository::hasMemberNumberColumn($pdo)) {
                            $memberNumberDisplay = MembershipService::displayMembershipNumber($memberNumberBase, $associateSuffix);
                            $stmt = $pdo->prepare('UPDATE members SET member_number = :member_number WHERE id = :id');
                            $stmt->execute([
                                'member_number' => $memberNumberDisplay,
                                'id' => $associateMemberId,
                            ]);
                        }
                    }

                    if ($associateMemberId) {
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM membership_periods WHERE member_id = :member_id');
                        $stmt->execute(['member_id' => $associateMemberId]);
                        $hasPeriods = (int) ($stmt->fetchColumn() ?: 0);
                        if ($hasPeriods === 0) {
                            $associatePeriodId = MembershipService::createMembershipPeriod($associateMemberId, $associateTerm, date('Y-m-d'));
                        }
                    }
                }

                if ($associateMemberId && $associateVehicles) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM member_bikes WHERE member_id = :member_id');
                    $stmt->execute(['member_id' => $associateMemberId]);
                    $hasAssociateBikes = (int) ($stmt->fetchColumn() ?: 0);
                    if ($hasAssociateBikes === 0) {
                        $bikeColumns = [];
                        $bikeHasRego = true;
                        $bikeHasColour = false;
                        $bikeHasPrimary = false;
                        try {
                            $bikeColumns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(PDO::FETCH_COLUMN, 0);
                            $bikeHasRego = in_array('rego', $bikeColumns, true);
                            $bikeHasColour = in_array('colour', $bikeColumns, true) || in_array('color', $bikeColumns, true);
                            $bikeHasPrimary = in_array('is_primary', $bikeColumns, true);
                        } catch (Throwable $e) {
                            $bikeColumns = [];
                            $bikeHasRego = true;
                            $bikeHasColour = false;
                            $bikeHasPrimary = false;
                        }

                        $primarySet = false;
                        if ($bikeHasPrimary) {
                            $primaryStmt = $pdo->prepare('SELECT 1 FROM member_bikes WHERE member_id = :member_id AND is_primary = 1 LIMIT 1');
                            $primaryStmt->execute(['member_id' => $associateMemberId]);
                            $primarySet = (bool) $primaryStmt->fetchColumn();
                        }

                        foreach ($associateVehicles as $vehicle) {
                            $make = trim((string) ($vehicle['make'] ?? ''));
                            $model = trim((string) ($vehicle['model'] ?? ''));
                            $yearValue = trim((string) ($vehicle['year'] ?? ''));
                            $year = $yearValue !== '' && is_numeric($yearValue) ? (int) $yearValue : null;
                            $rego = trim((string) ($vehicle['rego'] ?? ''));
                            $colour = trim((string) ($vehicle['colour'] ?? ($vehicle['color'] ?? '')));
                            if (strlen($rego) > 20) {
                                $rego = substr($rego, 0, 20);
                            }
                            if ($make !== '' && $model !== '') {
                                $columns = ['member_id', 'make', 'model', 'year', 'created_at'];
                                $placeholders = [':member_id', ':make', ':model', ':year', 'NOW()'];
                                $params = [
                                    'member_id' => $associateMemberId,
                                    'make' => $make,
                                    'model' => $model,
                                    'year' => $year,
                                ];
                                if ($bikeHasRego) {
                                    $columns[] = 'rego';
                                    $placeholders[] = ':rego';
                                    $params['rego'] = $rego !== '' ? $rego : null;
                                }
                                if ($bikeHasColour && $colour !== '') {
                                    if (in_array('colour', $bikeColumns, true)) {
                                        $columns[] = 'colour';
                                        $placeholders[] = ':colour';
                                        $params['colour'] = $colour;
                                    } elseif (in_array('color', $bikeColumns, true)) {
                                        $columns[] = 'color';
                                        $placeholders[] = ':color';
                                        $params['color'] = $colour;
                                    }
                                }
                                if ($bikeHasPrimary && !$primarySet) {
                                    $columns[] = 'is_primary';
                                    $placeholders[] = ':is_primary';
                                    $params['is_primary'] = 1;
                                }
                                $stmt = $pdo->prepare('INSERT INTO member_bikes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
                                $stmt->execute($params);
                                if ($bikeHasPrimary && !$primarySet) {
                                    $primarySet = true;
                                }
                            }
                        }
                    }
                }

                $internalNotes = 'Application approval';
                if ($associateMemberId) {
                    $internalNotes = json_encode([
                        'source' => 'application_approval',
                        'associate_member_id' => $associateMemberId,
                        'associate_period_id' => $associatePeriodId,
                        'membership_selection' => [
                            'full_selected' => $fullSelected,
                            'associate_selected' => $associateSelected,
                            'associate_add' => $associateAdd,
                        ],
                        'associate_details' => [
                            'first_name' => $associateFirst,
                            'last_name' => $associateLast,
                            'email' => $associateEmail !== '' ? $associateEmail : ($memberRow['email'] ?? ''),
                        ],
                    ], JSON_UNESCAPED_SLASHES);
                }

                $order = MembershipOrderService::createMembershipOrder((int) $row['member_id'], $periodId, $amount, [
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'fulfillment_status' => $fulfillmentStatus,
                    'currency' => $currency !== '' ? $currency : 'AUD',
                    'items' => $items,
                    'actor_user_id' => $user['id'] ?? null,
                    'term' => $term,
                    'admin_notes' => 'Application approval',
                    'internal_notes' => $internalNotes,
                ]);
                if ($order && $paymentRecorded) {
                    MembershipOrderService::activateMembershipForOrder($order, [
                        'payment_reference' => $paymentKey !== '' ? $paymentKey : null,
                        'period_id' => $periodId,
                    ]);
                    if ($associateMemberId) {
                        if ($associatePeriodId) {
                            MembershipService::markPaid($associatePeriodId, $paymentKey !== '' ? $paymentKey : ($order['order_number'] ?? ''));
                        } else {
                            $stmt = $pdo->prepare('UPDATE members SET status = "ACTIVE", updated_at = NOW() WHERE id = :id');
                            $stmt->execute(['id' => $associateMemberId]);
                        }
                    }
                }

                $paymentNeeded = !$paymentRecorded && $amount > 0;
                if ($paymentNeeded && $order) {
                    $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
                    if ($paymentMethod === 'stripe') {
                        $priceKey = $row['member_type'] . '_' . $term;
                        $prices = SettingsService::getGlobal('payments.membership_prices', []);
                        $priceId = is_array($prices) ? ($prices[$priceKey] ?? '') : '';
                        $session = null;
                        if ($priceId) {
                            $session = StripeService::createCheckoutSession($priceId, $memberRow['email'] ?? '', [
                                'period_id' => $periodId,
                                'member_id' => $row['member_id'],
                                'order_id' => $order['id'] ?? null,
                                'order_type' => 'membership',
                            ]);
                        }
                        if ($session && !empty($session['id'])) {
                            OrderService::updateStripeSession((int) ($order['id'] ?? 0), $session['id']);
                            $paymentLink = $session['url'] ?? $paymentLink;
                        }
                    }

                    $bankInstructions = (string) SettingsService::getGlobal('payments.bank_transfer_instructions', '');
                    if (!empty($memberRow['email'])) {
                        NotificationService::dispatch('membership_order_created', [
                            'primary_email' => $memberRow['email'],
                            'admin_emails' => NotificationService::getAdminEmails(),
                            'member_name' => trim(($memberRow['first_name'] ?? '') . ' ' . ($memberRow['last_name'] ?? '')),
                            'order_number' => $order['order_number'] ?? '',
                            'payment_link' => NotificationService::escape($paymentLink),
                            'payment_method' => $paymentMethod,
                            'bank_transfer_instructions' => NotificationService::escape($bankInstructions),
                        ]);
                    }
                    if ($paymentMethod === 'bank_transfer') {
                        NotificationService::dispatch('membership_admin_pending_approval', [
                            'primary_email' => '',
                            'admin_emails' => NotificationService::getAdminEmails(),
                            'order_number' => $order['order_number'] ?? '',
                            'member_name' => trim(($memberRow['first_name'] ?? '') . ' ' . ($memberRow['last_name'] ?? '')),
                        ]);
                    }
                    if (!empty($memberRow['phone'])) {
                        SmsService::send($memberRow['phone'], 'Membership approved. Pay now: ' . $paymentLink);
                    }
                }
                AuditService::log($user['id'], 'approve_application', 'Application #' . $appId . ' approved.');
                $alerts[] = [
                    'type' => 'success',
                    'message' => $paymentNeeded
                        ? 'Application approved. Payment instructions sent.'
                        : 'Application approved. Payment already recorded; no payment email sent.',
                ];
            }
        }
        if ($page === 'applications' && isset($_POST['reject_id'])) {
            $appId = (int) $_POST['reject_id'];
            $reason = substr(trim($_POST['reason'] ?? ''), 0, 255);
            $stmt = $pdo->prepare('SELECT status FROM membership_applications WHERE id = :id');
            $stmt->execute(['id' => $appId]);
            $status = $stmt->fetchColumn();
            if (!$status) {
                $alerts[] = ['type' => 'error', 'message' => 'Application not found.'];
            } elseif ($status === 'APPROVED') {
                $alerts[] = ['type' => 'error', 'message' => 'Approved applications cannot be rejected.'];
            } elseif ($status === 'REJECTED') {
                $alerts[] = ['type' => 'error', 'message' => 'Application already rejected.'];
            } else {
                $stmt = $pdo->prepare('UPDATE membership_applications SET status = "REJECTED", rejected_by = :user_id, rejected_at = NOW(), rejection_reason = :reason WHERE id = :id');
                $stmt->execute(['user_id' => $user['id'], 'reason' => $reason, 'id' => $appId]);
                AuditService::log($user['id'], 'reject_application', 'Application #' . $appId . ' rejected.');
                $alerts[] = ['type' => 'success', 'message' => 'Application rejected.'];
            }
        }
        if ($page === 'applications' && isset($_POST['resend_approval_id'])) {
            $appId = (int) $_POST['resend_approval_id'];
            $stmt = $pdo->prepare('SELECT a.member_id, m.user_id, m.first_name, m.last_name, m.email, m.phone FROM membership_applications a JOIN members m ON m.id = a.member_id WHERE a.id = :id');
            $stmt->execute(['id' => $appId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['email'])) {
                $ordersMemberColumn = orders_member_column($pdo);
                $ordersPaymentStatusColumn = orders_payment_status_column($pdo);
                $ordersMemberValue = null;
                if ($ordersMemberColumn === 'member_id') {
                    $ordersMemberValue = (int) ($row['member_id'] ?? 0);
                } elseif ($ordersMemberColumn === 'user_id') {
                    $ordersMemberValue = (int) ($row['user_id'] ?? 0);
                }
                $order = null;
                if ($ordersMemberColumn && $ordersMemberValue) {
                    $stmt = $pdo->prepare('SELECT * FROM orders WHERE ' . $ordersMemberColumn . ' = :value AND order_type = "membership" AND ' . $ordersPaymentStatusColumn . ' IN ("pending", "failed") ORDER BY created_at DESC LIMIT 1');
                    $stmt->execute(['value' => $ordersMemberValue]);
                    $order = $stmt->fetch();
                }
                if (!$order) {
                    $alerts[] = ['type' => 'success', 'message' => 'No pending membership order found to resend.'];
                } else {
                    $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
                    if (($order['payment_method'] ?? '') === 'bank_transfer') {
                        $bankInstructions = (string) SettingsService::getGlobal('payments.bank_transfer_instructions', '');
                        NotificationService::dispatch('membership_order_created', [
                            'primary_email' => $row['email'],
                            'admin_emails' => NotificationService::getAdminEmails(),
                            'order_number' => $order['order_number'] ?? '',
                            'payment_link' => NotificationService::escape($paymentLink),
                            'payment_method' => 'bank_transfer',
                            'bank_transfer_instructions' => NotificationService::escape($bankInstructions),
                        ]);
                        $memberName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        NotificationService::dispatch('membership_admin_pending_approval', [
                            'primary_email' => '',
                            'admin_emails' => NotificationService::getAdminEmails(),
                            'order_number' => $order['order_number'] ?? '',
                            'member_name' => $memberName !== '' ? $memberName : $row['email'],
                        ]);
                    } else {
                        $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
                        $itemsStmt->execute(['order_id' => (int) $order['id']]);
                        $items = $itemsStmt->fetchAll();
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
                        $session = StripeService::createCheckoutSessionWithLineItems($lineItems, $row['email'], $successUrl, $cancelUrl, [
                            'order_id' => (string) $order['id'],
                            'order_type' => 'membership',
                            'member_id' => (string) $row['member_id'],
                            'period_id' => (string) ($order['membership_period_id'] ?? ''),
                            'channel_id' => (string) ($order['channel_id'] ?? ''),
                        ]);
                        if ($session && !empty($session['id'])) {
                            OrderService::updateStripeSession((int) $order['id'], $session['id']);
                            $paymentLink = $session['url'] ?? $paymentLink;
                        }
                        NotificationService::dispatch('membership_order_created', [
                            'primary_email' => $row['email'],
                            'admin_emails' => NotificationService::getAdminEmails(),
                            'order_number' => $order['order_number'] ?? '',
                            'payment_link' => NotificationService::escape($paymentLink),
                            'payment_method' => 'stripe',
                        ]);
                    }
                    if (!empty($row['phone'])) {
                        SmsService::send($row['phone'], 'Membership approved. Pay now: ' . $paymentLink);
                    }
                    AuditService::log($user['id'], 'resend_application_approval', 'Resent approval email for application #' . $appId . '.');
                    $alerts[] = ['type' => 'success', 'message' => 'Approval email resent.'];
                }
            } else {
                $alerts[] = ['type' => 'error', 'message' => 'Unable to resend approval email. Missing member email.'];
            }
        }
        if ($page === 'notices' && ($_POST['action'] ?? '') === 'create_notice_admin') {
            $title = trim($_POST['notice_title'] ?? '');
            $contentRaw = trim($_POST['notice_content'] ?? '');
            $content = DomSnapshotService::sanitize($contentRaw);
            $category = strtolower(trim($_POST['notice_category'] ?? 'notice'));
            $audienceScope = strtolower(trim($_POST['notice_audience_scope'] ?? 'all'));
            $audienceState = trim($_POST['notice_audience_state'] ?? '');
            $audienceChapterId = (int) ($_POST['notice_audience_chapter'] ?? 0);
            $attachmentUrl = trim($_POST['notice_attachment_url'] ?? '');
            $attachmentType = strtolower(trim($_POST['notice_attachment_type'] ?? ''));

            $allowedCategories = ['notice', 'advert', 'announcement'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'notice';
            }
            $allowedAttachmentTypes = ['image', 'pdf'];
            if (!in_array($attachmentType, $allowedAttachmentTypes, true) || $attachmentUrl === '') {
                $attachmentType = '';
            }
            $allowedScopes = ['all', 'state', 'chapter'];
            if (!in_array($audienceScope, $allowedScopes, true)) {
                $audienceScope = 'all';
            }

            $australianStates = [
                ['code' => 'ACT', 'label' => 'Australian Capital Territory'],
                ['code' => 'NSW', 'label' => 'New South Wales'],
                ['code' => 'NT', 'label' => 'Northern Territory'],
                ['code' => 'QLD', 'label' => 'Queensland'],
                ['code' => 'SA', 'label' => 'South Australia'],
                ['code' => 'TAS', 'label' => 'Tasmania'],
                ['code' => 'VIC', 'label' => 'Victoria'],
                ['code' => 'WA', 'label' => 'Western Australia'],
            ];
            $stateCodes = array_column($australianStates, 'code');

            if ($audienceScope === 'state' && ($audienceState === '' || !in_array($audienceState, $stateCodes, true))) {
                $alerts[] = ['type' => 'error', 'message' => 'Please select a valid state.'];
            } elseif ($audienceScope === 'chapter' && $audienceChapterId <= 0) {
                $alerts[] = ['type' => 'error', 'message' => 'Please select a chapter.'];
            } elseif ($title === '' || $content === '') {
                $alerts[] = ['type' => 'error', 'message' => 'Please provide a title and description.'];
            } else {
                $noticeColumns = [];
                try {
                    $noticeColumns = $pdo->query('SHOW COLUMNS FROM notices')->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (PDOException $e) {
                    $noticeColumns = [];
                }
                $hasCategory = in_array('category', $noticeColumns, true);
                $hasAudience = in_array('audience_scope', $noticeColumns, true);
                $hasAttachmentUrl = in_array('attachment_url', $noticeColumns, true);
                $hasAttachmentType = in_array('attachment_type', $noticeColumns, true);
                $hasPublishedAt = in_array('published_at', $noticeColumns, true);

                $insertColumns = ['title', 'content', 'visibility', 'created_by', 'created_at'];
                $insertValues = [':title', ':content', ':visibility', ':created_by', 'NOW()'];
                $params = [
                    'title' => $title,
                    'content' => $content,
                    'visibility' => 'member',
                    'created_by' => $user['id'],
                ];

                if ($hasCategory) {
                    $insertColumns[] = 'category';
                    $insertValues[] = ':category';
                    $params['category'] = $category;
                }
                if ($hasAudience) {
                    $insertColumns[] = 'audience_scope';
                    $insertValues[] = ':scope';
                    $params['scope'] = $audienceScope;

                    $insertColumns[] = 'audience_state';
                    $insertValues[] = ':state';
                    $params['state'] = $audienceScope === 'state' ? $audienceState : null;

                    $insertColumns[] = 'audience_chapter_id';
                    $insertValues[] = ':chapter';
                    $params['chapter'] = $audienceScope === 'chapter' ? $audienceChapterId : null;
                }
                if ($hasAttachmentUrl) {
                    $insertColumns[] = 'attachment_url';
                    $insertValues[] = ':attachment_url';
                    $params['attachment_url'] = $attachmentUrl !== '' ? $attachmentUrl : null;
                }
                if ($hasAttachmentType) {
                    $insertColumns[] = 'attachment_type';
                    $insertValues[] = ':attachment_type';
                    $params['attachment_type'] = $attachmentType !== '' ? $attachmentType : null;
                }
                if ($hasPublishedAt) {
                    $insertColumns[] = 'published_at';
                    $insertValues[] = 'NOW()';
                }

                $stmt = $pdo->prepare('INSERT INTO notices (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')');
                $stmt->execute($params);

                AuditService::log($user['id'], 'notice_create', 'Notice created by admin.');
                $alerts[] = ['type' => 'success', 'message' => 'Notice published.'];
            }
        }
        if ($page === 'notices' && ($_POST['action'] ?? '') === 'approve_notice') {
            $noticeId = (int) ($_POST['notice_id'] ?? 0);
            $hasPublishedAt = (bool) $pdo->query("SHOW COLUMNS FROM notices LIKE 'published_at'")->fetch();
            if ($noticeId > 0 && $hasPublishedAt) {
                $stmt = $pdo->prepare('UPDATE notices SET published_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => $noticeId]);
                AuditService::log($user['id'], 'notice_approve', 'Notice #' . $noticeId . ' approved.');
                $alerts[] = ['type' => 'success', 'message' => 'Notice approved.'];
            } else {
                $alerts[] = ['type' => 'error', 'message' => 'Unable to approve notice.'];
            }
        }
        if ($page === 'notices' && ($_POST['action'] ?? '') === 'reject_notice') {
            $noticeId = (int) ($_POST['notice_id'] ?? 0);
            $reason = trim($_POST['reject_reason'] ?? '');
            if ($noticeId <= 0 || $reason === '') {
                $alerts[] = ['type' => 'error', 'message' => 'Please provide a rejection reason.'];
            } else {
                $noticeRow = null;
                $stmt = $pdo->prepare('SELECT n.*, u.email AS created_by_email, u.name AS created_by_name FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = :id');
                $stmt->execute(['id' => $noticeId]);
                $noticeRow = $stmt->fetch();

                $noticeColumns = [];
                try {
                    $noticeColumns = $pdo->query('SHOW COLUMNS FROM notices')->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (PDOException $e) {
                    $noticeColumns = [];
                }
                $hasVisibility = in_array('visibility', $noticeColumns, true);
                $hasPublishedAt = in_array('published_at', $noticeColumns, true);

                if ($noticeId > 0) {
                    if ($hasVisibility) {
                        $updateSql = 'UPDATE notices SET visibility = "rejected"';
                        $params = ['id' => $noticeId];
                        if ($hasPublishedAt) {
                            $updateSql .= ', published_at = NULL';
                        }
                        $updateSql .= ' WHERE id = :id';
                        $stmt = $pdo->prepare($updateSql);
                        $stmt->execute($params);
                    } else {
                        $stmt = $pdo->prepare('DELETE FROM notices WHERE id = :id');
                        $stmt->execute(['id' => $noticeId]);
                    }
                }

                if (!empty($noticeRow['created_by_email'])) {
                    $subject = 'Notice rejected';
                    $body = '<p>Your notice request was rejected.</p>'
                        . '<p><strong>Title:</strong> ' . e($noticeRow['title'] ?? 'Notice') . '</p>'
                        . '<p><strong>Reason:</strong> ' . e($reason) . '</p>';
                    EmailService::send($noticeRow['created_by_email'], $subject, $body);
                }
                AuditService::log($user['id'], 'notice_reject', 'Notice #' . $noticeId . ' rejected.');
                $alerts[] = ['type' => 'success', 'message' => 'Notice rejected.'];
            }
        }
        if ($page === 'fallen-wings' && ($_POST['action'] ?? '') === 'approve_fallen') {
            $entryId = (int) ($_POST['fallen_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE fallen_wings SET status = "APPROVED", approved_by = :user_id, approved_at = NOW(), updated_at = NOW() WHERE id = :id');
            $stmt->execute(['user_id' => $user['id'], 'id' => $entryId]);
            $alerts[] = ['type' => 'success', 'message' => 'Memorial entry approved.'];
        }
        if ($page === 'fallen-wings' && ($_POST['action'] ?? '') === 'reject_fallen') {
            $entryId = (int) ($_POST['fallen_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE fallen_wings SET status = "REJECTED", approved_by = :user_id, approved_at = NOW(), updated_at = NOW() WHERE id = :id');
            $stmt->execute(['user_id' => $user['id'], 'id' => $entryId]);
            $alerts[] = ['type' => 'success', 'message' => 'Memorial entry rejected.'];
        }
        if ($page === 'events' && isset($_POST['event_id'])) {
            EventService::updateDescription((int) $_POST['event_id'], $_POST['description'] ?? '', $user['id'], 'Manual update');
            AuditService::log($user['id'], 'event_update', 'Event #' . (int) $_POST['event_id'] . ' updated.');
            $alerts[] = ['type' => 'success', 'message' => 'Event updated.'];
        }
        if ($page === 'media' && !empty($_POST['embed_url'])) {
            $url = trim($_POST['embed_url']);
            $embedHtml = null;
            $thumbnail = null;
            $youtubeEnabled = SettingsService::getGlobal('integrations.youtube_embeds_enabled', true);
            $vimeoEnabled = SettingsService::getGlobal('integrations.vimeo_embeds_enabled', true);
            if (preg_match('~(youtube\\.com/watch\\?v=|youtu\\.be/)([A-Za-z0-9_-]+)~', $url, $matches)) {
                if (!$youtubeEnabled) {
                    $alerts[] = ['type' => 'error', 'message' => 'YouTube embeds are disabled.'];
                } else {
                $videoId = $matches[2];
                $embedHtml = '<iframe width="560" height="315" src="https://www.youtube.com/embed/' . $videoId . '" frameborder="0" allowfullscreen></iframe>';
                $thumbnail = 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
                }
            } elseif (preg_match('~vimeo\\.com/(\\d+)~', $url, $matches)) {
                if (!$vimeoEnabled) {
                    $alerts[] = ['type' => 'error', 'message' => 'Vimeo embeds are disabled.'];
                } else {
                $videoId = $matches[1];
                $embedHtml = '<iframe width="560" height="315" src="https://player.vimeo.com/video/' . $videoId . '" frameborder="0" allowfullscreen></iframe>';
                $thumbnail = 'https://vumbnail.com/' . $videoId . '.jpg';
                }
            }
            if ($embedHtml) {
                $stmt = $pdo->prepare('INSERT INTO media (type, title, path, embed_html, thumbnail_url, tags, visibility, uploaded_by, created_at) VALUES (:type, :title, :path, :embed_html, :thumbnail, :tags, :visibility, :uploaded_by, NOW())');
                $defaultVisibility = SettingsService::getGlobal('media.privacy_default', 'member');
                $stmt->execute([
                    'type' => 'video',
                    'title' => trim($_POST['title'] ?? $url),
                    'path' => $url,
                    'embed_html' => $embedHtml,
                    'thumbnail' => $thumbnail,
                    'tags' => trim($_POST['tags'] ?? ''),
                    'visibility' => $_POST['visibility'] ?? $defaultVisibility,
                    'uploaded_by' => $user['id'],
                ]);
                $alerts[] = ['type' => 'success', 'message' => 'Video embed saved.'];
            }
        }
        if ($page === 'media' && isset($_FILES['media_file']) && $_FILES['media_file']['name'] !== '') {
            $allowedTypes = SettingsService::getGlobal('media.allowed_types', []);
            $maxUploadMb = (float) SettingsService::getGlobal('media.max_upload_mb', 10);
            $maxBytes = (int) max(0, $maxUploadMb) * 1024 * 1024;
            $file = $_FILES['media_file'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                if ($maxBytes > 0 && (int) $file['size'] > $maxBytes) {
                    $alerts[] = ['type' => 'error', 'message' => 'Upload exceeds size limit.'];
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']) ?: '';
                    if (is_array($allowedTypes) && $allowedTypes && !in_array($mime, $allowedTypes, true)) {
                        $alerts[] = ['type' => 'error', 'message' => 'File type is not allowed.'];
                    } else {
                        $uploadDir = __DIR__ . '/../uploads/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                        $targetPath = $uploadDir . $safeName;
                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            $optimizeImages = SettingsService::getGlobal('media.image_optimization_enabled', false);
                            if ($optimizeImages && strpos($mime, 'image/') === 0) {
                                if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
                                    $img = @imagecreatefromjpeg($targetPath);
                                    if ($img) {
                                        imagejpeg($img, $targetPath, 85);
                                        imagedestroy($img);
                                    }
                                } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
                                    $img = @imagecreatefrompng($targetPath);
                                    if ($img) {
                                        imagepng($img, $targetPath, 6);
                                        imagedestroy($img);
                                    }
                                } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                                    $img = @imagecreatefromwebp($targetPath);
                                    if ($img && function_exists('imagewebp')) {
                                        imagewebp($img, $targetPath, 80);
                                        imagedestroy($img);
                                    }
                                }
                            }
                            $relativePath = '/uploads/' . $safeName;
                            $stmt = $pdo->prepare('INSERT INTO media (type, title, path, tags, visibility, uploaded_by, created_at) VALUES (:type, :title, :path, :tags, :visibility, :uploaded_by, NOW())');
                    $defaultVisibility = SettingsService::getGlobal('media.privacy_default', 'member');
                    $stmt->execute([
                        'type' => $_POST['media_type'] ?? 'file',
                        'title' => trim($_POST['title'] ?? $safeName),
                        'path' => $relativePath,
                        'tags' => trim($_POST['tags'] ?? ''),
                        'visibility' => $_POST['visibility'] ?? $defaultVisibility,
                        'uploaded_by' => $user['id'],
                    ]);
                            $alerts[] = ['type' => 'success', 'message' => 'Media uploaded.'];
                        } else {
                            $alerts[] = ['type' => 'error', 'message' => 'Upload failed.'];
                        }
                    }
                }
            } else {
                $alerts[] = ['type' => 'error', 'message' => 'Upload error.'];
            }
        }
        if ($page === 'wings' && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['name'] !== '') {
            $allowedTypes = SettingsService::getGlobal('media.allowed_types', []);
            $maxUploadMb = (float) SettingsService::getGlobal('media.max_upload_mb', 10);
            $maxBytes = (int) max(0, $maxUploadMb) * 1024 * 1024;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $pdfFile = $_FILES['pdf_file'];
            $coverFile = $_FILES['cover_file'] ?? null;
            if ($pdfFile['error'] === UPLOAD_ERR_OK) {
                $pdfOk = true;
                if ($maxBytes > 0 && (int) $pdfFile['size'] > $maxBytes) {
                    $alerts[] = ['type' => 'error', 'message' => 'PDF exceeds size limit.'];
                    $pdfOk = false;
                }
                $pdfMime = $finfo->file($pdfFile['tmp_name']) ?: '';
                if (is_array($allowedTypes) && $allowedTypes && !in_array($pdfMime, $allowedTypes, true)) {
                    $alerts[] = ['type' => 'error', 'message' => 'PDF file type is not allowed.'];
                    $pdfOk = false;
                }
                if (!$pdfOk) {
                    $pdfFile['error'] = UPLOAD_ERR_EXTENSION;
                }
            }
            if ($pdfFile['error'] === UPLOAD_ERR_OK) {
                $pdfName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $pdfFile['name']);
                $pdfPath = $uploadDir . $pdfName;
                if (move_uploaded_file($pdfFile['tmp_name'], $pdfPath)) {
                    $coverUrl = null;
                    if ($coverFile && $coverFile['error'] === UPLOAD_ERR_OK && $coverFile['name'] !== '') {
                        if ($maxBytes > 0 && (int) $coverFile['size'] > $maxBytes) {
                            $alerts[] = ['type' => 'error', 'message' => 'Cover exceeds size limit.'];
                        } else {
                            $coverMime = $finfo->file($coverFile['tmp_name']) ?: '';
                            if (is_array($allowedTypes) && $allowedTypes && !in_array($coverMime, $allowedTypes, true)) {
                                $alerts[] = ['type' => 'error', 'message' => 'Cover file type is not allowed.'];
                            } else {
                                $coverName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $coverFile['name']);
                                $coverPath = $uploadDir . $coverName;
                                if (move_uploaded_file($coverFile['tmp_name'], $coverPath)) {
                                    $coverUrl = '/uploads/' . $coverName;
                                }
                            }
                        }
                    }
                    if (!empty($_POST['is_latest'])) {
                        $pdo->query('UPDATE wings_issues SET is_latest = 0');
                    }
                    $stmt = $pdo->prepare('INSERT INTO wings_issues (title, pdf_url, cover_image_url, is_latest, published_at, created_by, created_at) VALUES (:title, :pdf_url, :cover_url, :is_latest, :published_at, :created_by, NOW())');
                    $stmt->execute([
                        'title' => trim($_POST['title'] ?? $pdfName),
                        'pdf_url' => '/uploads/' . $pdfName,
                        'cover_url' => $coverUrl,
                        'is_latest' => !empty($_POST['is_latest']) ? 1 : 0,
                        'published_at' => $_POST['published_at'] ?? date('Y-m-d'),
                        'created_by' => $user['id'],
                    ]);
                    $alerts[] = ['type' => 'success', 'message' => 'Wings issue uploaded.'];
                } else {
                    $alerts[] = ['type' => 'error', 'message' => 'PDF upload failed.'];
                }
            }
        }
        if ($page === 'payments' && ($_POST['action'] ?? '') === 'save_payment_settings') {
            header('Location: /admin/settings/index.php?section=payments');
            exit;
        }
    }
}

$pageTitles = [
    'dashboard' => 'Admin Dashboard',
    'members' => 'Members Directory',
    'applications' => 'Applications',
    'payments' => 'Payments & Refunds',
    'events' => 'Events Management',
    'notices' => 'Notice Board Management',
    'fallen-wings' => 'Fallen Wings Memorials',
    'media' => 'Media Library',
    'wings' => 'Wings Magazine',
    'ai-editor' => 'AI Page Builder',
    'audit' => 'Audit Log',
    'reports' => 'Reports & Exports',
];
$pageTitle = $pageTitles[$page] ?? 'Admin CRM';
$activePage = $page;

require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php foreach ($alerts as $alert): ?>
        <div class="rounded-lg px-4 py-2 text-sm <?= $alert['type'] === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?>">
          <?= e($alert['message']) ?>
        </div>
      <?php endforeach; ?>

      <?php if ($page === 'dashboard'): ?>
        <?php
          $activeCount = $pdo->query("SELECT COUNT(*) as c FROM members WHERE status = 'ACTIVE'")->fetch()['c'] ?? 0;
          $lapsedCount = $pdo->query("SELECT COUNT(*) as c FROM members WHERE status = 'LAPSED'")->fetch()['c'] ?? 0;
          $pendingCount = $pdo->query("SELECT COUNT(*) as c FROM membership_applications WHERE status = 'PENDING'")->fetch()['c'] ?? 0;
          $dueSoon = $pdo->query("SELECT COUNT(*) as c FROM membership_periods WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetch()['c'] ?? 0;
          $totalUsers = $pdo->query("SELECT COUNT(*) as c FROM users")->fetch()['c'] ?? 0;
          $recentPayments = $pdo->query("SELECT * FROM payments ORDER BY created_at DESC LIMIT 5")->fetchAll();
          $recentLogins = $pdo->query("SELECT u.name, ul.created_at FROM user_logins ul JOIN users u ON u.id = ul.user_id ORDER BY ul.created_at DESC LIMIT 5")->fetchAll();
          $events = $pdo->query("SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5")->fetchAll();
          $notices = $pdo->query("SELECT * FROM notices ORDER BY created_at DESC LIMIT 5")->fetchAll();
          $failedLogins24h = (int) ($pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'security.login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn() ?? 0);
          $newAdminDevices24h = (int) ($pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'security.admin_new_device' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn() ?? 0);
          $recentSecurityEvents = $pdo->query("SELECT action, created_at FROM activity_log WHERE action IN ('refund.processed','member.export','security.role_escalation') ORDER BY created_at DESC LIMIT 5")->fetchAll();
          $fimRow = $pdo->query("SELECT approved_at, last_scan_at, last_scan_status FROM file_integrity_baseline WHERE id = 1 LIMIT 1")->fetch();
          $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
          $settingMap = [];
          foreach ($settings as $setting) {
              $settingMap[$setting['setting_key']] = $setting['setting_value'];
          }
          $uploadDir = __DIR__ . '/../uploads';
          $mediaBytes = 0;
          if (is_dir($uploadDir)) {
              foreach (scandir($uploadDir) as $file) {
                  if ($file === '.' || $file === '..') {
                      continue;
                  }
                  $path = $uploadDir . '/' . $file;
                  if (is_file($path)) {
                      $mediaBytes += filesize($path);
                  }
              }
          }
          $mediaUsageMb = round($mediaBytes / 1024 / 1024, 2);
        ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h1 class="font-display text-3xl font-bold text-gray-900 mb-6">Admin Dashboard</h1>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-gray-50 rounded-xl p-4"><p class="text-sm text-gray-500">Active Members</p><p class="text-2xl font-semibold text-gray-900"><?= e((string) $activeCount) ?></p></div>
            <div class="bg-gray-50 rounded-xl p-4"><p class="text-sm text-gray-500">Lapsed Members</p><p class="text-2xl font-semibold text-gray-900"><?= e((string) $lapsedCount) ?></p></div>
            <div class="bg-gray-50 rounded-xl p-4"><p class="text-sm text-gray-500">Pending Approvals</p><p class="text-2xl font-semibold text-gray-900"><?= e((string) $pendingCount) ?></p></div>
            <div class="bg-gray-50 rounded-xl p-4"><p class="text-sm text-gray-500">Members Due Soon</p><p class="text-2xl font-semibold text-gray-900"><?= e((string) $dueSoon) ?></p></div>
            <div class="bg-gray-50 rounded-xl p-4"><p class="text-sm text-gray-500">Total Users</p><p class="text-2xl font-semibold text-gray-900"><?= e((string) $totalUsers) ?></p></div>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-4">
              <h2 class="font-display text-xl font-bold text-gray-900">Recent Payments</h2>
              <?php if (function_exists('can_access_path') && can_access_path($user, '/admin/settings/index.php')): ?>
                <a class="text-xs text-blue-600" href="/admin/settings/index.php?section=payments">Stripe settings</a>
              <?php endif; ?>
            </div>
            <ul class="space-y-2 text-sm text-gray-600">
              <?php foreach ($recentPayments as $payment): ?>
                <li><?= e($payment['description']) ?> - $<?= e($payment['amount']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <h2 class="font-display text-xl font-bold text-gray-900 mb-4">Recent Logins</h2>
            <ul class="space-y-2 text-sm text-gray-600">
              <?php foreach ($recentLogins as $login): ?>
                <li><?= e($login['name']) ?> - <?= e($login['created_at']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-4">
              <h2 class="font-display text-xl font-bold text-gray-900">Upcoming Events</h2>
              <?php if (function_exists('can_access_path') && can_access_path($user, '/admin/settings/index.php')): ?>
                <a class="text-xs text-blue-600" href="/admin/settings/index.php?section=events">Event settings</a>
              <?php endif; ?>
            </div>
            <ul class="space-y-2 text-sm text-gray-600">
              <?php foreach ($events as $event): ?>
                <li><?= e($event['title']) ?> - <?= e($event['event_date']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-4">
              <h2 class="font-display text-xl font-bold text-gray-900">Notice Board</h2>
              <?php if (function_exists('can_access_path') && can_access_path($user, '/admin/settings/index.php')): ?>
                <a class="text-xs text-blue-600" href="/admin/settings/index.php?section=notifications">Notification settings</a>
              <?php endif; ?>
            </div>
            <ul class="space-y-2 text-sm text-gray-600">
              <?php foreach ($notices as $notice): ?>
                <li><?= e($notice['title']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <h2 class="font-display text-xl font-bold text-gray-900 mb-4">System Health</h2>
            <p class="text-sm text-gray-600">Last renewal reminder: <?= e($settingMap['last_renewal_reminder_run'] ?? 'N/A') ?></p>
            <p class="text-sm text-gray-600">Last expiry job: <?= e($settingMap['last_expire_run'] ?? 'N/A') ?></p>
            <p class="text-sm text-gray-600">Last daily summary: <?= e($settingMap['last_daily_summary_run'] ?? 'N/A') ?></p>
            <p class="text-sm text-gray-600">Media storage: <?= e((string) $mediaUsageMb) ?> MB</p>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <h2 class="font-display text-xl font-bold text-gray-900 mb-4">Security Snapshot</h2>
            <p class="text-sm text-gray-600">Failed login attempts (24h): <?= e((string) $failedLogins24h) ?></p>
            <p class="text-sm text-gray-600">New admin devices (24h): <?= e((string) $newAdminDevices24h) ?></p>
            <p class="text-sm text-gray-600">Last baseline approval: <?= e($fimRow['approved_at'] ?? 'Never') ?></p>
            <p class="text-sm text-gray-600">Last integrity scan: <?= e($fimRow['last_scan_at'] ?? 'Never') ?> (<?= e($fimRow['last_scan_status'] ?? 'N/A') ?>)</p>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <h2 class="font-display text-xl font-bold text-gray-900 mb-4">Recent Security Events</h2>
            <ul class="space-y-2 text-sm text-gray-600">
              <?php foreach ($recentSecurityEvents as $event): ?>
                <li><?= e($event['action']) ?> - <?= e($event['created_at']) ?></li>
              <?php endforeach; ?>
              <?php if (empty($recentSecurityEvents)): ?>
                <li>No recent security events.</li>
              <?php endif; ?>
            </ul>
          </div>
        </section>
      <?php elseif ($page === 'members'): ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm text-sm">
          <h1 class="font-display text-2xl font-bold text-gray-900 mb-3">Members module is now here</h1>
          <p class="text-gray-600 mb-4">
            The legacy members section has moved to <a class="font-semibold text-primary" href="/admin/members">/admin/members</a>.
            Please use the new Members area for directory management, orders, refunds, and reporting.
          </p>
          <div class="flex flex-wrap gap-2">
            <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700" href="/admin/members">Open Members area</a>
          </div>
        </section>
      <?php elseif ($page === 'applications'): ?>
        <?php
          $statusFilter = strtoupper($_GET['status'] ?? 'PENDING');
          $allowedStatuses = ['PENDING', 'APPROVED', 'REJECTED'];
          if (!in_array($statusFilter, $allowedStatuses, true)) {
              $statusFilter = 'PENDING';
          }
          $statusCounts = ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
          $statusRows = $pdo->query('SELECT status, COUNT(*) as total FROM membership_applications GROUP BY status')->fetchAll();
          foreach ($statusRows as $row) {
              $status = strtoupper($row['status']);
              if (isset($statusCounts[$status])) {
                  $statusCounts[$status] = (int) $row['total'];
              }
          }
          $stmt = $pdo->prepare('SELECT a.*, m.first_name, m.last_name, m.email, m.phone, m.chapter_id, c.name as chapter_name, c.state as chapter_state FROM membership_applications a JOIN members m ON m.id = a.member_id LEFT JOIN chapters c ON c.id = m.chapter_id WHERE a.status = :status ORDER BY a.created_at ASC');
          $stmt->execute(['status' => $statusFilter]);
          $applications = $stmt->fetchAll();
          $chapters = ChapterRepository::listForSelection($pdo, false);
          $applicationRows = [];
          foreach ($applications as $application) {
              $notes = json_decode($application['notes'] ?? '', true);
              if (!is_array($notes)) {
                  $notes = [];
              }
              $application['notes_data'] = $notes;
              $applicationRows[] = [
                  'application' => $application,
                  'display_name' => trim($application['first_name'] . ' ' . $application['last_name']),
                  'display_type' => $application['member_type'],
                  'linked_label' => null,
                  'display_email' => $application['email'] ?? '',
              ];
              $associateSelected = $notes['membership']['associate_selected'] ?? false;
              $associateAdd = $notes['membership']['associate_add'] ?? '';
              $associate = $notes['associate'] ?? [];
              $associateName = trim(($associate['first_name'] ?? '') . ' ' . ($associate['last_name'] ?? ''));
              if ($associateSelected && $associateAdd === 'yes' && $associateName !== '') {
                  $applicationRows[] = [
                      'application' => $application,
                      'display_name' => $associateName,
                      'display_type' => 'ASSOCIATE',
                      'linked_label' => 'Linked to application #' . (string) $application['id'],
                      'display_email' => $associate['email'] ?? '',
                  ];
              }
          }
          $statusLabels = [
              'PENDING' => ['label' => 'Pending', 'classes' => 'bg-amber-100 text-amber-800'],
              'APPROVED' => ['label' => 'Approved', 'classes' => 'bg-emerald-100 text-emerald-800'],
              'REJECTED' => ['label' => 'Rejected', 'classes' => 'bg-rose-100 text-rose-800'],
          ];
        ?>
        <section class="grid gap-4 sm:grid-cols-3">
          <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Pending</p>
              <p class="text-2xl font-semibold text-gray-900"><?= e((string) $statusCounts['PENDING']) ?></p>
            </div>
            <div class="h-10 w-10 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center">
              <span class="material-icons-outlined text-base">hourglass_top</span>
            </div>
          </div>
          <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Approved</p>
              <p class="text-2xl font-semibold text-gray-900"><?= e((string) $statusCounts['APPROVED']) ?></p>
            </div>
            <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center">
              <span class="material-icons-outlined text-base">check_circle</span>
            </div>
          </div>
          <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
              <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Rejected</p>
              <p class="text-2xl font-semibold text-gray-900"><?= e((string) $statusCounts['REJECTED']) ?></p>
            </div>
            <div class="h-10 w-10 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center">
              <span class="material-icons-outlined text-base">block</span>
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="border-b border-gray-100 px-5 py-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
              <h1 class="font-display text-2xl font-bold text-gray-900">Applications</h1>
              <p class="text-sm text-gray-500">Review and action membership applications.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <?php foreach ($statusLabels as $statusKey => $statusMeta): ?>
                <a class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-xs font-semibold <?= $statusFilter === $statusKey ? 'border-gray-900 text-gray-900' : 'border-gray-200 text-gray-600 hover:border-gray-300' ?>" href="/admin/index.php?page=applications&status=<?= strtolower($statusKey) ?>">
                  <?= e($statusMeta['label']) ?>
                  <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600"><?= e((string) $statusCounts[$statusKey]) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-gray-500 border-b">
                <tr>
                  <th class="py-3 px-5">Name</th>
                  <th class="py-3 pr-4">Type</th>
                  <th class="py-3 pr-4">Submitted</th>
                  <th class="py-3 pr-4">Status</th>
                  <th class="py-3 pr-4">Chapter</th>
                  <th class="py-3 pr-4">Assign Chapter</th>
                  <th class="py-3 pr-4">Approve</th>
                  <th class="py-3 pr-4">Reject</th>
                  <th class="py-3 pr-4">Resend Email</th>
                  <th class="py-3 pr-5">Details</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($applicationRows as $row): ?>
                  <?php
                    $application = $row['application'];
                    $statusMeta = $statusLabels[$application['status']] ?? ['label' => $application['status'], 'classes' => 'bg-slate-100 text-slate-800'];
                    $canApprove = $application['status'] !== 'APPROVED';
                    $canReject = $application['status'] === 'PENDING';
                    $requestedChapterName = '';
                    $requestedChapterId = $application['notes_data']['requested_chapter_id'] ?? null;
                    if ($requestedChapterId) {
                        foreach ($chapters as $chapter) {
                            if ((int) $chapter['id'] === (int) $requestedChapterId) {
                                $requestedChapterName = $chapter['name'];
                                break;
                            }
                        }
                    }
                  ?>
                  <tr>
                    <td class="py-4 px-5 text-gray-900">
                      <div class="font-semibold"><?= e($row['display_name']) ?></div>
                      <?php if ($row['linked_label']): ?>
                        <div class="text-xs text-gray-500"><?= e($row['linked_label']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($row['display_email'])): ?>
                        <div class="text-xs text-gray-500"><?= e($row['display_email']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="py-4 pr-4 text-gray-600"><?= e($row['display_type']) ?></td>
                    <td class="py-4 pr-4 text-gray-600"><?= e($application['created_at']) ?></td>
                    <td class="py-4 pr-4">
                      <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusMeta['classes'] ?>">
                        <?= e($statusMeta['label']) ?>
                      </span>
                    </td>
                    <td class="py-4 pr-4 text-gray-600">
                      <?= e($application['chapter_name'] ? $application['chapter_name'] . ' (' . $application['chapter_state'] . ')' : 'Unassigned') ?>
                      <?php if ($requestedChapterName): ?>
                        <div class="text-xs text-gray-500">Requested: <?= e($requestedChapterName) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="py-4 pr-4">
                      <form method="post" class="flex flex-col gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                        <input type="hidden" name="member_id" value="<?= e((string) $application['member_id']) ?>">
                        <select name="assign_chapter_id" class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-700">
                          <option value="">Assign chapter</option>
                          <?php foreach ($chapters as $chapter): ?>
                            <option value="<?= e((string) $chapter['id']) ?>" <?= (int) $chapter['id'] === (int) $application['chapter_id'] ? 'selected' : '' ?>><?= e($chapter['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="inline-flex items-center justify-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-gray-300" type="submit">Assign</button>
                      </form>
                    </td>
                    <td class="py-4 pr-4">
                      <?php if ($canApprove): ?>
                        <button type="button" class="inline-flex items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 h-9 w-9 hover:bg-emerald-100" data-approve-open data-app-id="<?= e((string) $application['id']) ?>" data-app-name="<?= e($row['display_name']) ?>">
                          <span class="material-icons-outlined text-base">check</span>
                        </button>
                      <?php else: ?>
                        <span class="inline-flex items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 h-9 w-9 opacity-50 cursor-not-allowed" title="Already approved">
                          <span class="material-icons-outlined text-base">check</span>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="py-4 pr-4">
                      <?php if ($canReject): ?>
                        <button type="button" class="inline-flex items-center justify-center rounded-full border border-rose-200 bg-rose-50 text-rose-700 h-9 w-9 hover:bg-rose-100" data-reject-open data-app-id="<?= e((string) $application['id']) ?>" data-app-name="<?= e($row['display_name']) ?>">
                          <span class="material-icons-outlined text-base">close</span>
                        </button>
                      <?php else: ?>
                        <span class="inline-flex items-center justify-center rounded-full border border-rose-200 bg-rose-50 text-rose-700 h-9 w-9 opacity-50 cursor-not-allowed" title="Cannot reject">
                          <span class="material-icons-outlined text-base">close</span>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="py-4 pr-4">
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                        <input type="hidden" name="resend_approval_id" value="<?= e((string) $application['id']) ?>">
                        <button class="inline-flex items-center justify-center rounded-full border border-blue-200 bg-blue-50 text-blue-700 h-9 w-9 hover:bg-blue-100 <?= $application['status'] !== 'APPROVED' ? 'opacity-50 cursor-not-allowed' : '' ?>" type="submit" <?= $application['status'] !== 'APPROVED' ? 'disabled' : '' ?> title="Resend approval email">
                          <span class="material-icons-outlined text-base">forward_to_inbox</span>
                        </button>
                      </form>
                    </td>
                    <td class="py-4 pr-5">
                      <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/applications/view.php?id=<?= e((string) $application['id']) ?>&status=<?= strtolower($statusFilter) ?>">
                        <span class="material-icons-outlined text-base">visibility</span>
                        View
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <dialog id="approve-dialog" class="rounded-2xl border border-gray-200 shadow-xl p-0 w-full max-w-lg">
          <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="approve_id" id="approve-id">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Approve application</h2>
              <p class="text-sm text-gray-600">You're about to approve <span class="font-semibold" id="approve-name"></span>. Do you accept?</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button class="inline-flex items-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-gray-900" type="submit">Yes, approve</button>
              <button class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700" type="button" data-dialog-close>Cancel</button>
            </div>
          </form>
        </dialog>

        <dialog id="reject-dialog" class="rounded-2xl border border-gray-200 shadow-xl p-0 w-full max-w-lg">
          <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="reject_id" id="reject-id">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Reject application</h2>
              <p class="text-sm text-gray-600">Provide a one-line reason for rejecting <span class="font-semibold" id="reject-name"></span>.</p>
            </div>
            <input type="text" name="reason" maxlength="255" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-primary focus:ring-2 focus:ring-primary/30" placeholder="Reason for rejection">
            <div class="flex flex-wrap gap-2">
              <button class="inline-flex items-center rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white" type="submit">Reject</button>
              <button class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700" type="button" data-dialog-close>Cancel</button>
            </div>
          </form>
        </dialog>

        <script>
          (() => {
            const approveDialog = document.getElementById('approve-dialog');
            const rejectDialog = document.getElementById('reject-dialog');
            const approveId = document.getElementById('approve-id');
            const rejectId = document.getElementById('reject-id');
            const approveName = document.getElementById('approve-name');
            const rejectName = document.getElementById('reject-name');

            if (!approveDialog || !rejectDialog || typeof approveDialog.showModal !== 'function') {
              return;
            }

            document.querySelectorAll('[data-approve-open]').forEach((button) => {
              button.addEventListener('click', () => {
                approveId.value = button.dataset.appId || '';
                approveName.textContent = button.dataset.appName || 'this applicant';
                approveDialog.showModal();
              });
            });

            document.querySelectorAll('[data-reject-open]').forEach((button) => {
              button.addEventListener('click', () => {
                rejectId.value = button.dataset.appId || '';
                rejectName.textContent = button.dataset.appName || 'this applicant';
                rejectDialog.showModal();
              });
            });

            document.querySelectorAll('[data-dialog-close]').forEach((button) => {
              button.addEventListener('click', (event) => {
                event.preventDefault();
                approveDialog.close();
                rejectDialog.close();
              });
            });
          })();
        </script>
      <?php elseif ($page === 'payments'): ?>
        <?php
          $channel = PaymentSettingsService::getChannelByCode('primary');
          $paymentSettings = PaymentSettingsService::getSettingsByChannelId((int) $channel['id']);
          $stripeSettings = StripeSettingsService::getSettings();
          $activeKeys = StripeSettingsService::getActiveKeys();
          $webhookHealth = StripeSettingsService::webhookHealth($paymentSettings);
          $isConnected = !empty($activeKeys['secret_key']) && !empty($activeKeys['publishable_key']);
          $canRefund = AdminMemberAccess::canRefund($user);
          $orders = $pdo->query('SELECT o.*, u.name, u.email FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC LIMIT 50')->fetchAll();
          $webhookEvents = $pdo->query('SELECT * FROM webhook_events ORDER BY received_at DESC LIMIT 50')->fetchAll();
        ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-6">
          <h1 class="font-display text-2xl font-bold text-gray-900">Payments & Settings</h1>

          <div class="grid gap-6">
            <div class="rounded-xl border border-gray-100 bg-white p-5">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Stripe Connection (Primary)</h2>
                  <p class="mt-1 text-xs text-slate-500">Settings are managed in the Settings Hub.</p>
                </div>
                <?php if (function_exists('can_access_path') && can_access_path($user, '/admin/settings/index.php')): ?>
                  <a class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-slate-700" href="/admin/settings/index.php?section=payments">Go to Stripe Settings</a>
                <?php endif; ?>
              </div>
              <div class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between">
                  <span class="text-slate-600">Status</span>
                  <span class="<?= $isConnected ? 'text-green-600' : 'text-red-600' ?> font-semibold">
                    <?= $isConnected ? 'Connected' : 'Not connected' ?>
                  </span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-slate-600">Mode</span>
                  <span class="text-slate-800 font-semibold"><?= e($activeKeys['mode'] ?? 'test') ?></span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-slate-600">Publishable key</span>
                  <span class="text-slate-800 font-semibold"><?= !empty($activeKeys['publishable_key']) ? 'Configured' : 'Not set' ?></span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-slate-600">Secret key</span>
                  <span class="text-slate-800 font-semibold"><?= !empty($activeKeys['secret_key']) ? 'Configured' : 'Not set' ?></span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-slate-600">Webhook secret</span>
                  <span class="text-slate-800 font-semibold"><?= !empty($stripeSettings['webhook_secret']) ? 'Configured' : 'Not set' ?></span>
                </div>
                <div class="pt-2 text-xs text-slate-500">
                  Last webhook: <?= e($webhookHealth['last_received_at'] ?? 'Never') ?><br>
                  Last error: <?= e($webhookHealth['last_error'] ?? 'None') ?>
                </div>
              </div>
            </div>

            <div class="rounded-xl border border-gray-100 bg-white p-5 space-y-3 text-sm text-slate-600">
              <div class="flex items-center justify-between">
                <span>Invoice prefix</span>
                <span class="text-slate-900 font-semibold"><?= e($paymentSettings['invoice_prefix'] ?? 'INV') ?></span>
              </div>
              <div class="flex items-center justify-between">
                <span>PDF invoices</span>
                <span class="text-slate-900 font-semibold"><?= !empty($paymentSettings['generate_pdf']) ? 'Enabled' : 'Disabled' ?></span>
              </div>
              <div class="flex items-center justify-between">
                <span>Refund permissions</span>
                <span class="text-slate-900 font-semibold">Admin, Treasurer, Super Admin</span>
              </div>
              <div class="pt-2">
                <?php if (function_exists('can_access_path') && can_access_path($user, '/admin/settings/index.php')): ?>
                  <a class="text-sm text-blue-600" href="/admin/settings/index.php?section=store">Go to Store Settings</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>

        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="font-display text-xl font-bold text-gray-900">Recent Orders</h2>
            <span class="text-xs text-slate-500">Refunds are full only.</span>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-gray-500 border-b">
                <tr>
                  <th class="py-2 pr-3">Date</th>
                  <th class="py-2 pr-3">Order</th>
                  <th class="py-2 pr-3">Customer</th>
                  <th class="py-2 pr-3">Type</th>
                  <th class="py-2 pr-3">Total</th>
                  <th class="py-2 pr-3">Status</th>
                  <th class="py-2">Refund</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($orders as $order): ?>
                  <?php
                    $orderNumber = $order['order_number'] ?? ('ORD-' . $order['id']);
                    $memberOrderUrl = null;
                    if (!empty($order['member_id']) && ($order['order_type'] ?? '') === 'membership') {
                        $memberOrderUrl = '/admin/members/view.php?id=' . urlencode((string) $order['member_id'])
                            . '&tab=orders&orders_section=membership&order_id=' . urlencode((string) $order['id']);
                    }
                  ?>
                  <tr>
                    <td class="py-2 pr-3 text-gray-600"><?= e($order['created_at']) ?></td>
                    <td class="py-2 pr-3 text-gray-600">
                      <?php if ($memberOrderUrl): ?>
                        <a class="text-xs font-semibold text-blue-600 underline" href="<?= e($memberOrderUrl) ?>">#<?= e($orderNumber) ?></a>
                      <?php else: ?>
                        <span class="text-xs font-semibold text-gray-600">#<?= e($orderNumber) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="py-2 pr-3 text-gray-900">
                      <?= e($order['name'] ?? 'Member') ?><br>
                      <span class="text-xs text-slate-500"><?= e($order['email'] ?? '') ?></span>
                    </td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($order['order_type']) ?></td>
                    <td class="py-2 pr-3 text-gray-900">A$<?= e(number_format((float) $order['total'], 2)) ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($order['status']) ?></td>
                    <td class="py-2">
                      <?php if ($canRefund && $order['status'] === 'paid'): ?>
                        <form data-refund-form method="post" class="flex items-center gap-2">
                          <input type="hidden" name="order_id" value="<?= e((string) $order['id']) ?>">
                          <input type="text" name="reason" placeholder="Reason (optional)" class="text-xs rounded border border-gray-200 px-2 py-1">
                          <button class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold" type="submit">Refund</button>
                        </form>
                      <?php else: ?>
                        <span class="text-xs text-gray-400">Restricted</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                  <tr>
                    <td colspan="6" class="py-4 text-center text-gray-500">No orders found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
          <h2 class="font-display text-xl font-bold text-gray-900">Payments Debug</h2>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-gray-500 border-b">
                <tr>
                  <th class="py-2 pr-3">Received</th>
                  <th class="py-2 pr-3">Event</th>
                  <th class="py-2 pr-3">Type</th>
                  <th class="py-2 pr-3">Status</th>
                  <th class="py-2">Error</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($webhookEvents as $event): ?>
                  <tr>
                    <td class="py-2 pr-3 text-gray-600"><?= e($event['received_at']) ?></td>
                    <td class="py-2 pr-3 text-gray-900"><?= e($event['stripe_event_id']) ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($event['type']) ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($event['processed_status']) ?></td>
                    <td class="py-2 text-gray-600"><?= e($event['error'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$webhookEvents): ?>
                  <tr>
                    <td colspan="5" class="py-4 text-center text-gray-500">No webhook events yet.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <script>
          document.querySelectorAll('[data-refund-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
              event.preventDefault();
              const formData = new FormData(form);
              const payload = {
                order_id: formData.get('order_id'),
                reason: formData.get('reason') || null,
                csrf_token: '<?= e(Csrf::token()) ?>',
              };
              const response = await fetch('/api/admin/refunds/create', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload),
              });
              const data = await response.json();
              if (data.error) {
                alert(data.error);
                return;
              }
              window.location.reload();
            });
          });
        </script>
      <?php elseif ($page === 'events'): ?>
        <?php $events = $pdo->query('SELECT * FROM events ORDER BY event_date DESC')->fetchAll(); ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
          <h1 class="font-display text-2xl font-bold text-gray-900">Events Management</h1>
          <?php foreach ($events as $event): ?>
            <div class="border border-gray-100 rounded-xl p-4">
              <h3 class="text-lg font-semibold text-gray-900"><?= e($event['title']) ?></h3>
              <p class="text-sm text-gray-500 mb-3"><?= e($event['event_date']) ?> - <?= e($event['location']) ?></p>
              <form method="post" class="space-y-2">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="event_id" value="<?= e((string) $event['id']) ?>">
                <textarea name="description" rows="4" class="w-full"><?= e($event['description']) ?></textarea>
                <button class="inline-flex items-center px-3 py-1.5 rounded-lg bg-primary text-gray-900 text-xs font-semibold" type="submit">Save</button>
              </form>
            </div>
          <?php endforeach; ?>
        </section>
      <?php elseif ($page === 'notices'): ?>
        <?php
          $noticeHasPublishedAt = (bool) $pdo->query("SHOW COLUMNS FROM notices LIKE 'published_at'")->fetch();
          $noticeHasAudience = (bool) $pdo->query("SHOW COLUMNS FROM notices LIKE 'audience_scope'")->fetch();

          $australianStates = [
              ['code' => 'ACT', 'label' => 'Australian Capital Territory'],
              ['code' => 'NSW', 'label' => 'New South Wales'],
              ['code' => 'NT', 'label' => 'Northern Territory'],
              ['code' => 'QLD', 'label' => 'Queensland'],
              ['code' => 'SA', 'label' => 'South Australia'],
              ['code' => 'TAS', 'label' => 'Tasmania'],
              ['code' => 'VIC', 'label' => 'Victoria'],
              ['code' => 'WA', 'label' => 'Western Australia'],
          ];
          $noticeStates = $australianStates;
          $noticeChapters = $pdo->query('SELECT id, name, state FROM chapters WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
          $noticeFormState = $_POST['notice_audience_state'] ?? '';

          if ($noticeHasPublishedAt) {
              $pendingNotices = $pdo->query('SELECT n.*, u.name AS created_by_name, u.email AS created_by_email FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.visibility IN ("member", "public") AND n.published_at IS NULL ORDER BY n.created_at DESC')->fetchAll();
              $liveNotices = $pdo->query('SELECT n.*, u.name AS created_by_name, u.email AS created_by_email FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.visibility IN ("member", "public") AND n.published_at IS NOT NULL ORDER BY n.published_at DESC, n.created_at DESC')->fetchAll();
          } else {
              $pendingNotices = [];
              $liveNotices = $pdo->query('SELECT n.*, u.name AS created_by_name, u.email AS created_by_email FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.visibility IN ("member", "public") ORDER BY n.created_at DESC')->fetchAll();
          }
        ?>
        <section class="space-y-6">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl font-bold text-gray-900">Notice Board Management</h1>
              <p class="text-sm text-gray-500">Publish new notices or review pending requests.</p>
            </div>
            <div class="inline-flex items-center rounded-lg border border-gray-200 bg-white p-1 text-sm">
              <button type="button" data-notice-tab="live" class="px-3 py-1.5 rounded-md font-semibold text-gray-700">Live notices</button>
              <button type="button" data-notice-tab="pending" class="px-3 py-1.5 rounded-md font-semibold text-gray-700">Pending requests</button>
            </div>
          </div>

          <?php if (!$noticeHasPublishedAt): ?>
            <div class="rounded-lg bg-amber-50 text-amber-700 px-4 py-2 text-sm">
              Notice approval requires the new notices schema (published_at). Until then, all notices are live immediately.
            </div>
          <?php endif; ?>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-amber-100 rounded-lg text-amber-600">
                  <span class="material-icons-outlined">edit_square</span>
                </div>
                <div>
                  <h2 class="font-display text-xl font-bold text-gray-900">Create Notice</h2>
                  <p class="text-sm text-gray-500">Admin notices publish instantly.</p>
                </div>
              </div>
              <form method="post" class="space-y-4" id="notice-create-form">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_notice_admin">
                <input type="hidden" name="notice_content" id="notice-content-input">
                <input type="hidden" name="notice_attachment_url" id="notice-attachment-url">
                <input type="hidden" name="notice_attachment_type" id="notice-attachment-type">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <label class="text-sm font-medium text-gray-700">Title
                    <input type="text" name="notice_title" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
                  </label>
                  <label class="text-sm font-medium text-gray-700">Category
                    <select name="notice_category" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <option value="notice">Notice</option>
                      <option value="advert">Advert</option>
                      <option value="announcement">Important Announcement</option>
                    </select>
                  </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <label class="text-sm font-medium text-gray-700">Audience
                    <select name="notice_audience_scope" id="notice-audience-scope" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <option value="all">All members</option>
                      <option value="state">State</option>
                      <option value="chapter">Chapter</option>
                    </select>
                  </label>
                  <label class="text-sm font-medium text-gray-700">State
                    <select name="notice_audience_state" id="notice-audience-state" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" disabled>
                      <option value="">Select state</option>
                      <?php foreach ($noticeStates as $state): ?>
                        <option value="<?= e($state['code']) ?>" <?= $noticeFormState === $state['code'] ? 'selected' : '' ?>><?= e($state['label']) ?> (<?= e($state['code']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="text-sm font-medium text-gray-700">Chapter
                    <select name="notice_audience_chapter" id="notice-audience-chapter" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" disabled>
                      <option value="">Select chapter</option>
                      <?php foreach ($noticeChapters as $chapter): ?>
                        <option value="<?= e((string) $chapter['id']) ?>"><?= e($chapter['name']) ?><?= !empty($chapter['state']) ? ' (' . e($chapter['state']) . ')' : '' ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <div>
                  <p class="text-sm font-medium text-gray-700 mb-2">Description</p>
                  <div class="flex flex-wrap gap-2 border border-gray-200 rounded-lg bg-gray-50 px-3 py-2 text-xs" id="notice-toolbar">
                    <select id="notice-font" class="rounded border border-gray-200 bg-white px-2 py-1 text-xs">
                      <option value="">Font</option>
                      <option value="Georgia, serif">Georgia</option>
                      <option value="Times New Roman, serif">Times</option>
                      <option value="Arial, sans-serif">Arial</option>
                      <option value="Verdana, sans-serif">Verdana</option>
                    </select>
                    <select id="notice-size" class="rounded border border-gray-200 bg-white px-2 py-1 text-xs">
                      <option value="2">Small</option>
                      <option value="3" selected>Normal</option>
                      <option value="4">Large</option>
                      <option value="5">XL</option>
                    </select>
                    <input type="color" id="notice-color" class="h-7 w-8 border border-gray-200 rounded">
                    <button type="button" data-command="bold" class="rounded border border-gray-200 bg-white px-2 py-1">Bold</button>
                    <button type="button" data-command="italic" class="rounded border border-gray-200 bg-white px-2 py-1">Italic</button>
                    <button type="button" data-command="underline" class="rounded border border-gray-200 bg-white px-2 py-1">Underline</button>
                    <button type="button" data-command="insertUnorderedList" class="rounded border border-gray-200 bg-white px-2 py-1">List</button>
                    <button type="button" id="notice-link" class="rounded border border-gray-200 bg-white px-2 py-1">Link</button>
                  </div>
                  <div id="notice-editor" class="mt-2 min-h-[160px] rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:outline-none" contenteditable="true"></div>
                </div>
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-4" id="notice-upload-zone">
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <p class="text-sm font-semibold text-gray-700">Attachment (image or PDF)</p>
                      <p class="text-xs text-gray-500">Drag & drop or browse to upload.</p>
                    </div>
                    <input type="file" id="notice-attachment-input" accept="image/*,application/pdf" class="text-xs">
                  </div>
                  <div id="notice-attachment-preview" class="mt-3 hidden"></div>
                </div>
                <button class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Publish Notice</button>
              </form>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                  <span class="material-icons-outlined">info</span>
                </div>
                <div>
                  <h3 class="font-display text-lg font-bold text-gray-900">Review Tips</h3>
                  <p class="text-sm text-gray-500">Pending requests show who submitted them.</p>
                </div>
              </div>
              <ul class="text-sm text-gray-600 space-y-2">
                <li>Approving a notice sends it live immediately.</li>
                <li>Rejected notices will notify the member with a reason.</li>
                <li>Weekly digest includes new approved notices.</li>
              </ul>
            </div>
          </div>

          <div id="notice-live-tab" class="space-y-4">
            <div class="flex items-center justify-between">
              <h3 class="font-display text-xl font-bold text-gray-900">Live Notices</h3>
              <div class="inline-flex items-center rounded-lg border border-gray-200 bg-white p-1 text-sm">
                <button type="button" data-notice-view="list" class="px-3 py-1.5 rounded-md font-semibold text-gray-700">List view</button>
                <button type="button" data-notice-view="grid" class="px-3 py-1.5 rounded-md font-semibold text-gray-700">Grid view</button>
              </div>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <?php if ($liveNotices): ?>
                <div id="notice-list" class="space-y-4">
                  <?php foreach ($liveNotices as $notice): ?>
                    <?php
                      $category = $notice['category'] ?? 'notice';
                      $categoryLabel = $category === 'announcement' ? 'Important Announcement' : ucfirst($category);
                    ?>
                    <article class="border border-gray-100 rounded-2xl p-5 bg-white">
                      <div class="flex items-center gap-3 mb-3">
                        <div class="flex-1">
                          <p class="text-lg font-semibold text-gray-900"><?= e($notice['title']) ?></p>
                          <p class="text-xs text-gray-500"><?= e($categoryLabel) ?> • <?= e($notice['created_by_name'] ?? 'Member') ?></p>
                        </div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?= e(format_date_au($notice['published_at'] ?? $notice['created_at'])) ?></span>
                      </div>
                      <?php if (!empty($notice['attachment_url'])): ?>
                        <div class="mb-3 rounded-xl border border-gray-100 overflow-hidden">
                          <?php if (($notice['attachment_type'] ?? '') === 'pdf'): ?>
                            <object data="<?= e($notice['attachment_url']) ?>#page=1&zoom=page-fit" type="application/pdf" class="w-full h-72">
                              <div class="p-4 text-sm text-gray-500">PDF attached. <a class="text-secondary font-semibold" href="<?= e($notice['attachment_url']) ?>">Open</a></div>
                            </object>
                          <?php else: ?>
                            <img src="<?= e($notice['attachment_url']) ?>" alt="<?= e($notice['title']) ?>" class="w-full h-72 object-cover">
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <div class="prose prose-sm text-gray-600"><?= render_media_shortcodes($notice['content']) ?></div>
                    </article>
                  <?php endforeach; ?>
                </div>
                <div id="notice-grid" class="hidden grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                  <?php foreach ($liveNotices as $notice): ?>
                    <?php
                      $category = $notice['category'] ?? 'notice';
                      $categoryLabel = $category === 'announcement' ? 'Important Announcement' : ucfirst($category);
                    ?>
                    <article class="bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
                      <div class="relative aspect-[210/297] bg-gray-50">
                        <?php if (!empty($notice['attachment_url'])): ?>
                          <?php if (($notice['attachment_type'] ?? '') === 'pdf'): ?>
                            <object data="<?= e($notice['attachment_url']) ?>#page=1&zoom=page-fit" type="application/pdf" class="w-full h-full">
                              <div class="absolute inset-0 flex items-center justify-center text-gray-400 text-sm">PDF</div>
                            </object>
                          <?php else: ?>
                            <img src="<?= e($notice['attachment_url']) ?>" alt="<?= e($notice['title']) ?>" class="w-full h-full object-cover">
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="h-full w-full flex items-center justify-center text-gray-300">
                            <span class="material-icons-outlined text-5xl">campaign</span>
                          </div>
                        <?php endif; ?>
                        <span class="absolute top-3 left-3 inline-flex items-center rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-gray-700"><?= e($categoryLabel) ?></span>
                      </div>
                      <div class="p-4 space-y-2">
                        <h4 class="text-base font-semibold text-gray-900"><?= e($notice['title']) ?></h4>
                    <p class="text-xs text-gray-500"><?= e(format_date_au($notice['published_at'] ?? $notice['created_at'])) ?></p>
                        <div class="prose prose-sm text-gray-600"><?= render_media_shortcodes($notice['content']) ?></div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="text-sm text-gray-500">No live notices.</p>
              <?php endif; ?>
            </div>
          </div>

          <div id="notice-pending-tab" class="space-y-4 hidden">
            <h3 class="font-display text-xl font-bold text-gray-900">Pending Requests</h3>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <?php if ($pendingNotices): ?>
                <?php foreach ($pendingNotices as $notice): ?>
                  <?php
                    $category = $notice['category'] ?? 'notice';
                    $categoryLabel = $category === 'announcement' ? 'Important Announcement' : ucfirst($category);
                  ?>
                  <div class="border border-gray-100 rounded-xl p-4 space-y-3">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                      <div>
                        <h4 class="text-lg font-semibold text-gray-900"><?= e($notice['title']) ?></h4>
                        <p class="text-xs text-gray-500"><?= e($categoryLabel) ?> • Requested by <?= e($notice['created_by_name'] ?? 'Member') ?><?= !empty($notice['created_by_email']) ? ' (' . e($notice['created_by_email']) . ')' : '' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Submitted <?= e(format_date_au($notice['created_at'])) ?></p>
                      </div>
                      <div class="flex items-center gap-2">
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                          <input type="hidden" name="action" value="approve_notice">
                          <input type="hidden" name="notice_id" value="<?= e((string) $notice['id']) ?>">
                          <button class="inline-flex items-center px-3 py-1.5 rounded-lg bg-primary text-gray-900 text-xs font-semibold" type="submit">Approve</button>
                        </form>
                      </div>
                    </div>
                    <div class="prose prose-sm text-gray-600"><?= render_media_shortcodes($notice['content']) ?></div>
                    <form method="post" class="space-y-2">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="reject_notice">
                      <input type="hidden" name="notice_id" value="<?= e((string) $notice['id']) ?>">
                      <label class="text-xs font-semibold text-gray-600">Rejection reason
                        <textarea name="reject_reason" rows="2" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required></textarea>
                      </label>
                      <button class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700" type="submit">Reject with reason</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="text-sm text-gray-500">No pending notices.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>
        <script>
          (() => {
            const noticeEditor = document.getElementById('notice-editor');
            const noticeContentInput = document.getElementById('notice-content-input');
            const noticeForm = document.getElementById('notice-create-form');
            const noticeToolbar = document.getElementById('notice-toolbar');
            const fontSelect = document.getElementById('notice-font');
            const sizeSelect = document.getElementById('notice-size');
            const colorPicker = document.getElementById('notice-color');
            const linkBtn = document.getElementById('notice-link');
            const audienceScope = document.getElementById('notice-audience-scope');
            const audienceState = document.getElementById('notice-audience-state');
            const audienceChapter = document.getElementById('notice-audience-chapter');
            const noticeUploadInput = document.getElementById('notice-attachment-input');
            const noticeUploadZone = document.getElementById('notice-upload-zone');
            const noticeAttachmentUrl = document.getElementById('notice-attachment-url');
            const noticeAttachmentType = document.getElementById('notice-attachment-type');
            const noticeAttachmentPreview = document.getElementById('notice-attachment-preview');
            const csrfToken = '<?= e(Csrf::token()) ?>';

            const updateAudienceFields = () => {
              if (!audienceScope) {
                return;
              }
              const scope = audienceScope.value;
              if (audienceState) {
                audienceState.disabled = scope !== 'state';
              }
              if (audienceChapter) {
                audienceChapter.disabled = scope !== 'chapter';
              }
            };

            if (audienceScope) {
              audienceScope.addEventListener('change', updateAudienceFields);
              updateAudienceFields();
            }

            if (noticeToolbar) {
              noticeToolbar.addEventListener('click', (event) => {
                const button = event.target.closest('[data-command]');
                if (!button) {
                  return;
                }
                document.execCommand(button.dataset.command, false, null);
              });
            }
            if (fontSelect) {
              fontSelect.addEventListener('change', () => {
                if (fontSelect.value) {
                  document.execCommand('fontName', false, fontSelect.value);
                }
              });
            }
            if (sizeSelect) {
              sizeSelect.addEventListener('change', () => {
                document.execCommand('fontSize', false, sizeSelect.value);
              });
            }
            if (colorPicker) {
              colorPicker.addEventListener('input', () => {
                document.execCommand('foreColor', false, colorPicker.value);
              });
            }
            if (linkBtn) {
              linkBtn.addEventListener('click', () => {
                const url = prompt('Enter URL');
                if (url) {
                  document.execCommand('createLink', false, url);
                }
              });
            }
            if (noticeForm && noticeContentInput && noticeEditor) {
              noticeForm.addEventListener('submit', () => {
                noticeContentInput.value = noticeEditor.innerHTML.trim();
              });
            }

            const uploadFile = async (file, context) => {
              const formData = new FormData();
              formData.append('file', file);
              formData.append('context', context);
              const response = await fetch('/api/uploads/image', {
                method: 'POST',
                headers: {'X-CSRF-Token': csrfToken},
                body: formData,
              });
              return response.json();
            };

            const setNoticeAttachmentPreview = (file, result) => {
              if (!noticeAttachmentPreview) {
                return;
              }
              noticeAttachmentPreview.classList.remove('hidden');
              if (file.type === 'application/pdf') {
                noticeAttachmentPreview.innerHTML = `<div class="flex items-center gap-2 text-gray-600"><span class="material-icons-outlined text-base">picture_as_pdf</span>${file.name}</div>`;
              } else {
                noticeAttachmentPreview.innerHTML = `<img src="${result.url}" alt="Attachment" class="w-full h-40 object-cover rounded-lg">`;
              }
            };

            const handleNoticeFile = async (file) => {
              if (!file || !noticeAttachmentUrl || !noticeAttachmentType) {
                return;
              }
              const result = await uploadFile(file, 'notices');
              if (!result || result.error) {
                alert(result.error || 'Upload failed.');
                return;
              }
              noticeAttachmentUrl.value = result.url || '';
              noticeAttachmentType.value = result.type || '';
              setNoticeAttachmentPreview(file, result);
            };

            if (noticeUploadInput) {
              noticeUploadInput.addEventListener('change', (event) => {
                const file = event.target.files[0];
                handleNoticeFile(file);
              });
            }
            if (noticeUploadZone) {
              noticeUploadZone.addEventListener('dragover', (event) => {
                event.preventDefault();
                noticeUploadZone.classList.add('border-primary');
              });
              noticeUploadZone.addEventListener('dragleave', () => {
                noticeUploadZone.classList.remove('border-primary');
              });
              noticeUploadZone.addEventListener('drop', (event) => {
                event.preventDefault();
                noticeUploadZone.classList.remove('border-primary');
                const file = event.dataTransfer.files[0];
                handleNoticeFile(file);
              });
            }

            const noticeList = document.getElementById('notice-list');
            const noticeGrid = document.getElementById('notice-grid');
            const viewButtons = document.querySelectorAll('[data-notice-view]');
            const applyNoticeView = (view) => {
              if (!noticeList || !noticeGrid) {
                return;
              }
              noticeList.classList.toggle('hidden', view !== 'list');
              noticeGrid.classList.toggle('hidden', view !== 'grid');
              viewButtons.forEach((btn) => {
                btn.classList.toggle('bg-primary/10', btn.dataset.noticeView === view);
              });
              localStorage.setItem('adminNoticeView', view);
            };
            if (viewButtons.length) {
              viewButtons.forEach((btn) => {
                btn.addEventListener('click', () => applyNoticeView(btn.dataset.noticeView));
              });
              const savedView = localStorage.getItem('adminNoticeView') || 'list';
              applyNoticeView(savedView);
            }

            const liveTab = document.getElementById('notice-live-tab');
            const pendingTab = document.getElementById('notice-pending-tab');
            const tabButtons = document.querySelectorAll('[data-notice-tab]');
            const applyNoticeTab = (tab) => {
              if (!liveTab || !pendingTab) {
                return;
              }
              liveTab.classList.toggle('hidden', tab !== 'live');
              pendingTab.classList.toggle('hidden', tab !== 'pending');
              tabButtons.forEach((btn) => {
                btn.classList.toggle('bg-primary/10', btn.dataset.noticeTab === tab);
              });
              localStorage.setItem('adminNoticeTab', tab);
            };
            if (tabButtons.length) {
              tabButtons.forEach((btn) => {
                btn.addEventListener('click', () => applyNoticeTab(btn.dataset.noticeTab));
              });
              const savedTab = localStorage.getItem('adminNoticeTab') || 'live';
              applyNoticeTab(savedTab);
            }
          })();
        </script>
      <?php elseif ($page === 'fallen-wings'): ?>
        <?php
          $pendingMemorials = [];
          $approvedMemorials = [];
          $fallenTableExists = false;
          try {
              $fallenTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'fallen_wings'")->fetch();
          } catch (PDOException $e) {
              $fallenTableExists = false;
          }
          if ($fallenTableExists) {
              $pendingMemorials = $pdo->query('SELECT * FROM fallen_wings WHERE status = "PENDING" ORDER BY created_at DESC')->fetchAll();
              $approvedMemorials = $pdo->query('SELECT * FROM fallen_wings WHERE status = "APPROVED" ORDER BY full_name ASC')->fetchAll();
          }
        ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-6">
          <div>
            <h1 class="font-display text-2xl font-bold text-gray-900">Fallen Wings Memorials</h1>
            <p class="text-sm text-gray-500">Approve or reject memorial submissions.</p>
          </div>
          <?php if (!$fallenTableExists): ?>
            <div class="rounded-lg bg-amber-50 text-amber-700 px-4 py-2 text-sm">
              Fallen Wings table not found. Run the migration to enable memorial approvals.
            </div>
          <?php endif; ?>
          <div class="space-y-4">
            <h2 class="text-lg font-semibold text-gray-900">Pending submissions</h2>
            <?php if ($pendingMemorials): ?>
              <?php foreach ($pendingMemorials as $entry): ?>
                <div class="border border-gray-100 rounded-xl p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                  <div>
                    <p class="text-base font-semibold text-gray-900"><?= e($entry['full_name']) ?> (<?= e((string) $entry['year_of_passing']) ?>)</p>
                    <?php if (!empty($entry['member_number'])): ?>
                      <p class="text-xs text-gray-500 mt-1">Member #: <?= e($entry['member_number']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($entry['tribute'])): ?>
                      <p class="text-sm text-gray-600 mt-1"><?= e($entry['tribute']) ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="flex items-center gap-2">
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="approve_fallen">
                      <input type="hidden" name="fallen_id" value="<?= e((string) $entry['id']) ?>">
                      <button class="inline-flex items-center px-3 py-1.5 rounded-lg bg-primary text-gray-900 text-xs font-semibold" type="submit">Approve</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="reject_fallen">
                      <input type="hidden" name="fallen_id" value="<?= e((string) $entry['id']) ?>">
                      <button class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700" type="submit">Reject</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="text-sm text-gray-500">No pending submissions.</p>
            <?php endif; ?>
          </div>
          <div class="space-y-4">
            <h2 class="text-lg font-semibold text-gray-900">Approved memorials</h2>
            <?php if ($approvedMemorials): ?>
              <ul class="divide-y">
                <?php foreach ($approvedMemorials as $entry): ?>
                  <li class="py-3 flex items-center justify-between">
                    <div>
                      <p class="text-sm text-gray-800"><?= e($entry['full_name']) ?></p>
                      <?php if (!empty($entry['member_number'])): ?>
                        <p class="text-xs text-gray-500">Member #: <?= e($entry['member_number']) ?></p>
                      <?php endif; ?>
                    </div>
                    <span class="text-xs text-gray-500"><?= e((string) $entry['year_of_passing']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-sm text-gray-500">No approved memorials yet.</p>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif ($page === 'media'): ?>
        <?php
          $media = $pdo->query('SELECT * FROM media ORDER BY created_at DESC')->fetchAll();
          $recentCutoff = strtotime('-7 days');
          $newCount = 0;
          $sharedCount = 0;
          $typeCounts = ['image' => 0, 'video' => 0, 'pdf' => 0, 'file' => 0];
          foreach ($media as $item) {
              $typeKey = strtolower($item['type'] ?? 'file');
              if (!isset($typeCounts[$typeKey])) {
                  $typeKey = 'file';
              }
              $typeCounts[$typeKey] += 1;
              $createdAt = isset($item['created_at']) ? strtotime($item['created_at']) : false;
              if ($createdAt && $createdAt >= $recentCutoff) {
                  $newCount += 1;
              }
              if (($item['visibility'] ?? '') !== 'admin') {
                  $sharedCount += 1;
              }
          }
          $uploadDir = __DIR__ . '/../uploads';
          $mediaBytes = 0;
          if (is_dir($uploadDir)) {
              foreach (scandir($uploadDir) as $file) {
                  if ($file === '.' || $file === '..') {
                      continue;
                  }
                  $path = $uploadDir . '/' . $file;
                  if (is_file($path)) {
                      $mediaBytes += filesize($path);
                  }
              }
          }
          $mediaUsageMb = round($mediaBytes / 1024 / 1024, 1);
          $storageLimitMb = (float) SettingsService::getGlobal('media.storage_limit_mb', 5120);
          $usagePercent = $storageLimitMb > 0 ? min(100, ($mediaUsageMb / $storageLimitMb) * 100) : 0;
          $usageLabel = $mediaUsageMb >= 1024 ? round($mediaUsageMb / 1024, 1) . ' GB' : round($mediaUsageMb, 1) . ' MB';
          $limitLabel = $storageLimitMb >= 1024 ? round($storageLimitMb / 1024, 1) . ' GB' : round($storageLimitMb, 0) . ' MB';
        ?>
        <section class="relative overflow-hidden rounded-3xl border border-line bg-atmosphere p-6 shadow-soft">
          <div class="pointer-events-none absolute inset-0">
            <div class="absolute -top-24 right-0 h-52 w-52 rounded-full bg-primary/20 blur-3xl"></div>
            <div class="absolute bottom-10 left-24 h-60 w-60 rounded-full bg-ocean/20 blur-3xl"></div>
          </div>
          <div class="relative flex flex-col gap-6">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 animate-fade-up">
              <div>
                <p class="text-[10px] uppercase tracking-[0.3em] text-ocean/80">Media</p>
                <h1 class="font-display text-2xl text-ink">Media Library</h1>
                <p class="text-sm text-slate-500">Uploads are stored in /public_html/uploads/ and logged here.</p>
              </div>
              <div class="flex flex-wrap items-center gap-3">
                <div class="relative hidden md:block">
                  <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                    <span class="material-icons-outlined text-lg">search</span>
                  </span>
                  <input class="w-64 pl-10 pr-4 py-2 text-sm bg-white/80 border border-line rounded-lg focus:ring-2 focus:ring-primary/40 focus:border-primary placeholder-slate-400 text-ink" placeholder="Search media..." type="text">
                </div>
                <a href="#media-upload" class="inline-flex items-center gap-2 bg-ink hover:bg-primary-strong text-white px-4 py-2 rounded-lg text-sm font-medium shadow-soft transition-colors">
                  <span class="material-icons-outlined text-lg">cloud_upload</span>
                  Upload
                </a>
              </div>
            </div>
            <div class="grid lg:grid-cols-[260px_1fr] gap-6">
              <aside class="space-y-6">
                <div class="bg-paper border border-line rounded-2xl p-4 shadow-soft animate-float-in">
                  <div class="flex items-center justify-between">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Storage</h2>
                    <span class="text-xs font-medium text-ink"><?= e($usageLabel) ?></span>
                  </div>
                  <div class="mt-4 h-2 rounded-full bg-sand">
                    <div class="h-2 rounded-full bg-gradient-to-r from-primary to-ember" style="width: <?= e((string) $usagePercent) ?>%"></div>
                  </div>
                  <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                    <span><?= e($usageLabel) ?> of <?= e($limitLabel) ?></span>
                    <span><?= e((string) count($media)) ?> items</span>
                  </div>
                </div>
                <div class="bg-paper border border-line rounded-2xl p-4 shadow-soft animate-float-in stagger-1">
                  <h2 class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500 mb-3">Collections</h2>
                  <div class="flex flex-wrap gap-2">
                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-sand text-ink">Photos <?= e((string) $typeCounts['image']) ?></span>
                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-sand text-ink">Videos <?= e((string) $typeCounts['video']) ?></span>
                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-sand text-ink">Docs <?= e((string) ($typeCounts['pdf'] + $typeCounts['file'])) ?></span>
                  </div>
                  <p class="mt-4 text-xs text-slate-500"><span class="font-semibold text-ink"><?= e((string) $sharedCount) ?></span> shared items</p>
                </div>
                <div class="bg-paper border border-line rounded-2xl p-4 shadow-soft animate-float-in stagger-2">
                  <h2 class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500 mb-3">Visibility</h2>
                  <div class="space-y-2 text-sm text-slate-600">
                    <p class="flex items-center justify-between"><span>Public + Member</span><span class="font-semibold text-ink"><?= e((string) $sharedCount) ?></span></p>
                    <p class="flex items-center justify-between"><span>Admin only</span><span class="font-semibold text-ink"><?= e((string) (count($media) - $sharedCount)) ?></span></p>
                  </div>
                </div>
              </aside>
              <section class="space-y-6">
                <div class="grid md:grid-cols-3 gap-4 animate-fade-up">
                  <div class="bg-paper border border-line rounded-xl p-4 shadow-soft flex items-center justify-between">
                    <div>
                      <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Items</p>
                      <p class="mt-2 text-2xl font-semibold text-ink"><?= e((string) count($media)) ?></p>
                      <p class="text-xs text-slate-500">Across <?= e((string) count(array_filter($typeCounts))) ?> types</p>
                    </div>
                    <div class="h-10 w-10 rounded-full bg-primary/15 text-primary flex items-center justify-center">
                      <span class="material-icons-outlined">perm_media</span>
                    </div>
                  </div>
                  <div class="bg-paper border border-line rounded-xl p-4 shadow-soft flex items-center justify-between">
                    <div>
                      <p class="text-xs uppercase tracking-[0.24em] text-slate-500">New</p>
                      <p class="mt-2 text-2xl font-semibold text-ink"><?= e((string) $newCount) ?></p>
                      <p class="text-xs text-slate-500">Uploaded this week</p>
                    </div>
                    <div class="h-10 w-10 rounded-full bg-ember/15 text-ember flex items-center justify-center">
                      <span class="material-icons-outlined">bolt</span>
                    </div>
                  </div>
                  <div class="bg-paper border border-line rounded-xl p-4 shadow-soft flex items-center justify-between">
                    <div>
                      <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Shared</p>
                      <p class="mt-2 text-2xl font-semibold text-ink"><?= e((string) $sharedCount) ?></p>
                      <p class="text-xs text-slate-500">Visible to members</p>
                    </div>
                    <div class="h-10 w-10 rounded-full bg-ocean/15 text-ocean flex items-center justify-center">
                      <span class="material-icons-outlined">group</span>
                    </div>
                  </div>
                </div>
                <div id="media-upload" class="relative overflow-hidden bg-gradient-to-br from-paper via-sand to-white border border-line rounded-2xl p-6 shadow-card animate-fade-up stagger-1">
                  <div class="absolute -right-12 -top-12 h-32 w-32 rounded-full bg-primary/20 blur-2xl"></div>
                  <div class="absolute bottom-0 left-10 h-24 w-24 rounded-full bg-ocean/15 blur-2xl"></div>
                  <div class="relative space-y-4">
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Upload</p>
                      <h2 class="mt-2 font-display text-xl text-ink">Add media to the library</h2>
                      <p class="mt-1 text-sm text-slate-500">Images, PDFs, videos, and files are supported.</p>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <div>
                        <label class="text-sm font-medium text-slate-700">Title</label>
                        <input type="text" name="title" class="mt-1 w-full rounded-lg border border-line bg-white/80 px-3 py-2 text-sm text-ink focus:ring-2 focus:ring-primary/40 focus:border-primary">
                      </div>
                      <div>
                        <label class="text-sm font-medium text-slate-700">Video Embed URL (YouTube/Vimeo)</label>
                        <input type="text" name="embed_url" class="mt-1 w-full rounded-lg border border-line bg-white/80 px-3 py-2 text-sm text-ink focus:ring-2 focus:ring-primary/40 focus:border-primary" placeholder="https://...">
                      </div>
                      <div>
                        <label class="text-sm font-medium text-slate-700">File</label>
                        <input type="file" name="media_file" class="mt-1 w-full rounded-lg border border-line bg-white/80 px-3 py-2 text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-sand file:px-3 file:py-1.5 file:text-sm file:font-semibold">
                      </div>
                      <div>
                        <label class="text-sm font-medium text-slate-700">Type</label>
                        <select name="media_type" class="mt-1 w-full rounded-lg border border-line bg-white/80 px-3 py-2 text-sm text-ink focus:ring-2 focus:ring-primary/40">
                          <option value="image">Image</option>
                          <option value="pdf">PDF</option>
                          <option value="video">Video</option>
                          <option value="file">File</option>
                        </select>
                      </div>
                      <div>
                        <label class="text-sm font-medium text-slate-700">Visibility</label>
                        <select name="visibility" class="mt-1 w-full rounded-lg border border-line bg-white/80 px-3 py-2 text-sm text-ink focus:ring-2 focus:ring-primary/40">
                          <option value="public">Public</option>
                          <option value="member">Member</option>
                          <option value="admin">Admin</option>
                        </select>
                      </div>
                      <div>
                        <label class="text-sm font-medium text-slate-700">Tags</label>
                        <input type="text" name="tags" class="mt-1 w-full rounded-lg border border-line bg-white/80 px-3 py-2 text-sm text-ink focus:ring-2 focus:ring-primary/40">
                      </div>
                      <div class="md:col-span-2">
                        <button class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold shadow-soft hover:bg-primary-strong transition-colors" type="submit">
                          <span class="material-icons-outlined text-lg">cloud_upload</span>
                          Upload
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
                <div class="bg-paper border border-line rounded-2xl p-5 shadow-soft animate-fade-up stagger-2">
                  <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
                    <div class="flex flex-wrap items-center gap-2">
                      <button class="px-3 py-1.5 rounded-full bg-primary/15 text-ink text-xs font-semibold">All</button>
                      <button class="px-3 py-1.5 rounded-full bg-sand text-slate-600 text-xs font-medium hover:text-ink transition-colors">Photos</button>
                      <button class="px-3 py-1.5 rounded-full bg-sand text-slate-600 text-xs font-medium hover:text-ink transition-colors">Videos</button>
                      <button class="px-3 py-1.5 rounded-full bg-sand text-slate-600 text-xs font-medium hover:text-ink transition-colors">Docs</button>
                    </div>
                    <div class="flex items-center gap-2">
                      <select class="text-sm bg-paper border border-line rounded-lg px-3 py-2 text-slate-600 focus:ring-2 focus:ring-primary/40">
                        <option>Sort by: Newest</option>
                        <option>Sort by: Name</option>
                        <option>Sort by: Type</option>
                      </select>
                    </div>
                  </div>
                  <?php if (empty($media)): ?>
                    <div class="text-sm text-slate-500">No media has been uploaded yet.</div>
                  <?php else: ?>
                    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
                      <?php foreach ($media as $item): ?>
                        <?php
                          $type = strtolower($item['type'] ?? 'file');
                          $title = $item['title'] ?: basename($item['path'] ?? '');
                          $path = $item['path'] ?? '';
                          $previewUrl = $item['thumbnail_url'] ?? '';
                          if ($previewUrl === '' && $type === 'image' && $path !== '') {
                              $previewUrl = $path;
                          }
                          $createdLabel = '';
                          if (!empty($item['created_at'])) {
                              $createdAt = strtotime($item['created_at']);
                              $createdLabel = $createdAt ? format_date_au(date('Y-m-d H:i:s', $createdAt)) : '';
                          }
                          $visibility = ucfirst($item['visibility'] ?? 'member');
                          $typeLabel = strtoupper($type);
                          $icon = 'insert_drive_file';
                          if ($type === 'video') {
                              $icon = 'play_circle';
                          } elseif ($type === 'pdf') {
                              $icon = 'picture_as_pdf';
                          } elseif ($type === 'image') {
                              $icon = 'photo';
                          }
                        ?>
                        <div class="group bg-white border border-line rounded-2xl overflow-hidden shadow-soft hover:shadow-card transition-all">
                          <div class="relative h-36 bg-sand flex items-center justify-center">
                            <?php if ($previewUrl): ?>
                              <img alt="<?= e($title) ?>" class="w-full h-full object-cover" src="<?= e($previewUrl) ?>">
                            <?php else: ?>
                              <span class="material-icons-outlined text-5xl text-ember"><?= e($icon) ?></span>
                            <?php endif; ?>
                            <div class="absolute top-3 left-3 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] bg-paper/90 border border-line rounded-full text-ink"><?= e($typeLabel) ?></div>
                            <?php if ($path): ?>
                              <a class="absolute inset-0 bg-ink/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center" href="<?= e($path) ?>" target="_blank" rel="noopener">
                                <span class="material-icons-outlined text-2xl text-white">open_in_new</span>
                              </a>
                            <?php endif; ?>
                          </div>
                          <div class="p-4 space-y-2">
                            <div class="flex items-start justify-between gap-2">
                              <h3 class="text-sm font-semibold text-ink truncate" title="<?= e($title) ?>"><?= e($title) ?></h3>
                              <span class="text-[10px] uppercase tracking-[0.16em] text-slate-400"><?= e($visibility) ?></span>
                            </div>
                            <p class="text-xs text-slate-500 truncate" title="<?= e($path) ?>"><?= e($path) ?></p>
                            <div class="flex items-center justify-between text-xs text-slate-500">
                              <span><?= e($createdLabel ?: 'N/A') ?></span>
                              <code class="text-[11px] bg-sand/70 text-ink px-2 py-1 rounded-lg">[media:<?= e((string) $item['id']) ?>]</code>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </section>
            </div>
          </div>
        </section>
      <?php elseif ($page === 'wings'): ?>
        <?php $issues = $pdo->query('SELECT * FROM wings_issues ORDER BY published_at DESC')->fetchAll(); ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-6">
          <h1 class="font-display text-2xl font-bold text-gray-900">Wings Magazine</h1>
          <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <div>
              <label class="text-sm font-medium text-gray-700">Title</label>
              <input type="text" name="title" class="mt-1" required>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-700">Published Date</label>
              <input type="date" name="published_at" class="mt-1" value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div>
              <label class="text-sm font-medium text-gray-700">PDF File</label>
              <input type="file" name="pdf_file" class="mt-1" required>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-700">Cover Image (optional)</label>
              <input type="file" name="cover_file" class="mt-1">
            </div>
            <div class="md:col-span-2 flex items-center gap-2">
              <label class="flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" name="is_latest" value="1"> Set as latest</label>
            </div>
            <div class="md:col-span-2">
              <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Upload Issue</button>
            </div>
          </form>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-gray-500 border-b">
                <tr><th class="py-2 pr-3">Title</th><th class="py-2 pr-3">Published</th><th class="py-2 pr-3">Latest</th><th class="py-2">PDF</th></tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($issues as $issue): ?>
                  <tr>
                    <td class="py-2 pr-3 text-gray-900"><?= e($issue['title']) ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($issue['published_at']) ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= $issue['is_latest'] ? 'Yes' : 'No' ?></td>
                    <td class="py-2"><a class="text-secondary font-semibold" href="<?= e($issue['pdf_url']) ?>">Download</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($page === 'ai-editor'): ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h1 class="font-display text-2xl font-bold text-gray-900 mb-2">AI Page Builder</h1>
          <p class="text-sm text-gray-500 mb-4">Open the interactive page builder to edit front-facing pages.</p>
          <a class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" href="/admin/page-builder">Open AI Page Builder</a>
        </section>
      <?php elseif ($page === 'audit'): ?>
        <?php $audit = $pdo->query('SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 50')->fetchAll(); ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h1 class="font-display text-2xl font-bold text-gray-900 mb-4">Audit Log</h1>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-gray-500 border-b">
                <tr><th class="py-2 pr-3">User</th><th class="py-2 pr-3">Action</th><th class="py-2 pr-3">Details</th><th class="py-2">Date</th></tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($audit as $row): ?>
                  <tr>
                    <td class="py-2 pr-3 text-gray-900"><?= e($row['name'] ?? 'System') ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($row['action']) ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?= e($row['details']) ?></td>
                    <td class="py-2 text-gray-600"><?= e($row['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($page === 'reports'): ?>
        <?php $counts = $pdo->query("SELECT status, COUNT(*) as c FROM members GROUP BY status")->fetchAll(); ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h1 class="font-display text-2xl font-bold text-gray-900 mb-4">Reports & Exports</h1>
          <p class="text-sm text-gray-500">Export tools can be added here. Current member status counts:</p>
          <ul class="mt-3 space-y-2 text-sm text-gray-700">
            <?php foreach ($counts as $row): ?>
              <li><?= e($row['status']) ?>: <?= e((string) $row['c']) ?></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php else: ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h1 class="font-display text-2xl font-bold text-gray-900">Admin CRM</h1>
          <p class="text-sm text-gray-500">Page not found.</p>
        </section>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
