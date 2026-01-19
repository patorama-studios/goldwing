<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\MembershipService;
use App\Services\MembershipOrderService;
use App\Services\MembershipPricingService;
use App\Services\ChapterRepository;
use App\Services\StripeService;
use App\Services\PaymentSettingsService;
use App\Services\SettingsService;
use App\Services\BaseUrlService;
use App\Services\DomSnapshotService;
use App\Services\EmailService;
use App\Services\NotificationPreferenceService;
use App\Services\ActivityLogger;
use App\Services\ActivityRepository;
use App\Services\MemberRepository;
use App\Services\SecurityPolicyService;
use App\Services\TwoFactorService;
use App\Services\OrderService;

$require_once = require_once __DIR__ . '/../../calendar/lib/calendar_occurrences.php';

require_once __DIR__ . '/../../calendar/lib/calendar_occurrences.php';

require_login();

$page = $_GET['page'] ?? 'dashboard';
$page = preg_replace('/[^a-z0-9-]/', '', strtolower($page));
if ($page === 'notices') {
    $page = 'notices-view';
}
$user = current_user();

$userTimezone = SettingsService::getUser((int) ($user['id'] ?? 0), 'timezone', SettingsService::getGlobal('site.timezone', 'Australia/Sydney'));
$notificationPrefs = NotificationPreferenceService::load((int) ($user['id'] ?? 0));
$notificationCategories = NotificationPreferenceService::categories();
$masterNotificationsEnabled = !empty($notificationPrefs['master_enabled']);
$unsubscribeAll = !empty($notificationPrefs['unsubscribe_all_non_essential']);
$avatarUrl = SettingsService::getUser((int) ($user['id'] ?? 0), 'avatar_url', '');
$globalWeeklyDigestEnabled = SettingsService::getGlobal('notifications.weekly_digest_enabled', false);
$globalEventRemindersEnabled = SettingsService::getGlobal('notifications.event_reminders_enabled', true);
$customerPortalEnabled = SettingsService::getGlobal('payments.stripe.customer_portal_enabled', false);
$eventsTimezone = SettingsService::getGlobal('events.timezone', 'Australia/Sydney');
$zoomDefaultUrl = SettingsService::getGlobal('integrations.zoom_default_url', '');
$includeZoomLink = SettingsService::getGlobal('events.include_zoom_link', true);
$includeMapLink = SettingsService::getGlobal('events.include_map_link', true);
$rsvpDefaultEnabled = SettingsService::getGlobal('events.rsvp_default_enabled', true);
$publicTicketingEnabled = SettingsService::getGlobal('events.public_ticketing_enabled', false);
$defaultEventVisibility = SettingsService::getGlobal('events.visibility_default', 'member');

$twofaEnabled = TwoFactorService::isEnabled((int) ($user['id'] ?? 0));
$twofaRequirement = SecurityPolicyService::computeTwoFaRequirement($user ?? []);

$pdo = db();
$member = null;
$membershipPeriod = null;
$associates = [];
$fullMember = null;
$upcomingEvents = [];
$dashboardNotices = [];
$noticeBoardNotices = [];
$wingsLatest = null;
$membershipOrders = [];
$membershipOrderItems = [];
$membershipOrderItemsById = [];
$storeOrders = [];
$storeOrderItems = [];
$orderHistory = [];
$bikes = [];
$chapterRequests = [];
$billingMessage = '';
$billingError = '';
$fallenWings = [];
$fallenYears = [];
$fallenFilterYear = 0;
$fallenMessage = '';
$fallenError = '';
$fallenTableExists = false;
$noticeMessage = '';
$noticeError = '';
$noticeAvatars = [];
$noticeStates = [];
$noticeChapters = [];
$profileMessage = '';
$profileError = '';
$memberActivity = [];

if ($page === 'billing') {
    if (isset($_GET['success'])) {
        $billingMessage = 'Payment completed. Your membership will update shortly.';
    } elseif (isset($_GET['cancel'])) {
        $billingError = 'Checkout cancelled. You can try again when ready.';
    }
}

function status_badge_classes(string $status): string
{
    $clean = strtolower(trim($status));
    return match ($clean) {
        'active', 'paid', 'fulfilled', 'accepted' => 'bg-green-100 text-green-800',
        'pending', 'processing' => 'bg-yellow-100 text-yellow-800',
        'expired', 'lapsed', 'refunded', 'failed', 'rejected' => 'bg-red-100 text-red-800',
        'cancelled', 'inactive' => 'bg-gray-100 text-gray-800',
        'suspended' => 'bg-indigo-50 text-indigo-800',
        default => 'bg-slate-100 text-slate-800',
    };
}

function normalize_membership_price_term(string $term): string
{
    $clean = strtoupper(trim($term));
    $map = [
        'THREE_YEAR' => '3Y',
        'THREE_YEARS' => '3Y',
        '3YEAR' => '3Y',
        '3YEARS' => '3Y',
        'TWO_YEAR' => '2Y',
        'TWO_YEARS' => '2Y',
        '2YEAR' => '2Y',
        '2YEARS' => '2Y',
        'ONE_YEAR' => '1Y',
        'ONE_YEARS' => '1Y',
        '1YEAR' => '1Y',
        '1YEARS' => '1Y',
        'YEAR' => '1Y',
        'ANNUAL' => '1Y',
    ];
    return $map[$clean] ?? $clean;
}

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

