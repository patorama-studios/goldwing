<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../includes/stripe_references.php';

use App\Services\AdminMemberAccess;
use App\Services\ActivityRepository;
use App\Services\AuditHubService;
use App\Services\AwardsService;
use App\Services\CommitteeService;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\DownloadLogRepository;
use App\Services\EventRsvpRepository;
use App\Services\MemberRepository;
use App\Services\MembershipMigrationService;
use App\Services\MembershipService;
use App\Services\OrderRepository;
use App\Services\RefundService;
use App\Services\SecurityPolicyService;
use App\Services\SettingsService;
use App\Services\ChapterRepository;
use App\Services\NotificationPreferenceService;

require_permission('admin.members.view');

$backUrl = '/admin/members/index.php';
$returnToCandidate = (string) ($_GET['return_to'] ?? '');
if ($returnToCandidate !== '' && str_starts_with($returnToCandidate, '/admin/members/index.php')) {
    $backUrl = $returnToCandidate;
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

function orders_member_value(?array $member, int $memberId, string $column): ?int
{
  if ($column === 'member_id') {
    return $memberId > 0 ? $memberId : null;
  }
  if ($column === 'user_id') {
    return !empty($member['user_id']) ? (int) $member['user_id'] : null;
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

$user = current_user();
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);
$memberId = (int) ($_GET['id'] ?? 0);
if ($memberId <= 0) {
  http_response_code(404);
  echo 'Member not found.';
  exit;
}

$member = MemberRepository::findById($memberId);
if (!$member) {
  http_response_code(404);
  echo 'Member not found.';
  exit;
}
if ($chapterRestriction !== null && ((int) ($member['chapter_id'] ?? 0)) !== $chapterRestriction) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

// Legacy ?tab=orders&order_id=N URLs now live at /admin/membership-orders/view.php.
// The orders TABLE on this page links there directly; this redirect keeps any
// bookmarks/emails/notifications generated before the move pointing at the
// right place.
if (
  ($_GET['tab'] ?? '') === 'orders'
  && ($_GET['orders_section'] ?? 'membership') === 'membership'
  && !empty($_GET['order_id'])
  && (int) $_GET['order_id'] > 0
) {
  header('Location: /admin/membership-orders/view.php?id=' . (int) $_GET['order_id']);
  exit;
}

$tabOptions = [
  'overview' => 'Overview',
  'profile' => 'Profile',
  'roles' => 'Roles & Access',
  'settings' => 'Member Settings',
  'vehicles' => 'Vehicles',
  'orders' => 'Membership & Billing',
  'refunds' => 'Refunds',
  'activity' => 'Activity',
];
$tabIcons = [
  'overview' => 'dashboard',
  'profile' => 'person',
  'roles' => 'admin_panel_settings',
  'settings' => 'tune',
  'vehicles' => 'two_wheeler',
  'orders' => 'credit_card',
  'refunds' => 'currency_exchange',
  'activity' => 'history',
];
$allowedTabs = array_keys($tabOptions);
$tab = $_GET['tab'] ?? 'overview';
if (!isset($tabOptions[$tab])) {
  $tab = 'overview';
}
$ordersSection = $_GET['orders_section'] ?? 'membership';
if (!in_array($ordersSection, ['membership', 'store'], true)) {
  $ordersSection = 'membership';
}

$pdo = Database::connection();
$chapters = [];
$membershipTypes = [];
try {
  $chapters = ChapterRepository::listForSelection($pdo, false);
} catch (Throwable $e) {
  $chapters = [];
}
try {
  $membershipStmt = $pdo->prepare('SELECT id, name FROM membership_types WHERE is_active = 1 ORDER BY name');
  $membershipStmt->execute();
  $membershipTypes = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $membershipTypes = [];
}
try {
  $roleOptions = admin_role_builder_candidates($pdo);
} catch (Throwable $e) {
  $roleOptions = [];
}
[$adminRoleOptions, $systemRoleOptions] = [[], []];
foreach ($roleOptions as $roleOption) {
  if (admin_role_is_admin($roleOption)) {
    $adminRoleOptions[] = $roleOption;
  } else {
    $systemRoleOptions[] = $roleOption;
  }
}

$allowedMembershipNames = ['Life', 'Full', 'Associate'];
$membershipTypes = array_values(array_filter($membershipTypes, fn($type) => in_array($type['name'] ?? '', $allowedMembershipNames, true)));
usort($membershipTypes, fn($a, $b) => array_search($a['name'] ?? '', $allowedMembershipNames, true) <=> array_search($b['name'] ?? '', $allowedMembershipNames, true));

$selectedMembershipTypeId = null;
if (array_key_exists('membership_type_id', $member)) {
  $selectedMembershipTypeId = $member['membership_type_id'] !== null ? (int) $member['membership_type_id'] : null;
} elseif (!empty($member['member_type'])) {
  $memberTypeKey = strtoupper($member['member_type'] ?? '');
  foreach ($membershipTypes as $type) {
    if (strtoupper($type['name'] ?? '') === $memberTypeKey) {
      $selectedMembershipTypeId = (int) $type['id'];
      break;
    }
  }
}

$directoryPrefs = MemberRepository::directoryPreferences();
$directorySummary = MemberRepository::summarizeDirectoryPreferences($member);
$orders = OrderRepository::listByMember($memberId, 25);
$refunds = RefundService::listByMember($memberId, 25);
$events = EventRsvpRepository::listByMember($memberId, 25);
$downloads = DownloadLogRepository::listByMember($memberId, 25);
$membershipPeriod = null;
$latestPayment = null;
$migrationToken = null;
$migrationEnabled = (bool) SettingsService::getGlobal('membership.manual_migration_enabled', true);
$migrationStatusLabel = 'Not sent';
$migrationStatusDetail = '';

try {
  // Prefer the member's ACTIVE (paid) period so an unpaid PENDING_PAYMENT
  // renewal never masquerades as their paid-through date. Fall back to the
  // latest period when there is no ACTIVE one.
  $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE member_id = :member_id ORDER BY (status = "ACTIVE") DESC, start_date DESC, end_date DESC LIMIT 1');
  $stmt->execute(['member_id' => $memberId]);
  $membershipPeriod = $stmt->fetch();
} catch (Throwable $e) {
  $membershipPeriod = null;
}

$membershipOrders = [];
$membershipOrderItems = [];
$membershipPeriodById = [];
$ordersMemberColumn = orders_member_column($pdo);
$ordersMemberValue = $ordersMemberColumn ? orders_member_value($member, $memberId, $ordersMemberColumn) : null;
$ordersPaymentStatusColumn = orders_payment_status_column($pdo);
try {
  if ($ordersMemberColumn && $ordersMemberValue) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE ' . $ordersMemberColumn . ' = :value AND order_type = "membership" ORDER BY created_at DESC LIMIT 25');
    $stmt->execute(['value' => $ordersMemberValue]);
    $membershipOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $membershipOrders = [];
  }
  $latestPayment = $membershipOrders[0] ?? null;
  $orderIds = array_column($membershipOrders, 'id');
  if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id IN (' . $placeholders . ') ORDER BY order_id ASC, id ASC');
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
      $membershipOrderItems[$item['order_id']][] = $item;
    }
  }
  $periodIds = array_values(array_filter(array_unique(array_map(static fn($order) => (int) ($order['membership_period_id'] ?? 0), $membershipOrders))));
  if ($periodIds) {
    $periodPlaceholders = implode(',', array_fill(0, count($periodIds), '?'));
    $periodStmt = $pdo->prepare('SELECT * FROM membership_periods WHERE id IN (' . $periodPlaceholders . ')');
    $periodStmt->execute($periodIds);
    foreach ($periodStmt->fetchAll(PDO::FETCH_ASSOC) as $period) {
      $membershipPeriodById[(int) $period['id']] = $period;
    }
  }
} catch (Throwable $e) {
  $membershipOrders = [];
  $membershipOrderItems = [];
  $membershipPeriodById = [];
  $latestPayment = null;
}
$selectedOrderId = (int) ($_GET['order_id'] ?? 0);
$selectedMembershipOrder = null;
$selectedMembershipOrderItems = [];
$selectedMembershipPeriod = null;
if ($selectedOrderId > 0 && $membershipOrders) {
  foreach ($membershipOrders as $order) {
    if ((int) ($order['id'] ?? 0) === $selectedOrderId) {
      $selectedMembershipOrder = $order;
      break;
    }
  }
  if ($selectedMembershipOrder) {
    $selectedMembershipOrderItems = $membershipOrderItems[$selectedOrderId] ?? [];
    $selectedMembershipPeriod = $membershipPeriodById[(int) ($selectedMembershipOrder['membership_period_id'] ?? 0)] ?? null;
  }
}

$agmRegistrations = [];
try {
  $agmRegistrations = \App\Services\AgmRegistrationService::listForMember((int) ($member['id'] ?? 0));
} catch (\Throwable $agmErr) {
  $agmRegistrations = [];
}

$userId = (int) ($member['user_id'] ?? 0);
$userTimezone = SettingsService::getUser($userId, 'timezone', SettingsService::getGlobal('site.timezone', 'Australia/Sydney'));
$notificationCategories = NotificationPreferenceService::categories();
$notificationPrefs = NotificationPreferenceService::load($userId);
// Avatar: prefer members.avatar_url (works for legacy members without a
// linked user account), fall back to settings_user when empty for older data.
$avatarUrl = '';
if (!empty($member['avatar_url'])) {
    $avatarUrl = (string) $member['avatar_url'];
} elseif ($userId > 0) {
    $avatarUrl = (string) SettingsService::getUser($userId, 'avatar_url', '');
}
$masterNotificationsEnabled = !empty($notificationPrefs['master_enabled']);
$unsubscribeAll = !empty($notificationPrefs['unsubscribe_all_non_essential']);

$membershipPeriodByPayment = [];

$profileMessage = '';
$profileError = '';