function orders_member_value(array $member, array $user, string $column): ?int
{
    if ($column === 'member_id') {
        return !empty($member['id']) ? (int) $member['id'] : null;
    }
    if ($column === 'user_id') {
        if (!empty($member['user_id'])) {
            return (int) $member['user_id'];
        }
        if (!empty($user['id'])) {
            return (int) $user['id'];
        }
    }
    return null;
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


if ($user && $user['member_id']) {
    $stmt = $pdo->prepare('SELECT m.*, c.name as chapter_name FROM members m LEFT JOIN chapters c ON c.id = m.chapter_id WHERE m.id = :id');
    $stmt->execute(['id' => $user['member_id']]);
    $member = $stmt->fetch();

    if ($member) {
        $ordersMemberColumn = orders_member_column($pdo);
        $ordersMemberValue = $ordersMemberColumn ? orders_member_value($member, $user ?? [], $ordersMemberColumn) : null;
        $ordersPaymentStatusColumn = orders_payment_status_column($pdo);
        $memberActivity = ActivityRepository::listByMember((int) $member['id'], [], 60);
        $memberActivity = array_values(array_filter($memberActivity, function ($entry) {
            if (empty($entry['metadata'])) {
                return true;
            }
            $decoded = json_decode((string) $entry['metadata'], true);
            if (!is_array($decoded)) {
                return true;
            }
            $visibility = $decoded['visibility'] ?? 'member';
            return $visibility !== 'admin';
        }));
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $profileError = 'Invalid CSRF token.';
            } elseif ($_POST['action'] === 'update_profile') {
                $targetMemberId = (int) ($_POST['profile_member_id'] ?? $member['id']);
                $canEditTarget = $targetMemberId === (int) $member['id'];
                $targetMember = $member;

                if (!$canEditTarget && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
                    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id AND full_member_id = :full_id');
                    $stmt->execute(['id' => $targetMemberId, 'full_id' => $member['id']]);
                    $targetMember = $stmt->fetch();
                    $canEditTarget = (bool) $targetMember;
                }

                if (
                    !$canEditTarget
                    && $member['member_type'] === 'ASSOCIATE'
                    && !empty($member['full_member_id'])
                    && $targetMemberId === (int) $member['full_member_id']
                ) {
                    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
                    $stmt->execute(['id' => $targetMemberId]);
                    $targetMember = $stmt->fetch();
                    $canEditTarget = (bool) $targetMember;
                }

                if (!$canEditTarget || !$targetMember) {
                    $profileError = 'You do not have permission to update this profile.';
                } else {
                    $newEmail = trim($_POST['email'] ?? $targetMember['email']);
                    if (!MemberRepository::isEmailAvailable($newEmail, $targetMemberId)) {
                        $profileError = 'That email address is already linked to another member.';
                    } else {
                    $payload = [
                        'email' => $newEmail,
                        'phone' => trim($_POST['phone'] ?? ''),
                        'address_line1' => trim($_POST['address_line1'] ?? ''),
                        'address_line2' => trim($_POST['address_line2'] ?? ''),
                        'city' => trim($_POST['city'] ?? ''),
                        'state' => trim($_POST['state'] ?? ''),
                        'postcode' => trim($_POST['postal_code'] ?? ''),
                        'country' => trim($_POST['country'] ?? ''),
                        'wings_preference' => $_POST['wings_preference'] ?? $targetMember['wings_preference'],
                        'privacy_level' => $_POST['privacy_level'] ?? $targetMember['privacy_level'],
                    ];
                    foreach (MemberRepository::directoryPreferences() as $letter => $info) {
                        $payload['directory_pref_' . $letter] = isset($_POST['directory_pref_' . $letter]) ? 1 : 0;
                    }
                    $updated = MemberRepository::update($targetMemberId, $payload);
                    if ($updated && $targetMemberId === (int) $member['id'] && !empty($user['id'])) {
                        $stmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
                        $stmt->execute(['email' => $newEmail, 'id' => $user['id']]);
                    }
                    if ($updated) {
                        $profileMessage = $targetMemberId === (int) $member['id'] ? 'Profile updated.' : 'Linked profile updated.';
                    } else {
                        $profileError = 'Unable to save profile changes.';
                    }
                    }
                }
            } elseif ($_POST['action'] === 'request_chapter') {
                $requestedChapter = (int) ($_POST['requested_chapter_id'] ?? 0);
                if ($requestedChapter) {
                    $stmt = $pdo->prepare('INSERT INTO chapter_change_requests (member_id, requested_chapter_id, status, requested_at) VALUES (:member_id, :chapter_id, "PENDING", NOW())');
                    $stmt->execute(['member_id' => $member['id'], 'chapter_id' => $requestedChapter]);
                    $profileMessage = 'Chapter change request submitted.';
                }
            } elseif ($_POST['action'] === 'add_bike') {
                $make = trim($_POST['bike_make'] ?? '');
                $model = trim($_POST['bike_model'] ?? '');
                $year = (int) ($_POST['bike_year'] ?? 0);
                $rego = trim($_POST['bike_rego'] ?? '');
                $imageUrl = trim($_POST['bike_image_url'] ?? '');
                $color = trim($_POST['bike_color'] ?? '');
                $targetBikeMemberId = (int) ($_POST['profile_member_id'] ?? $member['id']);
                $canAddBike = $targetBikeMemberId === (int) $member['id'];

                if (!$canAddBike && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
                    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id AND full_member_id = :full_id');
                    $stmt->execute(['id' => $targetBikeMemberId, 'full_id' => $member['id']]);
                    $canAddBike = (bool) $stmt->fetchColumn();
                }

                if (
                    !$canAddBike
                    && $member['member_type'] === 'ASSOCIATE'
                    && !empty($member['full_member_id'])
                    && $targetBikeMemberId === (int) $member['full_member_id']
                ) {
                    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id');
                    $stmt->execute(['id' => $targetBikeMemberId]);
                    $canAddBike = (bool) $stmt->fetchColumn();
                }

                if (!$canAddBike) {
                    $profileError = 'You do not have permission to add bikes for this profile.';
                } elseif ($make && $model) {
                    $bikeColumns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(PDO::FETCH_COLUMN, 0);
                    $hasRego = in_array('rego', $bikeColumns, true);
                    $hasImage = in_array('image_url', $bikeColumns, true);
                    $hasColor = in_array('color', $bikeColumns, true) || in_array('colour', $bikeColumns, true);
                    $hasPrimary = in_array('is_primary', $bikeColumns, true);

                    $columns = ['member_id', 'make', 'model', 'year', 'created_at'];
                    $placeholders = [':member_id', ':make', ':model', ':year', 'NOW()'];
                    $params = [
                        'member_id' => $targetBikeMemberId,
                        'make' => $make,
                        'model' => $model,
                        'year' => $year ?: null,
                    ];
                    if ($hasRego) {
                        $columns[] = 'rego';
                        $placeholders[] = ':rego';
                        $params['rego'] = $rego !== '' ? $rego : null;
                    }
                    if ($hasImage) {
                        $columns[] = 'image_url';
                        $placeholders[] = ':image_url';
                        $params['image_url'] = $imageUrl !== '' ? $imageUrl : null;
                    }
                    if ($hasColor && $color !== '') {
                        if (in_array('color', $bikeColumns, true)) {
                            $columns[] = 'color';
                            $placeholders[] = ':color';
                            $params['color'] = $color;
                        } elseif (in_array('colour', $bikeColumns, true)) {
                            $columns[] = 'colour';
                            $placeholders[] = ':colour';
                            $params['colour'] = $color;
                        }
                    }
                    if ($hasPrimary) {
                        $primaryStmt = $pdo->prepare('SELECT 1 FROM member_bikes WHERE member_id = :member_id AND is_primary = 1 LIMIT 1');
                        $primaryStmt->execute(['member_id' => $targetBikeMemberId]);
                        $primaryExists = (bool) $primaryStmt->fetchColumn();
                        if (!$primaryExists) {
                            $columns[] = 'is_primary';
                            $placeholders[] = ':is_primary';
                            $params['is_primary'] = 1;
                        }
                    }
                    $stmt = $pdo->prepare('INSERT INTO member_bikes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
                    $stmt->execute($params);
                    $profileMessage = 'Bike added.';
                }
            } elseif ($_POST['action'] === 'update_bike') {
                $bikeId = (int) ($_POST['bike_id'] ?? 0);
                $make = trim($_POST['bike_make'] ?? '');
                $model = trim($_POST['bike_model'] ?? '');
                $year = (int) ($_POST['bike_year'] ?? 0);
                $rego = trim($_POST['bike_rego'] ?? '');
                $imageUrl = trim($_POST['bike_image_url'] ?? '');
                $color = trim($_POST['bike_color'] ?? '');
                $targetBikeMemberId = (int) ($_POST['profile_member_id'] ?? $member['id']);
                $canUpdateBike = $targetBikeMemberId === (int) $member['id'];

                if (!$canUpdateBike && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
                    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id AND full_member_id = :full_id');
                    $stmt->execute(['id' => $targetBikeMemberId, 'full_id' => $member['id']]);
                    $canUpdateBike = (bool) $stmt->fetchColumn();
                }

                if (
                    !$canUpdateBike
                    && $member['member_type'] === 'ASSOCIATE'
                    && !empty($member['full_member_id'])
                    && $targetBikeMemberId === (int) $member['full_member_id']
                ) {
                    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id');
                    $stmt->execute(['id' => $targetBikeMemberId]);
                    $canUpdateBike = (bool) $stmt->fetchColumn();
                }

                if (!$canUpdateBike || $bikeId <= 0) {
                    $profileError = 'You do not have permission to update bikes for this profile.';
                } elseif ($make === '' || $model === '') {
                    $profileError = 'Make and model are required.';
                } else {
                    $bikeColumns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(PDO::FETCH_COLUMN, 0);
                    $hasRego = in_array('rego', $bikeColumns, true);
                    $hasImage = in_array('image_url', $bikeColumns, true);
                    $hasColor = in_array('color', $bikeColumns, true) || in_array('colour', $bikeColumns, true);
                    $hasPrimary = in_array('is_primary', $bikeColumns, true);

                    $fields = ['make = :make', 'model = :model', 'year = :year'];
                    $params = [
                        'make' => $make,
                        'model' => $model,
                        'year' => $year ?: null,
                        'id' => $bikeId,
                        'member_id' => $targetBikeMemberId,
                    ];
                    if ($hasRego) {
                        $fields[] = 'rego = :rego';
                        $params['rego'] = $rego !== '' ? $rego : null;
                    }
                    if ($hasImage) {
                        $fields[] = 'image_url = :image_url';
                        $params['image_url'] = $imageUrl !== '' ? $imageUrl : null;
                    }
                    if ($hasColor) {
                        if (in_array('color', $bikeColumns, true)) {
                            $fields[] = 'color = :color';
                            $params['color'] = $color !== '' ? $color : null;
                        } elseif (in_array('colour', $bikeColumns, true)) {
                            $fields[] = 'colour = :colour';
                            $params['colour'] = $color !== '' ? $color : null;
                        }
                    }
                    $setPrimary = $hasPrimary && isset($_POST['is_primary']) && $_POST['is_primary'] === '1';
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('UPDATE member_bikes SET ' . implode(', ', $fields) . ' WHERE id = :id AND member_id = :member_id');
                    $stmt->execute($params);
                    if ($setPrimary) {
                        $stmt = $pdo->prepare('UPDATE member_bikes SET is_primary = 0 WHERE member_id = :member_id');
                        $stmt->execute(['member_id' => $targetBikeMemberId]);
                        $stmt = $pdo->prepare('UPDATE member_bikes SET is_primary = 1 WHERE id = :id AND member_id = :member_id');
                        $stmt->execute(['id' => $bikeId, 'member_id' => $targetBikeMemberId]);
                    }
                    $pdo->commit();
                    $profileMessage = 'Bike updated.';
                }
            } elseif ($_POST['action'] === 'delete_bike') {
                $bikeId = (int) ($_POST['bike_id'] ?? 0);
                $targetBikeMemberId = (int) ($_POST['profile_member_id'] ?? $member['id']);
                $canDeleteBike = $targetBikeMemberId === (int) $member['id'];

                if (!$canDeleteBike && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
                    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id AND full_member_id = :full_id');
                    $stmt->execute(['id' => $targetBikeMemberId, 'full_id' => $member['id']]);
                    $canDeleteBike = (bool) $stmt->fetchColumn();
                }

                if (
                    !$canDeleteBike
                    && $member['member_type'] === 'ASSOCIATE'
                    && !empty($member['full_member_id'])
                    && $targetBikeMemberId === (int) $member['full_member_id']
                ) {
                    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id');
                    $stmt->execute(['id' => $targetBikeMemberId]);
                    $canDeleteBike = (bool) $stmt->fetchColumn();
                }

                if (!$canDeleteBike || $bikeId <= 0) {
                    $profileError = 'You do not have permission to remove bikes for this profile.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM member_bikes WHERE id = :id AND member_id = :member_id');
                    $stmt->execute(['id' => $bikeId, 'member_id' => $targetBikeMemberId]);
                    $profileMessage = 'Bike removed.';
                }
            } elseif ($_POST['action'] === 'update_personal_settings') {
                $timezone = trim($_POST['user_timezone'] ?? '');
                $categories = [];
                foreach ($notificationCategories as $categoryKey => $categoryLabel) {
                    $categories[$categoryKey] = isset($_POST['notify_category'][$categoryKey]);
                }
                $prefs = [
                    'master_enabled' => isset($_POST['notify_master_enabled']),
                    'unsubscribe_all_non_essential' => isset($_POST['notify_unsubscribe_all']),
                    'categories' => $categories,
                ];
                $avatarInput = trim($_POST['avatar_url'] ?? '');
                SettingsService::setUser((int) $user['id'], 'timezone', $timezone !== '' ? $timezone : SettingsService::getGlobal('site.timezone', 'Australia/Sydney'));
                NotificationPreferenceService::save((int) $user['id'], $prefs);
                SettingsService::setUser((int) $user['id'], 'avatar_url', $avatarInput);
                $userTimezone = SettingsService::getUser((int) $user['id'], 'timezone', SettingsService::getGlobal('site.timezone', 'Australia/Sydney'));
                $notificationPrefs = NotificationPreferenceService::load((int) $user['id']);
                $avatarUrl = SettingsService::getUser((int) $user['id'], 'avatar_url', '');
                ActivityLogger::log('member', (int) $user['id'], (int) ($member['id'] ?? 0), 'notification.preferences_updated', [
                    'visibility' => 'member',
                ]);
                $profileMessage = 'Personal settings updated.';
            } elseif ($_POST['action'] === 'update_shipping') {
                $shipping = [
                    'address_line1' => trim($_POST['shipping_address_line1'] ?? ''),
                    'address_line2' => trim($_POST['shipping_address_line2'] ?? ''),
                    'city' => trim($_POST['shipping_city'] ?? ''),
                    'state' => trim($_POST['shipping_state'] ?? ''),
                    'postal_code' => trim($_POST['shipping_postal_code'] ?? ''),
                    'country' => trim($_POST['shipping_country'] ?? ''),
                ];

                if (!$member) {
                    $billingError = 'Unable to update shipping address.';
                } else {
                    $stmt = $pdo->prepare('UPDATE members SET address_line1 = :address1, address_line2 = :address2, city = :city, state = :state, postal_code = :postal, country = :country, updated_at = NOW() WHERE id = :id');
                    $stmt->execute([
                        'address1' => $shipping['address_line1'],
                        'address2' => $shipping['address_line2'],
                        'city' => $shipping['city'],
                        'state' => $shipping['state'],
                        'postal' => $shipping['postal_code'],
                        'country' => $shipping['country'],
                        'id' => $member['id'],
                    ]);

                    $channel = PaymentSettingsService::getChannelByCode('primary');
                    $settings = $channel ? PaymentSettingsService::getSettingsByChannelId((int) $channel['id']) : [];
                    $secretKey = $settings['secret_key'] ?? '';
                    if ($secretKey !== '') {
                        $customerId = $member['stripe_customer_id'] ?? '';
                        if ($customerId === '') {
                            $customer = StripeService::createCustomer($secretKey, [
                                'email' => $user['email'] ?? null,
                                'name' => $user['name'] ?? null,
                                'metadata' => ['user_id' => (string) ($user['id'] ?? '')],
                            ]);
                            $customerId = $customer['id'] ?? '';
                            if ($customerId !== '') {
                                $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :customer_id WHERE id = :id');
                                $stmt->execute(['customer_id' => $customerId, 'id' => $member['id']]);
                            }
                        }

                        if ($customerId !== '') {
                            StripeService::updateCustomer($secretKey, $customerId, [
                                'shipping' => [
                                    'name' => $user['name'] ?? 'Member',
                                    'address' => [
                                        'line1' => $shipping['address_line1'],
                                        'line2' => $shipping['address_line2'] ?: null,
                                        'city' => $shipping['city'] ?: null,
                                        'state' => $shipping['state'] ?: null,
                                        'postal_code' => $shipping['postal_code'] ?: null,
                                        'country' => $shipping['country'] ?: null,
                                    ],
                                ],
                            ]);
                        }
                    }

                    $billingMessage = 'Shipping address updated.';
                }
            } elseif ($_POST['action'] === 'membership_order_pay') {
                $orderId = (int) ($_POST['order_id'] ?? 0);
                if (!$member || $orderId <= 0) {
                    $billingError = 'Unable to start payment for this order.';
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND member_id = :member_id AND order_type = "membership" LIMIT 1');
                    $stmt->execute(['id' => $orderId, 'member_id' => $member['id']]);
                    $order = $stmt->fetch();
                    if (!$order) {
                        $billingError = 'Unable to locate the selected order.';
                    } elseif (!empty($order['payment_method']) && $order['payment_method'] !== 'stripe') {
                        $billingError = 'This order requires manual payment approval.';
                    } else {
                        $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
                        $itemsStmt->execute(['order_id' => $orderId]);
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
                        $session = StripeService::createCheckoutSessionWithLineItems($lineItems, $member['email'] ?? '', $successUrl, $cancelUrl, [
                            'order_id' => (string) $orderId,
                            'order_type' => 'membership',
                            'member_id' => (string) $member['id'],
                            'period_id' => (string) ($order['membership_period_id'] ?? ''),
                            'channel_id' => (string) ($order['channel_id'] ?? ''),
                        ]);
                        if (!$session || empty($session['id'])) {
                            $billingError = 'Unable to start checkout for this order.';
                        } else {
                            OrderService::updateStripeSession($orderId, $session['id']);
                            $updateFields = ['payment_method = "stripe"', 'status = "pending"', 'updated_at = NOW()'];
                            if (!empty($ordersPaymentStatusColumn) && $ordersPaymentStatusColumn !== 'status') {
                                $updateFields[] = 'payment_status = "pending"';
                            }
                            $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $updateFields) . ' WHERE id = :id');
                            $stmt->execute(['id' => $orderId]);
                            header('Location: ' . ($session['url'] ?? '/member/index.php?page=billing'));
                            exit;
                        }
                    }
                }
            } elseif ($_POST['action'] === 'membership_renew') {
                if (!$member) {
                    $billingError = 'Unable to start renewal.';
                } elseif (strtoupper((string) ($member['member_type'] ?? '')) === 'LIFE') {
                    $billingError = 'Life members do not need to renew.';
                } else {
                    $pendingOrderId = 0;
                    if ($ordersMemberColumn && $ordersMemberValue) {
                        $stmt = $pdo->prepare('SELECT id FROM orders WHERE ' . $ordersMemberColumn . ' = :value AND order_type = "membership" AND ' . $ordersPaymentStatusColumn . ' = "pending" ORDER BY created_at DESC LIMIT 1');
                        $stmt->execute(['value' => $ordersMemberValue]);
                        $pendingOrderId = (int) $stmt->fetchColumn();
                    }
                    if ($pendingOrderId > 0) {
                        $billingMessage = 'You already have a pending membership order.';
                    } else {
                        $term = '1Y';
                        $periodId = MembershipService::createMembershipPeriod((int) $member['id'], $term, date('Y-m-d'));
                        $magazineType = strtolower((string) ($member['wings_preference'] ?? 'digital')) === 'digital' ? 'PDF' : 'PRINTED';
                        $membershipTypeKey = strtoupper((string) ($member['member_type'] ?? 'FULL')) === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL';
                        $pricingPeriodKey = $term === '3Y' ? 'THREE_YEARS' : 'ONE_YEAR';
                        $priceCents = MembershipPricingService::getPriceCents($magazineType, $membershipTypeKey, $pricingPeriodKey) ?? 0;
                        $pricingCurrency = MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD';
                        $amount = round($priceCents / 100, 2);
                        $order = MembershipOrderService::createMembershipOrder((int) $member['id'], $periodId, $amount, [
                            'payment_method' => 'stripe',
                            'payment_status' => 'pending',
                            'fulfillment_status' => 'pending',
                            'currency' => $pricingCurrency,
                            'item_name' => 'Membership renewal ' . $term,
                            'term' => $term,
                        ]);
                        if (!$order) {
                            $billingError = 'Unable to create a renewal order.';
                        } else {
                            $priceKey = $membershipTypeKey . '_' . $term;
                            $prices = SettingsService::getGlobal('payments.membership_prices', []);
                            $priceId = is_array($prices) ? ($prices[$priceKey] ?? '') : '';
                            if ($priceId === '') {
                                $billingError = 'Membership pricing is not configured. Please contact support.';
                            } else {
                                $successUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&success=1');
                                $cancelUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&cancel=1');
                                $session = StripeService::createCheckoutSessionForPrice($priceId, $member['email'] ?? '', $successUrl, $cancelUrl, [
                                    'period_id' => $periodId,
                                    'member_id' => $member['id'],
                                    'order_id' => $order['id'] ?? null,
                                    'order_type' => 'membership',
                                ]);
                                if (!$session || empty($session['id'])) {
                                    $billingError = 'Unable to start renewal payment.';
                                } else {
                                    OrderService::updateStripeSession((int) ($order['id'] ?? 0), $session['id']);
                                    header('Location: ' . ($session['url'] ?? '/member/index.php?page=billing'));
                                    exit;
                                }
                            }
                        }
                    }
                }
            } elseif ($_POST['action'] === 'create_notice') {
                $title = trim($_POST['notice_title'] ?? '');
                $contentRaw = trim($_POST['notice_content'] ?? '');
                $content = DomSnapshotService::sanitize($contentRaw);
                $category = strtolower(trim($_POST['notice_category'] ?? 'notice'));
                $audienceScope = strtolower(trim($_POST['notice_audience_scope'] ?? 'all'));
                $audienceState = trim($_POST['notice_audience_state'] ?? '');
                $audienceChapterId = (int) ($_POST['notice_audience_chapter'] ?? 0);
                $attachmentUrl = trim($_POST['notice_attachment_url'] ?? '');
                $attachmentType = strtolower(trim($_POST['notice_attachment_type'] ?? ''));
                $noticeFormState = $audienceState;

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
                $stateCodes = array_column($australianStates ?? [], 'code');
                if ($audienceScope === 'state' && ($audienceState === '' || !in_array($audienceState, $stateCodes, true))) {
                    $noticeError = 'Please select a state for state notices.';
                } elseif ($audienceScope === 'chapter' && $audienceChapterId <= 0) {
                    $noticeError = 'Please select a chapter for chapter notices.';
                } elseif ($title === '' || $content === '') {
                    $noticeError = 'Please provide a title and description.';
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
                        $insertValues[] = ':published_at';
                        $params['published_at'] = null;
                    }

                    $stmt = $pdo->prepare('INSERT INTO notices (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')');
                    $stmt->execute($params);
                    if ($hasPublishedAt) {
                        $noticeMessage = 'Notice submitted for approval.';
                        $reviewEmails = [];
                        $stmt = $pdo->query('SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin", "committee", "super_admin") AND u.is_active = 1');
                        foreach ($stmt->fetchAll() as $row) {
                            if (!empty($row['email'])) {
                                $reviewEmails[] = $row['email'];
                            }
                        }
                        $reviewEmails = array_values(array_unique($reviewEmails));
                        if ($reviewEmails) {
                            $subject = 'Notice awaiting approval';
                            $body = '<p>A new notice is awaiting approval.</p>'
                                . '<p><strong>Title:</strong> ' . e($title) . '<br>'
                                . '<strong>Category:</strong> ' . e($category) . '</p>';
                            foreach ($reviewEmails as $email) {
                                EmailService::send($email, $subject, $body);
                            }
                        }
                    } else {
                        $noticeMessage = 'Notice published.';
                    }
                }
            } elseif ($_POST['action'] === 'submit_fallen_wings') {
                try {
                    $fallenTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'fallen_wings'")->fetch();
                } catch (PDOException $e) {
                    $fallenTableExists = false;
                }
                $fullName = trim($_POST['fallen_name'] ?? '');
                $dateOfPassing = trim($_POST['fallen_date'] ?? '');
                $yearInput = trim($_POST['fallen_year'] ?? '');
                $tribute = trim($_POST['fallen_tribute'] ?? '');
                $memberNumberRaw = trim($_POST['fallen_member_number'] ?? '');
                $memberNumberNormalized = $memberNumberRaw !== '' ? preg_replace('/\\s+/', '', $memberNumberRaw) : '';

                $year = 0;
                if ($dateOfPassing !== '') {
                    $timestamp = strtotime($dateOfPassing);
                    if ($timestamp !== false) {
                        $year = (int) date('Y', $timestamp);
                    }
                }
                if ($year <= 0 && $yearInput !== '') {
                    $year = (int) $yearInput;
                }

                if (!$fallenTableExists) {
                    $fallenError = 'Fallen Wings table not found. Please run the migration to enable submissions.';
                } elseif ($fullName === '' || $year <= 0) {
                    $fallenError = 'Please include a name and date of passing.';
                } elseif ($memberNumberNormalized !== '' && !preg_match('/^[A-Za-z0-9\\.\\-]+$/', $memberNumberNormalized)) {
                    $fallenError = 'Member number may only contain letters, numbers, dots, or dashes.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO fallen_wings (full_name, year_of_passing, member_number, tribute, status, submitted_by, created_at) VALUES (:full_name, :year_of_passing, :member_number, :tribute, "PENDING", :submitted_by, NOW())');
                    $stmt->execute([
                        'full_name' => $fullName,
                        'year_of_passing' => $year,
                        'member_number' => $memberNumberNormalized !== '' ? $memberNumberNormalized : null,
                        'tribute' => $tribute !== '' ? $tribute : null,
                        'submitted_by' => $user['id'],
                    ]);

                    $committeeEmails = [];
                    $stmt = $pdo->query('SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name = "committee" AND u.is_active = 1');
                    foreach ($stmt->fetchAll() as $row) {
                        if (!empty($row['email'])) {
                            $committeeEmails[] = $row['email'];
                        }
                    }
                    $committeeEmails = array_values(array_unique($committeeEmails));
                    if ($committeeEmails) {
                        $subject = 'Fallen Wings submission awaiting approval';
                        $body = '<p>A new Fallen Wings submission is awaiting review.</p>'
                            . '<p><strong>Name:</strong> ' . e($fullName) . '<br>'
                            . '<strong>Year:</strong> ' . e((string) $year) . '</p>';
                        if ($memberNumberNormalized !== '') {
                            $body .= '<p><strong>Member number:</strong> ' . e($memberNumberNormalized) . '</p>';
                        }
                        foreach ($committeeEmails as $email) {
                            EmailService::send($email, $subject, $body);
                        }
                    }

                    $fallenMessage = 'Submission received. A committee member will review it soon.';
                }
            }
            $stmt = $pdo->prepare('SELECT m.*, c.name as chapter_name FROM members m LEFT JOIN chapters c ON c.id = m.chapter_id WHERE m.id = :id');
            $stmt->execute(['id' => $user['member_id']]);
            $member = $stmt->fetch();
        }

        $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE member_id = :member_id ORDER BY end_date DESC LIMIT 1');
        $stmt->execute(['member_id' => $member['id']]);
        $membershipPeriod = $stmt->fetch();

        if ($member['member_type'] === 'FULL' || $member['member_type'] === 'LIFE') {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE full_member_id = :id');
            $stmt->execute(['id' => $member['id']]);
            $associates = $stmt->fetchAll();
        } elseif ($member['member_type'] === 'ASSOCIATE' && $member['full_member_id']) {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
            $stmt->execute(['id' => $member['full_member_id']]);
            $fullMember = $stmt->fetch();
        }

        $profileMember = $member;
        $profileMemberId = $member['id'];
        $profileContextLabel = 'Your Profile';
        $profileContextNote = '';

        $requestedProfileId = (int) ($_GET['member_id'] ?? 0);
        if ($requestedProfileId && $requestedProfileId !== (int) $member['id']) {
            if (in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
                foreach ($associates as $assoc) {
                    if ((int) $assoc['id'] === $requestedProfileId) {
                        $profileMember = $assoc;
                        $profileMemberId = $assoc['id'];
                        $profileContextLabel = 'Associate Profile';
                        $profileContextNote = 'Editing linked associate details.';
                        break;
                    }
                }
            } elseif ($member['member_type'] === 'ASSOCIATE' && $fullMember && (int) $fullMember['id'] === $requestedProfileId) {
                $profileMember = $fullMember;
                $profileMemberId = $fullMember['id'];
                $profileContextLabel = 'Full Member Profile';
                $profileContextNote = 'Editing linked full member details.';
            }
        }

        $canManageProfileBikes = $profileMemberId === (int) $member['id'];
        if (!$canManageProfileBikes && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
            $canManageProfileBikes = (bool) array_filter($associates, fn($assoc) => (int) ($assoc['id'] ?? 0) === $profileMemberId);
        }
        if (
            !$canManageProfileBikes
            && $member['member_type'] === 'ASSOCIATE'
            && $fullMember
            && (int) ($fullMember['id'] ?? 0) === $profileMemberId
        ) {
            $canManageProfileBikes = true;
        }

        $memberChapterId = $member['chapter_id'] ?? null;
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
        $stateNameMap = [
            'AUSTRALIAN CAPITAL TERRITORY' => 'ACT',
            'NEW SOUTH WALES' => 'NSW',
            'NORTHERN TERRITORY' => 'NT',
            'QUEENSLAND' => 'QLD',
            'SOUTH AUSTRALIA' => 'SA',
            'TASMANIA' => 'TAS',
            'VICTORIA' => 'VIC',
            'WESTERN AUSTRALIA' => 'WA',
        ];
        $memberStateRaw = strtoupper(trim($member['state'] ?? ''));
        $memberState = $stateNameMap[$memberStateRaw] ?? $memberStateRaw;

        $calendarEvents = [];
        try {
            if (calendar_table_exists($pdo, 'calendar_events')) {
                $eventsSql = 'SELECT e.*, m.path AS thumbnail_url, c.name AS chapter_name FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id LEFT JOIN chapters c ON c.id = e.chapter_id WHERE e.status = "published" AND (e.scope = "NATIONAL"';
                $eventsParams = [];
                if (!empty($memberChapterId)) {
                    $eventsSql .= ' OR e.chapter_id = :chapter_id';
                    $eventsParams['chapter_id'] = (int) $memberChapterId;
                }
                $eventsSql .= ')';
                $stmt = $pdo->prepare($eventsSql);
                $stmt->execute($eventsParams);
                $calendarEvents = $stmt->fetchAll();
            }
        } catch (PDOException $e) {
            $calendarEvents = [];
        }

        $rangeStart = new DateTime('now', new DateTimeZone('UTC'));
        $rangeEnd = (clone $rangeStart)->modify('+60 days');
        $occurrences = [];
        foreach ($calendarEvents as $event) {
            $eventOccurrences = calendar_expand_occurrences($event, $rangeStart, $rangeEnd);
            foreach ($eventOccurrences as $occ) {
                $occurrences[] = [
                    'event' => $event,
                    'start' => $occ['start'],
                ];
            }
        }
        usort($occurrences, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });
        $occurrences = array_slice($occurrences, 0, 5);
        foreach ($occurrences as $item) {
            $event = $item['event'];
            $location = '';
            if (!empty($event['meeting_point'])) {
                $location = $event['meeting_point'];
            } elseif (!empty($event['destination'])) {
                $location = $event['destination'];
            } elseif (!empty($event['event_type']) && $event['event_type'] === 'online') {
                $location = 'Online';
            } elseif (!empty($event['chapter_name'])) {
                $location = $event['chapter_name'];
            }
            $upcomingEvents[] = [
                'title' => $event['title'],
                'date_label' => calendar_format_dt($item['start']->format('Y-m-d H:i:s'), $event['timezone']),
                'location' => $location,
                'url' => '/calendar/event_view.php?slug=' . urlencode($event['slug'] ?? ''),
            ];
        }

        $noticeHasAudience = false;
        $noticeHasPublishedAt = false;
        try {
            $noticeHasAudience = (bool) $pdo->query("SHOW COLUMNS FROM notices LIKE 'audience_scope'")->fetch();
            $noticeHasPublishedAt = (bool) $pdo->query("SHOW COLUMNS FROM notices LIKE 'published_at'")->fetch();
        } catch (PDOException $e) {
            $noticeHasAudience = false;
            $noticeHasPublishedAt = false;
        }

        $noticeParams = [];
        $noticePublishedClause = $noticeHasPublishedAt ? ' AND n.published_at IS NOT NULL' : '';
        if ($noticeHasAudience) {
            $noticeBaseSql = 'SELECT n.*, u.name AS created_by_name FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.visibility IN ("member", "public")' . $noticePublishedClause . ' AND (n.audience_scope = "all" OR (n.audience_scope = "state" AND n.audience_state = :state) OR (n.audience_scope = "chapter" AND n.audience_chapter_id = :chapter))';
            $noticeParams = [
                'state' => $memberState,
                'chapter' => $memberChapterId ?: 0,
            ];
        } else {
            $noticeBaseSql = 'SELECT n.*, u.name AS created_by_name FROM notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.visibility IN ("member", "public")' . $noticePublishedClause;
        }

        $noticeOrder = $noticeHasPublishedAt ? 'n.published_at DESC, n.created_at DESC' : 'n.created_at DESC';
        $dashboardNoticeSql = $noticeBaseSql . ' ORDER BY n.is_pinned DESC, ' . $noticeOrder . ' LIMIT 5';
        $stmt = $pdo->prepare($dashboardNoticeSql);
        $stmt->execute($noticeParams);
        $dashboardNotices = $stmt->fetchAll();

        $noticeBoardSql = $noticeBaseSql . ' ORDER BY n.is_pinned DESC, ' . $noticeOrder;
        $stmt = $pdo->prepare($noticeBoardSql);
        $stmt->execute($noticeParams);
        $noticeBoardNotices = $stmt->fetchAll();

        $noticeCreatorIds = array_values(array_unique(array_filter(array_column($noticeBoardNotices, 'created_by'))));
        $noticeAvatars = [];
        foreach ($noticeCreatorIds as $creatorId) {
            $noticeAvatars[$creatorId] = SettingsService::getUser((int) $creatorId, 'avatar_url', '');
        }

        $stmt = $pdo->prepare('SELECT * FROM wings_issues WHERE is_latest = 1 ORDER BY published_at DESC LIMIT 1');
        $stmt->execute();
        $wingsLatest = $stmt->fetch();

        $membershipOrders = [];
        if ($ordersMemberColumn && $ordersMemberValue) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE ' . $ordersMemberColumn . ' = :value AND order_type = "membership" ORDER BY created_at DESC');
            $stmt->execute(['value' => $ordersMemberValue]);
            $membershipOrders = $stmt->fetchAll();
        }
        $membershipOrderItemsById = [];
        if ($membershipOrders) {
            $membershipOrderIds = array_column($membershipOrders, 'id');
            if ($membershipOrderIds) {
                $placeholders = implode(',', array_fill(0, count($membershipOrderIds), '?'));
                $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id IN (' . $placeholders . ') ORDER BY order_id ASC, id ASC');
                $stmt->execute($membershipOrderIds);
                $membershipOrderItems = $stmt->fetchAll();
                foreach ($membershipOrderItems as $item) {
                    $membershipOrderItemsById[$item['order_id']][] = $item;
                }
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $user['id']]);
        $storeOrders = $stmt->fetchAll();

        $storeOrderIds = array_column($storeOrders, 'id');
        if ($storeOrderIds) {
            $placeholders = implode(',', array_fill(0, count($storeOrderIds), '?'));
            $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id IN (' . $placeholders . ') ORDER BY order_id ASC, id ASC');
            $stmt->execute($storeOrderIds);
            $storeOrderItems = $stmt->fetchAll();
        }

        $storeItemsByOrder = [];
        foreach ($storeOrderItems as $item) {
            $storeItemsByOrder[$item['order_id']][] = $item;
        }

        foreach ($membershipOrders as $order) {
            $daysRemainingLabel = null;
            if (!empty($membershipPeriod['end_date'])) {
                $daysRemaining = (int) ceil((strtotime($membershipPeriod['end_date']) - time()) / 86400);
                $daysRemainingLabel = $daysRemaining > 0 ? $daysRemaining . ' days' : 'Due';
            } elseif (!empty($member['member_type']) && $member['member_type'] === 'LIFE') {
                $daysRemainingLabel = 'No expiry';
            }
            $paymentMethod = trim((string) ($order['payment_method'] ?? ''));
            $paymentMethodKey = strtolower(str_replace(' ', '_', $paymentMethod));
            $isManual = in_array($paymentMethodKey, ['manual', 'bank_transfer', 'cash', 'complimentary', 'life_member'], true);
            $orderItems = $membershipOrderItemsById[$order['id']] ?? [];
            $itemList = [];
            foreach ($orderItems as $item) {
                $itemList[] = [
                    'label' => $item['name'],
                    'quantity' => $item['quantity'],
                ];
            }
            if (!$itemList) {
                $itemList[] = ['label' => 'Membership order', 'quantity' => 1];
            }
            $orderHistory[] = [
                'type' => 'membership',
                'date' => $order['created_at'],
                'title' => 'Membership order ' . ($order['order_number'] ?? ''),
                'status' => $order['payment_status'] ?? $order['status'],
                'amount' => number_format((float) ($order['total'] ?? 0), 2),
                'items' => $itemList,
                'days_remaining_label' => $daysRemainingLabel,
                'is_manual' => $isManual,
                'source' => $order['payment_method'] ?? null,
                'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                'order_id' => $order['id'] ?? null,
                'order_number' => $order['order_number'] ?? null,
                'payment_status' => $order['payment_status'] ?? null,
            ];
        }

        foreach ($storeOrders as $order) {
            $items = $storeItemsByOrder[$order['id']] ?? [];
            $itemList = [];
            foreach ($items as $item) {
                $label = $item['title_snapshot'];
                if (!empty($item['variant_snapshot'])) {
                    $label .= ' (' . $item['variant_snapshot'] . ')';
                }
                $itemList[] = [
                    'label' => $label,
                    'quantity' => $item['quantity'],
                ];
            }
            $orderHistory[] = [
                'type' => 'store',
                'date' => $order['created_at'],
                'title' => 'Store order #' . ($order['order_number'] ?? ''),
                'status' => $order['status'],
                'amount' => $order['total'],
                'items' => $itemList,
                'order_id' => $order['id'],
            ];
        }

        usort($orderHistory, function ($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });

        $bikeColumns = [];
        $bikeHasPrimary = false;
        try {
            $bikeColumns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(PDO::FETCH_COLUMN, 0);
            $bikeHasPrimary = in_array('is_primary', $bikeColumns, true);
        } catch (Throwable $e) {
            $bikeColumns = [];
            $bikeHasPrimary = false;
        }
        $bikeOrder = $bikeHasPrimary ? 'is_primary DESC, created_at DESC' : 'created_at DESC';
        $stmt = $pdo->prepare('SELECT * FROM member_bikes WHERE member_id = :member_id ORDER BY ' . $bikeOrder);
        $stmt->execute(['member_id' => $profileMemberId]);
        $bikes = $stmt->fetchAll();

        $chapterRequestHasReason = false;
        try {
            $chapterRequestHasReason = (bool) $pdo->query("SHOW COLUMNS FROM chapter_change_requests LIKE 'rejection_reason'")->fetch();
        } catch (PDOException $e) {
            $chapterRequestHasReason = false;
        }
        $chapterRequestSelect = $chapterRequestHasReason ? ', r.rejection_reason' : '';
        $stmt = $pdo->prepare('SELECT r.id, c.name, r.status, r.requested_at, r.approved_at' . $chapterRequestSelect . ' FROM chapter_change_requests r JOIN chapters c ON c.id = r.requested_chapter_id WHERE r.member_id = :member_id ORDER BY r.requested_at DESC');
        $stmt->execute(['member_id' => $member['id']]);
        $chapterRequests = $stmt->fetchAll();

        $noticeStates = $australianStates;

        $stmt = $pdo->query('SELECT id, name, state FROM chapters WHERE is_active = 1 ORDER BY name ASC');
        $noticeChapters = $stmt->fetchAll();

        $fallenWings = [];
        $fallenYears = [];
        $fallenFilterYear = isset($_GET['fallen_year']) ? (int) $_GET['fallen_year'] : 0;
        try {
            $fallenTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'fallen_wings'")->fetch();
        } catch (PDOException $e) {
            $fallenTableExists = false;
        }

        if ($fallenTableExists) {
            $fallenSql = 'SELECT * FROM fallen_wings WHERE status = "APPROVED"';
            $fallenParams = [];
            if ($fallenFilterYear > 0) {
                $fallenSql .= ' AND year_of_passing = :year';
                $fallenParams['year'] = $fallenFilterYear;
            }
            $fallenSql .= ' ORDER BY full_name ASC';
            $stmt = $pdo->prepare($fallenSql);
            $stmt->execute($fallenParams);
            $fallenWings = $stmt->fetchAll();

            $stmt = $pdo->query('SELECT DISTINCT year_of_passing FROM fallen_wings WHERE status = "APPROVED" AND year_of_passing IS NOT NULL ORDER BY year_of_passing DESC');
            $fallenYears = array_filter(array_map('intval', array_column($stmt->fetchAll(), 'year_of_passing')));
        }
    }
}