$associates = [];
$fullMember = null;
try {
  if (in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE full_member_id = :id');
    $stmt->execute(['id' => $memberId]);
    $associates = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($member['member_type'] === 'ASSOCIATE' && !empty($member['full_member_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
    $stmt->execute(['id' => $member['full_member_id']]);
    $fullMember = $stmt->fetch(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $associates = [];
  $fullMember = null;
}

$profileMember = $member;
$profileMemberId = $memberId;
$profileContextLabel = 'Member Profile';
$profileContextNote = '';
$profileContextName = '';
$requestedProfileId = (int) ($_GET['profile_member_id'] ?? 0);
if ($requestedProfileId && $requestedProfileId !== $memberId) {
  if (in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
    foreach ($associates as $assoc) {
      if ((int) ($assoc['id'] ?? 0) === $requestedProfileId) {
        $profileMember = MemberRepository::findById((int) $assoc['id']) ?? $assoc;
        $profileMemberId = (int) ($profileMember['id'] ?? $assoc['id']);
        $profileContextLabel = 'Linked Associate Profile';
        $profileContextNote = 'Editing linked associate contact details.';
        $profileContextName = trim(($profileMember['first_name'] ?? '') . ' ' . ($profileMember['last_name'] ?? ''));
        break;
      }
    }
  } elseif ($member['member_type'] === 'ASSOCIATE' && $fullMember && (int) ($fullMember['id'] ?? 0) === $requestedProfileId) {
    $profileMember = MemberRepository::findById((int) $fullMember['id']) ?? $fullMember;
    $profileMemberId = (int) ($profileMember['id'] ?? $fullMember['id']);
    $profileContextLabel = 'Linked Full Member Profile';
    $profileContextNote = 'Editing linked full member contact details.';
    $profileContextName = trim(($profileMember['first_name'] ?? '') . ' ' . ($profileMember['last_name'] ?? ''));
  }
}

$isLinkedProfile = $profileMemberId !== $memberId;
$profileMemberNumber = $profileMember['member_number_display'] ?? ($profileMember['member_number'] ?? '—');
if ($profileMemberNumber === '') {
  $profileMemberNumber = '—';
}
$profileMembershipTypeLabel = $profileMember['membership_type_name'] ?? ucfirst(strtolower((string) ($profileMember['member_type'] ?? 'Member')));
$profileStatusLabel = ucfirst(strtolower((string) ($profileMember['status'] ?? 'pending')));
$profileChapterLabel = $profileMember['chapter_name'] ?? 'Unassigned';
$profileJoinedLabel = formatDate($profileMember['join_date'] ?? $profileMember['created_at'] ?? null);

$chapterRequests = [];
$chapterRequestHasReason = false;
try {
  $chapterRequestHasReason = (bool) $pdo->query("SHOW COLUMNS FROM chapter_change_requests LIKE 'rejection_reason'")->fetch();
} catch (Throwable $e) {
  $chapterRequestHasReason = false;
}
try {
  $reasonSelect = $chapterRequestHasReason ? ', r.rejection_reason' : '';
  $stmt = $pdo->prepare('SELECT r.id, r.requested_chapter_id, c.name, r.status, r.requested_at, r.approved_at' . $reasonSelect . ' FROM chapter_change_requests r JOIN chapters c ON c.id = r.requested_chapter_id WHERE r.member_id = :member_id ORDER BY r.requested_at DESC');
  $stmt->execute(['member_id' => $memberId]);
  $chapterRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $chapterRequests = [];
}

$bikeColumns = [];
$bikeHasPrimary = false;
try {
  $bikeColumns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(PDO::FETCH_COLUMN, 0);
  $bikeHasPrimary = in_array('is_primary', $bikeColumns, true);
} catch (Throwable $e) {
  $bikeColumns = [];
  $bikeHasPrimary = false;
}

$bikes = [];
try {
  $bikeOrder = $bikeHasPrimary ? 'is_primary DESC, created_at DESC' : 'created_at DESC';
  $stmt = $pdo->prepare('SELECT * FROM member_bikes WHERE member_id = :member_id ORDER BY ' . $bikeOrder);
  $stmt->execute(['member_id' => $memberId]);
  $bikes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $bikes = [];
}

if ($migrationEnabled && !empty($member['manual_migration_disabled'])) {
  $migrationStatusLabel = 'Disabled for member';
} elseif (!$migrationEnabled) {
  $migrationStatusLabel = 'Disabled globally';
} else {
  $migrationToken = MembershipMigrationService::getLatestForMember($memberId);
  if ($migrationToken) {
    if (!empty($migrationToken['used_at'])) {
      $migrationStatusLabel = 'Used';
      $migrationStatusDetail = 'Used ' . formatDateTime($migrationToken['used_at']);
    } elseif (!empty($migrationToken['disabled_at'])) {
      $migrationStatusLabel = 'Disabled';
    } elseif (strtotime($migrationToken['expires_at']) <= time()) {
      $migrationStatusLabel = 'Expired';
      $migrationStatusDetail = 'Expired ' . formatDateTime($migrationToken['expires_at']);
    } else {
      $migrationStatusLabel = 'Sent';
      $migrationStatusDetail = 'Expires ' . formatDateTime($migrationToken['expires_at']);
    }
  }
}
$membershipTypeLabel = $member['membership_type_name'] ?? ucfirst(strtolower((string) ($member['member_type'] ?? 'Member')));
$membershipStatusKey = strtolower((string) ($member['status'] ?? 'pending'));
$membershipStatusLabel = ucfirst($membershipStatusKey);
$memberStatusForSelect = match ($membershipStatusKey) {
  'lapsed' => 'expired',
  'inactive' => 'suspended',
  default => $membershipStatusKey,
};
$lastPaymentDateLabel = $latestPayment ? formatDateTime($latestPayment['paid_at'] ?? $latestPayment['created_at'] ?? null) : '—';
$renewalDateLabel = (strtoupper((string) ($member['member_type'] ?? '')) === 'LIFE') ? 'N/A' : formatDate($membershipPeriod['end_date'] ?? null);
$paymentMethodLabel = $latestPayment ? ($latestPayment['payment_method'] ?? '') : '';
$paymentMethodLabel = $paymentMethodLabel !== '' ? ucwords(str_replace('_', ' ', $paymentMethodLabel)) : '—';
$paymentStatusLabel = $latestPayment ? ($latestPayment['payment_status'] ?? '') : '';
$paymentStatusLabel = $paymentStatusLabel !== '' ? ucfirst(strtolower((string) $paymentStatusLabel)) : '—';

$addressLines = array_filter([
  trim((string) ($member['address_line1'] ?? '')),
  trim((string) ($member['address_line2'] ?? '')),
  trim((string) ($member['suburb'] ?? $member['city'] ?? '')),
  trim((string) ($member['state'] ?? '')),
  trim((string) ($member['postal_code'] ?? '')),
  trim((string) ($member['country'] ?? '')),
], static fn($line) => $line !== '');

$primaryVehicle = null;
if ($bikeHasPrimary) {
  foreach ($bikes as $bike) {
    if ((int) ($bike['is_primary'] ?? 0) === 1) {
      $primaryVehicle = $bike;
      break;
    }
  }
}
if (!$primaryVehicle && !empty($bikes)) {
  $primaryVehicle = $bikes[0];
}
$primaryVehicleYearLabel = $primaryVehicle ? ($primaryVehicle['year'] ?? '—') : '—';
$primaryVehicleRegoLabel = $primaryVehicle ? ($primaryVehicle['rego'] ?? '—') : '—';

$activityActor = trim((string) ($_GET['activity_actor'] ?? ''));
$activityAction = trim((string) ($_GET['activity_action'] ?? ''));
$activityStartInput = trim((string) ($_GET['activity_start'] ?? ''));
$activityEndInput = trim((string) ($_GET['activity_end'] ?? ''));
$activityStartRange = $activityStartInput ? $activityStartInput . ' 00:00:00' : '';
$activityEndRange = $activityEndInput ? $activityEndInput . ' 23:59:59' : '';

$activityEntries = ActivityRepository::listByMember($memberId, [
  'actor_type' => $activityActor,
  'action' => $activityAction,
  'start' => $activityStartRange,
  'end' => $activityEndRange,
], 60);

$startLimit = $activityStartRange ? strtotime($activityStartRange) : null;
$endLimit = $activityEndRange ? strtotime($activityEndRange) : null;

$canEditFullProfile = AdminMemberAccess::canEditFullProfile($user);
$canEditContact = AdminMemberAccess::canEditContact($user);
$canEditAddress = AdminMemberAccess::canEditAddress($user);
$canResetPassword = AdminMemberAccess::canResetPassword($user);
$canSetPassword = AdminMemberAccess::canSetPassword($user);
$canImpersonate = AdminMemberAccess::canImpersonate($user);
$canRefund = AdminMemberAccess::canRefund($user);
$canManualFix = AdminMemberAccess::canManualOrderFix($user);
$canManageVehicles = AdminMemberAccess::canManageVehicles($user);
$canEditRoles = current_admin_can('admin.roles.manage', $user);
$canEditSettings = AdminMemberAccess::isFullAccess($user);
$canManageSecurity = current_admin_can('admin.users.edit', $user);
$memberHasUser = !empty($member['user_id']);

$flash = $_SESSION['members_flash'] ?? null;
$flashContext = $flash['context'] ?? null;
unset($_SESSION['members_flash']);

$memberRoles = [];
if (!empty($member['user_roles_csv'])) {
  $memberRoles = array_filter(array_map('trim', explode(',', $member['user_roles_csv'])));
}
$twofaRequirement = SecurityPolicyService::computeTwoFaRequirement([
  'id' => (int) ($member['user_id'] ?? 0),
  'roles' => $memberRoles,
]);
$twofaOverride = $member['twofa_override'] ?? 'DEFAULT';
$twofaEnforcement = $twofaOverride === 'EXEMPT'
  ? 'Exempt'
  : ($twofaRequirement === 'DISABLED' ? 'Disabled' : ($twofaRequirement === 'REQUIRED' ? 'Required' : 'Optional'));
$twofaStatus = !empty($member['twofa_enabled_at']) ? 'Enabled' : 'Not enabled';

$csrfToken = Csrf::token();

function statusBadgeClasses(string $status): string
{
  $status = strtolower(trim($status));
  return match ($status) {
    'active', 'paid', 'fulfilled', 'accepted', 'completed' => 'bg-green-100 text-green-800',
    'pending', 'processing', 'new', 'packed', 'shipped' => 'bg-yellow-100 text-yellow-800',
    'expired', 'lapsed', 'refunded', 'failed', 'rejected' => 'bg-red-100 text-red-800',
    'cancelled', 'inactive' => 'bg-gray-100 text-gray-800',
    'suspended' => 'bg-indigo-50 text-indigo-800',
    default => 'bg-slate-100 text-slate-800',
  };
}

function formatCurrency(?int $cents): string
{
  $cents = $cents ?? 0;
  return 'A$' . number_format($cents / 100, 2);
}

function buildTabUrl(int $memberId, string $tab, array $overrides = []): string
{
  $params = $_GET;
  $params['tab'] = $tab;
  $params['id'] = $memberId;
  foreach ($overrides as $key => $value) {
    if ($value === null) {
      unset($params[$key]);
      continue;
    }
    $params[$key] = $value;
  }
  return '/admin/members/view.php?' . http_build_query($params);
}

$matchesActivityFilters = function (string $timestamp, string $actorType, string $actionKey) use ($startLimit, $endLimit, $activityActor, $activityAction): bool {
  $ts = strtotime($timestamp);
  if ($startLimit !== null && $ts < $startLimit) {
    return false;
  }
  if ($endLimit !== null && $ts > $endLimit) {
    return false;
  }
  if ($activityActor && $actorType !== $activityActor) {
    return false;
  }
  if ($activityAction && stripos($actionKey, $activityAction) === false) {
    return false;
  }
  return true;
};

$timeline = [];
foreach ($activityEntries as $entry) {
  $actionKey = $entry['action'] ?? 'activity';
  if (!$matchesActivityFilters($entry['created_at'], $entry['actor_type'] ?? 'admin', $actionKey)) {
    continue;
  }
  $metadata = null;
  if (!empty($entry['metadata'])) {
    $decoded = json_decode($entry['metadata'], true);
    if ($decoded !== null) {
      $metadata = $decoded;
    }
  }
  $timeline[] = [
    'id' => $entry['id'] ?? null,
    'timestamp' => $entry['created_at'],
    'actor_type' => $entry['actor_type'] ?? 'admin',
    'source' => 'activity',
    'action' => $actionKey,
    'label' => ucwords(str_replace(['_', '.'], ' ', $actionKey)),
    'target' => '—',
    'metadata' => $metadata,
  ];
}

foreach ($events as $rsvp) {
  $actionKey = 'event_rsvp.' . ($rsvp['status'] ?? 'unknown');
  if (!$matchesActivityFilters($rsvp['created_at'], 'member', $actionKey)) {
    continue;
  }
  $timeline[] = [
    'timestamp' => $rsvp['created_at'],
    'actor_type' => 'member',
    'source' => 'event',
    'action' => $actionKey,
    'label' => 'Event RSVP — ' . ucfirst((string) ($rsvp['status'] ?? 'unknown')),
    'target' => $rsvp['event_title'] ?? 'Event',
    'metadata' => [
      'status' => $rsvp['status'],
      'event_date' => $rsvp['event_date'],
    ],
  ];
}

foreach ($downloads as $download) {
  $actionKey = 'download';
  if (!$matchesActivityFilters($download['created_at'], 'member', $actionKey)) {
    continue;
  }
  $fileLabel = $download['file_path'] ?? '';
  if ($fileLabel !== '') {
    $fileLabel = basename((string) $fileLabel);
  }
  $timeline[] = [
    'timestamp' => $download['created_at'],
    'actor_type' => 'member',
    'source' => 'download',
    'action' => $actionKey,
    'label' => 'Download recorded',
    'target' => $fileLabel !== '' ? $fileLabel : '—',
    'metadata' => [
      'file_path' => $download['file_path'],
      'ip_address' => $download['ip_address'],
    ],
  ];
}

foreach ($orders as $order) {
  $statusKey = $order['status'] ?? 'unknown';
  $actionKey = 'order.' . $statusKey;
  if (!$matchesActivityFilters($order['created_at'], 'member', $actionKey)) {
    continue;
  }
  $totalCents = (int) ($order['total_cents'] ?? 0);
  $timeline[] = [
    'timestamp' => $order['created_at'],
    'actor_type' => 'member',
    'source' => 'order',
    'action' => $actionKey,
    'label' => 'Order ' . ucfirst($statusKey),
    'target' => '#' . ($order['order_number'] ?? $order['id']),
    'metadata' => [
      'status' => $statusKey,
      'order_id' => $order['id'],
      'total' => '$' . number_format($totalCents / 100, 2),
    ],
  ];
}

foreach ($refunds as $refund) {
  $statusKey = $refund['status'] ?? 'unknown';
  $actionKey = 'refund.' . $statusKey;
  if (!$matchesActivityFilters($refund['created_at'], 'admin', $actionKey)) {
    continue;
  }
  $amountCents = (int) ($refund['amount_cents'] ?? 0);
  $timeline[] = [
    'timestamp' => $refund['created_at'],
    'actor_type' => 'admin',
    'source' => 'refund',
    'action' => $actionKey,
    'label' => 'Refund ' . ucfirst($statusKey),
    'target' => 'Order #' . ($refund['order_id'] ?? '—'),
    'metadata' => [
      'amount' => '$' . number_format($amountCents / 100, 2),
      'reason' => $refund['reason'],
    ],
  ];
}

usort($timeline, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));
$timeline = array_slice($timeline, 0, 60);

$pageTitle = 'Member: ' . ($member['first_name'] . ' ' . $member['last_name']);
$activePage = 'members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Members';
    require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($flash && $flashContext !== 'account_access'): ?>
        <div id="memberFlashBanner"
          class="rounded-2xl border p-4 text-sm flex items-start gap-3 <?= $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' ?>">
          <span class="material-icons-outlined text-[20px] mt-0.5 flex-shrink-0"><?= $flash['type'] === 'error' ? 'error_outline' : 'check_circle' ?></span>
          <span class="font-medium"><?= e($flash['message']) ?></span>
        </div>
        <script>
          (function () {
            var el = document.getElementById('memberFlashBanner');
            if (el && typeof el.scrollIntoView === 'function') {
              el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
          })();
        </script>
      <?php endif; ?>

      <?php if ($member['member_type'] === 'ASSOCIATE' && $fullMember): ?>
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-5 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="material-icons-outlined text-indigo-500 text-xl">account_tree</span>
            <div>
              <p class="text-xs font-bold uppercase tracking-wider text-indigo-500 mb-0.5">Household Member</p>
              <p class="text-sm text-indigo-900">This is an associate member. Their household account is managed by
                <strong><?= e($fullMember['first_name'] . ' ' . $fullMember['last_name']) ?></strong>
                (#<?= e(\App\Services\MembershipService::displayMembershipNumber((int) $fullMember['member_number_base'], (int) $fullMember['member_number_suffix'])) ?>).
              </p>
            </div>
          </div>
          <a href="/admin/members/view.php?id=<?= e((int) $fullMember['id']) ?>"
            class="shrink-0 inline-flex items-center gap-2 rounded-full border border-indigo-300 bg-white px-4 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition-colors">
            <span class="material-icons-outlined text-[16px]">person</span>
            View full member
          </a>
        </div>
      <?php endif; ?>

      <?php $isAdminViewLifeMember = strtoupper((string) ($member['member_type'] ?? '')) === 'LIFE'; ?>
      <section class="rounded-2xl border <?= $isAdminViewLifeMember ? 'border-yellow-300' : 'border-gray-200' ?> bg-white shadow-sm">
        <div class="border-b <?= $isAdminViewLifeMember ? 'border-yellow-200 bg-yellow-50' : 'border-gray-100' ?> px-8 py-6">
          <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
            <div>
              <a class="text-xs font-bold tracking-widest text-gray-400 uppercase inline-flex items-center gap-2 mb-3 hover:text-gray-500 transition-colors"
                href="<?= e($backUrl) ?>">
                <span class="material-icons-outlined text-[16px]">arrow_back</span>
                Member Profile
              </a>
              <div class="flex items-center gap-4">
                <div class="h-14 w-14 rounded-full <?= $isAdminViewLifeMember ? 'border-2 border-yellow-300 bg-yellow-200' : 'border border-gray-200 bg-gray-50' ?> overflow-hidden flex items-center justify-center">
                  <?php if ($avatarUrl): ?>
                    <img src="<?= e($avatarUrl) ?>"
                      alt="Avatar of <?= e($member['first_name'] . ' ' . $member['last_name']) ?>"
                      class="h-full w-full object-cover">
                  <?php elseif ($isAdminViewLifeMember): ?>
                    <span class="material-icons-outlined text-yellow-600 text-2xl">star</span>
                  <?php else: ?>
                    <span class="flex h-full w-full items-center justify-center text-gray-400 font-semibold text-lg">
                      <?= e(substr($member['first_name'] ?? '', 0, 1) . substr($member['last_name'] ?? '', 0, 1)) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-4xl font-bold <?= $isAdminViewLifeMember ? 'text-yellow-900' : 'text-gray-900' ?> font-display">
                      <?= e($member['first_name'] . ' ' . $member['last_name']) ?>
                    </h1>
                    <?php if ($isAdminViewLifeMember): ?>
                      <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-yellow-200 text-yellow-800 text-xs font-bold uppercase tracking-wide">
                        <span class="material-icons-outlined text-[12px]">star</span>Life Member
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="flex flex-wrap items-center gap-3 mt-6">
                <div class="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-white border border-gray-200 shadow-sm">
                  <div class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center text-gray-500">
                    <span class="material-icons-outlined text-[18px]">tag</span>
                  </div>
                  <div class="flex flex-col">
                    <span
                      class="text-[10px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">Member
                      ID</span>
                    <span
                      class="text-sm font-bold text-gray-900 leading-tight"><?= e($member['member_number_display'] ?? '—') ?></span>
                  </div>
                </div>
                <div class="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-white border border-gray-200 shadow-sm">
                  <div class="w-9 h-9 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600">
                    <span class="material-icons-outlined text-[18px]">diversity_3</span>
                  </div>
                  <div class="flex flex-col">
                    <span
                      class="text-[10px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">Chapter</span>
                    <span
                      class="text-sm font-bold text-gray-900 leading-tight"><?= e($member['chapter_name'] ?? 'Unassigned') ?></span>
                  </div>
                </div>
                <div class="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-white border border-gray-200 shadow-sm">
                  <div class="w-9 h-9 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-600">
                    <span class="material-icons-outlined text-[18px]">calendar_month</span>
                  </div>
                  <div class="flex flex-col">
                    <span
                      class="text-[10px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">Joined</span>
                    <span
                      class="text-sm font-bold text-gray-900 leading-tight"><?= e(formatDate($member['join_date'] ?? $member['created_at'] ?? null)) ?></span>
                  </div>
                </div>
                <?php if (!empty($member['is_historic'])): ?>
                  <div class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-stone-100 border border-stone-300 shadow-sm">
                    <span class="material-icons-outlined text-stone-600 text-[18px]">history</span>
                    <div class="flex flex-col">
                      <span class="text-[10px] font-bold text-stone-500 uppercase tracking-wider leading-none mb-0.5">Flag</span>
                      <span class="text-sm font-bold text-stone-800 leading-tight">Historic Vehicle</span>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="flex flex-col items-end">
              <span class="text-xs font-medium text-gray-500 mb-1">Current Status</span>
              <div
                class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-bold bg-yellow-100 text-yellow-700 border border-yellow-200 shadow-sm uppercase tracking-wide">
                <span class="w-2 h-2 rounded-full bg-yellow-500 mr-2"></span>
                <?= e(strtoupper((string) ($member['status'] ?? 'pending'))) ?>
              </div>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 overflow-x-auto pb-1 mt-8">
            <?php foreach ($tabOptions as $tabKey => $tabLabel): ?>
              <?php $icon = $tabIcons[$tabKey] ?? 'circle'; ?>
              <?php $tabUrl = buildTabUrl($memberId, $tabKey, $tabKey === 'profile' ? [] : ['profile_member_id' => null]); ?>
              <a class="group flex items-center gap-2 px-5 py-2.5 rounded-full <?= $tab === $tabKey ? 'bg-gray-900 text-white shadow-md ring-1 ring-black/5' : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-300 hover:bg-gray-50 shadow-sm' ?> transition-all"
                href="<?= e($tabUrl) ?>">
                <span
                  class="material-icons-outlined text-[18px] <?= $tab === $tabKey ? 'text-white' : 'text-gray-400 group-hover:text-gray-600' ?>"><?= e($icon) ?></span>
                <span class="text-sm font-medium"><?= e($tabLabel) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="p-5 space-y-6">
          <?php if ($tab === 'overview'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
              <div class="lg:col-span-2 space-y-6">
                <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-0 overflow-hidden">
                  <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <div class="flex items-center gap-3">
                      <div class="bg-primary/10 p-2 rounded-lg text-primary">
                        <span class="material-icons-outlined">badge</span>
                      </div>
                      <div>
                        <h2 class="text-lg font-bold text-gray-900">Contact Card</h2>
                        <p class="text-xs text-gray-500">Master record for
                          <?= e($member['first_name'] . ' ' . $member['last_name']) ?>
                        </p>
                      </div>
                    </div>
                    <span class="text-xs text-gray-400">Last login:
                      <?= $member['last_login_at'] ? formatDateTime($member['last_login_at']) : 'Never' ?></span>
                  </div>
                  <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-10">
                      <div class="space-y-6">
                        <h3
                          class="text-sm font-bold text-gray-900 border-b border-gray-100 pb-2 mb-4 flex items-center gap-2">
                          <span class="material-icons-outlined text-gray-400 text-base">person</span>
                          Contact Details
                        </h3>
                        <div class="grid grid-cols-1 gap-y-5">
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Full Name</h4>
                            <p class="text-sm font-medium text-gray-900">
                              <?= e($member['first_name'] . ' ' . $member['last_name']) ?>
                            </p>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Address</h4>
                            <?php if ($addressLines): ?>
                              <p class="text-sm font-medium text-gray-900 leading-relaxed">
                                <?= implode('<br>', array_map(fn($line) => e($line), $addressLines)) ?>
                              </p>
                            <?php else: ?>
                              <p class="text-sm text-gray-500">No address on file</p>
                            <?php endif; ?>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Email</h4>
                            <a class="text-sm font-medium text-primary hover:underline"
                              href="mailto:<?= e($member['email']) ?>"><?= e($member['email']) ?></a>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Phone</h4>
                            <p class="text-sm font-medium text-gray-900"><?= e($member['phone'] ?? '—') ?></p>
                          </div>
                        </div>
                      </div>
                      <div class="space-y-6">
                        <h3
                          class="text-sm font-bold text-gray-900 border-b border-gray-100 pb-2 mb-4 flex items-center gap-2">
                          <span class="material-icons-outlined text-gray-400 text-base">card_membership</span>
                          Membership Details
                        </h3>
                        <div class="grid grid-cols-2 gap-y-5 gap-x-4">
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Type</h4>
                            <p class="text-sm font-semibold text-gray-900"><?= e($membershipTypeLabel) ?></p>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Status</h4>
                            <span
                              class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-200">
                              <?= e(strtoupper((string) ($member['status'] ?? 'pending'))) ?>
                            </span>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Joined Date</h4>
                            <p class="text-sm font-medium text-gray-900"><?= e(formatDate($member['join_date'] ?? $member['created_at'] ?? null)) ?></p>
                            <?php if ($canEditFullProfile): ?>
                              <details class="mt-1">
                                <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-700 select-none">Edit join date</summary>
                                <form method="post" action="/admin/members/actions.php" class="mt-2 flex flex-wrap items-end gap-2">
                                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                  <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                  <input type="hidden" name="tab" value="overview">
                                  <input type="hidden" name="action" value="member_join_date_update">
                                  <input type="date" name="join_date" value="<?= e($member['join_date'] ?? '') ?>"
                                    class="rounded-lg border border-gray-200 px-2 py-1 text-sm text-gray-900">
                                  <button type="submit" class="rounded-lg bg-primary px-3 py-1 text-sm font-semibold text-gray-900">Save</button>
                                </form>
                                <p class="mt-1 text-[11px] text-gray-400">Leave blank to clear (falls back to record-created date).</p>
                              </details>
                            <?php endif; ?>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Renewal Date</h4>
                            <p class="text-sm font-medium text-gray-900"><?= e($renewalDateLabel) ?></p>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Last Payment</h4>
                            <p class="text-sm text-gray-500"><?= e($lastPaymentDateLabel) ?></p>
                          </div>
                          <div>
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Method</h4>
                            <p class="text-sm text-gray-500"><?= e($paymentMethodLabel) ?></p>
                          </div>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100">
                          <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Associate Member</h4>
                          <?php if (in_array($member['member_type'], ['FULL', 'LIFE'], true)): ?>
                            <?php if ($associates): ?>
                              <div class="space-y-2">
                                <?php foreach ($associates as $assoc): ?>
                                  <div
                                    class="flex items-center justify-between p-3 rounded-lg bg-gray-50 border border-gray-100">
                                    <div>
                                      <p class="text-sm font-semibold text-gray-900">
                                        <?= e($assoc['first_name'] . ' ' . $assoc['last_name']) ?>
                                      </p>
                                      <p class="text-xs text-gray-500">
                                        <?= e(\App\Services\MembershipService::displayMembershipNumber((int) $assoc['member_number_base'], (int) $assoc['member_number_suffix'])) ?>
                                      </p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                      <a class="text-xs font-semibold text-secondary"
                                        href="<?= e(buildTabUrl($memberId, 'profile', ['profile_member_id' => $assoc['id']])) ?>">View</a>
                                      <?php if ($canEditFullProfile): ?>
                                        <button type="button" data-associate-unlink
                                          data-associate-id="<?= e((int) $assoc['id']) ?>"
                                          class="text-xs font-semibold text-rose-600 hover:text-rose-700">Unlink</button>
                                      <?php endif; ?>
                                    </div>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php else: ?>
                              <div
                                class="flex items-center justify-between p-3 rounded-lg bg-gray-50 border border-gray-100 border-dashed">
                                <span class="text-sm text-gray-500 italic">No associates linked</span>
                                <button
                                  class="text-xs font-medium text-primary hover:text-primary/80 transition-colors flex items-center gap-1"
                                  type="button">
                                  <span class="material-icons-outlined text-sm">add_link</span>
                                  Link Associate
                                </button>
                              </div>
                            <?php endif; ?>
                          <?php elseif ($member['member_type'] === 'ASSOCIATE' && $fullMember): ?>
                            <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 border border-gray-100">
                              <div>
                                <p class="text-sm font-semibold text-gray-900">
                                  <?= e($fullMember['first_name'] . ' ' . $fullMember['last_name']) ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                  <?= e(\App\Services\MembershipService::displayMembershipNumber((int) $fullMember['member_number_base'], (int) $fullMember['member_number_suffix'])) ?>
                                </p>
                              </div>
                              <a class="text-xs font-semibold text-secondary"
                                href="<?= e(buildTabUrl($memberId, 'profile', ['profile_member_id' => $fullMember['id']])) ?>">View</a>
                            </div>
                          <?php else: ?>
                            <div
                              class="flex items-center justify-between p-3 rounded-lg bg-gray-50 border border-gray-100 border-dashed">
                              <span class="text-sm text-gray-500 italic">No associate linked</span>
                              <button
                                class="text-xs font-medium text-primary hover:text-primary/80 transition-colors flex items-center gap-1"
                                type="button">
                                <span class="material-icons-outlined text-sm">add_link</span>
                                Link Associate
                              </button>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                    <div class="mt-8 pt-8 border-t border-gray-100 grid grid-cols-1 md:grid-cols-2 gap-12">
                      <div>
                        <div class="flex items-center justify-between mb-3 gap-2">
                          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest">Directory Preferences</h3>
                          <a href="<?= e(buildTabUrl($memberId, 'profile')) ?>"
                            class="text-xs font-semibold text-primary hover:underline inline-flex items-center gap-1">
                            <span class="material-icons-outlined text-[14px]">edit</span>
                            Edit on Profile tab
                          </a>
                        </div>
                        <?php
                          $privacyLevel = strtoupper(trim((string) ($profileMember['privacy_level'] ?? 'A')));
                          $privacyLabels = [
                            'A' => 'Name only',
                            'B' => 'Name + Address',
                            'C' => 'Name + Address + Phone',
                            'D' => 'Name + Address + Email',
                            'E' => 'Name + Address + Phone + Email',
                            'F' => 'Excluded from directory',
                          ];
                          $privacyExcluded = ($privacyLevel === 'F');
                          $privacyLabel = $privacyLabels[$privacyLevel] ?? $privacyLevel;
                        ?>
                        <div class="mb-3 inline-flex items-center gap-2 rounded-lg border <?= $privacyExcluded ? 'border-red-200 bg-red-50 text-red-700' : 'border-gray-200 bg-white text-gray-700' ?> px-3 py-2 text-xs">
                          <span class="material-icons-outlined text-[14px]"><?= $privacyExcluded ? 'visibility_off' : 'visibility' ?></span>
                          <span class="font-semibold uppercase tracking-wide">Privacy <?= e($privacyLevel) ?></span>
                          <span class="opacity-70">— <?= e($privacyLabel) ?></span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                          <?php foreach ($directorySummary as $letter => $enabled): ?>
                            <?php $title = $directoryPrefs[$letter]['label'] ?? ''; ?>
                            <span
                              title="<?= e($title) ?>"
                              class="inline-flex items-center px-2 py-1 rounded text-[10px] font-medium border <?= $enabled ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-50 text-gray-400 border-gray-200 line-through' ?>">
                              <span class="font-bold mr-1"><?= e($letter) ?></span>
                              <?= e($title) ?>
                            </span>
                          <?php endforeach; ?>
                          <?php if (empty($directorySummary)): ?>
                            <span class="text-xs text-gray-400 italic">No preference columns available on this database.</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div>
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Primary Bike</h3>
                        <div class="w-full overflow-hidden rounded-lg border border-gray-200">
                          <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <tbody class="divide-y divide-gray-200 bg-white">
                              <tr>
                                <td
                                  class="px-3 py-2 text-gray-500 bg-gray-50 w-1/3 text-xs font-medium uppercase tracking-wide">
                                  Make</td>
                                <td class="px-3 py-2 text-gray-900 font-medium"><?= e($primaryVehicle['make'] ?? '—') ?>
                                </td>
                              </tr>
                              <tr>
                                <td
                                  class="px-3 py-2 text-gray-500 bg-gray-50 w-1/3 text-xs font-medium uppercase tracking-wide">
                                  Model</td>
                                <td class="px-3 py-2 text-gray-900 font-medium"><?= e($primaryVehicle['model'] ?? '—') ?>
                                </td>
                              </tr>
                              <tr>
                                <td
                                  class="px-3 py-2 text-gray-500 bg-gray-50 w-1/3 text-xs font-medium uppercase tracking-wide">
                                  Year</td>
                                <td class="px-3 py-2 text-gray-900 font-medium"><?= e($primaryVehicleYearLabel) ?></td>
                              </tr>
                              <tr>
                                <td
                                  class="px-3 py-2 text-gray-500 bg-gray-50 w-1/3 text-xs font-medium uppercase tracking-wide">
                                  Rego</td>
                                <td class="px-3 py-2 text-gray-900 font-medium"><?= e($primaryVehicleRegoLabel) ?></td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="space-y-6">
                <?php
                  // ── AGM Awards trophy cabinet ─────────────────────────────
                  // Pulls every award this member has won across all years.
                  // Skips silently if the awards tables haven't been migrated
                  // yet so old installs don't blow up.
                  $memberAwards = AwardsService::winnersForMember((int) $member['id']);
                ?>
                <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
                  <div class="p-6 border-b border-gray-100 flex items-center justify-between bg-gradient-to-br from-amber-50/50 to-white">
                    <div class="flex items-center gap-3">
                      <div class="bg-amber-500/10 p-2 rounded-lg text-amber-700">
                        <span class="material-icons-outlined">workspace_premium</span>
                      </div>
                      <div>
                        <h2 class="text-sm font-bold text-gray-900">AGM Awards</h2>
                        <p class="text-xs text-gray-500">
                          <?php if ($memberAwards): ?>
                            <?= count($memberAwards) ?> trophy<?= count($memberAwards) === 1 ? '' : 'ies' ?>
                            across <?= count(array_unique(array_column($memberAwards, 'year'))) ?> year<?= count(array_unique(array_column($memberAwards, 'year'))) === 1 ? '' : 's' ?>
                          <?php else: ?>
                            No trophies recorded yet
                          <?php endif; ?>
                        </p>
                      </div>
                    </div>
                    <?php if (function_exists('current_admin_can') && current_admin_can('admin.awards.manage', $user)): ?>
                      <a href="/admin/awards/" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:text-amber-900">
                        Awards
                        <span class="material-icons-outlined text-sm">arrow_forward</span>
                      </a>
                    <?php endif; ?>
                  </div>
                  <?php if ($memberAwards): ?>
                    <ul class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
                      <?php foreach ($memberAwards as $aw): ?>
                        <li class="px-5 py-3 flex items-start gap-3">
                          <?php if (!empty($aw['primary_photo'])): ?>
                            <img src="<?= e($aw['primary_photo']) ?>" alt="" class="w-12 h-12 rounded-lg object-cover border border-gray-200 shrink-0">
                          <?php else: ?>
                            <div class="w-12 h-12 rounded-lg bg-amber-50 border border-amber-100 flex items-center justify-center shrink-0">
                              <span class="material-icons-outlined text-amber-400 text-xl">emoji_events</span>
                            </div>
                          <?php endif; ?>
                          <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate"><?= e($aw['category_name']) ?></p>
                            <?php if (!empty($aw['memorial_trophy_name'])): ?>
                              <p class="text-[10px] uppercase tracking-wider font-bold text-amber-700"><?= e($aw['memorial_trophy_name']) ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 mt-0.5">
                              <?= (int) $aw['year'] ?>
                              <?php if (!empty($aw['bike_description'])): ?>
                                · <?= e($aw['bike_description']) ?>
                              <?php endif; ?>
                            </p>
                          </div>
                          <?php if (function_exists('current_admin_can') && current_admin_can('admin.awards.manage', $user)): ?>
                            <a href="/admin/awards/edit.php?id=<?= (int) $aw['id'] ?>" class="text-xs text-gray-400 hover:text-primary shrink-0" title="Edit winner record">
                              <span class="material-icons-outlined text-base">edit</span>
                            </a>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="p-6 text-center">
                      <span class="material-icons-outlined text-amber-300 text-4xl">emoji_events</span>
                      <p class="text-sm text-gray-500 mt-2">This member hasn't won an AGM trophy yet.</p>
                      <?php if (function_exists('current_admin_can') && current_admin_can('admin.awards.manage', $user)): ?>
                        <a href="/admin/awards/" class="inline-flex items-center gap-1 mt-3 text-xs font-semibold text-amber-700 hover:text-amber-900">
                          Browse the trophy wall
                          <span class="material-icons-outlined text-sm">arrow_forward</span>
                        </a>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-6">
                  <h3 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">bolt</span>
                    Quick Actions
                  </h3>
                  <?php if ($flash && $flashContext === 'account_access'): ?>
                    <div id="accountAccessFlash" class="rounded-lg px-4 py-3 text-sm mb-4 flex items-start gap-2 <?= $flash['type'] === 'error' ? 'border border-red-200 bg-red-50 text-red-700' : 'border border-green-200 bg-green-50 text-green-700' ?>">
                      <span class="material-icons-outlined text-[18px] mt-0.5 flex-shrink-0"><?= $flash['type'] === 'error' ? 'error_outline' : 'check_circle' ?></span>
                      <span><?= e($flash['message']) ?></span>
                    </div>
                    <script>document.getElementById('accountAccessFlash')?.scrollIntoView({behavior:'smooth',block:'center'});</script>
                  <?php endif; ?>
                  <div class="space-y-3 mb-8">
                    <?php if ($canImpersonate && $memberHasUser): ?>
                      <form method="post" action="/admin/members/actions.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="overview">
                        <input type="hidden" name="action" value="impersonate_member">
                        <button
                          class="w-full inline-flex justify-center items-center px-4 py-2.5 border border-primary shadow-sm text-sm font-semibold rounded-lg text-white bg-primary hover:bg-primary/90 transition-all">
                          <span class="material-icons-outlined mr-2 text-[18px]">visibility</span>
                          View as Member
                        </button>
                      </form>
                    <?php else: ?>
                      <button
                        class="w-full inline-flex justify-center items-center px-4 py-2.5 border border-gray-200 shadow-sm text-sm font-medium rounded-lg text-gray-400 bg-gray-50 cursor-not-allowed"
                        type="button" disabled>
                        <span class="material-icons-outlined mr-2 text-[18px]">visibility</span>
                        View as Member
                      </button>
                    <?php endif; ?>
                    <?php if ($canResetPassword): ?>
                      <form method="post" action="/admin/members/actions.php"
                        data-confirm-email-form
                        data-member-name="<?= e(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?>"
                        data-member-email="<?= e($member['email'] ?? '') ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="overview">
                        <input type="hidden" name="action" value="send_welcome_email">
                        <button type="button" data-confirm-email-trigger
                          class="w-full inline-flex justify-center items-center px-4 py-2.5 border border-blue-200 shadow-sm text-sm font-medium rounded-lg text-blue-700 bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-all">
                          <span class="material-icons-outlined mr-2 text-[18px]">mark_email_read</span>
                          Send welcome email
                        </button>
                      </form>
                      <form method="post" action="/admin/members/actions.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="overview">
                        <input type="hidden" name="action" value="send_reset_link">
                        <button
                          class="w-full inline-flex justify-center items-center px-4 py-2.5 border border-gray-200 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 hover:border-gray-300 transition-all">
                          <span class="material-icons-outlined mr-2 text-[18px]">lock_reset</span>
                          Send password reset link
                        </button>
                      </form>
                    <?php endif; ?>
                    <?php if ($canSetPassword): ?>
                      <details class="group">
                        <summary
                          class="list-none w-full inline-flex justify-between items-center px-4 py-2.5 border border-gray-200 text-sm font-medium rounded-lg text-gray-700 bg-gray-50 hover:bg-white transition-colors cursor-pointer">
                          <span class="flex items-center">
                            <span class="material-icons-outlined mr-2 text-[18px] text-gray-500">key</span>
                            Set password <span class="text-[10px] text-gray-400 ml-1 font-normal">(admin only)</span>
                          </span>
                          <span
                            class="material-icons-outlined text-gray-400 group-open:text-gray-600 text-[18px]">arrow_right</span>
                        </summary>
                        <div class="mt-3 space-y-3">
                          <form method="post" action="/admin/members/actions.php" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                            <input type="hidden" name="tab" value="overview">
                            <input type="hidden" name="action" value="set_password">
                            <div>
                              <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">New
                                password</label>
                              <input type="password" name="new_password" autocomplete="new-password"
                                data-password-strength data-pw-confirm="new_password_confirm"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary text-sm py-2.5">
                            </div>
                            <div>
                              <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Confirm</label>
                              <input type="password" name="new_password_confirm" autocomplete="new-password"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary text-sm py-2.5">
                            </div>
                            <button type="submit"
                              class="inline-flex justify-center rounded-md border border-transparent bg-primary py-2 px-6 text-sm font-semibold text-white shadow-sm hover:bg-primary/90">Save
                              password</button>
                          </form>
                        </div>
                      </details>
                    <?php endif; ?>
                  </div>
                  <div class="mb-6 pt-6 border-t border-gray-100">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Status Management</h4>
                    <div class="grid grid-cols-2 gap-2">
                      <?php if ($canEditFullProfile): ?>
                        <form method="post" action="/admin/members/actions.php">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="overview">
                          <input type="hidden" name="action" value="change_status">
                          <input type="hidden" name="new_status" value="active">
                          <button
                            class="flex flex-col items-center justify-center p-3 border border-gray-200 rounded-lg hover:border-green-400 hover:bg-green-50 transition-all group h-20 w-full"
                            type="submit">
                            <span
                              class="material-icons-outlined text-gray-400 group-hover:text-green-600 mb-1">check_circle</span>
                            <span class="text-xs font-medium text-gray-600 group-hover:text-green-700">Activate</span>
                          </button>
                        </form>
                        <form method="post" action="/admin/members/actions.php">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="overview">
                          <input type="hidden" name="action" value="change_status">
                          <input type="hidden" name="new_status" value="suspended">
                          <button
                            class="flex flex-col items-center justify-center p-3 border border-gray-200 rounded-lg hover:border-orange-400 hover:bg-orange-50 transition-all group h-20 w-full"
                            type="submit">
                            <span
                              class="material-icons-outlined text-gray-400 group-hover:text-orange-600 mb-1">block</span>
                            <span class="text-xs font-medium text-gray-600 group-hover:text-orange-700">Suspend</span>
                          </button>
                        </form>
                        <form method="post" action="/admin/members/actions.php"
                          onsubmit="return confirm('Archive this member? They will be hidden from the main list but kept in the database.');">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="overview">
                          <input type="hidden" name="action" value="member_archive">
                          <button
                            class="flex flex-col items-center justify-center p-3 border border-gray-200 rounded-lg hover:border-yellow-400 hover:bg-yellow-50 transition-all group h-20 w-full"
                            type="submit">
                            <span
                              class="material-icons-outlined text-gray-400 group-hover:text-yellow-600 mb-1">archive</span>
                            <span class="text-xs font-medium text-gray-600 group-hover:text-yellow-700">Archive</span>
                          </button>
                        </form>
                        <form method="post" action="/admin/members/actions.php"
                          onsubmit="return confirm('This action is not reversible. Deleting will remove this member and all related data from the database. Continue?');">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="overview">
                          <input type="hidden" name="action" value="member_delete">
                          <button
                            class="flex flex-col items-center justify-center p-3 border border-gray-200 rounded-lg hover:border-red-400 hover:bg-red-50 transition-all group h-20 w-full"
                            type="submit">
                            <span class="material-icons-outlined text-gray-400 group-hover:text-red-600 mb-1">delete</span>
                            <span class="text-xs font-medium text-gray-600 group-hover:text-red-700">Delete</span>
                          </button>
                        </form>
                      <?php else: ?>
                        <p class="text-xs text-gray-500">Only Admin/Committee can manage member status.</p>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="mb-6 pt-2 border-t border-gray-100">
                    <?php if ($canEditFullProfile): ?>
                      <form method="post" action="/admin/members/actions.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="overview">
                        <input type="hidden" name="action" value="member_number_update">
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 mt-4"
                          for="member-id">Member ID</label>
                        <div class="flex rounded-md shadow-sm">
                          <input id="member-id" name="member_number" type="text"
                            value="<?= e($member['member_number_display'] ?? '') ?>"
                            class="focus:ring-primary focus:border-primary flex-1 block w-full rounded-l-md sm:text-sm border-gray-300 py-2 pl-4">
                          <button
                            class="-ml-px relative inline-flex items-center space-x-2 px-4 py-2 border border-gray-300 text-xs font-bold rounded-r-md text-gray-700 bg-gray-50 hover:bg-gray-100 focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary"
                            type="submit">
                            <span>Update</span>
                          </button>
                        </div>
                      </form>
                    <?php endif; ?>
                  </div>
                  <div class="pt-4 border-t border-gray-100">
                    <div class="flex justify-between items-center text-sm mb-3">
                      <span class="text-gray-400 uppercase text-xs font-bold tracking-widest">Manual Migration</span>
                      <span class="font-medium text-gray-900 text-xs"><?= e($migrationStatusLabel) ?></span>
                    </div>
                    <div class="space-y-2">
                      <?php if ($migrationEnabled && empty($member['manual_migration_disabled'])): ?>
                        <form method="post" action="/admin/members/actions.php">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="overview">
                          <input type="hidden" name="action" value="send_migration_link">
                          <button
                            class="w-full inline-flex justify-center items-center px-4 py-2 border border-gray-200 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-white hover:border-gray-300 transition-all">
                            Send Manual Migrate Form
                          </button>
                        </form>
                      <?php endif; ?>
                      <?php if ($migrationEnabled): ?>
                        <form method="post" action="/admin/members/actions.php">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="overview">
                          <input type="hidden" name="action"
                            value="<?= empty($member['manual_migration_disabled']) ? 'disable_migration_link' : 'enable_migration_link' ?>">
                          <button
                            class="w-full inline-flex justify-center items-center px-4 py-2 border border-gray-200 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-gray-50 hover:border-gray-300 transition-all">
                            <?= empty($member['manual_migration_disabled']) ? 'Disable migration link' : 'Enable migration link' ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php elseif ($tab === 'profile'): ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-6">
              <?php if ($isLinkedProfile): ?>
                <div class="flex items-center justify-between gap-4 text-xs uppercase tracking-wider text-gray-500">
                  <div class="flex items-center gap-2">
                    <span class="material-icons-outlined text-[16px]">swap_horiz</span>
                    <?= e($profileContextLabel) ?>
                    <?php if ($profileContextName !== ''): ?>
                      <span
                        class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-[10px] font-semibold"><?= e($profileContextName) ?></span>
                    <?php endif; ?>
                  </div>
                  <a class="inline-flex items-center gap-2 text-xs font-semibold text-secondary"
                    href="<?= e(buildTabUrl($memberId, 'profile', ['profile_member_id' => null])) ?>">
                    <span class="material-icons-outlined text-[16px]">arrow_back</span>
                    Back to main member
                  </a>
                </div>
                <?php if ($profileContextNote !== ''): ?>
                  <p class="text-xs text-gray-500"><?= e($profileContextNote) ?></p>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($profileMessage): ?>
                <div class="rounded-lg bg-emerald-50 text-emerald-700 px-4 py-2 text-sm"><?= e($profileMessage) ?></div>
              <?php endif; ?>
              <?php if ($profileError): ?>
                <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($profileError) ?></div>
              <?php endif; ?>
              <?php if (!$canEditContact && !$canEditAddress): ?>
                <div class="rounded-2xl bg-yellow-50 p-4 text-xs font-semibold uppercase tracking-[0.3em] text-yellow-700">
                  Insufficient permissions to edit</div>
              <?php endif; ?>
              <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <div class="space-y-6">
                  <form method="post" action="/admin/members/actions.php" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                    <input type="hidden" name="tab" value="profile">
                    <input type="hidden" name="action" value="save_profile">
                    <?php if ($isLinkedProfile): ?>
                      <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                    <?php endif; ?>
                    <div class="space-y-6">
                      <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
                        <div class="flex items-center gap-3 mb-6">
                          <div class="p-2 bg-blue-100 rounded-lg text-blue-600">
                            <span class="material-icons-outlined">contact_mail</span>
                          </div>
                          <div>
                            <h2 class="font-display text-xl font-bold text-gray-900">Contact Information</h2>
                            <p class="text-sm text-gray-500">Update emails, phone, and mailing address in one place.</p>
                          </div>
                        </div>
                        <?php if ($isLinkedProfile): ?>
                          <div
                            class="mb-6 grid grid-cols-1 sm:grid-cols-2 gap-4 rounded-xl border border-gray-100 bg-gray-50 px-4 py-3">
                            <div>
                              <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Member ID</p>
                              <p class="text-sm font-semibold text-gray-900"><?= e($profileMemberNumber) ?></p>
                            </div>
                            <div>
                              <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Type</p>
                              <p class="text-sm font-semibold text-gray-900"><?= e($profileMembershipTypeLabel) ?></p>
                            </div>
                            <div>
                              <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                              <span
                                class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs font-semibold text-gray-700">
                                <?= e(strtoupper($profileStatusLabel)) ?>
                              </span>
                            </div>
                            <div>
                              <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Chapter</p>
                              <p class="text-sm font-semibold text-gray-900"><?= e($profileChapterLabel) ?></p>
                            </div>
                            <div>
                              <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Joined</p>
                              <p class="text-sm font-semibold text-gray-900"><?= e($profileJoinedLabel) ?></p>
                            </div>
                          </div>
                        <?php endif; ?>
                        <?php if (!$canEditContact || !$canEditAddress): ?>
                          <div class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            <span class="material-icons-outlined text-[16px] mt-0.5">lock</span>
                            <span>
                              <?php if (!$canEditContact && !$canEditAddress): ?>
                                You don't have permission to edit any of these fields. Contact an administrator if you need to request changes.
                              <?php elseif (!$canEditContact): ?>
                                Name, email and phone are read-only for your role. Address fields remain editable.
                              <?php else: ?>
                                Address fields are read-only for your role. Name, email and phone remain editable.
                              <?php endif; ?>
                            </span>
                          </div>
                        <?php endif; ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                          <div>
                            <label class="text-sm font-medium text-gray-700">First name</label>
                            <input type="text" name="first_name" value="<?= e($profileMember['first_name']) ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditContact ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">Last name</label>
                            <input type="text" name="last_name" value="<?= e($profileMember['last_name']) ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditContact ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">Email</label>
                            <input type="email" name="email" value="<?= e($profileMember['email']) ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditContact ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">Phone</label>
                            <input type="text" name="phone" value="<?= e($profileMember['phone'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditContact ? '' : 'disabled' ?>>
                          </div>
                          <div class="md:col-span-2">
                            <label class="text-sm font-medium text-gray-700">Address line 1</label>
                            <input id="admin_address_line1" data-google-autocomplete="address"
                              data-google-autocomplete-city="#admin_address_suburb"
                              data-google-autocomplete-state="#admin_address_state"
                              data-google-autocomplete-postal="#admin_address_postcode"
                              data-google-autocomplete-country="#admin_address_country" type="text" name="address_line1"
                              value="<?= e($profileMember['address_line1'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditAddress ? '' : 'disabled' ?>>
                          </div>
                          <div class="md:col-span-2">
                            <label class="text-sm font-medium text-gray-700">Address line 2</label>
                            <input id="admin_address_line2" type="text" name="address_line2"
                              value="<?= e($profileMember['address_line2'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditAddress ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">Suburb</label>
                            <input id="admin_address_suburb" type="text" name="suburb"
                              value="<?= e($profileMember['suburb'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditAddress ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">State</label>
                            <input id="admin_address_state" type="text" name="state"
                              value="<?= e($profileMember['state'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditAddress ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">Postal code</label>
                            <input id="admin_address_postcode" type="text" name="postcode"
                              value="<?= e($profileMember['postal_code'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditAddress ? '' : 'disabled' ?>>
                          </div>
                          <div>
                            <label class="text-sm font-medium text-gray-700">Country</label>
                            <input id="admin_address_country" type="text" name="country"
                              value="<?= e($profileMember['country'] ?? '') ?>"
                              class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                              <?= $canEditAddress ? '' : 'disabled' ?>>
                          </div>
                        </div>
                      </div>
                      <?php if (!$isLinkedProfile): ?>
                        <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
                          <div class="flex items-center gap-3 mb-6">
                            <div class="p-2 bg-amber-100 rounded-lg text-amber-600">
                              <span class="material-icons-outlined">tune</span>
                            </div>
                            <div>
                              <h2 class="font-display text-xl font-bold text-gray-900">Preferences &amp; Assistance</h2>
                              <p class="text-sm text-gray-500">Customize privacy and directory visibility.</p>
                            </div>
                          </div>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="text-sm font-medium text-gray-700">
                              Wings preference
                              <select name="wings_preference"
                                class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                                <option value="digital" <?= $profileMember['wings_preference'] === 'digital' ? 'selected' : '' ?>>Digital</option>
                                <option value="print" <?= $profileMember['wings_preference'] === 'print' ? 'selected' : '' ?>>
                                  Print</option>
                                <option value="both" <?= $profileMember['wings_preference'] === 'both' ? 'selected' : '' ?>>
                                  Both</option>
                              </select>
                            </label>
                            <label class="text-sm font-medium text-gray-700">
                              Australia Post presort code (Zone)
                              <input type="text" name="australia_presort_code" maxlength="10"
                                value="<?= e($profileMember['australia_presort_code'] ?? '') ?>"
                                placeholder="e.g. 254"
                                class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <span class="mt-1 block text-xs font-normal text-gray-500">Admin only &mdash; not shown to members. For Australia Post printed-Wings sorting.</span>
                            </label>
                          </div>
                          <div class="mt-5 text-sm font-medium text-gray-700 mb-2">Assistance flags</div>
                          <input type="hidden" name="directory_pref_submitted" value="1">
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-gray-600">
                            <?php foreach (\App\Services\MemberRepository::directoryPreferences() as $letter => $info): ?>
                              <label class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                <input type="checkbox" name="directory_pref_<?= e($letter) ?>" value="1"
                                  <?= !empty($profileMember[$info['column']] ?? null) ? 'checked' : '' ?>
                                  <?= $canEditFullProfile ? '' : 'disabled' ?>
                                  class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                                <span><?= e($letter) ?> — <?= e($info['label']) ?></span>
                              </label>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>
                    <?php if ($canEditContact || $canEditAddress || $canEditFullProfile): ?>
                      <div class="flex justify-end">
                        <button type="submit"
                          class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold">Save
                          changes</button>
                      </div>
                    <?php elseif (!$canEditContact && !$canEditAddress): ?>
                      <p class="text-xs text-gray-500">Contact your admin to request profile changes.</p>
                    <?php endif; ?>
                  </form>
                </div>
                <div class="space-y-6">
                  <?php if ($canEditSettings): ?>
                  <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center gap-3 mb-4">
                      <div class="p-2 bg-teal-100 rounded-lg text-teal-600">
                        <span class="material-icons-outlined">account_circle</span>
                      </div>
                      <div>
                        <h2 class="font-display text-lg font-bold text-gray-900">Profile Image</h2>
                        <p class="text-sm text-gray-500">Upload a photo for this member.</p>
                      </div>
                    </div>
                    <form method="post" action="/admin/members/actions.php" class="space-y-3">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                      <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                      <input type="hidden" name="tab" value="profile">
                      <input type="hidden" name="action" value="member_avatar_update">
                      <div class="flex items-center gap-3">
                        <div id="profile-avatar-preview"
                          class="h-16 w-16 rounded-full bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center flex-shrink-0">
                          <?php if ($avatarUrl): ?>
                            <img src="<?= e($avatarUrl) ?>" alt="<?= e($member['first_name'] . ' ' . $member['last_name']) ?>"
                              class="h-full w-full object-cover">
                          <?php else: ?>
                            <span class="material-icons-outlined text-gray-400 text-2xl">person</span>
                          <?php endif; ?>
                        </div>
                        <div class="flex-1 space-y-2">
                          <input type="hidden" name="avatar_url" id="profile-avatar-url-input" value="<?= e($avatarUrl) ?>">
                          <button type="button"
                            class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                            data-upload-trigger
                            data-upload-target="profile-avatar-url-input"
                            data-upload-preview="profile-avatar-preview"
                            data-upload-context="avatars">
                            <span class="material-icons-outlined text-sm">upload</span>
                            Upload photo
                          </button>
                          <?php if ($avatarUrl): ?>
                            <p class="text-xs text-gray-400 truncate max-w-[160px]" title="<?= e($avatarUrl) ?>">Current: <?= e(basename($avatarUrl)) ?></p>
                          <?php endif; ?>
                        </div>
                      </div>
                      <button type="submit"
                        class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold">
                        Save photo
                      </button>
                    </form>
                  </div>
                  <?php endif; ?>
                  <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-start justify-between gap-3 mb-4">
                      <div class="p-2 bg-indigo-100 rounded-lg text-indigo-600">
                        <span class="material-icons-outlined">hub</span>
                      </div>
                      <div>
                        <h2 class="font-display text-lg font-bold text-gray-900">Membership Links</h2>
                        <p class="text-sm text-gray-500">Manage linked member profiles.</p>
                      </div>
                      <?php if ($canEditFullProfile && in_array($member['member_type'], ['FULL', 'LIFE'], true)): ?>
                        <button type="button" data-associate-open
                          class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-5 py-2 text-sm font-semibold text-gray-700 hover:border-gray-300 shadow-sm bg-white">
                          <span class="material-icons-outlined text-sm">link</span>
                          Link associate
                        </button>
                      <?php endif; ?>
                    </div>
                    <?php if (in_array($member['member_type'], ['FULL', 'LIFE'], true)): ?>
                      <?php if ($associates): ?>
                        <ul class="space-y-3 text-sm text-gray-700">
                          <?php foreach ($associates as $assoc): ?>
                            <li
                              class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-white px-3 py-2">
                              <div>
                                <p class="font-medium text-gray-900"><?= e($assoc['first_name'] . ' ' . $assoc['last_name']) ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                  <?= e(\App\Services\MembershipService::displayMembershipNumber((int) $assoc['member_number_base'], (int) $assoc['member_number_suffix'])) ?>
                                </p>
                              </div>
                              <a class="inline-flex items-center text-xs font-semibold text-secondary"
                                href="<?= e(buildTabUrl($memberId, 'profile', ['profile_member_id' => $assoc['id']])) ?>">Edit</a>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php else: ?>
                        <p class="text-sm text-gray-500">No associates linked yet.</p>
                      <?php endif; ?>
                    <?php elseif ($member['member_type'] === 'ASSOCIATE' && $fullMember): ?>
                      <div class="rounded-lg border border-gray-100 bg-white px-3 py-3 text-sm text-gray-700">
                        <p class="font-medium text-gray-900">
                          <?= e($fullMember['first_name'] . ' ' . $fullMember['last_name']) ?>
                        </p>
                        <p class="text-xs text-gray-500">
                          <?= e(\App\Services\MembershipService::displayMembershipNumber((int) $fullMember['member_number_base'], (int) $fullMember['member_number_suffix'])) ?>
                        </p>
                        <a class="mt-3 inline-flex items-center text-xs font-semibold text-secondary"
                          href="<?= e(buildTabUrl($memberId, 'profile', ['profile_member_id' => $fullMember['id']])) ?>">Edit</a>
                      </div>
                    <?php else: ?>
                      <p class="text-sm text-gray-500">No linked membership details available.</p>
                    <?php endif; ?>
                  </div>
                  <?php if ($canEditFullProfile && in_array($member['member_type'], ['FULL', 'LIFE'], true)): ?>
                    <div data-associate-modal class="hidden fixed inset-0 z-[200] flex items-center justify-center">
                      <div class="absolute inset-0 bg-black/40 backdrop-blur-sm"></div>
                      <div
                        class="relative w-full max-w-2xl rounded-[32px] bg-white p-6 shadow-[0_25px_65px_-25px_rgba(15,23,42,0.6)]">
                        <div class="flex items-start justify-between gap-4">
                          <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.4em] text-gray-400">Link associate</p>
                            <h3 class="text-xl font-bold text-gray-900">Search by name or member number</h3>
                          </div>
                          <button type="button" data-associate-close
                            class="rounded-full border border-gray-200 p-2 text-gray-400 hover:text-gray-600">
                            <span class="material-icons-outlined text-base">close</span>
                          </button>
                        </div>
                        <form data-associate-search-form class="mt-5 space-y-3">
                          <label class="text-xs font-semibold text-gray-500 uppercase tracking-[0.3em]">Search</label>
                          <div class="flex items-center gap-3">
                            <input name="associate_query" type="search" placeholder="Name, email or member #"
                              class="flex-1 rounded-2xl border border-gray-200 px-4 py-3 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/40"
                              autocomplete="off">
                            <button type="submit"
                              class="rounded-2xl bg-primary px-4 py-3 text-sm font-semibold text-gray-900 hover:bg-primary/90">Search</button>
                          </div>
                        </form>
                        <p data-associate-feedback class="mt-1 text-sm text-gray-500">&nbsp;</p>
                        <div data-associate-results class="mt-4 space-y-3 text-sm text-gray-700"></div>
                      </div>
                    </div>
                    <div data-associate-config data-csrf-token="<?= e(Csrf::token()) ?>"
                      data-member-id="<?= e($memberId) ?>"></div>
                  <?php endif; ?>
                  <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center gap-3 mb-4">
                      <div class="p-2 bg-orange-100 rounded-lg text-orange-600">
                        <span class="material-icons-outlined">group</span>
                      </div>
                      <div>
                        <h2 class="font-display text-lg font-bold text-gray-900">Chapter Change</h2>
                        <p class="text-sm text-gray-500">Assign the member to a chapter.</p>
                      </div>
                    </div>
                    <form method="post" action="/admin/members/actions.php" class="space-y-3">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                      <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                      <input type="hidden" name="tab" value="profile">
                      <input type="hidden" name="action" value="assign_chapter">
                      <select name="requested_chapter_id"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Select chapter</option>
                        <?php foreach ($chapters as $chapter): ?>
                          <?php
                          $chapterLabel = $chapter['display_label'] ?? $chapter['name'];
                          if (!empty($chapter['state'])) {
                            $chapterLabel .= ' (' . $chapter['state'] . ')';
                          }
                          ?>
                          <option value="<?= e((string) $chapter['id']) ?>" <?= (int) ($member['chapter_id'] ?? 0) === (int) $chapter['id'] ? 'selected' : '' ?>>
                            <?= e($chapterLabel) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button
                        class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
                        type="submit">Apply</button>
                    </form>
                    <?php if ($chapterRequests): ?>
                      <div class="mt-4 space-y-3 text-sm text-gray-600">
                        <?php foreach ($chapterRequests as $request): ?>
                          <div class="rounded-lg border border-gray-100 bg-white px-3 py-3">
                            <div class="flex items-center justify-between gap-3">
                              <div>
                                <p class="font-semibold text-gray-900"><?= e($request['name']) ?></p>
                                <p class="text-xs text-gray-500">Requested: <?= e(formatDateTime($request['requested_at'])) ?></p>
                              </div>
                              <span
                                class="text-[11px] font-bold uppercase tracking-widest text-gray-500"><?= e($request['status']) ?></span>
                            </div>
                            <?php if (!empty($request['rejection_reason'])): ?>
                              <p class="mt-2 text-xs text-red-600">Reason: <?= e($request['rejection_reason']) ?></p>
                            <?php endif; ?>
                            <?php if (($request['status'] ?? '') === 'PENDING' && $canEditFullProfile): ?>
                              <div class="mt-3 flex flex-col gap-2">
                                <form method="post" action="/admin/members/actions.php" class="flex items-center gap-2">
                                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                  <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                  <input type="hidden" name="tab" value="profile">
                                  <input type="hidden" name="action" value="chapter_request_decision">
                                  <input type="hidden" name="request_id" value="<?= e((string) $request['id']) ?>">
                                  <input type="hidden" name="decision" value="approve">
                                  <button
                                    class="inline-flex items-center rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs font-semibold text-green-700"
                                    type="submit">Approve</button>
                                </form>
                                <form method="post" action="/admin/members/actions.php" class="space-y-2">
                                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                  <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                  <input type="hidden" name="tab" value="profile">
                                  <input type="hidden" name="action" value="chapter_request_decision">
                                  <input type="hidden" name="request_id" value="<?= e((string) $request['id']) ?>">
                                  <input type="hidden" name="decision" value="reject">
                                  <textarea name="rejection_reason" rows="2"
                                    class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-700"
                                    placeholder="Rejection reason" required></textarea>
                                  <button
                                    class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
                                    type="submit">Reject</button>
                                </form>
                              </div>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php elseif ($tab === 'roles'): ?>
            <div class="space-y-6">
              <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center gap-3">
                  <div class="p-2 bg-emerald-100 rounded-lg text-emerald-700">
                    <span class="material-icons-outlined">shield_person</span>
                  </div>
                  <div>
                    <h2 class="font-display text-xl font-bold text-gray-900">Roles &amp; Access</h2>
                    <p class="text-sm text-gray-500">Assign system access roles independently from membership types.</p>
                  </div>
                </div>
                <form method="post" action="/admin/members/actions.php" class="space-y-4">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                  <input type="hidden" name="tab" value="roles">
                  <input type="hidden" name="action" value="roles_update">
                  <input type="hidden" name="roles_system_submitted" value="1">
                  <input type="hidden" name="roles_admin_submitted" value="1">
                  <?php if ($userId <= 0): ?>
                    <div class="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700 mb-3">
                      <strong class="block mb-1">⚠️ Roles cannot be assigned yet</strong>
                      Roles apply to user login accounts, not membership records. This member does not have a user account
                      yet. Send them a migration/activation link first.
                    </div>
                  <?php endif; ?>
                  <div class="space-y-4">
                    <div>
                      <p class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-2">System Roles</p>
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <?php foreach ($systemRoleOptions as $roleOption): ?>
                          <?php
                          $name = $roleOption['name'] ?? '';
                          $display = str_replace(['_', '-'], ' ', ucfirst($name));
                          ?>
                          <label class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                            <input type="checkbox" name="roles_system[]" value="<?= e($name) ?>" <?= in_array($name, $memberRoles, true) ? 'checked' : '' ?>     <?= ($canEditRoles && $userId > 0) ? '' : 'disabled' ?>
                              class="rounded border-gray-300 text-primary focus:ring-2 focus:ring-primary">
                            <span><?= e($display) ?></span>
                          </label>
                        <?php endforeach; ?>
                        <?php if (!$systemRoleOptions): ?>
                          <p class="text-xs text-gray-500">No system roles available.</p>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div>
                      <p class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-2">Admin Roles</p>
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <?php foreach ($adminRoleOptions as $roleOption): ?>
                          <?php
                          $name = $roleOption['name'] ?? '';
                          $display = str_replace(['_', '-'], ' ', ucfirst($name));
                          ?>
                          <label class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                            <input type="checkbox" name="roles_admin[]" value="<?= e($name) ?>" <?= in_array($name, $memberRoles, true) ? 'checked' : '' ?>     <?= ($canEditRoles && $userId > 0) ? '' : 'disabled' ?>
                              class="rounded border-gray-300 text-primary focus:ring-2 focus:ring-primary">
                            <span><?= e($display) ?></span>
                          </label>
                        <?php endforeach; ?>
                        <?php if (!$adminRoleOptions): ?>
                          <p class="text-xs text-gray-500">No admin roles configured yet.</p>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <p class="text-xs text-gray-500">Admin roles map to the new permissions registry.</p>
                  <?php if ($canEditRoles && $userId > 0): ?>
                    <button
                      class="inline-flex items-center px-4 py-2 rounded-full bg-primary text-xs font-semibold text-gray-900"
                      type="submit">Save role assignments</button>
                  <?php else: ?>
                    <p class="text-xs text-gray-500">
                      <?= $canEditRoles ? 'Assign a linked user account before updating roles.' : 'Only Admins can manage roles.' ?>
                    </p>
                  <?php endif; ?>
                </form>
              </div>
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-indigo-100 rounded-lg text-indigo-600">
                      <span class="material-icons-outlined">shield</span>
                    </div>
                    <div>
                      <h3 class="font-display text-lg font-bold text-gray-900">Require 2FA</h3>
                      <p class="text-sm text-gray-500">Force secure logins for this member.</p>
                    </div>
                  </div>
                  <div class="space-y-3">
                    <div class="flex items-center justify-between">
                      <p class="text-sm font-semibold text-gray-900">Enforcement</p>
                      <span
                        class="inline-flex items-center rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700"><?= e($twofaEnforcement) ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                      <p class="text-sm text-gray-600">Enrollment status: <span
                          class="font-semibold text-gray-900"><?= e($twofaStatus) ?></span></p>
                      <form method="post" action="/admin/members/actions.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="roles">
                        <input type="hidden" name="action" value="twofa_toggle">
                        <input type="hidden" name="twofa_required"
                          value="<?= $twofaOverride === 'REQUIRED' ? '0' : '1' ?>">
                        <button
                          class="rounded-full border border-gray-200 px-4 py-1 text-xs font-semibold text-gray-700 <?= $canManageSecurity ? 'hover:border-gray-400' : 'opacity-40 cursor-not-allowed' ?>"
                          type="submit" <?= $canManageSecurity ? '' : 'disabled' ?>>
                          <?= $twofaOverride === 'REQUIRED' ? 'Set optional' : 'Require 2FA' ?>
                        </button>
                      </form>
                    </div>
                    <?php if (!empty($member['user_id'])): ?>
                      <div class="space-y-1">
                        <form method="post" action="/admin/members/actions.php">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="roles">
                          <input type="hidden" name="action" value="twofa_force">
                          <button type="submit"
                            class="w-full rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 <?= $canManageSecurity ? 'hover:bg-gray-50' : 'opacity-40 cursor-not-allowed' ?>"
                            <?= $canManageSecurity ? '' : 'disabled' ?>>Force 2FA enrollment</button>
                        </form>
                        <form method="post" action="/admin/members/actions.php">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="roles">
                          <input type="hidden" name="action" value="twofa_exempt">
                          <button type="submit"
                            class="w-full rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 <?= $canManageSecurity ? 'hover:bg-gray-50' : 'opacity-40 cursor-not-allowed' ?>"
                            <?= $canManageSecurity ? '' : 'disabled' ?>>Exempt from 2FA</button>
                        </form>
                        <form method="post" action="/admin/members/actions.php"
                          onsubmit="return confirm('Reset 2FA for this user?');">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="roles">
                          <input type="hidden" name="action" value="twofa_reset">
                          <button type="submit"
                            class="w-full rounded-full border border-red-200 px-4 py-2 text-xs font-semibold text-red-600 hover:bg-red-50">Reset
                            2FA</button>
                        </form>
                      </div>
                    <?php endif; ?>
                    <p class="text-xs text-gray-500">Members will be forced to enroll at next login when 2FA is required.
                    </p>
                  </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                      <span class="material-icons-outlined">key</span>
                    </div>
                    <div>
                      <h3 class="font-display text-lg font-bold text-gray-900">Account access</h3>
                      <p class="text-sm text-gray-500">Manage login resets and temporary credentials.</p>
                    </div>
                  </div>
                  <div class="space-y-3">
                    <?php if ($flash && $flashContext === 'account_access'): ?>
                      <div
                        class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
                        <?= e($flash['message']) ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($canResetPassword): ?>
                      <form method="post" action="/admin/members/actions.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="roles">
                        <input type="hidden" name="action" value="send_reset_link">
                        <button
                          class="w-full rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Send
                          password reset link</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($canSetPassword): ?>
                      <form method="post" action="/admin/members/actions.php" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="roles">
                        <input type="hidden" name="action" value="set_password">
                        <div>
                          <label class="text-xs uppercase tracking-[0.3em] text-gray-500">New password</label>
                          <input type="password" name="new_password"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </div>
                        <div>
                          <label class="text-xs uppercase tracking-[0.3em] text-gray-500">Confirm</label>
                          <input type="password" name="new_password_confirm"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </div>
                        <button type="submit"
                          class="w-full rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900">Save
                          password</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php elseif ($tab === 'settings'): ?>
            <div class="space-y-6">
              <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center gap-3">
                  <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                    <span class="material-icons-outlined">settings</span>
                  </div>
                  <div>
                    <h2 class="font-display text-xl font-bold text-gray-900">Member Settings</h2>
                    <p class="text-sm text-gray-500">Reuse the member portal handlers for timezone, avatar, and
                      notification preferences.</p>
                  </div>
                </div>
                <form method="post" action="/admin/members/actions.php" class="space-y-4">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                  <input type="hidden" name="tab" value="settings">
                  <input type="hidden" name="action" value="member_settings_update">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="text-sm font-medium text-gray-700">
                      Timezone
                      <input name="user_timezone"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20"
                        value="<?= e($userTimezone) ?>" placeholder="Australia/Sydney">
                    </label>
                    <div>
                      <p class="text-sm font-medium text-gray-700">Profile image</p>
                      <div class="mt-2 flex items-center gap-3">
                        <div class="h-14 w-14 rounded-full bg-gray-50 border border-gray-200 overflow-hidden">
                          <?php if ($avatarUrl): ?>
                            <img src="<?= e($avatarUrl) ?>"
                              alt="<?= e($member['first_name'] . ' ' . $member['last_name']) ?>"
                              class="h-full w-full object-cover">
                          <?php else: ?>
                            <span class="material-icons-outlined text-gray-400 text-lg">person</span>
                          <?php endif; ?>
                        </div>
                        <input name="avatar_url"
                          class="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20"
                          value="<?= e($avatarUrl) ?>" placeholder="Image URL">
                      </div>
                    </div>
                  </div>
                  <div class="space-y-2">
                    <div class="text-sm font-medium text-gray-700">Email notifications</div>
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-4 space-y-3">
                      <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
                        <span class="font-medium">Enable notifications</span>
                        <input type="checkbox" name="notify_master_enabled" <?= $masterNotificationsEnabled ? 'checked' : '' ?>   <?= $canEditSettings ? '' : 'disabled' ?>
                          class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                      </label>
                      <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
                        <span class="font-medium">Unsubscribe from non-essential mails</span>
                        <input type="checkbox" name="notify_unsubscribe_all" <?= $unsubscribeAll ? 'checked' : '' ?>
                          <?= $canEditSettings ? '' : 'disabled' ?>
                          class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                      </label>
                      <p class="text-xs text-gray-500">Mandatory security and billing emails still send when required.</p>
                    </div>
                    <div class="text-xs uppercase tracking-[0.2em] text-gray-400">Categories</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <?php foreach ($notificationCategories as $categoryKey => $categoryLabel): ?>
                        <label
                          class="flex items-center gap-2 rounded-lg border border-gray-100 bg-white px-3 py-2 text-sm text-gray-700">
                          <input type="checkbox" name="notify_category[<?= e($categoryKey) ?>]"
                            <?= !empty($notificationPrefs['categories'][$categoryKey]) ? 'checked' : '' ?>
                            <?= $canEditSettings ? '' : 'disabled' ?>
                            class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                          <?= e($categoryLabel) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <?php if ($canEditSettings): ?>
                    <div class="flex justify-end">
                      <button
                        class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-sm font-semibold text-gray-900"
                        type="submit">Save settings</button>
                    </div>
                  <?php else: ?>
                    <p class="text-xs text-gray-500">Only Admin and Committee can update member settings.</p>
                  <?php endif; ?>
                </form>
              </div>
            </div>
          <?php elseif ($tab === 'vehicles'): ?>
            <?php if (!$canManageVehicles): ?>
              <div class="rounded-3xl border border-gray-200 bg-yellow-50 p-4 text-sm font-semibold text-yellow-700">You do
                not have permission to manage vehicles.</div>
            <?php else: ?>
              <div class="space-y-6">
                <!-- Member-level historic-rego flag (members.is_historic). Applies to the
                     household, not a specific bike — used by the "Has Historic Rego" filter
                     on the all-members list and surfaced as a badge in the profile header.
                     Light-yellow card to stand apart from the white "My Bikes" card below. -->
                <div class="bg-yellow-50 rounded-2xl p-5 shadow-sm border border-yellow-200">
                  <form method="post" action="/admin/members/actions.php" class="flex items-start justify-between gap-4">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                    <input type="hidden" name="tab" value="vehicles">
                    <input type="hidden" name="action" value="set_historic">
                    <div class="flex items-start gap-3">
                      <div class="p-2 bg-yellow-100 rounded-lg text-yellow-700">
                        <span class="material-icons-outlined">history</span>
                      </div>
                      <div>
                        <h2 class="font-display text-base font-bold text-gray-900">Has Historic Rego</h2>
                        <p class="text-sm text-gray-600">Toggle on if this member's bike is registered as a historical vehicle (25+ yrs).</p>
                      </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                      <!-- Toggle switch — matches the pattern used in settings/index.php
                           (Stripe Test/Live toggle). Input, track, and thumb are all direct
                           siblings inside the label so Tailwind's peer-checked: modifier
                           reaches them. The switch position is the on/off indicator. -->
                      <label class="relative inline-flex h-6 w-11 cursor-pointer" title="Toggle historic rego flag">
                        <input type="checkbox" name="is_historic" value="1" class="sr-only peer" <?= !empty($member['is_historic']) ? 'checked' : '' ?>>
                        <span class="absolute inset-0 rounded-full bg-gray-300 peer-checked:bg-green-500 transition-colors"></span>
                        <span class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                      </label>
                      <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold">Save</button>
                    </div>
                  </form>
                </div>
                <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
                  <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                      <div class="p-2 bg-yellow-100 rounded-lg text-yellow-600">
                        <span class="material-icons-outlined">two_wheeler</span>
                      </div>
                      <div>
                        <h2 class="font-display text-lg font-bold text-gray-900">My Bikes</h2>
                        <p class="text-sm text-gray-500">Keep your garage up to date.</p>
                      </div>
                    </div>
                    <span
                      class="inline-flex items-center rounded-full bg-yellow-50 px-3 py-1 text-xs font-semibold text-yellow-700"><?= count($bikes) ?>
                      Bikes</span>
                  </div>
                  <?php if ($bikes): ?>
                    <ul class="space-y-3 text-sm text-gray-700">
                      <?php foreach ($bikes as $bike): ?>
                        <li class="rounded-xl border border-gray-100 bg-white p-3 space-y-3">
                          <div class="flex gap-3">
                            <div class="h-16 w-20 rounded-lg bg-gray-50 overflow-hidden flex items-center justify-center">
                              <?php if (!empty($bike['image_url'])): ?>
                                <img src="<?= e($bike['image_url']) ?>" alt="<?= e($bike['make'] . ' ' . $bike['model']) ?>"
                                  class="h-full w-full object-cover">
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
                                  <span
                                    class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-0.5 font-semibold text-yellow-700">Rego:
                                    <?= e($bike['rego']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($bikeColor)): ?>
                                  <span
                                    class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-600">Colour:
                                    <?= e($bikeColor) ?></span>
                                <?php endif; ?>
                              </div>
                            </div>
                            <?php if ($canManageVehicles): ?>
                              <form method="post" action="/admin/members/actions.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                <input type="hidden" name="tab" value="vehicles">
                                <input type="hidden" name="action" value="bike_delete">
                                <input type="hidden" name="bike_id" value="<?= e((string) $bike['id']) ?>">
                                <button
                                  class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50"
                                  type="submit" onclick="return confirm('Remove this bike?');">Remove</button>
                              </form>
                            <?php endif; ?>
                          </div>
                          <?php if ($canManageVehicles): ?>
                            <?php $bikeId = (int) $bike['id']; ?>
                            <form method="post" action="/admin/members/actions.php"
                              class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                              <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                              <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                              <input type="hidden" name="tab" value="vehicles">
                              <input type="hidden" name="action" value="bike_update">
                              <input type="hidden" name="bike_id" value="<?= e((string) $bikeId) ?>">
                              <input type="text" name="bike_make" value="<?= e($bike['make'] ?? '') ?>" placeholder="Make"
                                required
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="text" name="bike_model" value="<?= e($bike['model'] ?? '') ?>" placeholder="Model"
                                required
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="number" name="bike_year" value="<?= e($bike['year'] ?? '') ?>" placeholder="Year"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="text" name="bike_color" value="<?= e($bikeColor ?? '') ?>" placeholder="Colour"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <input type="text" name="bike_rego" value="<?= e($bike['rego'] ?? '') ?>" placeholder="Rego"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                              <div class="flex items-center gap-3">
                                <div id="bike-image-preview-<?= e((string) $bikeId) ?>"
                                  class="h-16 w-20 rounded-lg bg-gray-50 text-gray-300 flex items-center justify-center overflow-hidden">
                                  <?php if (!empty($bike['image_url'])): ?>
                                    <img src="<?= e($bike['image_url']) ?>" alt="<?= e($bike['make'] . ' ' . $bike['model']) ?>"
                                      class="h-full w-full object-cover">
                                  <?php else: ?>
                                    <span class="material-icons-outlined">image</span>
                                  <?php endif; ?>
                                </div>
                                <input type="hidden" name="bike_image_url" id="bike-image-url-input-<?= e((string) $bikeId) ?>"
                                  value="<?= e($bike['image_url'] ?? '') ?>">
                                <button type="button"
                                  class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700"
                                  data-upload-trigger data-upload-target="bike-image-url-input-<?= e((string) $bikeId) ?>"
                                  data-upload-preview="bike-image-preview-<?= e((string) $bikeId) ?>"
                                  data-upload-context="bikes">Update bike image</button>
                              </div>
                              <?php if ($bikeHasPrimary): ?>
                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700">
                                  <input type="radio" name="is_primary" value="1" <?= (int) ($bike['is_primary'] ?? 0) === 1 ? 'checked' : '' ?> class="text-primary focus:ring-2 focus:ring-primary">
                                  Primary bike
                                </label>
                              <?php endif; ?>
                              <button
                                class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold"
                                type="submit">Save changes</button>
                            </form>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <p class="text-sm text-gray-500">No bikes saved yet.</p>
                  <?php endif; ?>
                  <?php if ($canManageVehicles): ?>
                    <form method="post" action="/admin/members/actions.php" class="mt-4 space-y-2">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                      <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                      <input type="hidden" name="tab" value="vehicles">
                      <input type="hidden" name="action" value="bike_add">
                      <input type="text" name="bike_make" placeholder="Make" required
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_model" placeholder="Model" required
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="number" name="bike_year" placeholder="Year"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_color" placeholder="Colour"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_rego" placeholder="Rego"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <div class="flex items-center gap-3">
                        <div id="bike-image-preview"
                          class="h-16 w-20 rounded-lg bg-gray-50 text-gray-300 flex items-center justify-center overflow-hidden">
                          <span class="material-icons-outlined">image</span>
                        </div>
                        <input type="hidden" name="bike_image_url" id="bike-image-url-input">
                        <button type="button"
                          class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700"
                          data-upload-trigger data-upload-target="bike-image-url-input"
                          data-upload-preview="bike-image-preview" data-upload-context="bikes">Upload bike image</button>
                      </div>
                      <button
                        class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold"
                        type="submit">Add Bike</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php elseif ($tab === 'orders'): ?>
            <?php
              // Renewal countdown — derive a friendly "X days remaining" badge for the summary card.
              $renewalEndDate        = $membershipPeriod['end_date'] ?? '';
              $renewalCountdownText  = '';
              $renewalCountdownClass = 'bg-gray-100 text-gray-700 border border-gray-200';
              if ($renewalEndDate) {
                  try {
                      $today    = new DateTimeImmutable(date('Y-m-d'));
                      $end      = new DateTimeImmutable($renewalEndDate);
                      $daysLeft = (int) $today->diff($end)->format('%r%a');
                      if ($daysLeft < 0) {
                          $renewalCountdownText  = abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue';
                          $renewalCountdownClass = 'bg-rose-50 text-rose-700 border border-rose-100';
                      } elseif ($daysLeft === 0) {
                          $renewalCountdownText  = 'Due today';
                          $renewalCountdownClass = 'bg-amber-50 text-amber-700 border border-amber-100';
                      } elseif ($daysLeft <= 30) {
                          $renewalCountdownText  = $daysLeft . ' days remaining';
                          $renewalCountdownClass = 'bg-amber-50 text-amber-700 border border-amber-100';
                      } else {
                          $renewalCountdownText  = $daysLeft . ' days remaining';
                          $renewalCountdownClass = 'bg-emerald-50 text-emerald-700 border border-emerald-100';
                      }
                  } catch (Exception $e) {
                      // Silent fail — countdown just won't show.
                  }
              }
            ?>
            <div class="space-y-6">
              <!-- ── Membership summary (hero card at top) ─────────────────────── -->
              <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Membership summary</p>
                    <p class="text-sm text-gray-500">Latest status and payment history at a glance.</p>
                  </div>
                  <span class="text-xs font-semibold text-gray-500"><?= count($membershipOrders) ?> order<?= count($membershipOrders) === 1 ? '' : 's' ?></span>
                </div>
                <div class="grid grid-cols-2 gap-5 md:grid-cols-4">
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Membership type</p>
                    <p class="mt-1 text-base font-semibold text-gray-900"><?= e($membershipTypeLabel) ?></p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Status</p>
                    <span class="mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= statusBadgeClasses(strtolower((string) $membershipStatusLabel)) ?>"><?= e($membershipStatusLabel) ?></span>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Last payment</p>
                    <p class="mt-1 text-base font-semibold text-gray-900"><?= e($lastPaymentDateLabel) ?></p>
                    <p class="text-[11px] text-gray-500 truncate"><?= e($paymentMethodLabel) ?> · <?= e($paymentStatusLabel) ?></p>
                  </div>
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Renewal date</p>
                    <div class="mt-1 flex items-center gap-1.5">
                      <p class="text-base font-semibold text-gray-900"><?= e($renewalDateLabel) ?></p>
                      <?php if ($canEditFullProfile): ?>
                        <button type="button" data-renewal-edit-toggle
                          class="text-gray-400 hover:text-gray-700 transition" title="Edit renewal date" aria-label="Edit renewal date">
                          <span class="material-icons-outlined text-base">edit</span>
                        </button>
                      <?php endif; ?>
                    </div>
                    <?php if ($renewalCountdownText): ?>
                      <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $renewalCountdownClass ?>"><?= e($renewalCountdownText) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($canEditFullProfile): ?>
                  <div data-renewal-edit class="hidden rounded-xl border border-gray-200 bg-gray-50 p-3">
                    <form method="post" action="/admin/members/actions.php" class="flex flex-wrap items-end gap-2">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                      <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                      <input type="hidden" name="tab" value="orders">
                      <input type="hidden" name="orders_section" value="membership">
                      <input type="hidden" name="action" value="membership_renewal_update">
                      <label class="flex-1 min-w-[200px] text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">
                        Renewal date
                        <input type="date" name="renewal_date" value="<?= e($membershipPeriod['end_date'] ?? '') ?>"
                          class="mt-1 block w-full rounded-lg border border-gray-200 px-3 py-2 text-sm font-normal normal-case tracking-normal text-gray-900">
                      </label>
                      <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-gray-900">Save</button>
                      <button type="button" data-renewal-edit-cancel class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700">Cancel</button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
              <!-- ── Membership & Admin (slimmed: chapter / type / status / notes) ── -->
              <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center gap-3">
                  <div class="p-2 bg-indigo-100 rounded-lg text-indigo-600">
                    <span class="material-icons-outlined">verified_user</span>
                  </div>
                  <div>
                    <h2 class="font-display text-xl font-bold text-gray-900">Membership &amp; Admin</h2>
                    <p class="text-sm text-gray-500">Current state — chapter, membership type, status, and admin notes.</p>
                  </div>
                </div>
                <form method="post" action="/admin/members/actions.php" class="space-y-4">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                  <input type="hidden" name="tab" value="orders">
                  <input type="hidden" name="action" value="save_profile">
                  <?php if ($canEditFullProfile): ?>
                    <input type="hidden" name="admin_flags_submitted" value="1">
                    <?php foreach ($directoryPrefs as $letter => $info): ?>
                      <?php if (!empty($member[$info['column']])): ?>
                        <input type="hidden" name="directory_pref_<?= e($letter) ?>" value="1">
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label class="text-sm font-medium text-gray-700">Chapter</label>
                      <select name="chapter_id"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20"
                        <?= $canEditFullProfile ? '' : 'disabled' ?>>
                        <option value="">Unassigned</option>
                        <?php foreach ($chapters as $chapter): ?>
                          <option value="<?= e($chapter['id']) ?>" <?= (int) $chapter['id'] === (int) ($member['chapter_id'] ?? 0) ? 'selected' : '' ?>><?= e(($chapter['display_label'] ?? $chapter['name']) . ' (' . $chapter['state'] . ')') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="text-sm font-medium text-gray-700">Membership type</label>
                      <select name="membership_type_id"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20"
                        <?= $canEditFullProfile ? '' : 'disabled' ?>>
                        <?php foreach ($membershipTypes as $type): ?>
                          <option value="<?= e($type['id']) ?>" <?= $selectedMembershipTypeId !== null && $selectedMembershipTypeId === (int) $type['id'] ? 'selected' : '' ?>><?= e($type['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="text-sm font-medium text-gray-700">Status</label>
                      <select name="status"
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20"
                        <?= $canEditFullProfile ? '' : 'disabled' ?>>
                        <?php foreach (['pending', 'active', 'expired', 'cancelled', 'suspended'] as $statusOption): ?>
                          <option value="<?= e($statusOption) ?>" <?= $memberStatusForSelect === $statusOption ? 'selected' : '' ?>><?= ucfirst($statusOption) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php if ($canEditFullProfile): ?>
                      <div class="md:col-span-2">
                        <label class="text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" rows="3"
                          class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20"><?= e($member['notes'] ?? '') ?></textarea>
                      </div>
                      <?php $cmtHeldRoles = CommitteeService::rolesForMember($memberId); ?>
                      <div class="md:col-span-2">
                        <div class="flex items-center justify-between mb-2">
                          <p class="text-sm font-medium text-gray-700">Committee &amp; Leadership</p>
                          <a href="/admin/settings/committee-roles.php" class="text-xs text-primary hover:underline inline-flex items-center gap-1">
                            Manage <span class="material-icons-outlined text-sm">open_in_new</span>
                          </a>
                        </div>
                        <?php if ($cmtHeldRoles): ?>
                          <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                            <ul class="flex flex-wrap gap-2">
                              <?php foreach ($cmtHeldRoles as $hr): ?>
                                <li class="text-xs bg-white border border-gray-100 rounded-full px-3 py-1 text-gray-700"><?= e($hr['name']) ?></li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                        <?php else: ?>
                          <p class="text-xs text-gray-500">No committee roles assigned. Use the Committee &amp; Leadership Roles settings page to assign.</p>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <?php if ($canEditFullProfile): ?>
                    <div class="flex justify-end">
                      <button
                        class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-sm font-semibold text-gray-900"
                        type="submit">Save changes</button>
                    </div>
                  <?php endif; ?>
                </form>
              </div>
              <div class="flex flex-wrap gap-2">
                <a class="rounded-full px-4 py-2 text-xs font-semibold <?= $ordersSection === 'membership' ? 'bg-primary/10 text-gray-900' : 'border border-gray-200 text-gray-600' ?>"
                  href="<?= e(buildTabUrl($memberId, 'orders', ['orders_section' => 'membership'])) ?>">
                  Membership Orders
                </a>
                <a class="rounded-full px-4 py-2 text-xs font-semibold <?= $ordersSection === 'store' ? 'bg-primary/10 text-gray-900' : 'border border-gray-200 text-gray-600' ?>"
                  href="<?= e(buildTabUrl($memberId, 'orders', ['orders_section' => 'store'])) ?>">
                  Store Orders
                </a>
              </div>
              <?php if ($ordersSection === 'membership'): ?>
                <div class="space-y-6">
                  <div class="rounded-3xl border border-gray-200 bg-white p-4 shadow-sm space-y-3">
                    <div class="flex items-center justify-between">
                      <h3 class="text-sm font-semibold text-gray-900">Membership orders</h3>
                      <span class="text-xs font-semibold text-gray-500"><?= count($membershipOrders) ?> shown</span>
                    </div>
                    <?php if ($membershipOrders): ?>
                      <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                          <thead class="text-left text-xs uppercase text-gray-500">
                            <tr>
                              <th class="px-3 py-2">Order #</th>
                              <th class="px-3 py-2">Date</th>
                              <th class="px-3 py-2">Items</th>
                              <th class="px-3 py-2">Total</th>
                              <th class="px-3 py-2">Payment method</th>
                              <th class="px-3 py-2">Payment status</th>
                              <th class="px-3 py-2">Actions</th>
                            </tr>
                          </thead>
                          <tbody class="divide-y">
                            <?php foreach ($membershipOrders as $order): ?>
                              <?php
                              $orderItems = $membershipOrderItems[$order['id']] ?? [];
                              $paymentMethod = $order['payment_method'] ?? '';
                              $paymentMethodLabel = $paymentMethod !== '' ? ucwords(str_replace('_', ' ', $paymentMethod)) : '—';
                              $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
                              $orderNumber = $order['order_number'] ?? ('M-' . $order['id']);
                              $isVoided = !empty($order['voided_at']);
                              $isPaidish = in_array($paymentStatus, ['accepted', 'refunded'], true);
                              $rowClass = $isVoided ? 'bg-slate-50 text-slate-400 line-through opacity-70' : ($paymentStatus === 'pending' ? 'bg-yellow-50/40' : '');
                              ?>
                              <tr class="<?= $rowClass ?>">
                                <td class="px-3 py-2 text-gray-600">
                                  <?= e($orderNumber) ?>
                                  <?php if ($isVoided): ?>
                                    <span class="ml-1 inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-700 no-underline" title="Voided <?= e((string) $order['voided_at']) ?><?= !empty($order['voided_reason']) ? ' — ' . e((string) $order['voided_reason']) : '' ?>">Voided</span>
                                  <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-gray-600"><?= e(formatDate($order['created_at'] ?? null)) ?></td>
                                <td class="px-3 py-2 text-gray-600">
                                  <?php if ($orderItems): ?>
                                    <div class="space-y-1">
                                      <?php foreach ($orderItems as $item): ?>
                                        <div><?= e($item['name']) ?> <span
                                            class="text-xs text-gray-500">x<?= e((string) ($item['quantity'] ?? 1)) ?></span></div>
                                      <?php endforeach; ?>
                                    </div>
                                  <?php else: ?>
                                    <span class="text-xs text-gray-400">—</span>
                                  <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-gray-600">
                                  <?= e(formatCurrency((int) round(((float) ($order['total'] ?? 0)) * 100))) ?>
                                </td>
                                <td class="px-3 py-2 text-gray-600"><?= e($paymentMethodLabel) ?></td>
                                <td class="px-3 py-2">
                                  <span
                                    class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= statusBadgeClasses($paymentStatus) ?>"><?= ucfirst($paymentStatus) ?></span>
                                </td>
                                <td class="px-3 py-2 text-gray-600">
                                  <div class="flex flex-wrap gap-2">
                                    <a class="inline-flex items-center rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700"
                                      href="/admin/membership-orders/view.php?id=<?= (int) $order['id'] ?>">View &rarr;</a>
                                    <?php if ($canManualFix && in_array($paymentStatus, ['pending', 'failed'], true) && ($paymentMethod === '' || $paymentMethod === 'stripe')): ?>
                                      <form method="post" action="/admin/members/actions.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="orders_section" value="membership">
                                        <input type="hidden" name="action" value="membership_order_send_link">
                                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                        <button
                                          class="inline-flex items-center rounded-full border border-blue-200 px-3 py-1 text-xs font-semibold text-blue-700"
                                          type="submit">Send checkout link</button>
                                      </form>
                                    <?php endif; ?>
                                    <?php if ($canManualFix && $paymentStatus === 'pending' && in_array($paymentMethod, ['bank_transfer', 'manual', 'cash', 'complimentary'], true)): ?>
                                      <form method="post" action="/admin/members/actions.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="orders_section" value="membership">
                                        <input type="hidden" name="action" value="membership_order_accept">
                                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                        <button
                                          class="inline-flex items-center rounded-full border border-emerald-200 px-3 py-1 text-xs font-semibold text-emerald-700"
                                          type="submit">Accept</button>
                                      </form>
                                      <form method="post" action="/admin/members/actions.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="orders_section" value="membership">
                                        <input type="hidden" name="action" value="membership_order_reject">
                                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                        <button
                                          class="inline-flex items-center rounded-full border border-rose-200 px-3 py-1 text-xs font-semibold text-rose-700"
                                          type="submit">Reject</button>
                                      </form>
                                    <?php endif; ?>
                                    <?php if ($canManualFix): ?>
                                      <?php if ($isVoided): ?>
                                        <form method="post" action="/admin/members/actions.php">
                                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                          <input type="hidden" name="tab" value="orders">
                                          <input type="hidden" name="orders_section" value="membership">
                                          <input type="hidden" name="action" value="membership_order_unvoid">
                                          <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                          <button class="inline-flex items-center rounded-full border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700" type="submit">Restore</button>
                                        </form>
                                      <?php else: ?>
                                        <form method="post" action="/admin/members/actions.php" onsubmit="return confirm('Void this order? It will be hidden from default lists.');">
                                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                          <input type="hidden" name="tab" value="orders">
                                          <input type="hidden" name="orders_section" value="membership">
                                          <input type="hidden" name="action" value="membership_order_void">
                                          <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                          <button class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700" type="submit">Void</button>
                                        </form>
                                      <?php endif; ?>
                                      <form method="post" action="/admin/members/actions.php" onsubmit="return confirmMembershipOrderDelete(this, <?= $isPaidish ? 'true' : 'false' ?>);">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="orders_section" value="membership">
                                        <input type="hidden" name="action" value="membership_order_delete">
                                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                        <input type="hidden" name="delete_confirm" value="">
                                        <button class="inline-flex items-center rounded-full border border-red-300 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700" type="submit">Delete</button>
                                      </form>
                                    <?php endif; ?>
                                  </div>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                      <?php if ($canManualFix): ?>
                        <form method="post" action="/admin/members/actions.php" class="mt-4 grid gap-3 md:grid-cols-3">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                          <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                          <input type="hidden" name="tab" value="orders">
                          <input type="hidden" name="orders_section" value="membership">
                          <input type="hidden" name="action" value="membership_order_note">
                          <label class="text-sm font-medium text-gray-700 md:col-span-1">
                            Order
                            <select name="order_id" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                              <?php foreach ($membershipOrders as $order): ?>
                                <option value="<?= e($order['id']) ?>"><?= e($order['order_number'] ?? ('M-' . $order['id'])) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label class="text-sm font-medium text-gray-700 md:col-span-2">
                            Internal note
                            <input type="text" name="note"
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"
                              placeholder="Add internal note">
                          </label>
                          <div class="md:col-span-3">
                            <button class="rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700"
                              type="submit">Save note</button>
                          </div>
                        </form>
                      <?php endif; ?>
                    <?php else: ?>
                      <p class="text-sm text-gray-500">No membership orders recorded yet.</p>
                    <?php endif; ?>
                  </div>
                  <?php if ($agmRegistrations): ?>
                    <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                      <div class="flex items-center justify-between">
                        <div>
                          <p class="text-xs uppercase tracking-[0.3em] text-gray-500">AGM registrations</p>
                          <p class="text-sm text-gray-500">Annual General Meeting registrations linked to this member.</p>
                        </div>
                        <span class="text-xs font-semibold text-gray-500"><?= count($agmRegistrations) ?> registration<?= count($agmRegistrations) === 1 ? '' : 's' ?></span>
                      </div>
                      <table class="w-full text-sm">
                        <thead>
                          <tr class="text-left text-gray-500 border-b border-gray-200">
                            <th class="py-2">Event</th>
                            <th class="py-2">Number</th>
                            <th class="py-2">Status</th>
                            <th class="py-2 text-right">Total</th>
                            <th class="py-2"></th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($agmRegistrations as $reg): ?>
                            <tr class="border-b border-gray-100">
                              <td class="py-2"><?= e(($reg['event_year'] ?? '') . ' — ' . ($reg['event_title'] ?? '')) ?></td>
                              <td class="py-2 font-mono text-xs"><?= e($reg['registration_number']) ?></td>
                              <td class="py-2"><span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium <?= ($reg['payment_status'] === 'paid') ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= e(str_replace('_', ' ', $reg['payment_status'])) ?></span></td>
                              <td class="py-2 text-right">A$<?= number_format((float) $reg['total'], 2) ?></td>
                              <td class="py-2 text-right"><a href="/admin/agm/?tab=submissions&event_id=<?= (int) $reg['agm_event_id'] ?>&view=<?= (int) $reg['id'] ?>" class="text-xs text-primary hover:underline">View →</a></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                  <?php // Membership summary has moved to the top of the tab.
                        // Manual order is now full-width below the orders table. ?>
                  <?php if ($canManualFix): ?>
                    <div data-tour="manual-membership-section" class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                      <div class="flex items-center justify-between">
                        <div>
                          <h3 class="text-base font-semibold text-gray-900">Manual membership order</h3>
                          <p class="text-sm text-gray-500">Create a <strong>new</strong> membership period — separate from the current state above.</p>
                        </div>
                        <span class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full">Admin only</span>
                      </div>
                      <form method="post" action="/admin/members/actions.php" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="orders">
                        <input type="hidden" name="orders_section" value="membership">
                        <input type="hidden" name="action" value="manual_membership_order">
                        <div class="grid gap-3 sm:grid-cols-2">
                          <label class="text-sm font-medium text-gray-700">
                            Membership type
                            <select data-tour="manual-membership-type" name="manual_membership_type_id" required
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                              <option value="" disabled selected>— Select type —</option>
                              <?php foreach ($membershipTypes as $type): ?>
                                <option value="<?= e($type['id']) ?>"><?= e($type['name']) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label class="text-sm font-medium text-gray-700">
                            Cost (AUD)
                            <input data-tour="manual-membership-cost" type="number" step="0.01" min="0" name="manual_membership_cost"
                              value="" placeholder="0.00" required
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                          </label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                          <label class="text-sm font-medium text-gray-700">
                            Payment method
                            <select name="manual_payment_method" required
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                              <option value="" disabled selected>— Select method —</option>
                              <?php foreach (['Stripe', 'Manual', 'Bank Transfer', 'Cash', 'Complimentary'] as $method): ?>
                                <option value="<?= e($method) ?>"><?= e($method) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label class="text-sm font-medium text-gray-700">
                            Membership status
                            <select name="manual_membership_status" required
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                              <option value="" disabled selected>— Select status —</option>
                              <?php foreach (['active' => 'Active', 'pending' => 'Pending', 'complimentary' => 'Complimentary', 'lapsed' => 'Lapsed'] as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                          <label class="text-sm font-medium text-gray-700">
                            Membership start date
                            <input type="date" name="manual_start_date" value="" required
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                          </label>
                          <label class="text-sm font-medium text-gray-700">
                            Renewal date
                            <input type="date" name="manual_renewal_date" value="" required
                              class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                          </label>
                        </div>
                        <label class="text-sm font-medium text-gray-700">
                          Order reference / internal notes
                          <textarea name="manual_order_reference" rows="2" placeholder="Optional"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"></textarea>
                        </label>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                          <span class="material-icons-outlined text-base text-secondary">info</span>
                          <p>Manual entries update Payment, Membership Periods, and Activity automatically.</p>
                        </div>
                        <button type="submit" data-tour="manual-membership-save"
                          class="w-full rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-gray-900">Save manual
                          order</button>
                      </form>
                    </div>
                  <?php endif; ?>

                  <?php if ($canManualFix && !empty($member['email'])):
                    // Build pricing reference: show this member's renewal price per active period.
                    $payReqMagType   = strtolower((string)($member['wings_preference'] ?? 'digital')) === 'digital' ? 'PDF' : 'PRINTED';
                    $payReqMemberType = strtoupper((string)($member['member_type'] ?? 'FULL')) === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL';
                    $payReqPeriods   = [];
                    foreach (\App\Services\MembershipPricingService::getRenewalPeriods(true) as $pPeriod) {
                        $pCents = \App\Services\MembershipPricingService::renewalAmountCents($payReqMagType, $payReqMemberType, (int)$pPeriod['duration_months']);
                        if ($pCents > 0) {
                            $payReqPeriods[] = ['label' => $pPeriod['label'], 'amount' => number_format($pCents / 100, 2)];
                        }
                    }
                  ?>
                  <div class="rounded-3xl border border-blue-100 bg-blue-50/40 p-6 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                      <div>
                        <h3 class="text-base font-semibold text-gray-900">Request payment from member</h3>
                        <p class="text-sm text-gray-500">Creates a new Stripe checkout link and emails it to the member. No new membership period is created.</p>
                      </div>
                      <span class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full">Admin only</span>
                    </div>
                    <?php if ($payReqPeriods): ?>
                      <div>
                        <p class="text-xs font-medium text-gray-500 mb-1.5">Pricing reference for this member (<?= e($payReqMemberType) ?> / <?= e($payReqMagType) ?>)</p>
                        <div class="flex flex-wrap gap-2">
                          <?php foreach ($payReqPeriods as $pp): ?>
                            <button type="button" onclick="document.getElementById('pay-req-amount').value='<?= e($pp['amount']) ?>';document.getElementById('pay-req-desc').value='<?= e(addslashes($pp['label'])) ?> membership renewal'"
                              class="rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-50">
                              <?= e($pp['label']) ?> — $<?= e($pp['amount']) ?>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                    <form method="post" action="/admin/members/actions.php" class="space-y-3">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                      <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                      <input type="hidden" name="tab" value="orders">
                      <input type="hidden" name="orders_section" value="membership">
                      <input type="hidden" name="action" value="membership_payment_request">
                      <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm font-medium text-gray-700">
                          Amount (AUD)
                          <input id="pay-req-amount" type="number" step="0.01" min="0.01" name="req_amount"
                            placeholder="0.00" required
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </label>
                        <label class="text-sm font-medium text-gray-700">
                          Description (shown on invoice)
                          <input id="pay-req-desc" type="text" name="req_description" maxlength="120"
                            placeholder="e.g. 3-year membership renewal top-up"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </label>
                      </div>
                      <p class="text-xs text-gray-500">Link sent to: <strong><?= e($member['email']) ?></strong></p>
                      <button type="submit"
                        class="w-full rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                        Send payment link
                      </button>
                    </form>
                  </div>
                  <?php endif; ?>

                </div>
              <?php else: ?>
                <div class="space-y-6">
                  <div class="rounded-3xl border border-gray-200 bg-white p-4 shadow-sm space-y-3">
                    <div class="flex items-center justify-between">
                      <h3 class="text-sm font-semibold text-gray-900">Store orders</h3>
                      <span class="text-xs font-semibold text-gray-500"><?= count($orders) ?> shown</span>
                    </div>
                    <div class="overflow-x-auto">
                      <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500">
                          <tr>
                            <th class="px-3 py-2">Order #</th>
                            <th class="px-3 py-2">Order status</th>
                            <th class="px-3 py-2">Payment</th>
                            <th class="px-3 py-2">Fulfillment</th>
                            <th class="px-3 py-2">Total</th>
                            <th class="px-3 py-2">Created</th>
                            <th class="px-3 py-2">Actions</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y">
                          <?php foreach ($orders as $order): ?>
                            <?php
                              $storeVoided = !empty($order['voided_at']);
                              $storePaymentStatus = (string) ($order['payment_status'] ?? 'unpaid');
                              $storePaidish = in_array($storePaymentStatus, ['paid', 'partial_refund', 'refunded'], true);
                            ?>
                            <tr class="<?= $storeVoided ? 'bg-slate-50 text-slate-400 line-through opacity-70' : '' ?>">
                              <td class="px-3 py-2 text-gray-600">
                                <?= e($order['order_number'] ?? 'ORD_' . $order['id']) ?>
                                <?php if ($storeVoided): ?>
                                  <span class="ml-1 inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-700 no-underline" title="Voided <?= e((string) $order['voided_at']) ?>">Voided</span>
                                <?php endif; ?>
                              </td>
                              <td class="px-3 py-2">
                                <span
                                  class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= statusBadgeClasses($order['order_status'] ?? '') ?>"><?= ucfirst($order['order_status'] ?? 'Unknown') ?></span>
                              </td>
                              <td class="px-3 py-2 text-gray-600">
                                <?= e(ucwords(str_replace('_', ' ', $storePaymentStatus))) ?>
                              </td>
                              <td class="px-3 py-2 text-gray-600">
                                <?= e(ucfirst((string) ($order['fulfillment_status'] ?? 'unfulfilled'))) ?>
                              </td>
                              <td class="px-3 py-2 text-gray-600"><?= e(formatCurrency($order['total_cents'] ?? 0)) ?></td>
                              <td class="px-3 py-2 text-gray-600"><?= e(formatDate($order['created_at'])) ?></td>
                              <td class="px-3 py-2 text-gray-600">
                                <div class="flex flex-wrap gap-2">
                                  <a class="inline-flex items-center rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700"
                                    href="/admin/store/orders/<?= e($order['id']) ?>" target="_blank">View</a>
                                  <?php if ($canManualFix): ?>
                                    <?php if ($storeVoided): ?>
                                      <form method="post" action="/admin/members/actions.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="orders_section" value="store">
                                        <input type="hidden" name="action" value="store_order_unvoid">
                                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                        <button class="inline-flex items-center rounded-full border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700" type="submit">Restore</button>
                                      </form>
                                    <?php else: ?>
                                      <form method="post" action="/admin/members/actions.php" onsubmit="return confirm('Void this store order? It will be hidden from default lists.');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="orders_section" value="store">
                                        <input type="hidden" name="action" value="store_order_void">
                                        <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                        <button class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700" type="submit">Void</button>
                                      </form>
                                    <?php endif; ?>
                                    <form method="post" action="/admin/members/actions.php" onsubmit="return confirmMembershipOrderDelete(this, <?= $storePaidish ? 'true' : 'false' ?>);">
                                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                      <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                      <input type="hidden" name="tab" value="orders">
                                      <input type="hidden" name="orders_section" value="store">
                                      <input type="hidden" name="action" value="store_order_delete">
                                      <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                      <input type="hidden" name="delete_confirm" value="">
                                      <button class="inline-flex items-center rounded-full border border-red-300 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700" type="submit">Delete</button>
                                    </form>
                                  <?php endif; ?>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                          <?php if (empty($orders)): ?>
                            <tr>
                              <td colspan="7" class="px-3 py-4 text-center text-gray-500">No store orders found.</td>
                            </tr>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <?php if ($canManualFix): ?>
                    <div class="rounded-3xl border border-gray-200 bg-white p-4 shadow-sm space-y-4">
                      <h3 class="text-sm font-semibold text-gray-900">Manual order fix</h3>
                      <form method="post" action="/admin/members/actions.php" class="grid gap-4 lg:grid-cols-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="orders">
                        <input type="hidden" name="orders_section" value="store">
                        <input type="hidden" name="action" value="manual_order_fix">
                        <label class="text-sm font-medium text-gray-700">
                          Order
                          <select name="order_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <?php foreach ($orders as $order): ?>
                              <option value="<?= e($order['id']) ?>"><?= e($order['order_number'] ?? $order['id']) ?> -
                                <?= ucfirst($order['status'] ?? 'Unknown') ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="text-sm font-medium text-gray-700">
                          Status
                          <select name="status" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <?php foreach (['pending', 'paid', 'fulfilled', 'cancelled', 'refunded'] as $statusOption): ?>
                              <option value="<?= e($statusOption) ?>"><?= ucfirst($statusOption) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="text-sm font-medium text-gray-700">
                          Note
                          <textarea name="order_note" rows="2"
                            class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm"></textarea>
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                          <input type="checkbox" name="attach_to_member" value="1"
                            class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                          Attach order to this member
                        </label>
                        <button class="rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900"
                          type="submit">Save fix &amp; log</button>
                      </form>
                    </div>
                    <div class="rounded-3xl border border-gray-200 bg-white p-4 shadow-sm space-y-3">
                      <h3 class="text-sm font-semibold text-gray-900">Resync from Stripe</h3>
                      <form method="post" action="/admin/members/actions.php" class="grid gap-3 lg:grid-cols-2"
                        onsubmit="return confirm('Resynchronize this order with Stripe?');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                        <input type="hidden" name="tab" value="orders">
                        <input type="hidden" name="orders_section" value="store">
                        <input type="hidden" name="action" value="order_resync">
                        <label class="text-sm font-medium text-gray-700">
                          Order
                          <select name="order_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <?php foreach ($orders as $order): ?>
                              <option value="<?= e($order['id']) ?>"><?= e($order['order_number'] ?? $order['id']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <button
                          class="rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700">Resync
                          now</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <script>
              function confirmMembershipOrderDelete(form, isPaidish) {
                var warn = isPaidish
                  ? 'This order shows as paid/refunded. Stripe records will NOT be affected. Continue deleting locally?'
                  : 'Permanently delete this order and its line items?';
                if (!confirm(warn)) return false;
                var typed = (window.prompt('Type DELETE to confirm permanent removal.') || '').trim().toUpperCase();
                if (typed !== 'DELETE') { alert('Cancelled — confirmation not matched.'); return false; }
                form.delete_confirm.value = 'DELETE';
                return true;
              }

              // Renewal-date inline editor — pencil icon on the summary card toggles the form below.
              (function () {
                var toggle = document.querySelector('[data-renewal-edit-toggle]');
                var panel  = document.querySelector('[data-renewal-edit]');
                var cancel = document.querySelector('[data-renewal-edit-cancel]');
                if (!toggle || !panel) return;
                toggle.addEventListener('click', function () {
                  panel.classList.toggle('hidden');
                  if (!panel.classList.contains('hidden')) {
                    var input = panel.querySelector('input[name="renewal_date"]');
                    if (input) input.focus();
                  }
                });
                if (cancel) {
                  cancel.addEventListener('click', function () { panel.classList.add('hidden'); });
                }
              })();
            </script>
          <?php elseif ($tab === 'refunds'): ?>
          <?php elseif ($tab === 'refunds'): ?>
            <div class="space-y-6">
              <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Refund history</h3>
                <div class="overflow-x-auto">
                  <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                      <tr>
                        <th class="px-3 py-2">Refund #</th>
                        <th class="px-3 py-2">Order #</th>
                        <th class="px-3 py-2">Amount</th>
                        <th class="px-3 py-2">Reason</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Created</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y">
                      <?php foreach ($refunds as $refund): ?>
                        <tr>
                          <td class="px-3 py-2 text-gray-600"><?= e($refund['id']) ?></td>
                          <td class="px-3 py-2 text-gray-600"><?= e($refund['order_number'] ?? $refund['order_id']) ?></td>
                          <td class="px-3 py-2 text-gray-600"><?= e(formatCurrency($refund['amount_cents'] ?? 0)) ?></td>
                          <td class="px-3 py-2 text-gray-600"><?= e($refund['reason']) ?></td>
                          <td class="px-3 py-2 text-gray-600">
                            <span
                              class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= statusBadgeClasses($refund['status'] ?? '') ?>"><?= ucfirst($refund['status'] ?? 'Unknown') ?></span>
                          </td>
                          <td class="px-3 py-2 text-gray-600"><?= e(formatDate($refund['created_at'])) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (empty($refunds)): ?>
                        <tr>
                          <td colspan="6" class="px-3 py-4 text-center text-gray-500">No refunds recorded.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <?php if ($canRefund): ?>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm space-y-3">
                  <h3 class="text-sm font-semibold text-gray-900">Request refund</h3>
                  <form method="post" action="/admin/members/actions.php" class="space-y-4"
                    onsubmit="return confirm('Process a Stripe refund?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                    <input type="hidden" name="tab" value="refunds">
                    <input type="hidden" name="action" value="refund_submit">
                    <label class="text-sm font-medium text-gray-700">
                      Order
                      <select name="refund_order_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        <?php foreach ($orders as $order): ?>
                          <?php $remaining = OrderRepository::calculateRefundableCents((int) $order['id']); ?>
                          <option value="<?= e($order['id']) ?>"><?= e($order['order_number'] ?? $order['id']) ?> —
                            <?= e(formatCurrency($remaining)) ?> refundable
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <div class="grid gap-3 lg:grid-cols-2">
                      <label class="text-sm font-medium text-gray-700">
                        Amount (AUD)
                        <input type="number" name="refund_amount" step="0.01" min="0"
                          class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                      </label>
                      <label class="text-sm font-medium text-gray-700">
                        Reason
                        <input type="text" name="refund_reason"
                          class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                      </label>
                    </div>
                    <button class="rounded-full bg-primary px-5 py-2 text-xs font-semibold text-gray-900"
                      type="submit">Create refund</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          <?php elseif ($tab === 'activity'): ?>
            <?php
              // Source badges — mirror the Audit Hub palette where they overlap,
              // and add a few extra for the per-member synthetic timeline.
              $activitySourceLabel = static function (string $src): string {
                return match ($src) {
                  'activity' => 'Activity',
                  'order'    => 'Order',
                  'refund'   => 'Refund',
                  'event'    => 'Event',
                  'download' => 'Download',
                  default    => ucfirst($src),
                };
              };
              $activitySourceBadge = static function (string $src): string {
                return match ($src) {
                  'activity' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
                  'order'    => 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',
                  'refund'   => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
                  'event'    => 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200',
                  'download' => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
                  default    => 'bg-slate-50 text-slate-600 ring-1 ring-slate-200',
                };
              };
              $activityRelativeTime = static function (string $datetime): string {
                if (!$datetime) return '';
                $ts = strtotime($datetime);
                if (!$ts) return '';
                $diff = time() - $ts;
                if ($diff < 60)     return $diff <= 1 ? 'just now' : $diff . 's ago';
                if ($diff < 3600)   return floor($diff / 60) . 'm ago';
                if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
                if ($diff < 604800) return floor($diff / 86400) . 'd ago';
                return date('j M Y', $ts);
              };
            ?>
            <div class="space-y-6">
              <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <form method="get" class="grid gap-4 lg:grid-cols-4">
                  <input type="hidden" name="tab" value="activity">
                  <input type="hidden" name="id" value="<?= e($memberId) ?>">
                  <label class="text-sm font-medium text-gray-700">
                    Start
                    <input type="date" name="activity_start" value="<?= e($activityStartInput) ?>"
                      class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  </label>
                  <label class="text-sm font-medium text-gray-700">
                    End
                    <input type="date" name="activity_end" value="<?= e($activityEndInput) ?>"
                      class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  </label>
                  <label class="text-sm font-medium text-gray-700">
                    Actor
                    <select name="activity_actor" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                      <option value="">Any</option>
                      <?php foreach (['admin', 'member', 'system'] as $actor): ?>
                        <option value="<?= e($actor) ?>" <?= $activityActor === $actor ? 'selected' : '' ?>>
                          <?= ucfirst($actor) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="text-sm font-medium text-gray-700">
                    Action contains
                    <input type="text" name="activity_action" value="<?= e($activityAction) ?>"
                      class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="order.updated">
                  </label>
                  <div class="lg:col-span-4 flex items-center justify-between">
                    <button class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink"
                      type="submit">Apply filters</button>
                    <a href="/admin/audit/?q=<?= e(urlencode(($member['email'] ?? ''))) ?>"
                       class="text-xs font-semibold text-primary hover:underline">
                      View this member in the Audit Hub →
                    </a>
                  </div>
                </form>
              </div>

              <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                  <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-400 bg-gray-50 border-b border-gray-100">
                      <tr>
                        <th class="py-2.5 px-4">When</th>
                        <th class="py-2.5 px-3">Source</th>
                        <th class="py-2.5 px-3">Actor</th>
                        <th class="py-2.5 px-3">Action</th>
                        <th class="py-2.5 px-3">Target</th>
                        <th class="py-2.5 px-3">Details</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <?php if (!$timeline): ?>
                        <tr>
                          <td colspan="6" class="py-12 text-center text-sm text-gray-500">
                            No activity entries match the filters.
                          </td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($timeline as $entry):
                          $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
                          $emailSnapshot = $metadata['email_snapshot'] ?? null;
                          $notificationKey = (string) ($metadata['notification_key'] ?? '');
                          $canResend = ($entry['action'] ?? '') === 'email.sent'
                                       && $notificationKey !== ''
                                       && !in_array($notificationKey, ['security_email_otp'], true);
                          $source = (string) ($entry['source'] ?? 'activity');
                          $ip = (string) ($metadata['ip_address'] ?? '');
                          // Drop the bulky email_snapshot from the friendly-pair
                          // metadata since it has its own rendering block below.
                          $metaForPairs = $metadata;
                          unset($metaForPairs['email_snapshot']);
                          $pairs = AuditHubService::formatPayload($metaForPairs, null, $ip ?: null);
                          $rawJson = AuditHubService::rawPayloadFromArray($metadata);
                          $relative = $activityRelativeTime((string) ($entry['timestamp'] ?? ''));
                        ?>
                          <tr class="hover:bg-gray-50/70 transition-colors align-top">
                            <td class="px-4 py-3 whitespace-nowrap">
                              <p class="text-sm font-medium text-gray-900"><?= e($relative) ?></p>
                              <p class="text-[11px] text-gray-400"><?= e((string) $entry['timestamp']) ?></p>
                            </td>
                            <td class="px-3 py-3">
                              <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold <?= e($activitySourceBadge($source)) ?>">
                                <?= e($activitySourceLabel($source)) ?>
                              </span>
                            </td>
                            <td class="px-3 py-3">
                              <p class="text-sm font-medium text-gray-900"><?= e(ucfirst((string) ($entry['actor_type'] ?? '—'))) ?></p>
                            </td>
                            <td class="px-3 py-3">
                              <p class="text-sm text-gray-800"><?= e((string) ($entry['label'] ?? '—')) ?></p>
                              <?php if (!empty($entry['action']) && $entry['action'] !== $entry['label']): ?>
                                <p class="text-[11px] text-gray-400 font-mono"><?= e((string) $entry['action']) ?></p>
                              <?php endif; ?>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-700">
                              <?= e((string) ($entry['target'] ?? '—')) ?>
                            </td>
                            <td class="px-3 py-3">
                              <?php if ($emailSnapshot && is_array($emailSnapshot)): ?>
                                <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm text-gray-700 space-y-1">
                                  <div><span class="text-[11px] uppercase tracking-wide text-gray-400">Subject</span> <span class="text-gray-800"><?= e($emailSnapshot['subject'] ?? '') ?></span></div>
                                  <div><span class="text-[11px] uppercase tracking-wide text-gray-400">From</span> <span class="text-gray-800"><?= e(($emailSnapshot['from_name'] ?? '') . ' <' . ($emailSnapshot['from_email'] ?? '') . '>') ?></span></div>
                                  <?php if (!empty($emailSnapshot['reply_to'])): ?>
                                    <div><span class="text-[11px] uppercase tracking-wide text-gray-400">Reply-to</span> <span class="text-gray-800"><?= e($emailSnapshot['reply_to']) ?></span></div>
                                  <?php endif; ?>
                                  <?php if ($notificationKey !== ''): ?>
                                    <div><span class="text-[11px] uppercase tracking-wide text-gray-400">Notification</span> <span class="text-gray-800"><?= e($notificationKey) ?></span></div>
                                  <?php endif; ?>
                                  <?php if (isset($metadata['admin_override'])): ?>
                                    <div><span class="text-[11px] uppercase tracking-wide text-gray-400">Admin override</span> <span class="text-gray-800"><?= !empty($metadata['admin_override']) ? 'Yes' : 'No' ?></span></div>
                                  <?php endif; ?>
                                </div>
                                <details class="mt-2">
                                  <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-primary hover:underline">View email body</summary>
                                  <div class="mt-2 rounded-lg border border-gray-100 bg-white p-3 text-sm">
                                    <?= $emailSnapshot['body'] ?? '' ?>
                                  </div>
                                </details>
                                <?php if ($canResend && !empty($entry['id'])): ?>
                                  <form method="post" action="/admin/members/actions.php" class="mt-3">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="member_id" value="<?= e($memberId) ?>">
                                    <input type="hidden" name="tab" value="activity">
                                    <input type="hidden" name="action" value="resend_notification">
                                    <input type="hidden" name="activity_id" value="<?= e((string) $entry['id']) ?>">
                                    <button class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700 hover:border-gray-300"
                                      type="submit">Resend email</button>
                                  </form>
                                <?php endif; ?>
                              <?php elseif ($pairs): ?>
                                <ul class="space-y-1 text-sm text-gray-700">
                                  <?php foreach (array_slice($pairs, 0, 4) as $pair): ?>
                                    <li>
                                      <span class="text-[11px] uppercase tracking-wide text-gray-400"><?= e($pair['label']) ?></span>
                                      <span class="text-gray-800"><?= e($pair['value']) ?></span>
                                    </li>
                                  <?php endforeach; ?>
                                  <?php if (count($pairs) > 4): ?>
                                    <li class="text-[11px] text-gray-400">+<?= e((string) (count($pairs) - 4)) ?> more</li>
                                  <?php endif; ?>
                                </ul>
                              <?php else: ?>
                                <span class="text-sm text-gray-400">—</span>
                              <?php endif; ?>
                              <?php if ($rawJson && !$emailSnapshot): ?>
                                <details class="mt-2">
                                  <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-primary hover:underline">Show raw</summary>
                                  <pre class="mt-2 max-w-md whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-[11px] text-gray-600 border border-gray-100"><?= e($rawJson) ?></pre>
                                </details>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </section>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>
    <div id="upload-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4"
      data-csrf="<?= e($csrfToken) ?>">
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl border border-gray-200">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 class="font-display text-lg font-bold text-gray-900">Upload image</h3>
          <button type="button" class="text-gray-400 hover:text-gray-600" data-upload-close>
            <span class="material-icons-outlined">close</span>
          </button>
        </div>
        <div class="p-5 space-y-4">
          <div id="upload-dropzone"
            class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
            <p class="font-semibold text-gray-700">Drag & drop a file here</p>
            <p class="mt-1 text-xs">or click to browse from your computer</p>
            <input type="file" id="upload-file-input" class="hidden" accept="image/*">
          </div>
          <div id="upload-preview" class="hidden rounded-xl border border-gray-100 bg-white p-3 text-sm"></div>
          <div class="flex items-center justify-end gap-3">
            <button type="button"
              class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700"
              data-upload-cancel>Cancel</button>
            <button type="button"
              class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold disabled:opacity-50"
              data-upload-save disabled>Save</button>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
  (() => {
    const modal = document.getElementById('upload-modal');
    if (!modal) {
      return;
    }
    const dropzone = document.getElementById('upload-dropzone');
    const fileInput = document.getElementById('upload-file-input');
    const preview = document.getElementById('upload-preview');
    const saveBtn = document.querySelector('[data-upload-save]');
    const cancelBtn = document.querySelector('[data-upload-cancel]');
    const closeBtn = document.querySelector('[data-upload-close]');
    const csrfToken = modal.dataset.csrf || '';
    let activeTargetInput = null;
    let activeTargetPreview = null;
    let activeContext = 'members';
    let selectedFile = null;

    const resetModal = () => {
      selectedFile = null;
      if (saveBtn) {
        saveBtn.disabled = true;
      }
      if (preview) {
        preview.innerHTML = '';
        preview.classList.add('hidden');
      }
      if (fileInput) {
        fileInput.value = '';
      }
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
      if (fileInput) {
        fileInput.setAttribute('accept', 'image/*');
      }
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    };

    document.querySelectorAll('[data-upload-trigger]').forEach((trigger) => {
      trigger.addEventListener('click', () => openModal(trigger));
    });

    const setPreview = (file) => {
      if (!preview) {
        return;
      }
      preview.classList.remove('hidden');
      preview.innerHTML = '';
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
      if (saveBtn) {
        saveBtn.disabled = false;
      }
      setPreview(file);
    };

    if (dropzone && fileInput) {
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
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData,
      });
      return response.json();
    };

    const flagFormUnsaved = (targetInput) => {
      if (!targetInput) {
        return;
      }
      const form = targetInput.closest('form');
      if (!form) {
        return;
      }
      let note = form.querySelector('[data-photo-unsaved-note]');
      if (!note) {
        note = document.createElement('div');
        note.setAttribute('data-photo-unsaved-note', '');
        note.className = 'mt-2 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800';
        note.innerHTML = '<span class="material-icons-outlined text-sm">info</span><span>Photo uploaded — click <strong>Save</strong> below to keep it.</span>';
        const submit = form.querySelector('button[type="submit"]');
        if (submit && submit.parentNode) {
          submit.parentNode.insertBefore(note, submit);
        } else {
          form.appendChild(note);
        }
      }
      const submit = form.querySelector('button[type="submit"]');
      if (submit) {
        submit.classList.add('ring-2', 'ring-amber-400', 'ring-offset-2');
      }
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
          activeTargetPreview.innerHTML = `<img src="${result.url}" alt="Uploaded" class="h-full w-full object-cover">`;
        }
        flagFormUnsaved(activeTargetInput);
        closeModal();
      });
    }

    [cancelBtn, closeBtn].forEach((btn) => {
      if (btn) {
        btn.addEventListener('click', closeModal);
      }
    });
  })();
</script>
<script defer src="/assets/js/admin-member-links.js"></script>

<!-- Welcome email confirm modal -->
<div id="welcomeEmailModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
  <div class="absolute inset-0 bg-black/40" id="welcomeEmailModalBackdrop"></div>
  <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl mx-4">
    <div id="welcomeEmailConfirmView">
      <h3 class="text-lg font-semibold text-gray-900">Send welcome email</h3>
      <p class="text-sm text-gray-500 mt-1">This will send a welcome email with a password setup link to the following member:</p>
      <div class="mt-3 rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 flex items-center gap-2">
        <span class="material-icons-outlined text-gray-400 text-[18px]">person</span>
        <div>
          <p class="text-sm font-semibold text-gray-800" id="welcomeEmailMemberName"></p>
          <p class="text-xs text-gray-500" id="welcomeEmailMemberEmail"></p>
        </div>
      </div>
      <div class="flex items-center justify-end gap-2 mt-6 pt-4 border-t border-gray-100">
        <button type="button" id="welcomeEmailCancel" class="rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
        <button type="button" id="welcomeEmailConfirm" class="rounded-full bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700">Send welcome email</button>
      </div>
    </div>
    <div id="welcomeEmailSuccessView" class="hidden text-center py-4">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
        <svg class="h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
      </div>
      <h3 class="text-base font-semibold text-gray-900">Email sent!</h3>
      <p class="mt-1 text-sm text-gray-500">The welcome email has been sent successfully.</p>
    </div>
  </div>
</div>
<script>
  (() => {
    const modal = document.getElementById('welcomeEmailModal');
    const confirmView = document.getElementById('welcomeEmailConfirmView');
    const successView = document.getElementById('welcomeEmailSuccessView');
    const cancelBtn = document.getElementById('welcomeEmailCancel');
    const confirmBtn = document.getElementById('welcomeEmailConfirm');
    const backdrop = document.getElementById('welcomeEmailModalBackdrop');
    const nameEl = document.getElementById('welcomeEmailMemberName');
    const emailEl = document.getElementById('welcomeEmailMemberEmail');
    let pendingForm = null;

    const closeModal = () => {
      modal.classList.add('hidden');
      confirmView.classList.remove('hidden');
      successView.classList.add('hidden');
      pendingForm = null;
    };

    document.querySelectorAll('[data-confirm-email-trigger]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const form = btn.closest('[data-confirm-email-form]');
        if (!form) return;
        pendingForm = form;
        if (nameEl) nameEl.textContent = form.dataset.memberName || 'This member';
        if (emailEl) emailEl.textContent = form.dataset.memberEmail || '';
        modal.classList.remove('hidden');
      });
    });

    cancelBtn?.addEventListener('click', closeModal);
    backdrop?.addEventListener('click', closeModal);

    confirmBtn?.addEventListener('click', () => {
      if (!pendingForm) return;
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Sending…';
      pendingForm.submit();
    });
  })();
</script>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>