$pageTitles = [
    'dashboard' => 'Member Dashboard',
    'profile' => 'Profile',
    'wings' => 'Wings Magazine',
    'notices-view' => 'Notice Board',
    'notices-create' => 'Create Notice',
    'fallen-wings' => 'Fallen Wings',
    'billing' => 'Billing & Payments',
    'history' => 'Membership History',
    'store' => 'Store',
    'settings' => 'Personal Settings',
];
$pageTitle = $pageTitles[$page] ?? 'Member Portal';
$activePage = in_array($page, ['notices-view', 'notices-create'], true) ? 'notices' : $page;
$activeSubPage = $page;

require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($page === 'dashboard'): ?>
        <section class="bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100 relative overflow-hidden">
          <div class="absolute top-0 right-0 p-4 opacity-10 pointer-events-none">
            <span class="material-icons-outlined text-9xl text-primary transform rotate-12">verified</span>
          </div>
          <div class="relative z-10">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
              <div>
                <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                  <?php
                    $welcomeName = trim((string) ($member['first_name'] ?? ''));
                    if ($welcomeName === '') {
                        $displayName = trim((string) ($user['name'] ?? ''));
                        if ($displayName === '' || strcasecmp($displayName, 'member') === 0) {
                            $displayName = trim((string) ($member['last_name'] ?? ''));
                        }
                        $welcomeName = $displayName !== '' ? $displayName : 'there';
                    }
                  ?>
                  Welcome back, <span class="text-primary"><?= e($welcomeName) ?></span>
                </h1>
                <p class="text-gray-500">Manage your membership, view events, and stay updated.</p>
              </div>
              <span class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-medium bg-primary text-gray-900 shadow-sm self-start md:self-center">
                <?= e($member['member_type'] ?? 'Member') ?> Member
              </span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 border-t border-gray-100 pt-6">
              <div>
                <p class="text-sm text-gray-500 mb-1">Membership #</p>
                <p class="text-lg font-semibold text-gray-900">
                  <?= $member ? e(MembershipService::displayMembershipNumber((int) $member['member_number_base'], (int) $member['member_number_suffix'])) : 'N/A' ?>
                </p>
              </div>
              <div>
                <p class="text-sm text-gray-500 mb-1">Status</p>
                <div class="flex items-center gap-2">
                  <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                  <p class="text-lg font-semibold text-gray-900"><?= e($member['status'] ?? 'N/A') ?></p>
                </div>
              </div>
              <div>
                <p class="text-sm text-gray-500 mb-1">Expiry Date</p>
                <p class="text-lg font-semibold text-gray-900">
                  <?= ($member && $member['member_type'] === 'LIFE') ? 'No expiry' : e($membershipPeriod['end_date'] ?? 'N/A') ?>
                </p>
              </div>
            </div>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col h-full">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-green-100 rounded-lg text-green-600">
                <span class="material-icons-outlined">bolt</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Quick Actions</h2>
            </div>
            <div class="space-y-3 flex-1">
              <a class="w-full group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition-all duration-200" href="/member/index.php?page=profile">
                <span class="font-medium text-gray-700 group-hover:text-green-700">Edit membership details</span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
              <a class="w-full group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition-all duration-200" href="/member/index.php?page=billing">
                <span class="font-medium text-gray-700 group-hover:text-green-700">Billing & payments</span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
              <a class="w-full group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition-all duration-200" href="/member/index.php?page=history">
                <span class="font-medium text-gray-700 group-hover:text-green-700">Membership history</span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
              <?php if ($membershipPeriod && $membershipPeriod['status'] === 'PENDING_PAYMENT'): ?>
                <a class="w-full group flex items-center justify-between p-3 rounded-xl border border-primary bg-primary/10 text-gray-900" href="/member/index.php?page=billing">
                  <span class="font-medium">Complete payment</span>
                  <span class="material-icons-outlined text-sm">payments</span>
                </a>
              <?php elseif ($membershipPeriod && $membershipPeriod['end_date'] && strtotime($membershipPeriod['end_date']) <= strtotime('+60 days')): ?>
                <a class="w-full group flex items-center justify-between p-3 rounded-xl border border-primary bg-primary/10 text-gray-900" href="/member/index.php?page=billing">
                  <span class="font-medium">Renew now</span>
                  <span class="material-icons-outlined text-sm">payments</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col h-full">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-blue-100 rounded-lg text-blue-600">
                <span class="material-icons-outlined">group</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Associates</h2>
            </div>
            <div class="bg-gray-50 rounded-xl p-4 flex-1">
              <?php if ($associates): ?>
                <ul class="space-y-4">
                  <?php foreach ($associates as $assoc): ?>
                    <li class="flex items-start gap-3">
                      <span class="w-2 h-2 mt-2 rounded-full bg-blue-500"></span>
                      <div>
                        <p class="text-sm font-medium text-gray-900"><?= e($assoc['first_name'] . ' ' . $assoc['last_name']) ?> (<?= e(MembershipService::displayMembershipNumber((int) $assoc['member_number_base'], (int) $assoc['member_number_suffix'])) ?>)</p>
                        <span class="inline-block mt-1 px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-800 rounded"><?= e($assoc['status']) ?></span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php elseif ($fullMember): ?>
                <p class="text-sm text-gray-600">Linked Full member:</p>
                <p class="text-base font-semibold text-gray-900"><?= e($fullMember['first_name'] . ' ' . $fullMember['last_name']) ?></p>
                <p class="text-sm text-gray-500">Member #: <?= e(MembershipService::displayMembershipNumber((int) $fullMember['member_number_base'], (int) $fullMember['member_number_suffix'])) ?></p>
                <p class="text-sm text-gray-500">Status: <?= e($fullMember['status']) ?></p>
              <?php else: ?>
                <p class="text-sm text-gray-500">No associates linked.</p>
              <?php endif; ?>
            </div>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col h-full">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-amber-100 rounded-lg text-amber-600">
                <span class="material-icons-outlined">receipt_long</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Billing Summary</h2>
            </div>
            <div class="space-y-4 flex-1">
              <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Last Payment</p>
                <p class="text-gray-900 font-medium mt-1"><?= e($membershipPeriod['paid_at'] ?? 'N/A') ?></p>
              </div>
              <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Status</p>
                <p class="text-green-600 font-medium mt-1 flex items-center gap-1">
                  <span class="material-icons-outlined text-sm">check_circle</span>
                  <?= e($membershipPeriod['status'] ?? 'N/A') ?>
                </p>
              </div>
            </div>
            <a class="mt-6 w-full py-2.5 px-4 rounded-xl border border-secondary text-secondary hover:bg-secondary hover:text-white transition-all font-medium text-sm" href="/member/index.php?page=billing">
              Update payment method
            </a>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-orange-100 rounded-lg text-orange-600">
                <span class="material-icons-outlined">badge</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Snapshot</h2>
            </div>
            <?php if ($member): ?>
            <div class="space-y-3 text-sm">
              <div class="flex flex-col pb-3 border-b border-gray-100">
                <span class="text-gray-500 text-xs uppercase">Email</span>
                <span class="font-medium text-gray-900 truncate"><?= e($member['email']) ?></span>
              </div>
              <div class="flex flex-col pb-3 border-b border-gray-100">
                <span class="text-gray-500 text-xs uppercase">Phone</span>
                <span class="font-medium text-gray-900 truncate"><?= e($member['phone'] ?? 'N/A') ?></span>
              </div>
              <div class="flex flex-col pb-3 border-b border-gray-100">
                <span class="text-gray-500 text-xs uppercase">Chapter</span>
                <span class="font-medium text-gray-900"><?= e($member['chapter_name'] ?? 'Unassigned') ?></span>
              </div>
              <div class="flex flex-col pb-3 border-b border-gray-100">
                <span class="text-gray-500 text-xs uppercase">Wings</span>
                <span class="font-medium text-gray-900"><?= e($member['wings_preference']) ?></span>
              </div>
              <div class="flex flex-col">
                <span class="text-gray-500 text-xs uppercase">Directory</span>
                <span class="font-medium text-gray-900"><?= e($member['privacy_level']) ?></span>
              </div>
            </div>
            <a class="mt-6 inline-flex items-center text-sm font-semibold text-secondary" href="/member/index.php?page=profile">
              Edit profile
              <span class="material-icons-outlined text-base ml-1">arrow_forward</span>
            </a>
            <?php endif; ?>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-indigo-100 rounded-lg text-indigo-600">
                <span class="material-icons-outlined">import_contacts</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Wings Magazine</h2>
            </div>
            <?php if ($wingsLatest): ?>
              <div class="flex items-start gap-4">
                <div class="w-24 aspect-[3/4] rounded-lg border border-gray-100 bg-white shadow-sm overflow-hidden">
                  <?php if (!empty($wingsLatest['cover_image_url'])): ?>
                    <img src="<?= e($wingsLatest['cover_image_url']) ?>" alt="<?= e($wingsLatest['title']) ?>" class="h-full w-full object-cover">
                  <?php else: ?>
                    <div class="h-full w-full flex items-center justify-center bg-gray-50 text-gray-300">
                      <span class="material-icons-outlined text-3xl">auto_stories</span>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="flex-1">
                  <p class="text-sm text-gray-500">Latest issue</p>
                  <p class="text-lg font-semibold text-gray-900"><?= e($wingsLatest['title']) ?></p>
                  <div class="mt-3 flex flex-wrap gap-2">
                    <a class="px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" href="<?= e($wingsLatest['pdf_url']) ?>" target="_blank" rel="noopener">Read Now</a>
                    <a class="px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold" href="/member/index.php?page=wings">Archive</a>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <p class="text-sm text-gray-500">No issues uploaded yet.</p>
            <?php endif; ?>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-teal-100 rounded-lg text-teal-600">
                <span class="material-icons-outlined">event</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Upcoming Events</h2>
            </div>
            <?php if ($upcomingEvents): ?>
              <ul class="space-y-3">
                <?php foreach ($upcomingEvents as $event): ?>
                  <li>
                    <a class="flex items-center justify-between gap-3 rounded-xl border border-transparent hover:border-teal-200 hover:bg-teal-50 px-2 py-2 transition" href="<?= e($event['url']) ?>">
                      <div>
                        <p class="text-sm font-medium text-gray-900"><?= e($event['title']) ?></p>
                        <p class="text-xs text-gray-500">
                          <?= e($event['date_label']) ?>
                          <?php if (!empty($event['location'])): ?>
                            • <?= e($event['location']) ?>
                          <?php endif; ?>
                        </p>
                      </div>
                      <span class="material-icons-outlined text-gray-400">chevron_right</span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-sm text-gray-500">No upcoming events.</p>
            <?php endif; ?>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-red-100 rounded-lg text-red-600">
                <span class="material-icons-outlined">campaign</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Notice Board</h2>
            </div>
            <?php if ($dashboardNotices): ?>
              <ul class="space-y-4">
                <?php foreach ($dashboardNotices as $notice): ?>
                  <?php
                    $avatar = $noticeAvatars[$notice['created_by']] ?? '';
                    $categoryLabel = ucfirst($notice['category'] ?? 'notice');
                  ?>
                  <li class="rounded-xl border border-gray-100 bg-white p-4">
                    <div class="flex items-center gap-3 mb-2">
                      <div class="h-9 w-9 rounded-full bg-red-50 text-red-600 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($avatar)): ?>
                          <img src="<?= e($avatar) ?>" alt="<?= e($notice['created_by_name'] ?? 'Member') ?>" class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-sm">person</span>
                        <?php endif; ?>
                      </div>
                      <div class="flex-1">
                        <p class="text-sm font-semibold text-gray-900"><?= e($notice['title']) ?></p>
                        <p class="text-xs text-gray-500"><?= e($categoryLabel) ?> • <?= e($notice['created_by_name'] ?? 'Member') ?></p>
                      </div>
                    </div>
                    <div class="prose prose-sm text-gray-600"><?= render_media_shortcodes($notice['content']) ?></div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-sm text-gray-500">No notices.</p>
            <?php endif; ?>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center gap-3 mb-6">
              <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                <span class="material-icons-outlined">receipt</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Order History</h2>
            </div>
            <?php $recentOrders = array_slice($orderHistory, 0, 5); ?>
            <?php if ($recentOrders): ?>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                      <th class="py-2 pr-3">Date</th>
                      <th class="py-2 pr-3">Type</th>
                      <th class="py-2 pr-3">Amount</th>
                      <th class="py-2">Status</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <?php foreach ($recentOrders as $order): ?>
                      <tr>
                        <td class="py-2 pr-3 text-gray-600"><?= e($order['date']) ?></td>
                        <td class="py-2 pr-3 text-gray-900"><?= e(ucfirst($order['type'])) ?></td>
                        <td class="py-2 pr-3 text-gray-900">$<?= e($order['amount']) ?></td>
                        <td class="py-2 text-gray-600"><?= e(ucfirst($order['status'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-sm text-gray-500">No orders yet.</p>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif ($page === 'profile'): ?>
        <?php
          $directoryPrefs = MemberRepository::directoryPreferences();
          $profileMembershipPeriod = $membershipPeriod;
          if ($profileMemberId !== $member['id']) {
              $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE member_id = :member_id ORDER BY end_date DESC LIMIT 1');
              $stmt->execute(['member_id' => $profileMemberId]);
              $profileMembershipPeriod = $stmt->fetch();
          }
          $profileMembershipOrders = $membershipOrders;
          if ($profileMemberId !== $member['id']) {
              $profileOrdersValue = $ordersMemberColumn ? orders_member_value($profileMember ?? [], $user ?? [], $ordersMemberColumn) : null;
              if ($ordersMemberColumn && $profileOrdersValue) {
                  $stmt = $pdo->prepare('SELECT * FROM orders WHERE ' . $ordersMemberColumn . ' = :value AND order_type = "membership" ORDER BY created_at DESC');
                  $stmt->execute(['value' => $profileOrdersValue]);
                  $profileMembershipOrders = $stmt->fetchAll();
              } else {
                  $profileMembershipOrders = [];
              }
          }
          $profileLatestOrder = $profileMembershipOrders[0] ?? null;
          $profileMemberNumber = '—';
          if (!empty($profileMember['member_number_base'])) {
              $profileMemberNumber = MembershipService::displayMembershipNumber((int) $profileMember['member_number_base'], (int) ($profileMember['member_number_suffix'] ?? 0));
          } elseif (!empty($profileMember['member_number'])) {
              $profileMemberNumber = $profileMember['member_number'];
          }
          $membershipTypeMap = [
              'FULL' => 'Full Member',
              'ASSOCIATE' => 'Associate Member',
              'LIFE' => 'Life Member',
          ];
          $profileMembershipTypeLabel = $membershipTypeMap[strtoupper((string) ($profileMember['member_type'] ?? ''))] ?? 'Member';
          $profileMembershipStatusKey = strtolower((string) ($profileMember['status'] ?? 'pending'));
          $profileMembershipStatusLabel = ucfirst($profileMembershipStatusKey);
          $profileAddressLines = array_filter([
              trim((string) ($profileMember['address_line1'] ?? '')),
              trim((string) ($profileMember['address_line2'] ?? '')),
              trim((string) ($profileMember['city'] ?? '')),
              trim((string) ($profileMember['state'] ?? '')),
              trim((string) ($profileMember['postal_code'] ?? '')),
              trim((string) ($profileMember['country'] ?? '')),
          ], static fn($line) => $line !== '');
          $primaryBike = null;
          if (!empty($bikes) && !empty($bikeHasPrimary)) {
              foreach ($bikes as $bike) {
                  if ((int) ($bike['is_primary'] ?? 0) === 1) {
                      $primaryBike = $bike;
                      break;
                  }
              }
          }
          if (!$primaryBike && !empty($bikes)) {
              $primaryBike = $bikes[0];
          }
          $primaryBikeYearLabel = $primaryBike ? ($primaryBike['year'] ?? '—') : '—';
          $primaryBikeRegoLabel = $primaryBike ? ($primaryBike['rego'] ?? '—') : '—';
          $profileRenewalLabel = strtoupper((string) ($profileMember['member_type'] ?? '')) === 'LIFE' ? 'N/A' : format_date($profileMembershipPeriod['end_date'] ?? null);
          $profileLastPaymentLabel = $profileLatestOrder ? format_datetime($profileLatestOrder['paid_at'] ?? $profileLatestOrder['created_at'] ?? null) : '—';
          $profilePaymentMethodLabel = $profileLatestOrder ? ($profileLatestOrder['payment_method'] ?? '') : '';
          $profilePaymentMethodLabel = $profilePaymentMethodLabel !== '' ? ucwords(str_replace('_', ' ', $profilePaymentMethodLabel)) : '—';
          $profileStatusClasses = status_badge_classes($profileMembershipStatusKey);
          $currentStatusLabel = strtoupper((string) ($profileMember['status'] ?? 'pending'));
          $profileChapterName = $profileMember['chapter_name'] ?? 'Unassigned';
          $joinedLabel = format_date($profileMember['created_at'] ?? null);
          $twofaStatusLabel = $twofaEnabled ? 'Enabled' : 'Not enabled';
          $twofaActionHref = $twofaEnabled ? '/member/2fa_verify.php' : '/member/2fa_enroll.php';
          $twofaActionLabel = $twofaEnabled ? 'Manage 2FA' : 'Setup 2FA';
          $profileActionUrl = '/member/index.php?page=profile';
          if ($profileMemberId !== $member['id']) {
              $profileActionUrl .= '&member_id=' . urlencode((string) $profileMemberId);
          }
        ?>
        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="border-b border-gray-100 px-8 py-6">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
              <div>
                <div class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-2 mb-3">
                  <span class="material-icons-outlined text-[16px]">arrow_back</span>
                  Member Profile
                  <?php if ($profileMemberId !== $member['id']): ?>
                    <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-[10px] font-semibold"><?= e($profileContextLabel) ?></span>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-4">
                  <div class="h-16 w-16 rounded-full border border-gray-200 bg-gray-50 overflow-hidden">
                    <?php if (!empty($avatarUrl)): ?>
                      <img src="<?= e($avatarUrl) ?>" alt="<?= e($profileMember['first_name'] . ' ' . $profileMember['last_name']) ?>" class="h-full w-full object-cover">
                    <?php else: ?>
                      <span class="flex h-full w-full items-center justify-center text-gray-400 font-semibold text-lg">
                        <?= e(substr($profileMember['first_name'] ?? '', 0, 1) . substr($profileMember['last_name'] ?? '', 0, 1)) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h1 class="text-4xl font-bold text-gray-900 font-display"><?= e($profileMember['first_name'] . ' ' . $profileMember['last_name']) ?></h1>
                    <p class="text-sm text-gray-500">
                      <?= e($profileContextNote !== '' ? $profileContextNote : 'Manage your personal profile information.') ?>
                    </p>
                  </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 mt-6">
                  <div class="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-white border border-gray-200 shadow-sm">
                    <div class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-500">
                      <span class="material-icons-outlined text-[18px]">tag</span>
                    </div>
                    <div class="flex flex-col">
                      <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Member ID</span>
                      <span class="text-sm font-bold text-gray-900"><?= e($profileMemberNumber) ?></span>
                    </div>
                  </div>
                  <div class="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-white border border-gray-200 shadow-sm">
                    <div class="w-9 h-9 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600">
                      <span class="material-icons-outlined text-[18px]">diversity_3</span>
                    </div>
                    <div class="flex flex-col">
                      <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Chapter</span>
                      <span class="text-sm font-bold text-gray-900"><?= e($profileChapterName) ?></span>
                    </div>
                  </div>
                  <div class="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-white border border-gray-200 shadow-sm">
                    <div class="w-9 h-9 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-600">
                      <span class="material-icons-outlined text-[18px]">calendar_month</span>
                    </div>
                    <div class="flex flex-col">
                      <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Joined</span>
                      <span class="text-sm font-bold text-gray-900"><?= e($joinedLabel) ?></span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="flex flex-col items-start gap-3">
                <span class="text-xs font-medium text-gray-500">Current Status</span>
                <div class="inline-flex items-center gap-2 rounded-lg bg-yellow-100 px-4 py-2 text-sm font-semibold text-yellow-700 border border-yellow-200 uppercase tracking-wider">
                  <span class="w-2 h-2 rounded-full bg-yellow-500"></span>
                  <?= e($currentStatusLabel) ?>
                </div>
                <?php if ($profileMemberId !== $member['id']): ?>
                  <a href="/member/index.php?page=profile" class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                    <span class="material-icons-outlined text-[16px]">arrow_back</span>
                    Back to my profile
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="p-5 space-y-6">
            <?php if ($profileMessage): ?>
              <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700"><?= e($profileMessage) ?></div>
            <?php endif; ?>
            <?php if ($profileError): ?>
              <div class="rounded-2xl border border-red-100 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700"><?= e($profileError) ?></div>
            <?php endif; ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
              <div class="lg:col-span-2 space-y-6">
                <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
                  <div class="p-6 border-b border-gray-100 flex items-center gap-3">
                    <div class="bg-primary/10 p-2 rounded-lg text-primary">
                      <span class="material-icons-outlined">badge</span>
                    </div>
                    <div>
                      <h2 class="text-lg font-bold text-gray-900">Contact &amp; billing</h2>
                      <p class="text-xs text-gray-500">Update contact details, billing address, and preferences for your membership.</p>
                    </div>
                  </div>
                  <div class="p-8">
                    <form method="post" action="/member/index.php?page=profile" class="space-y-8">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="update_profile">
                      <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label class="text-sm font-medium text-gray-700">Email</label>
                          <input type="email" name="email" value="<?= e($profileMember['email']) ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Phone</label>
                          <input type="text" name="phone" value="<?= e($profileMember['phone'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="md:col-span-2">
                          <label class="text-sm font-medium text-gray-700">Billing address line 1</label>
                          <input id="member_profile_address_line1" data-google-autocomplete="address" data-google-autocomplete-city="#member_profile_city" data-google-autocomplete-state="#member_profile_state" data-google-autocomplete-postal="#member_profile_postal" data-google-autocomplete-country="#member_profile_country" type="text" name="address_line1" value="<?= e($profileMember['address_line1'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="md:col-span-2">
                          <label class="text-sm font-medium text-gray-700">Billing address line 2</label>
                          <input id="member_profile_address_line2" type="text" name="address_line2" value="<?= e($profileMember['address_line2'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">City</label>
                          <input id="member_profile_city" type="text" name="city" value="<?= e($profileMember['city'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">State</label>
                          <input id="member_profile_state" type="text" name="state" value="<?= e($profileMember['state'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Postal code</label>
                          <input id="member_profile_postal" type="text" name="postal_code" value="<?= e($profileMember['postal_code'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Country</label>
                          <input id="member_profile_country" type="text" name="country" value="<?= e($profileMember['country'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                      </div>
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="text-sm font-medium text-gray-700">
                          Wings preference
                          <select name="wings_preference" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="digital" <?= $profileMember['wings_preference'] === 'digital' ? 'selected' : '' ?>>Digital</option>
                            <option value="print" <?= $profileMember['wings_preference'] === 'print' ? 'selected' : '' ?>>Print</option>
                            <option value="both" <?= $profileMember['wings_preference'] === 'both' ? 'selected' : '' ?>>Both</option>
                          </select>
                        </label>
                        <label class="text-sm font-medium text-gray-700">
                          Directory privacy
                          <select name="privacy_level" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="A" <?= $profileMember['privacy_level'] === 'A' ? 'selected' : '' ?>>A — Name only</option>
                            <option value="B" <?= $profileMember['privacy_level'] === 'B' ? 'selected' : '' ?>>B — Name + Address</option>
                            <option value="C" <?= $profileMember['privacy_level'] === 'C' ? 'selected' : '' ?>>C — Name + Address + Phone</option>
                            <option value="D" <?= $profileMember['privacy_level'] === 'D' ? 'selected' : '' ?>>D — Name + Address + Email</option>
                            <option value="E" <?= $profileMember['privacy_level'] === 'E' ? 'selected' : '' ?>>E — Name + Address + Phone + Email</option>
                            <option value="F" <?= $profileMember['privacy_level'] === 'F' ? 'selected' : '' ?>>F — Exclude from directory</option>
                          </select>
                        </label>
                      </div>
                      <div>
                        <p class="text-sm font-medium text-gray-700 mb-3">Directory preferences &amp; assistance</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-gray-600">
                          <?php foreach ($directoryPrefs as $letter => $info): ?>
                            <label class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                              <input type="checkbox" name="directory_pref_<?= e($letter) ?>" value="1" <?= !empty($profileMember[$info['column']] ?? null) ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                              <span><?= e($letter) ?> — <?= e($info['label']) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold">Save changes</button>
                      </div>
                    </form>
                  </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                      <span class="material-icons-outlined">card_membership</span>
                    </div>
                    <div>
                      <h2 class="text-lg font-bold text-gray-900">Membership summary</h2>
                      <p class="text-sm text-gray-500">Enrollment status, renewal, and primary bike.</p>
                    </div>
                  </div>
                  <div class="grid grid-cols-2 gap-4 text-sm text-gray-600">
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Type</p>
                      <p class="text-sm font-semibold text-gray-900"><?= e($profileMembershipTypeLabel) ?></p>
                    </div>
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Status</p>
                      <span class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold <?= $profileStatusClasses ?>">
                        <span class="w-2 h-2 rounded-full <?= strpos($profileStatusClasses, 'text-green') !== false ? 'bg-green-500' : 'bg-yellow-500' ?>"></span>
                        <?= e($profileMembershipStatusLabel) ?>
                      </span>
                    </div>
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Joined</p>
                      <p class="text-sm font-medium text-gray-900"><?= e($joinedLabel) ?></p>
                    </div>
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Renewal date</p>
                      <p class="text-sm font-medium text-gray-900"><?= e($profileRenewalLabel) ?></p>
                    </div>
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Last payment</p>
                      <p class="text-sm text-gray-500"><?= e($profileLastPaymentLabel) ?></p>
                    </div>
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Payment method</p>
                      <p class="text-sm text-gray-500"><?= e($profilePaymentMethodLabel) ?></p>
                    </div>
                  </div>
                  <div class="pt-4">
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-2">Primary bike</p>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                      <div>
                        <p class="text-xs text-gray-400 uppercase tracking-[0.3em] mb-1">Make</p>
                        <p class="text-sm font-medium text-gray-900"><?= e($primaryBike['make'] ?? '—') ?></p>
                      </div>
                      <div>
                        <p class="text-xs text-gray-400 uppercase tracking-[0.3em] mb-1">Model</p>
                        <p class="text-sm font-medium text-gray-900"><?= e($primaryBike['model'] ?? '—') ?></p>
                      </div>
                      <div>
                        <p class="text-xs text-gray-400 uppercase tracking-[0.3em] mb-1">Year</p>
                        <p class="text-sm font-medium text-gray-900"><?= e($primaryBikeYearLabel) ?></p>
                      </div>
                      <div>
                        <p class="text-xs text-gray-400 uppercase tracking-[0.3em] mb-1">Rego</p>
                        <p class="text-sm font-medium text-gray-900"><?= e($primaryBikeRegoLabel) ?></p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="space-y-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-orange-50 rounded-lg text-orange-600">
                      <span class="material-icons-outlined">bolt</span>
                    </div>
                    <div>
                      <h3 class="font-display text-lg font-bold text-gray-900">Quick actions</h3>
                      <p class="text-sm text-gray-500">Account essentials you can manage yourself.</p>
                    </div>
                  </div>
                  <div class="space-y-3">
                    <a class="w-full inline-flex items-center justify-center rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" href="/member/reset_password.php">
                      <span class="material-icons-outlined text-base">lock_reset</span>
                      <span class="ml-2">Reset password</span>
                    </a>
                    <div class="flex items-center justify-between rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-900">
                      <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-gray-400">Two-factor authentication</p>
                        <p><?= e($twofaStatusLabel) ?> (<?= strtolower($twofaRequirement) === 'required' ? 'required' : 'optional' ?>)</p>
                      </div>
                      <a class="inline-flex items-center gap-1 text-xs font-semibold text-primary" href="<?= e($twofaActionHref) ?>">
                        <?= e($twofaActionLabel) ?>
                        <span class="material-icons-outlined text-[16px]">open_in_new</span>
                      </a>
                    </div>
                    <a class="inline-flex items-center justify-center gap-2 rounded-full border border-primary bg-primary/10 px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/20" href="/?page=membership">
                      <span class="material-icons-outlined text-base">credit_card</span>
                      Membership &amp; billing
                    </a>
                  </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-indigo-100 rounded-lg text-indigo-600">
                      <span class="material-icons-outlined">hub</span>
                    </div>
                    <div>
                      <h3 class="font-display text-lg font-bold text-gray-900">Membership links</h3>
                      <p class="text-sm text-gray-500">Manage linked member profiles.</p>
                    </div>
                  </div>
                  <?php if (in_array($member['member_type'], ['FULL', 'LIFE'], true)): ?>
                    <?php if ($associates): ?>
                      <ul class="space-y-3 text-sm text-gray-700">
                        <?php foreach ($associates as $assoc): ?>
                          <li class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-white px-3 py-2">
                            <div>
                              <p class="font-medium text-gray-900"><?= e($assoc['first_name'] . ' ' . $assoc['last_name']) ?></p>
                              <p class="text-xs text-gray-500"><?= e(MembershipService::displayMembershipNumber((int) $assoc['member_number_base'], (int) $assoc['member_number_suffix'])) ?></p>
                            </div>
                            <a class="inline-flex items-center text-xs font-semibold text-secondary" href="/member/index.php?page=profile&member_id=<?= e((string) $assoc['id']) ?>">Edit</a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <p class="text-sm text-gray-500">No associates linked yet.</p>
                    <?php endif; ?>
                  <?php elseif ($member['member_type'] === 'ASSOCIATE' && $fullMember): ?>
                    <div class="rounded-lg border border-gray-100 bg-white px-3 py-3 text-sm text-gray-700">
                      <p class="font-medium text-gray-900"><?= e($fullMember['first_name'] . ' ' . $fullMember['last_name']) ?></p>
                      <p class="text-xs text-gray-500"><?= e(MembershipService::displayMembershipNumber((int) $fullMember['member_number_base'], (int) $fullMember['member_number_suffix'])) ?></p>
                      <a class="mt-3 inline-flex items-center text-xs font-semibold text-secondary" href="/member/index.php?page=profile&member_id=<?= e((string) $fullMember['id']) ?>">Edit</a>
                    </div>
                  <?php else: ?>
                    <p class="text-sm text-gray-500">No linked membership details available.</p>
                  <?php endif; ?>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-orange-100 rounded-lg text-orange-600">
                      <span class="material-icons-outlined">group</span>
                    </div>
                    <div>
                      <h3 class="font-display text-lg font-bold text-gray-900">Chapter change</h3>
                      <p class="text-sm text-gray-500">Request a move to another chapter.</p>
                    </div>
                  </div>
                  <?php if ($member['member_type'] === 'ASSOCIATE' && !empty($member['full_member_id'])): ?>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                      Chapter changes are managed by the full member linked to this profile.
                    </div>
                  <?php else: ?>
                    <form method="post" class="space-y-3">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="request_chapter">
                      <select name="requested_chapter_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Select chapter</option>
                        <?php foreach (ChapterRepository::listForSelection($pdo, true) as $chapter): ?>
                          <?php
                            $chapterLabel = $chapter['name'];
                            if (!empty($chapter['state'])) {
                                $chapterLabel .= ' (' . $chapter['state'] . ')';
                            }
                          ?>
                          <option value="<?= e((string) $chapter['id']) ?>"><?= e($chapterLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors" type="submit">Submit request</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($chapterRequests): ?>
                    <div class="mt-4 space-y-2 text-sm text-gray-600">
                      <?php foreach ($chapterRequests as $request): ?>
                        <div class="rounded-lg border border-gray-100 bg-white px-3 py-2">
                          <p class="font-semibold text-gray-900"><?= e($request['name']) ?></p>
                          <p class="text-xs text-gray-500">Requested: <?= e($request['requested_at']) ?></p>
                          <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Status: <?= e($request['status']) ?></p>
                          <?php if (!empty($request['rejection_reason'])): ?>
                            <p class="mt-1 text-xs text-red-600">Reason: <?= e($request['rejection_reason']) ?></p>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                  <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                      <div class="p-2 bg-yellow-100 rounded-lg text-yellow-600">
                        <span class="material-icons-outlined">two_wheeler</span>
                      </div>
                      <div>
                        <h3 class="font-display text-lg font-bold text-gray-900">My bikes</h3>
                        <p class="text-sm text-gray-500">Keep your garage up to date.</p>
                      </div>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-yellow-50 px-3 py-1 text-xs font-semibold text-yellow-700"><?= count($bikes) ?> Bikes</span>
                  </div>
                  <?php if ($bikes): ?>
                    <ul class="space-y-3 text-sm text-gray-700">
                      <?php foreach ($bikes as $bike): ?>
                        <li class="rounded-xl border border-gray-100 bg-white p-3 space-y-3">
                          <div class="flex gap-3">
                            <div class="h-16 w-20 rounded-lg bg-gray-50 overflow-hidden flex items-center justify-center">
                              <?php if (!empty($bike['image_url'])): ?>
                                <img src="<?= e($bike['image_url']) ?>" alt="<?= e($bike['make'] . ' ' . $bike['model']) ?>" class="h-full w-full object-cover">
                              <?php else: ?>
                                <span class="material-icons-outlined text-gray-300">two_wheeler</span>
                              <?php endif; ?>
                            </div>
                            <div class="flex-1">
                              <p class="font-semibold text-gray-900"><?= e($bike['make'] . ' ' . $bike['model']) ?></p>
                              <p class="text-xs text-gray-500"><?= e($bike['year'] ?? 'Year not set') ?></p>
                              <?php
                                $bikeColor = $bike['color'] ?? ($bike['colour'] ?? '');
                              ?>
                              <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <?php if (!empty($bike['rego'])): ?>
                                  <span class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-0.5 font-semibold text-yellow-700">Rego: <?= e($bike['rego']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($bikeColor)): ?>
                                  <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-600">Colour: <?= e($bikeColor) ?></span>
                                <?php endif; ?>
                              </div>
                            </div>
                            <?php if ($canManageProfileBikes): ?>
                              <form method="post" action="<?= e($profileActionUrl) ?>" class="ml-auto">
                                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="delete_bike">
                                <input type="hidden" name="bike_id" value="<?= e((string) $bike['id']) ?>">
                                <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                                <button class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50" type="submit" onclick="return confirm('Remove this bike?');">Remove</button>
                              </form>
                            <?php endif; ?>
                          </div>
                          <?php if ($canManageProfileBikes): ?>
                            <?php $bikeId = (int) $bike['id']; ?>
                            <form method="post" action="<?= e($profileActionUrl) ?>" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                              <input type="hidden" name="action" value="update_bike">
                              <input type="hidden" name="bike_id" value="<?= e((string) $bikeId) ?>">
                              <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                              <input type="text" name="bike_make" value="<?= e($bike['make'] ?? '') ?>" placeholder="Make" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="text" name="bike_model" value="<?= e($bike['model'] ?? '') ?>" placeholder="Model" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="number" name="bike_year" value="<?= e($bike['year'] ?? '') ?>" placeholder="Year" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="text" name="bike_color" value="<?= e($bikeColor ?? '') ?>" placeholder="Colour" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="text" name="bike_rego" value="<?= e($bike['rego'] ?? '') ?>" placeholder="Rego" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <div class="flex items-center gap-3">
                                <div id="bike-image-preview-<?= e((string) $bikeId) ?>" class="h-16 w-20 rounded-lg bg-gray-50 text-gray-300 flex items-center justify-center overflow-hidden">
                                  <?php if (!empty($bike['image_url'])): ?>
                                    <img src="<?= e($bike['image_url']) ?>" alt="<?= e($bike['make'] . ' ' . $bike['model']) ?>" class="h-full w-full object-cover">
                                  <?php else: ?>
                                    <span class="material-icons-outlined">image</span>
                                  <?php endif; ?>
                                </div>
                                <input type="hidden" name="bike_image_url" id="bike-image-url-input-<?= e((string) $bikeId) ?>" value="<?= e($bike['image_url'] ?? '') ?>">
                                <button type="button" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700" data-upload-trigger data-upload-target="bike-image-url-input-<?= e((string) $bikeId) ?>" data-upload-preview="bike-image-preview-<?= e((string) $bikeId) ?>" data-upload-context="bikes">Update bike image</button>
                              </div>
                              <?php if (!empty($bikeHasPrimary)): ?>
                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700">
                                  <input type="radio" name="is_primary" value="1" <?= (int) ($bike['is_primary'] ?? 0) === 1 ? 'checked' : '' ?> class="text-primary focus:ring-2 focus:ring-primary">
                                  Primary bike
                                </label>
                              <?php endif; ?>
                              <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Save changes</button>
                            </form>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <p class="text-sm text-gray-500">No bikes saved yet.</p>
                  <?php endif; ?>
                  <?php if ($canManageProfileBikes): ?>
                    <form method="post" action="<?= e($profileActionUrl) ?>" class="mt-4 space-y-2">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="add_bike">
                      <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                      <input type="text" name="bike_make" placeholder="Make" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_model" placeholder="Model" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="number" name="bike_year" placeholder="Year" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_color" placeholder="Colour" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_rego" placeholder="Rego" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <div class="flex items-center gap-3">
                        <div id="bike-image-preview" class="h-16 w-20 rounded-lg bg-gray-50 text-gray-300 flex items-center justify-center overflow-hidden">
                          <span class="material-icons-outlined">image</span>
                        </div>
                        <input type="hidden" name="bike_image_url" id="bike-image-url-input">
                        <button type="button" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700" data-upload-trigger data-upload-target="bike-image-url-input" data-upload-preview="bike-image-preview" data-upload-context="bikes">Upload bike image</button>
                      </div>
                      <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Add Bike</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </section>
      <?php elseif ($page === 'settings'): ?>
        <section class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900">Personal Settings</h1>
            <p class="text-gray-500 mt-2">Control your timezone, profile image, and notification preferences.</p>
          </div>
          <div class="flex items-center gap-3 rounded-full border border-gray-100 bg-white px-4 py-2 shadow-sm">
            <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center overflow-hidden">
              <?php if (!empty($avatarUrl)): ?>
                <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['name'] ?? 'Member') ?>" class="h-full w-full object-cover">
              <?php else: ?>
                <span class="material-icons-outlined">tune</span>
              <?php endif; ?>
            </div>
            <div class="text-sm">
              <p class="font-semibold text-gray-900"><?= e($user['name'] ?? 'Member') ?></p>
              <p class="text-primary font-medium">Account Settings</p>
            </div>
          </div>
        </section>
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-2 space-y-6">
            <?php if ($profileMessage): ?>
              <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($profileMessage) ?></div>
            <?php endif; ?>
            <?php if ($profileError): ?>
              <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($profileError) ?></div>
            <?php endif; ?>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                  <span class="material-icons-outlined">settings</span>
                </div>
                <div>
                  <h2 class="font-display text-xl font-bold text-gray-900">Account Preferences</h2>
                  <p class="text-sm text-gray-500">Keep your profile and alerts aligned with your needs.</p>
                </div>
              </div>
              <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="update_personal_settings">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <label class="text-sm font-medium text-gray-700">Timezone
                    <input name="user_timezone" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" value="<?= e($userTimezone) ?>" placeholder="Australia/Sydney">
                  </label>
                  <div>
                    <p class="text-sm font-medium text-gray-700">Profile image</p>
                    <div class="mt-2 flex items-center gap-3">
                      <div id="avatar-preview" class="h-14 w-14 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($avatarUrl)): ?>
                          <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['name'] ?? 'Member') ?>" class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-base">person</span>
                        <?php endif; ?>
                      </div>
                      <input type="hidden" name="avatar_url" id="avatar-url-input" value="<?= e($avatarUrl) ?>">
                      <button type="button" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700" data-upload-trigger data-upload-target="avatar-url-input" data-upload-preview="avatar-preview" data-upload-context="avatars">Upload image</button>
                    </div>
                  </div>
                </div>
                <div>
                  <p class="text-sm font-medium text-gray-700 mb-2">Account Notifications</p>
                  <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 space-y-3">
                    <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
                      <span class="font-medium">Email notifications</span>
                      <input type="checkbox" name="notify_master_enabled" <?= $masterNotificationsEnabled ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                    </label>
                    <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
                      <span class="font-medium">Unsubscribe from all non-essential emails</span>
                      <input type="checkbox" name="notify_unsubscribe_all" <?= $unsubscribeAll ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                    </label>
                    <p class="text-xs text-gray-500">Mandatory security and billing emails still send when required.</p>
                  </div>
                  <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($notificationCategories as $key => $label): ?>
                      <label class="flex items-center gap-2 rounded-xl border border-gray-100 bg-white px-3 py-2 text-sm text-gray-700">
                        <input type="checkbox" name="notify_category[<?= e($key) ?>]" <?= !empty($notificationPrefs['categories'][$key]) ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                        <?= e($label) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="flex items-center justify-between">
                  <a class="text-sm text-slate-500" href="/member/index.php?page=settings">Cancel</a>
                  <button class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Save settings</button>
                </div>
              </form>
            </div>
          </div>
          <div class="space-y-6">
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-3">
                <div class="p-2 bg-blue-100 rounded-lg text-blue-600">
                  <span class="material-icons-outlined">person</span>
                </div>
                <h3 class="font-display text-lg font-bold text-gray-900">Profile</h3>
              </div>
              <p class="text-sm text-gray-600">Update your membership details and contact information.</p>
              <a class="mt-4 inline-flex items-center text-sm font-semibold text-secondary" href="/member/index.php?page=profile">Edit profile</a>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-3">
                <div class="p-2 bg-emerald-100 rounded-lg text-emerald-600">
                  <span class="material-icons-outlined">shield</span>
                </div>
                <h3 class="font-display text-lg font-bold text-gray-900">Password & Security</h3>
              </div>
              <p class="text-sm text-gray-600">Reset your password or review account security.</p>
              <a class="mt-4 inline-flex items-center text-sm font-semibold text-secondary" href="/member/reset_password.php">Reset password</a>
            </div>
          </div>
        </section>
      <?php elseif ($page === 'activity'): ?>
        <section class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900">Activity</h1>
            <p class="text-gray-500 mt-2">Recent actions linked to your membership.</p>
          </div>
        </section>
        <section class="mt-6 space-y-4">
          <?php if ($memberActivity): ?>
            <?php foreach ($memberActivity as $entry): ?>
              <?php
                $actionKey = $entry['action'] ?? 'activity';
                $metadata = [];
                if (!empty($entry['metadata'])) {
                    $decoded = json_decode((string) $entry['metadata'], true);
                    if (is_array($decoded)) {
                        $metadata = $decoded;
                    }
                }
                $label = $actionKey === 'email.sent' ? 'Email sent' : ucwords(str_replace(['.', '_'], ' ', $actionKey));
              ?>
              <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between text-xs uppercase tracking-[0.2em] text-gray-500">
                  <span><?= e(format_datetime_au($entry['created_at'] ?? 'now')) ?></span>
                  <span><?= e(ucfirst($entry['actor_type'] ?? 'system')) ?></span>
                </div>
                <p class="mt-2 text-sm font-semibold text-gray-900"><?= e($label) ?></p>
                <?php if ($actionKey !== 'email.sent' && !empty($metadata)): ?>
                  <p class="text-xs text-gray-500 mt-1"><?= e(json_encode($metadata, JSON_UNESCAPED_SLASHES)) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">No activity recorded yet.</div>
          <?php endif; ?>
        </section>
      <?php elseif ($page === 'wings'): ?>
        <?php $issues = $pdo->query('SELECT * FROM wings_issues ORDER BY published_at DESC')->fetchAll(); ?>
        <?php $latestIssue = $wingsLatest ?: ($issues[0] ?? null); ?>
        <?php
          $issueYears = [];
          foreach ($issues as $issue) {
              if (!empty($issue['published_at'])) {
                  $yearValue = date('Y', strtotime($issue['published_at']));
                  $issueYears[$yearValue] = true;
              }
          }
          $issueYearOptions = array_keys($issueYears);
          rsort($issueYearOptions);
        ?>
        <section class="space-y-6">
          <div class="bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100 relative overflow-hidden">
            <div class="absolute -top-8 -right-6 h-24 w-24 rounded-full bg-primary/20 blur-2xl"></div>
            <div class="absolute top-0 right-0 p-4 opacity-10 pointer-events-none">
              <span class="material-icons-outlined text-9xl text-primary transform rotate-12">auto_stories</span>
            </div>
            <?php if ($latestIssue): ?>
              <div class="relative z-10 grid grid-cols-1 lg:grid-cols-[360px,1fr] gap-8 items-center">
                <div class="w-full max-w-[360px] xl:max-w-[420px]">
                  <div class="aspect-[3/4] rounded-2xl border border-gray-100 bg-white shadow-md overflow-hidden">
                    <?php if (!empty($latestIssue['cover_image_url'])): ?>
                      <img alt="<?= e($latestIssue['title']) ?>" class="w-full h-full object-cover" src="<?= e($latestIssue['cover_image_url']) ?>">
                    <?php else: ?>
                      <div class="w-full h-full flex items-center justify-center bg-gray-50">
                        <span class="material-icons-outlined text-6xl text-gray-300">import_contacts</span>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="space-y-4">
                  <div class="flex flex-wrap items-center gap-3 text-sm text-gray-500">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-primary/10 text-gray-900">Latest Issue</span>
                    <?php if (!empty($latestIssue['published_at'])): ?>
                      <span>Released <?= e(format_date_au($latestIssue['published_at'])) ?></span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h2 class="font-display text-3xl md:text-4xl font-bold text-gray-900"><?= e($latestIssue['title']) ?></h2>
                    <p class="text-base text-gray-600 mt-3">Catch up on the newest issue and explore past editions in the archive.</p>
                  </div>
                  <div class="flex flex-wrap gap-3">
                    <a class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" href="<?= e($latestIssue['pdf_url']) ?>" target="_blank" rel="noopener">
                      <span class="material-icons-outlined text-base">menu_book</span>
                      <span class="ml-2">Read Online</span>
                    </a>
                    <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors" href="<?= e($latestIssue['pdf_url']) ?>" download>
                      <span class="material-icons-outlined text-base">download</span>
                      <span class="ml-2">Download PDF</span>
                    </a>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="relative z-10">
                <h2 class="font-display text-2xl font-bold text-gray-900 mb-2">Wings Magazine</h2>
                <p class="text-sm text-gray-500">No issues available.</p>
              </div>
            <?php endif; ?>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <h3 class="font-display text-lg font-bold text-gray-900">Archive</h3>
                <?php if ($issues): ?>
                  <span id="wings-count" class="text-sm text-gray-500"><?= count($issues) ?> issues</span>
                <?php endif; ?>
              </div>
              <?php if ($issues): ?>
                <div class="flex flex-wrap items-center gap-3">
                  <div class="relative">
                    <span class="material-icons-outlined text-base text-gray-400 absolute left-3 top-1/2 -translate-y-1/2">search</span>
                    <input id="wings-search" class="pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg bg-white" placeholder="Search issues..." type="search">
                  </div>
                  <select id="wings-year" class="py-2 pl-3 pr-8 text-sm border border-gray-200 rounded-lg bg-white">
                    <option value="all">All years</option>
                    <?php foreach ($issueYearOptions as $yearOption): ?>
                      <option value="<?= e($yearOption) ?>"><?= e($yearOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
            </div>
            <?php if ($issues): ?>
              <div id="wings-archive-grid" class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <?php foreach ($issues as $issue): ?>
                  <?php
                    $publishedLabel = '';
                    $publishedYear = '';
                    if (!empty($issue['published_at'])) {
                        $publishedLabel = format_date_au($issue['published_at']);
                        $publishedYear = date('Y', strtotime($issue['published_at']));
                    }
                    $issueTitle = strtolower($issue['title'] ?? '');
                  ?>
                  <article class="wings-issue-card group bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow" data-title="<?= e($issueTitle) ?>" data-year="<?= e($publishedYear) ?>">
                    <div class="aspect-[3/4] bg-gray-50 overflow-hidden">
                      <?php if (!empty($issue['cover_image_url'])): ?>
                        <img alt="<?= e($issue['title']) ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" src="<?= e($issue['cover_image_url']) ?>">
                      <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center">
                          <span class="material-icons-outlined text-4xl text-gray-300">menu_book</span>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="p-4 space-y-2">
                      <h4 class="text-base font-semibold text-gray-900"><?= e($issue['title']) ?></h4>
                      <?php if ($publishedLabel): ?>
                        <p class="text-xs text-gray-500"><?= e($publishedLabel) ?></p>
                      <?php endif; ?>
                      <a class="inline-flex items-center text-sm font-semibold text-secondary" href="<?= e($issue['pdf_url']) ?>">
                        Read issue
                        <span class="material-icons-outlined text-base ml-1">arrow_forward</span>
                      </a>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
              <p id="wings-empty" class="hidden text-sm text-gray-500 mt-4">No issues match those filters.</p>
              <script>
                (() => {
                  const searchInput = document.getElementById('wings-search');
                  const yearSelect = document.getElementById('wings-year');
                  const cards = document.querySelectorAll('.wings-issue-card');
                  const emptyState = document.getElementById('wings-empty');
                  const countLabel = document.getElementById('wings-count');

                  if (!searchInput || !yearSelect || !cards.length) {
                    return;
                  }

                  const applyFilters = () => {
                    const term = searchInput.value.trim().toLowerCase();
                    const yearValue = yearSelect.value;
                    let visibleCount = 0;

                    cards.forEach((card) => {
                      const title = card.dataset.title || '';
                      const cardYear = card.dataset.year || '';
                      const matchesTerm = term === '' || title.includes(term);
                      const matchesYear = yearValue === 'all' || cardYear === yearValue;
                      const isVisible = matchesTerm && matchesYear;

                      card.classList.toggle('hidden', !isVisible);
                      if (isVisible) {
                        visibleCount += 1;
                      }
                    });

                    if (countLabel) {
                      countLabel.textContent = `${visibleCount} issue${visibleCount === 1 ? '' : 's'}`;
                    }
                    if (emptyState) {
                      emptyState.classList.toggle('hidden', visibleCount !== 0);
                    }
                  };

                  searchInput.addEventListener('input', applyFilters);
                  yearSelect.addEventListener('change', applyFilters);
                  applyFilters();
                })();
              </script>
            <?php else: ?>
              <p class="text-sm text-gray-500 mt-3">No issues available.</p>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif ($page === 'notices-create'): ?>
        <section class="space-y-6">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Create Notice</h2>
              <p class="text-sm text-gray-500">Submit a notice for admin or committee approval.</p>
            </div>
          </div>
          <?php if ($noticeMessage): ?>
            <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($noticeMessage) ?></div>
          <?php endif; ?>
          <?php if ($noticeError): ?>
            <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($noticeError) ?></div>
          <?php endif; ?>
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-amber-100 rounded-lg text-amber-600">
                  <span class="material-icons-outlined">edit_square</span>
                </div>
                <div>
                  <h3 class="font-display text-xl font-bold text-gray-900">Notice Details</h3>
                  <p class="text-sm text-gray-500">Choose who should receive the notice.</p>
                </div>
              </div>
              <form method="post" class="space-y-4" id="notice-create-form">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_notice">
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
                        <option value="<?= e($state['code']) ?>" <?= ($noticeFormState ?? '') === $state['code'] ? 'selected' : '' ?>><?= e($state['label']) ?> (<?= e($state['code']) ?>)</option>
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
                <button class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Submit for approval</button>
              </form>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                  <span class="material-icons-outlined">info</span>
                </div>
                <div>
                  <h3 class="font-display text-lg font-bold text-gray-900">Posting Tips</h3>
                  <p class="text-sm text-gray-500">Keep notices clear and actionable.</p>
                </div>
              </div>
              <ul class="text-sm text-gray-600 space-y-2">
                <li>Choose the right audience before posting.</li>
                <li>Use a PDF for detailed flyers or notices.</li>
                <li>Admins and committee approve notices before publishing.</li>
              </ul>
            </div>
          </div>
        </section>
      <?php elseif ($page === 'notices-view'): ?>
        <section class="space-y-6">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Notice Board</h2>
              <p class="text-sm text-gray-500">Browse the latest approved notices.</p>
            </div>
            <div class="inline-flex items-center rounded-lg border border-gray-200 bg-white p-1 text-sm">
              <button type="button" data-notice-view="list" class="px-3 py-1.5 rounded-md font-semibold text-gray-700">List view</button>
              <button type="button" data-notice-view="grid" class="px-3 py-1.5 rounded-md font-semibold text-gray-700">Grid view</button>
            </div>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-4">
              <h3 class="font-display text-xl font-bold text-gray-900">Current Notices</h3>
            </div>
            <?php if ($noticeBoardNotices): ?>
              <div id="notice-list" class="space-y-4">
                <?php foreach ($noticeBoardNotices as $notice): ?>
                  <?php
                    $creatorId = $notice['created_by'] ?? null;
                    $avatar = ($creatorId && isset($noticeAvatars[$creatorId])) ? $noticeAvatars[$creatorId] : '';
                    $category = $notice['category'] ?? 'notice';
                    $categoryLabel = $category === 'announcement' ? 'Important Announcement' : ucfirst($category);
                  ?>
                  <article class="border border-gray-100 rounded-2xl p-5 bg-white">
                    <div class="flex items-center gap-3 mb-3">
                      <div class="h-10 w-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($avatar)): ?>
                          <img src="<?= e($avatar) ?>" alt="<?= e($notice['created_by_name'] ?? 'Member') ?>" class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-sm">person</span>
                        <?php endif; ?>
                      </div>
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
                <?php foreach ($noticeBoardNotices as $notice): ?>
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
              <p class="text-sm text-gray-500">No notices available.</p>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif ($page === 'fallen-wings'): ?>
        <section class="space-y-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Fallen Wings</h2>
              <p class="text-sm text-gray-500">Remembering members who have taken their final ride.</p>
            </div>
            <form method="get" class="flex items-center gap-2">
              <input type="hidden" name="page" value="fallen-wings">
              <select name="fallen_year" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="0">All years</option>
                <?php foreach ($fallenYears as $yearOption): ?>
                  <option value="<?= e((string) $yearOption) ?>" <?= $fallenFilterYear === (int) $yearOption ? 'selected' : '' ?>><?= e((string) $yearOption) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold" type="submit">Filter</button>
            </form>
          </div>
          <?php if ($fallenMessage): ?>
            <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($fallenMessage) ?></div>
          <?php endif; ?>
          <?php if ($fallenError): ?>
            <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($fallenError) ?></div>
          <?php endif; ?>
          <?php if (!$fallenTableExists): ?>
            <div class="rounded-lg bg-amber-50 text-amber-700 px-4 py-2 text-sm">
              Fallen Wings table not found. Run the migration to enable memorial submissions.
            </div>
          <?php endif; ?>
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-6">
                <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                  <span class="material-icons-outlined">military_tech</span>
                </div>
                <div>
                  <h3 class="font-display text-xl font-bold text-gray-900">Memorial Roll</h3>
                  <p class="text-sm text-gray-500">Alphabetical by default, filter by year to narrow the list.</p>
                </div>
              </div>
              <?php if ($fallenWings): ?>
                <ul class="divide-y">
                          <?php foreach ($fallenWings as $entry): ?>
                    <li class="py-4">
                      <div class="flex items-center justify-between gap-4">
                        <div>
                          <p class="text-base font-semibold text-gray-900"><?= e($entry['full_name']) ?></p>
                          <?php if (!empty($entry['member_number'])): ?>
                            <p class="text-xs text-gray-500">Member #: <?= e($entry['member_number']) ?></p>
                          <?php endif; ?>
                          <?php if (!empty($entry['tribute'])): ?>
                            <p class="text-sm text-gray-600 mt-1"><?= e($entry['tribute']) ?></p>
                          <?php endif; ?>
                        </div>
                        <span class="text-sm font-semibold text-gray-500"><?= e((string) ($entry['year_of_passing'] ?? '')) ?></span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="text-sm text-gray-500">No memorial entries found.</p>
              <?php endif; ?>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-4">
                <div class="p-2 bg-amber-100 rounded-lg text-amber-600">
                  <span class="material-icons-outlined">add_circle</span>
                </div>
                <div>
                  <h3 class="font-display text-lg font-bold text-gray-900">Submit a Memorial</h3>
                  <p class="text-sm text-gray-500">Requests are reviewed by committee.</p>
                </div>
              </div>
              <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="submit_fallen_wings">
                <input type="text" name="fallen_name" placeholder="Member full name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
                <input type="date" name="fallen_date" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" min="1900-01-01" max="<?= e(date('Y-m-d')) ?>" required>
                <input type="text" name="fallen_member_number" maxlength="120" placeholder="Member number (optional)" value="<?= e($_POST['fallen_member_number'] ?? '') ?>" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" pattern="[A-Za-z0-9.\\-]+">
                <textarea name="fallen_tribute" rows="4" placeholder="Optional tribute or note" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"></textarea>
                <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Submit request</button>
              </form>
            </div>
          </div>
        </section>
      <?php elseif ($page === 'store'): ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="font-display text-2xl font-bold text-gray-900 mb-2">Store</h2>
          <p class="text-sm text-gray-500">Browse the members-only store.</p>
          <a class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold mt-4" href="/store">Go to Store</a>
        </section>
      <?php elseif ($page === 'billing'): ?>
        <section class="space-y-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Billing & Payments</h2>
              <p class="text-sm text-gray-500">Manage payment methods, shipping details, and recent orders.</p>
            </div>
            <?php if ($customerPortalEnabled): ?>
              <button id="billing-portal" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold">Manage payment details</button>
            <?php endif; ?>
          </div>
          <?php if ($billingMessage): ?>
            <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($billingMessage) ?></div>
          <?php endif; ?>
          <?php if ($billingError): ?>
            <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($billingError) ?></div>
          <?php endif; ?>
          <?php
            $pendingPeriod = null;
            $pendingMembershipOrder = null;
            $bankInstructions = (string) SettingsService::getGlobal('payments.bank_transfer_instructions', '');
            if ($member) {
                $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE member_id = :member_id AND status = "PENDING_PAYMENT" ORDER BY created_at DESC LIMIT 1');
                $stmt->execute(['member_id' => $member['id']]);
                $pendingPeriod = $stmt->fetch();
                $pendingMembershipOrder = null;
                if ($ordersMemberColumn && $ordersMemberValue) {
                    $stmt = $pdo->prepare('SELECT * FROM orders WHERE ' . $ordersMemberColumn . ' = :value AND order_type = "membership" AND ' . $ordersPaymentStatusColumn . ' IN ("pending", "failed") ORDER BY created_at DESC LIMIT 1');
                    $stmt->execute(['value' => $ordersMemberValue]);
                    $pendingMembershipOrder = $stmt->fetch();
                }
            }
            if (!$pendingMembershipOrder && $pendingPeriod && $member) {
                $termKey = normalize_membership_price_term((string) ($pendingPeriod['term'] ?? ''));
                $memberTypeKey = strtoupper((string) ($member['member_type'] ?? 'FULL')) === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL';
                $magazineType = strtolower((string) ($member['wings_preference'] ?? 'digital')) === 'digital' ? 'PDF' : 'PRINTED';
                $pricingPeriodKey = $termKey === '3Y' ? 'THREE_YEARS' : 'ONE_YEAR';
                $priceCents = MembershipPricingService::getPriceCents($magazineType, $memberTypeKey, $pricingPeriodKey) ?? 0;
                $pricingCurrency = MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD';
                $amount = round($priceCents / 100, 2);
                $order = MembershipOrderService::createMembershipOrder((int) $member['id'], (int) ($pendingPeriod['id'] ?? 0), $amount, [
                    'payment_method' => 'stripe',
                    'payment_status' => 'pending',
                    'fulfillment_status' => 'pending',
                    'currency' => $pricingCurrency,
                    'term' => $termKey,
                    'item_name' => 'Membership renewal ' . $termKey,
                ]);
                if ($order) {
                    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $order['id'] ?? 0]);
                    $pendingMembershipOrder = $stmt->fetch();
                }
            }
            $renewEligible = false;
            if ($member && strtoupper((string) ($member['member_type'] ?? '')) !== 'LIFE' && !empty($membershipPeriod['end_date'])) {
                $renewEligible = strtotime((string) $membershipPeriod['end_date']) <= strtotime('+60 days');
            }
            if (!empty($membershipPeriod['status']) && strtoupper((string) $membershipPeriod['status']) === 'LAPSED') {
                $renewEligible = true;
            }
          ?>
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-emerald-100 rounded-lg text-emerald-600">
                  <span class="material-icons-outlined">credit_card</span>
                </div>
                <div>
                  <h3 class="font-display text-lg font-bold text-gray-900">Membership status</h3>
                  <p class="text-sm text-gray-500">Track membership status and pending payments.</p>
                </div>
              </div>
              <?php
                $billingMembershipTypeLabel = 'Member';
                if ($member) {
                    $memberTypeKey = strtoupper((string) ($member['member_type'] ?? ''));
                    $billingMembershipTypeLabel = match ($memberTypeKey) {
                        'FULL' => 'Full Member',
                        'ASSOCIATE' => 'Associate Member',
                        'LIFE' => 'Life Member',
                        default => 'Member',
    };
}
                $billingStatusLabel = $member ? ucfirst(strtolower((string) ($member['status'] ?? 'pending'))) : '—';
                $billingExpiryLabel = ($member && strtoupper((string) ($member['member_type'] ?? '')) === 'LIFE') ? 'N/A' : format_date($membershipPeriod['end_date'] ?? null);
              ?>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm text-gray-600">
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Type</p>
                  <p class="text-sm font-semibold text-gray-900"><?= e($billingMembershipTypeLabel) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Status</p>
                  <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= status_badge_classes($billingStatusLabel) ?>"><?= e($billingStatusLabel) ?></span>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Expiry</p>
                  <p class="text-sm font-semibold text-gray-900"><?= e($billingExpiryLabel) ?></p>
                </div>
              </div>
              <?php if ($pendingMembershipOrder): ?>
                <?php
                  $pendingPaymentMethod = strtolower((string) ($pendingMembershipOrder['payment_method'] ?? 'stripe'));
                  $pendingStatus = strtolower((string) ($pendingMembershipOrder['payment_status'] ?? 'pending'));
                ?>
                <div class="rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 space-y-2">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      Pending order <span class="font-semibold"><?= e($pendingMembershipOrder['order_number'] ?? '') ?></span>
                    </div>
                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold <?= status_badge_classes($pendingStatus) ?>"><?= ucfirst($pendingStatus) ?></span>
                  </div>
                  <?php if ($pendingPaymentMethod === 'stripe'): ?>
                    <form method="post" class="mt-2">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="membership_order_pay">
                      <input type="hidden" name="order_id" value="<?= e((string) $pendingMembershipOrder['id']) ?>">
                      <button class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900" type="submit">Pay now</button>
                    </form>
                  <?php elseif ($pendingPaymentMethod === 'bank_transfer'): ?>
                    <?php if ($bankInstructions !== ''): ?>
                      <div class="rounded-lg bg-white/80 px-3 py-2 text-xs text-gray-700 whitespace-pre-line"><?= e($bankInstructions) ?></div>
                    <?php else: ?>
                      <p class="text-xs text-gray-600">Bank transfer instructions will be emailed to you.</p>
                    <?php endif; ?>
                  <?php else: ?>
                    <p class="text-xs text-gray-600">Awaiting payment approval.</p>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <p class="text-sm text-gray-600">No pending membership payments.</p>
              <?php endif; ?>
              <?php if ($renewEligible && !$pendingMembershipOrder): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="membership_renew">
                  <button class="inline-flex items-center rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700" type="submit">Renew membership</button>
                </form>
              <?php endif; ?>
              <?php if (!$customerPortalEnabled): ?>
                <p class="text-xs text-gray-500">Customer portal is disabled. Contact support for card updates.</p>
              <?php endif; ?>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-blue-100 rounded-lg text-blue-600">
                  <span class="material-icons-outlined">local_shipping</span>
                </div>
                <div>
                  <h3 class="font-display text-lg font-bold text-gray-900">Shipping Address</h3>
                  <p class="text-sm text-gray-500">Keep your shipping details current for store orders.</p>
                </div>
              </div>
              <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="update_shipping">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <input id="member_shipping_address_line1" data-google-autocomplete="address" data-google-autocomplete-city="#member_shipping_city" data-google-autocomplete-state="#member_shipping_state" data-google-autocomplete-postal="#member_shipping_postal" data-google-autocomplete-country="#member_shipping_country" type="text" name="shipping_address_line1" placeholder="Address line 1" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($member['address_line1'] ?? '') ?>">
                  <input id="member_shipping_address_line2" type="text" name="shipping_address_line2" placeholder="Address line 2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($member['address_line2'] ?? '') ?>">
                  <input id="member_shipping_city" type="text" name="shipping_city" placeholder="City" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($member['city'] ?? '') ?>">
                  <input id="member_shipping_state" type="text" name="shipping_state" placeholder="State" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($member['state'] ?? '') ?>">
                  <input id="member_shipping_postal" type="text" name="shipping_postal_code" placeholder="Postal code" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($member['postal_code'] ?? '') ?>">
                  <input id="member_shipping_country" type="text" name="shipping_country" placeholder="Country" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($member['country'] ?? '') ?>">
                </div>
                <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Save shipping address</button>
              </form>
            </div>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h3 class="font-display text-lg font-bold text-gray-900">Order History</h3>
                <p class="text-sm text-gray-500">All membership and store payments in one place.</p>
              </div>
            </div>
            <?php if ($orderHistory): ?>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                      <th class="py-2 pr-3">Date</th>
                      <th class="py-2 pr-3">Items</th>
                      <th class="py-2 pr-3">Status</th>
                      <th class="py-2 pr-3">Amount</th>
                      <th class="py-2 pr-3">Renewal</th>
                      <th class="py-2">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <?php foreach ($orderHistory as $order): ?>
                      <tr>
                        <td class="py-3 pr-3 text-gray-600"><?= e($order['date']) ?></td>
                        <td class="py-3 pr-3 text-gray-900">
                          <div class="space-y-1">
                            <?php foreach ($order['items'] as $item): ?>
                              <div><?= e($item['label']) ?> <span class="text-xs text-gray-500">x<?= e((string) $item['quantity']) ?></span></div>
                            <?php endforeach; ?>
                            <?php if (!empty($order['is_manual'])): ?>
                              <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-amber-700">Manual</span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="py-3 pr-3 text-gray-600"><?= e(ucfirst($order['status'])) ?></td>
                        <td class="py-3 pr-3 text-gray-900">$<?= e($order['amount']) ?></td>
                        <td class="py-3 pr-3 text-gray-600">
                          <?php if ($order['type'] === 'membership' && !empty($order['days_remaining_label'])): ?>
                            <?= e($order['days_remaining_label']) ?>
                          <?php else: ?>
                            &mdash;
                          <?php endif; ?>
                        </td>
                        <td class="py-3 text-gray-600">
                          <?php if ($order['type'] === 'membership' && in_array(strtolower((string) ($order['payment_status'] ?? $order['status'] ?? '')), ['pending', 'failed'], true) && in_array(strtolower((string) ($order['payment_method'] ?? 'stripe')), ['stripe', 'card', ''], true)): ?>
                            <form method="post">
                              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                              <input type="hidden" name="action" value="membership_order_pay">
                              <input type="hidden" name="order_id" value="<?= e((string) ($order['order_id'] ?? '')) ?>">
                              <button class="inline-flex items-center px-3 py-1.5 rounded-lg border border-primary text-xs font-semibold text-primary hover:bg-primary/10" type="submit">Pay now</button>
                            </form>
                          <?php elseif ($order['type'] === 'store' && !empty($order['order_id'])): ?>
                            <form method="post" action="/store/cart">
                              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                              <input type="hidden" name="action" value="reorder">
                              <input type="hidden" name="order_id" value="<?= e((string) $order['order_id']) ?>">
                              <button class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50" type="submit">Reorder</button>
                            </form>
                          <?php else: ?>
                            &mdash;
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-sm text-gray-500">No orders found.</p>
            <?php endif; ?>
          </div>
        </section>
        <?php if ($customerPortalEnabled): ?>
          <script>
            const portalButton = document.getElementById('billing-portal');
            if (portalButton) {
              portalButton.addEventListener('click', async () => {
                const response = await fetch('/api/billing/portal');
                const data = await response.json();
                if (data.url) {
                  window.location.href = data.url;
                  return;
                }
                alert(data.error || 'Unable to open billing portal.');
              });
            }
          </script>
        <?php endif; ?>
      <?php elseif ($page === 'history'): ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="font-display text-2xl font-bold text-gray-900 mb-4">Membership History</h2>
          <?php
            $history = [];
            if ($member) {
                $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE member_id = :member_id ORDER BY start_date DESC');
                $stmt->execute(['member_id' => $member['id']]);
                $history = $stmt->fetchAll();
            }
          ?>
          <?php if ($history): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                  <tr>
                    <th class="py-2 pr-3">Term</th>
                    <th class="py-2 pr-3">Start</th>
                    <th class="py-2 pr-3">End</th>
                    <th class="py-2">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <?php foreach ($history as $period): ?>
                    <tr>
                      <td class="py-2 pr-3 text-gray-900"><?= e($period['term']) ?></td>
                      <td class="py-2 pr-3 text-gray-600"><?= e($period['start_date']) ?></td>
                      <td class="py-2 pr-3 text-gray-600"><?= e($period['end_date'] ?? 'No expiry') ?></td>
                      <td class="py-2 text-gray-600"><?= e($period['status']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="text-sm text-gray-500">No membership history yet.</p>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="font-display text-2xl font-bold text-gray-900">Member Portal</h2>
          <p class="text-sm text-gray-500">Page not found.</p>
        </section>
      <?php endif; ?>
    </div>
    <div id="upload-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" data-csrf="<?= e(Csrf::token()) ?>">
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl border border-gray-200">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 class="font-display text-lg font-bold text-gray-900">Upload image</h3>
          <button type="button" class="text-gray-400 hover:text-gray-600" data-upload-close>
            <span class="material-icons-outlined">close</span>
          </button>
        </div>
        <div class="p-5 space-y-4">
          <div id="upload-dropzone" class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
            <p class="font-semibold text-gray-700">Drag & drop a file here</p>
            <p class="mt-1 text-xs">or click to browse from your computer</p>
            <input type="file" id="upload-file-input" class="hidden" accept="image/*">
          </div>
          <div id="upload-preview" class="hidden rounded-xl border border-gray-100 bg-white p-3 text-sm"></div>
          <div class="flex items-center justify-end gap-3">
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700" data-upload-cancel>Cancel</button>
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold disabled:opacity-50" data-upload-save disabled>Save</button>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
  (() => {
    const modal = document.getElementById('upload-modal');
    const dropzone = document.getElementById('upload-dropzone');
    const fileInput = document.getElementById('upload-file-input');
    const preview = document.getElementById('upload-preview');
    const saveBtn = document.querySelector('[data-upload-save]');
    const cancelBtn = document.querySelector('[data-upload-cancel]');
    const closeBtn = document.querySelector('[data-upload-close]');
    const csrfToken = modal ? modal.dataset.csrf : '';
    let activeTargetInput = null;
    let activeTargetPreview = null;
    let activeContext = 'members';
    let selectedFile = null;

    const resetModal = () => {
      selectedFile = null;
      saveBtn.disabled = true;
      preview.innerHTML = '';
      preview.classList.add('hidden');
      fileInput.value = '';
    };

    const closeModal = () => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      resetModal();
    };

    const openModal = (trigger) => {
      activeTargetInput = document.getElementById(trigger.dataset.uploadTarget || '');
      activeTargetPreview = document.getElementById(trigger.dataset.uploadPreview || '');
      activeContext = trigger.dataset.uploadContext || 'members';
      const accept = activeContext === 'notices' ? 'image/*,application/pdf' : 'image/*';
      fileInput.setAttribute('accept', accept);
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    };

    document.querySelectorAll('[data-upload-trigger]').forEach((trigger) => {
      trigger.addEventListener('click', () => openModal(trigger));
    });

    const setPreview = (file) => {
      preview.classList.remove('hidden');
      preview.innerHTML = '';
      if (file.type === 'application/pdf') {
        preview.innerHTML = `<div class="flex items-center gap-2 text-gray-600"><span class="material-icons-outlined text-base">picture_as_pdf</span>${file.name}</div>`;
        return;
      }
      const reader = new FileReader();
      reader.onload = (event) => {
        preview.innerHTML = `<img src="${event.target.result}" alt="Preview" class="w-full h-48 object-cover rounded-lg">`;
      };
      reader.readAsDataURL(file);
    };

    const handleFile = (file) => {
      if (!file) {
        return;
      }
      selectedFile = file;
      saveBtn.disabled = false;
      setPreview(file);
    };

    if (dropzone) {
      dropzone.addEventListener('click', () => fileInput.click());
      dropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        dropzone.classList.add('border-primary');
      });
      dropzone.addEventListener('dragleave', () => dropzone.classList.remove('border-primary'));
      dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropzone.classList.remove('border-primary');
        const file = event.dataTransfer.files[0];
        handleFile(file);
      });
    }

    if (fileInput) {
      fileInput.addEventListener('change', (event) => {
        const file = event.target.files[0];
        handleFile(file);
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

    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        if (!selectedFile || !activeTargetInput) {
          return;
        }
        saveBtn.disabled = true;
        const result = await uploadFile(selectedFile, activeContext);
        if (!result || result.error) {
          alert(result.error || 'Upload failed.');
          saveBtn.disabled = false;
          return;
        }
        activeTargetInput.value = result.url || '';
        if (activeTargetPreview) {
          if (result.type === 'pdf') {
            activeTargetPreview.innerHTML = '<span class="material-icons-outlined text-gray-400">picture_as_pdf</span>';
          } else {
            activeTargetPreview.innerHTML = `<img src="${result.url}" alt="Uploaded" class="h-full w-full object-cover">`;
          }
        }
        closeModal();
      });
    }

    [cancelBtn, closeBtn].forEach((btn) => {
      if (btn) {
        btn.addEventListener('click', closeModal);
      }
    });

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
      localStorage.setItem('noticeView', view);
    };
    if (viewButtons.length) {
      viewButtons.forEach((btn) => {
        btn.addEventListener('click', () => applyNoticeView(btn.dataset.noticeView));
      });
      const savedView = localStorage.getItem('noticeView') || 'list';
      applyNoticeView(savedView);
    }
  })();
</script>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
