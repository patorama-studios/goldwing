<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\CommitteeService;
use App\Services\Csrf;
use App\Services\MembershipService;
use App\Services\MembershipOrderService;
use App\Services\MembershipPricingService;
use App\Services\ChapterRepository;
use App\Services\StripeService;
use App\Services\PaymentSettingsService;
use App\Services\PendingRequestsService;
use App\Services\MembershipUpgradeService;
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
use App\Services\NotificationService;

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
$associateAvatarUrl = SettingsService::getUser((int) ($user['id'] ?? 0), 'associate_avatar_url', '');
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
$billingMessage = $_SESSION['flash_billing_message'] ?? '';
$billingError = $_SESSION['flash_billing_error'] ?? '';
$fallenWings = [];
$fallenYears = [];
$fallenFilterYear = 0;
$fallenMessage = $_SESSION['flash_fallen_message'] ?? '';
$fallenError = $_SESSION['flash_fallen_error'] ?? '';
$fallenTableExists = false;
$noticeMessage = $_SESSION['flash_notice_message'] ?? '';
$noticeError = $_SESSION['flash_notice_error'] ?? '';
$noticeAvatars = [];
$noticeStates = [];
$noticeChapters = [];
$profileMessage = $_SESSION['flash_profile_message'] ?? '';
$profileError = $_SESSION['flash_profile_error'] ?? '';

unset(
  $_SESSION['flash_billing_message'], $_SESSION['flash_billing_error'],
  $_SESSION['flash_fallen_message'], $_SESSION['flash_fallen_error'],
  $_SESSION['flash_notice_message'], $_SESSION['flash_notice_error'],
  $_SESSION['flash_profile_message'], $_SESSION['flash_profile_error']
);
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

function membership_renewal_amount_cents(string $magazineType, string $memberTypeKey, string $termMonths): int
{
  $months = (int) $termMonths;
  // Prefer an admin-defined renewal period whose duration matches exactly.
  $period = MembershipPricingService::findRenewalPeriodByMonths($months);
  if ($period) {
    $cents = MembershipPricingService::getRenewalPriceCents($magazineType, $memberTypeKey, $period['id']);
    if ($cents !== null) {
      return (int) $cents;
    }
  }
  // Fallback: derive from the legacy lookup (which itself falls through to
  // pro-rata calculations if no exact period match exists).
  if ($months === 36) {
    return (int) (MembershipPricingService::getPriceCents($magazineType, $memberTypeKey, 'THREE_YEARS') ?? 0);
  }
  $oneYear = (int) (MembershipPricingService::getPriceCents($magazineType, $memberTypeKey, 'ONE_YEAR') ?? 0);
  if ($months === 24) {
    return $oneYear * 2;
  }
  return $oneYear;
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
  $stmt = $pdo->prepare('SELECT m.*, ' . \App\Services\ChapterRepository::displayNameSql($pdo) . ' as chapter_name FROM members m LEFT JOIN chapters c ON c.id = m.chapter_id WHERE m.id = :id');
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
          $canEditTarget = false; // Associates cannot edit Full member details
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
              'suburb' => trim($_POST['suburb'] ?? ''),
              'state' => trim($_POST['state'] ?? ''),
              'postcode' => trim($_POST['postal_code'] ?? ''),
              'country' => trim($_POST['country'] ?? ''),
              // wings_preference is intentionally NOT taken from POST — it determines
              // membership cost (printed-magazine surcharge applied via MembershipUpgradeService
              // and the apply/renew flows), so members must contact admin to change it.
              // The admin Vehicles/Profile view is the only place this flag can be flipped.
              'privacy_level' => $_POST['privacy_level'] ?? $targetMember['privacy_level'],
              'is_historic' => isset($_POST['is_historic']) ? 1 : 0,
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

          try {
            $stmtChapter = $pdo->prepare('SELECT name, abbreviation FROM chapters WHERE id = :id');
            $stmtChapter->execute(['id' => $requestedChapter]);
            $chapterRow = $stmtChapter->fetch(PDO::FETCH_ASSOC);
            $requestedChapterName = $chapterRow
                ? \App\Services\ChapterRepository::formatLabel($chapterRow['name'] ?? '', $chapterRow['abbreviation'] ?? null)
                : 'Unknown Chapter';
            
            $memberFullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            $memberNumberObj = App\Services\MembershipService::displayMembershipNumber((int) ($member['member_number_base'] ?? 0), (int) ($member['member_number_suffix'] ?? 0));
            $memberNumber = $memberNumberObj ?: 'Unknown';
            
            $adminEmails = [];
            $stmtEmails = $pdo->query('SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin", "committee", "area_rep") AND u.is_active = 1');
            foreach ($stmtEmails->fetchAll() as $row) {
              if (!empty($row['email'])) {
                $adminEmails[] = $row['email'];
              }
            }
            $adminEmails = array_values(array_unique($adminEmails));
            if ($adminEmails) {
              $subject = 'Chapter Change Request: ' . $memberFullName;
              $body = '<p>A member has requested to change their chapter.</p>'
                . '<ul>'
                . '<li><strong>Member:</strong> ' . htmlspecialchars($memberFullName) . '</li>'
                . '<li><strong>Member #:</strong> ' . htmlspecialchars($memberNumber) . '</li>'
                . '<li><strong>Requested Chapter:</strong> ' . htmlspecialchars($requestedChapterName) . '</li>'
                . '</ul>'
                . '<p>Please log in to the admin dashboard under Member Management to review and approve or reject this request.</p>';
              foreach ($adminEmails as $email) {
                EmailService::send($email, $subject, $body, ['is_transactional' => true]);
              }
            }
          } catch (Throwable $e) {
            // Silently fail email sending so it doesn't break the user request.
          }
        }
      } elseif ($_POST['action'] === 'request_profile_change') {
        $field = (string) ($_POST['field_name'] ?? '');
        $requested = (string) ($_POST['requested_value'] ?? '');
        if (!isset(\App\Services\PendingRequestsService::PROFILE_FIELDS[$field])) {
          $profileError = 'Unknown profile field.';
        } else {
          $existing = \App\Services\PendingRequestsService::latestPendingProfileChange((int) $member['id'], $field);
          if ($existing) {
            $profileError = 'A change request for this field is already pending review.';
          } else {
            $newRequestId = \App\Services\PendingRequestsService::submitProfileChange(
              (int) $member['id'],
              $field,
              $requested
            );
            if ($newRequestId === null) {
              $profileError = 'That value was not valid for the selected field.';
            } else {
              $profileMessage = (\App\Services\PendingRequestsService::PROFILE_FIELDS[$field]['label'] ?? 'Profile')
                . ' change request submitted for review.';
              try {
                $memberFullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                $fieldLabel = \App\Services\PendingRequestsService::PROFILE_FIELDS[$field]['label'] ?? $field;
                $newDisplay = \App\Services\PendingRequestsService::formatProfileValue($requested);
                $adminEmails = [];
                $stmtEmails = $pdo->query('SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin", "committee") AND u.is_active = 1');
                foreach ($stmtEmails->fetchAll() as $row) {
                  if (!empty($row['email'])) {
                    $adminEmails[] = $row['email'];
                  }
                }
                $adminEmails = array_values(array_unique($adminEmails));
                if ($adminEmails) {
                  $subject = 'Profile Change Request: ' . $memberFullName;
                  $body = '<p>A member has requested a profile change.</p>'
                    . '<ul>'
                    . '<li><strong>Member:</strong> ' . htmlspecialchars($memberFullName) . '</li>'
                    . '<li><strong>Field:</strong> ' . htmlspecialchars($fieldLabel) . '</li>'
                    . '<li><strong>Requested Value:</strong> ' . htmlspecialchars($newDisplay) . '</li>'
                    . '</ul>'
                    . '<p>Review and approve or reject this request in the Notification Hub.</p>';
                  foreach ($adminEmails as $email) {
                    EmailService::send($email, $subject, $body, ['is_transactional' => true]);
                  }
                }
              } catch (Throwable $e) {
                // Don't let email failures block the request submission.
              }
            }
          }
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
          $canAddBike = false; // Associates cannot add bikes to Full member
        }

        if (!$canAddBike) {
          $profileError = 'You do not have permission to add bikes for this profile.';
        } elseif ($make === '' || $model === '') {
          $profileError = 'Make and model are required.';
        } else {
          try {
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
          } catch (Throwable $e) {
            $profileError = 'Unable to save bike. Please try again.';
          }
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
          $canUpdateBike = false; // Associates cannot update bikes for Full member
        }

        if (!$canUpdateBike || $bikeId <= 0) {
          $profileError = 'You do not have permission to update bikes for this profile.';
        } elseif ($make === '' || $model === '') {
          $profileError = 'Make and model are required.';
        } else {
          try {
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
          } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
              $pdo->rollBack();
            }
            $profileError = 'Unable to save bike changes. Please try again.';
          }
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
          $canDeleteBike = false; // Associates cannot delete bikes for Full member
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
        $assocAvatarInput = trim($_POST['associate_avatar_url'] ?? '');
        SettingsService::setUser((int) $user['id'], 'timezone', $timezone !== '' ? $timezone : SettingsService::getGlobal('site.timezone', 'Australia/Sydney'));
        NotificationPreferenceService::save((int) $user['id'], $prefs);
        SettingsService::setUser((int) $user['id'], 'avatar_url', $avatarInput);
        SettingsService::setUser((int) $user['id'], 'associate_avatar_url', $assocAvatarInput);
        // Committee privacy flag — only persisted if the member actually
        // holds at least one role (the form field only renders in that case).
        if ($member && CommitteeService::rolesForMember((int) $member['id'])) {
            $committeePrivate = isset($_POST['committee_private']) ? 1 : 0;
            try {
                $stmt = $pdo->prepare('UPDATE members SET committee_private = :p WHERE id = :id');
                $stmt->execute([':p' => $committeePrivate, ':id' => (int) $member['id']]);
                $member['committee_private'] = $committeePrivate;
            } catch (\Throwable $e) {
                // Column may not exist yet if Migration 017 hasn't run — fail silently.
            }
        }
        $userTimezone = SettingsService::getUser((int) $user['id'], 'timezone', SettingsService::getGlobal('site.timezone', 'Australia/Sydney'));
        $notificationPrefs = NotificationPreferenceService::load((int) $user['id']);
        $avatarUrl = SettingsService::getUser((int) $user['id'], 'avatar_url', '');
        $associateAvatarUrl = SettingsService::getUser((int) $user['id'], 'associate_avatar_url', '');
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
        } elseif (empty($_POST['acknowledged'])) {
          $billingError = 'Please confirm your membership details are correct before renewing.';
        } else {
          $termMonths = (string) ($_POST['term'] ?? '12');
          if (!in_array($termMonths, ['12', '24', '36'], true)) {
            $termMonths = '12';
          }
          $termCode = $termMonths . 'M';
          $includePartner = !empty($_POST['include_partner']);

          {
            // Partner lookup. `$associates` and `$fullMember` get populated near
            // line 1220 — well after this POST handler runs — so we have to do
            // the same queries inline here, otherwise `$includePartner` always
            // silently drops the partner and Stripe only sees the self total.
            $renewPartnerForHandler = null;
            if ($includePartner) {
              $memberTypeUpper = strtoupper((string) ($member['member_type'] ?? ''));
              if ($memberTypeUpper === 'FULL' || $memberTypeUpper === 'LIFE') {
                $partnerStmt = $pdo->prepare('SELECT * FROM members WHERE full_member_id = :id LIMIT 1');
                $partnerStmt->execute(['id' => $member['id']]);
                $renewPartnerForHandler = $partnerStmt->fetch() ?: null;
              } elseif ($memberTypeUpper === 'ASSOCIATE' && !empty($member['full_member_id'])) {
                $partnerStmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
                $partnerStmt->execute(['id' => $member['full_member_id']]);
                $renewPartnerForHandler = $partnerStmt->fetch() ?: null;
              }
            }

            $renewers = [['member' => $member, 'role' => 'self']];
            if ($renewPartnerForHandler && strtoupper((string) ($renewPartnerForHandler['member_type'] ?? '')) !== 'LIFE') {
              $renewers[] = ['member' => $renewPartnerForHandler, 'role' => 'partner'];
            }

            // Void any prior PENDING_PAYMENT periods + pending/failed orders for
            // every renewer in this transaction. If the member already started a
            // renewal and bailed out of Stripe, we want THIS submission's term
            // and price to be what ships — not the abandoned attempt.
            $renewerMemberIds = [];
            $renewerOrderValues = [];
            foreach ($renewers as $r) {
              $rid = (int) ($r['member']['id'] ?? 0);
              if ($rid > 0) {
                $renewerMemberIds[] = $rid;
              }
              if ($ordersMemberColumn) {
                $val = orders_member_value($r['member'], $user ?? [], $ordersMemberColumn);
                if ($val !== null) {
                  $renewerOrderValues[] = (int) $val;
                }
              }
            }
            // Mark old pending/failed membership orders as cancelled. Voiding
            // (voided_at) would also work, but `cancelled` is the same status
            // markOrderRejected/Failed use and keeps the row visible in audit.
            if ($renewerOrderValues && $ordersMemberColumn) {
              $placeholders = implode(',', array_fill(0, count($renewerOrderValues), '?'));
              $orderUpdate = $pdo->prepare(
                'UPDATE orders SET status = "cancelled", '
                . $ordersPaymentStatusColumn . ' = "cancelled", updated_at = NOW(), '
                . 'internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = "" '
                . 'THEN "Superseded by new renewal attempt" '
                . 'ELSE CONCAT(internal_notes, "\nSuperseded by new renewal attempt") END '
                . 'WHERE ' . $ordersMemberColumn . ' IN (' . $placeholders . ') '
                . 'AND order_type = "membership" '
                . 'AND ' . $ordersPaymentStatusColumn . ' IN ("pending", "failed")'
              );
              $orderUpdate->execute($renewerOrderValues);
            }
            // Delete pending periods — they were never paid, no audit value.
            if ($renewerMemberIds) {
              $periodPlaceholders = implode(',', array_fill(0, count($renewerMemberIds), '?'));
              $periodDelete = $pdo->prepare(
                'DELETE FROM membership_periods '
                . 'WHERE member_id IN (' . $periodPlaceholders . ') '
                . 'AND status = "PENDING_PAYMENT"'
              );
              $periodDelete->execute($renewerMemberIds);
            }

            $pricingCurrency = MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD';
            $startDate = date('Y-m-d');
            $lineItems = [];
            $createdOrderIds = [];
            $createdOrderRows = [];
            $createdPeriodIds = [];
            $renewError = null;
            $termYearsLabel = $termMonths === '36' ? '3 years' : ($termMonths === '24' ? '2 years' : '1 year');

            foreach ($renewers as $r) {
              $rMember = $r['member'];
              $rTypeKey = strtoupper((string) ($rMember['member_type'] ?? 'FULL')) === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL';
              $rMagazine = strtolower((string) ($rMember['wings_preference'] ?? 'digital')) === 'digital' ? 'PDF' : 'PRINTED';

              $amountCents = membership_renewal_amount_cents($rMagazine, $rTypeKey, $termMonths);
              if ($amountCents <= 0) {
                $renewError = 'Membership pricing is not configured for ' . ucfirst(strtolower($rTypeKey)) . ' ' . $termMonths . '-month renewal. Please set it in Admin → Settings → Membership pricing.';
                break;
              }
              $amount = round($amountCents / 100, 2);

              $periodId = MembershipService::createMembershipPeriod((int) $rMember['id'], $termCode, $startDate);
              if (!$periodId) {
                $renewError = 'Unable to create a renewal period.';
                break;
              }
              $createdPeriodIds[] = $periodId;

              $itemLabel = ucfirst(strtolower($rTypeKey)) . ' membership renewal (' . $termYearsLabel . ')';
              $order = MembershipOrderService::createMembershipOrder((int) $rMember['id'], $periodId, $amount, [
                'payment_method' => 'stripe',
                'payment_status' => 'pending',
                'fulfillment_status' => 'pending',
                'currency' => $pricingCurrency,
                'item_name' => $itemLabel,
                'term' => $termCode,
              ]);
              if (!$order) {
                $renewError = 'Unable to create a renewal order.';
                break;
              }
              $createdOrderIds[] = (int) ($order['id'] ?? 0);
              $createdOrderRows[] = [
                'order_number' => (string) ($order['order_number'] ?? ''),
                'member_email' => (string) ($rMember['email'] ?? ''),
                'member_name' => trim(((string) ($rMember['first_name'] ?? '')) . ' ' . ((string) ($rMember['last_name'] ?? ''))),
              ];
              $lineItems[] = [
                'name' => $itemLabel,
                'unit_amount' => $amountCents,
                'quantity' => 1,
                'currency' => strtolower($pricingCurrency),
              ];
            }

            if ($renewError) {
              $billingError = $renewError;
            } else {
              $successUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&success=1');
              $cancelUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&cancel=1');
              $metadata = [
                'order_type' => 'membership',
                'member_id' => (string) $member['id'],
                'order_ids' => implode(',', $createdOrderIds),
                'period_ids' => implode(',', $createdPeriodIds),
                'term' => $termCode,
                'includes_partner' => $partner ? '1' : '0',
              ];
              $session = StripeService::createCheckoutSessionWithLineItems($lineItems, $member['email'] ?? '', $successUrl, $cancelUrl, $metadata);
              if (!$session || empty($session['id'])) {
                $billingError = 'Unable to start renewal payment.';
              } else {
                foreach ($createdOrderIds as $oid) {
                  if ($oid > 0) {
                    OrderService::updateStripeSession($oid, $session['id']);
                  }
                }
                $resumeLink = (string) ($session['url'] ?? BaseUrlService::buildUrl('/member/index.php?page=billing'));
                $bankInstructions = (string) SettingsService::getGlobal('payments.bank_transfer_instructions', '');
                foreach ($createdOrderRows as $created) {
                  if ($created['member_email'] === '') {
                    continue;
                  }
                  NotificationService::dispatch('membership_order_created', [
                    'primary_email' => $created['member_email'],
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'member_name' => $created['member_name'],
                    'order_number' => $created['order_number'],
                    'payment_link' => NotificationService::escape($resumeLink),
                    'payment_method' => 'stripe',
                    'bank_transfer_instructions' => NotificationService::escape($bankInstructions),
                  ]);
                }
                header('Location: ' . ($session['url'] ?? '/member/index.php?page=billing'));
                exit;
              }
            }
          }
        }
      } elseif ($_POST['action'] === 'membership_cancel_request') {
        if (!$member) {
          $billingError = 'Unable to record cancellation.';
        } else {
          $undo = !empty($_POST['undo']);
          if ($undo) {
            $stmt = $pdo->prepare('UPDATE members SET do_not_renew = 0, do_not_renew_at = NULL, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) $member['id']]);
            ActivityLogger::log('member', (int) $member['id'], $user['id'] ?? null, 'membership.cancel_request_withdrawn', []);
            $_SESSION['flash_billing_message'] = 'Cancellation request withdrawn — your membership will still renew.';
          } else {
            $stmt = $pdo->prepare('UPDATE members SET do_not_renew = 1, do_not_renew_at = NOW(), updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) $member['id']]);
            ActivityLogger::log('member', (int) $member['id'], $user['id'] ?? null, 'membership.cancel_requested', [
              'reason' => trim((string) ($_POST['reason'] ?? '')),
            ]);
            $supportEmail = (string) SettingsService::getGlobal('site.support_email', '');
            if ($supportEmail === '') {
              $supportEmail = (string) SettingsService::getGlobal('mail.support_email', '');
            }
            if ($supportEmail !== '') {
              $fullName = trim(((string) ($member['first_name'] ?? '')) . ' ' . ((string) ($member['last_name'] ?? '')));
              $reasonText = trim((string) ($_POST['reason'] ?? ''));
              $body = "A member has requested to cancel their renewal:\n\n"
                . "Member: " . $fullName . " (#" . ($member['id'] ?? '') . ")\n"
                . "Email: " . ($member['email'] ?? '') . "\n"
                . "Type: " . ($member['member_type'] ?? '') . "\n"
                . "Reason: " . ($reasonText !== '' ? $reasonText : '(none provided)') . "\n\n"
                . "They will retain access until their current paid period expires. Their account is flagged 'do not renew'.";
              EmailService::send($supportEmail, 'Member cancellation request: ' . $fullName, $body);
            }
            $_SESSION['flash_billing_message'] = 'Your cancellation request has been recorded. You will keep access until your current period ends. Staff will be in touch.';
          }
          header('Location: /member/index.php?page=billing');
          exit;
        }
      } elseif ($_POST['action'] === 'upgrade_membership') {
        if (!$member) {
          $profileError = 'Unable to start the upgrade.';
        } elseif (strtoupper((string) ($member['member_type'] ?? '')) !== 'ASSOCIATE') {
          $profileError = 'Only Associate members can upgrade to Full membership.';
        } else {
          $result = MembershipUpgradeService::startCheckout($member);
          if (!empty($result['ok']) && !empty($result['redirect_url'])) {
            header('Location: ' . $result['redirect_url']);
            exit;
          }
          $profileError = $result['error'] ?? 'Unable to start the upgrade checkout.';
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
          $hasStatus = in_array('status', $noticeColumns, true);

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
          if ($hasStatus) {
            $insertColumns[] = 'status';
            $insertValues[] = ':status';
            $params['status'] = 'pending';
          }

          $stmt = $pdo->prepare('INSERT INTO notices (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')');
          $stmt->execute($params);
          $noticeId = (int) $pdo->lastInsertId();
          if ($hasPublishedAt) {
            $noticeMessage = 'Notice submitted for approval.';

            // Notify webmasters/admins via the centralised notification hub
            try {
              $reviewEmails = \App\Services\NotificationService::getAdminEmails();
              $stmt = $pdo->query('SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin", "committee", "webmaster") AND u.is_active = 1');
              foreach ($stmt->fetchAll() as $row) {
                if (!empty($row['email'])) {
                  $reviewEmails[] = $row['email'];
                }
              }
              $reviewEmails = array_values(array_unique($reviewEmails));
              \App\Services\NotificationService::dispatch('webmaster_new_request', [
                'admin_emails' => implode(', ', $reviewEmails),
                'request_type' => 'Notice',
                'request_title' => $title,
                'submitter_name' => $user['name'] ?? ($user['email'] ?? 'Member'),
                'review_link' => \App\Services\BaseUrlService::emailLink('/admin/requests/view.php?type=notice&id=' . $noticeId),
              ]);
            } catch (Throwable $e) {
              // fallback no-op; existing email loop kept below for resilience
            }

            // Legacy direct email retained as a fallback
            $reviewEmails = [];
            $stmt = $pdo->query('SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin", "committee") AND u.is_active = 1');
            foreach ($stmt->fetchAll() as $row) {
              if (!empty($row['email'])) {
                $reviewEmails[] = $row['email'];
              }
            }
            $reviewEmails = array_values(array_unique($reviewEmails));
            if (false && $reviewEmails) {
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
          $imageUrl = null;
          $pdfUrl = null;
          $uploadDir = __DIR__ . '/../uploads/fallen_wings/';
          if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
          }

          if (isset($_FILES['tribute_image']) && $_FILES['tribute_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['tribute_image']['error'] === UPLOAD_ERR_OK) {
              $ext = strtolower(pathinfo($_FILES['tribute_image']['name'], PATHINFO_EXTENSION));
              if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = uniqid('img_') . '.' . $ext;
                if (move_uploaded_file($_FILES['tribute_image']['tmp_name'], $uploadDir . $filename)) {
                  $imageUrl = '/uploads/fallen_wings/' . $filename;
                }
              } else {
                $fallenError = 'Image must be JPG, PNG, or WEBP.';
              }
            } else {
              $fallenError = 'Failed to upload image. Error code: ' . $_FILES['tribute_image']['error'];
            }
          }

          if (empty($fallenError) && isset($_FILES['tribute_pdf']) && $_FILES['tribute_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['tribute_pdf']['error'] === UPLOAD_ERR_OK) {
              $ext = strtolower(pathinfo($_FILES['tribute_pdf']['name'], PATHINFO_EXTENSION));
              if ($ext === 'pdf') {
                $filename = uniqid('pdf_') . '.pdf';
                if (move_uploaded_file($_FILES['tribute_pdf']['tmp_name'], $uploadDir . $filename)) {
                  $pdfUrl = '/uploads/fallen_wings/' . $filename;
                }
              } else {
                $fallenError = 'Tribute document must be a PDF.';
              }
            } else {
              $fallenError = 'Failed to upload PDF. Error code: ' . $_FILES['tribute_pdf']['error'];
            }
          }

          if (empty($fallenError)) {
            $stmt = $pdo->prepare('INSERT INTO fallen_wings (full_name, year_of_passing, member_number, tribute, status, submitted_by, image_url, pdf_url, created_at) VALUES (:full_name, :year_of_passing, :member_number, :tribute, "PENDING", :submitted_by, :image_url, :pdf_url, NOW())');
            $stmt->execute([
              'full_name' => $fullName,
              'year_of_passing' => $year,
              'member_number' => $memberNumberNormalized !== '' ? $memberNumberNormalized : null,
              'tribute' => $tribute !== '' ? $tribute : null,
              'image_url' => $imageUrl,
              'pdf_url' => $pdfUrl,
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
      }

      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($profileMessage) $_SESSION['flash_profile_message'] = $profileMessage;
        if ($profileError) $_SESSION['flash_profile_error'] = $profileError;
        if ($billingMessage) $_SESSION['flash_billing_message'] = $billingMessage;
        if ($billingError) $_SESSION['flash_billing_error'] = $billingError;
        if ($noticeMessage) $_SESSION['flash_notice_message'] = $noticeMessage;
        if ($noticeError) $_SESSION['flash_notice_error'] = $noticeError;
        if ($fallenMessage) $_SESSION['flash_fallen_message'] = $fallenMessage;
        if ($fallenError) $_SESSION['flash_fallen_error'] = $fallenError;

        $queryArr = ['page' => $page];
        if (!empty($_GET['member_id'])) {
          $queryArr['member_id'] = (string) $_GET['member_id'];
        }
        $redirectUrl = '/member/index.php?' . http_build_query($queryArr);
        header('Location: ' . $redirectUrl);
        exit;
      }
      $stmt = $pdo->prepare('SELECT m.*, ' . \App\Services\ChapterRepository::displayNameSql($pdo) . ' as chapter_name FROM members m LEFT JOIN chapters c ON c.id = m.chapter_id WHERE m.id = :id');
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

    $canEditProfile = $profileMemberId === (int) $member['id'];
    if (!$canEditProfile && in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
      $canEditProfile = (bool) array_filter($associates, fn($assoc) => (int) ($assoc['id'] ?? 0) === $profileMemberId);
    }
    // Also use this for managing bikes
    $canManageProfileBikes = $canEditProfile;

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
        $eventsSql = 'SELECT e.*, m.path AS thumbnail_url, ' . \App\Services\ChapterRepository::displayNameSql($pdo) . ' AS chapter_name FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id LEFT JOIN chapters c ON c.id = e.chapter_id WHERE e.status = "published" AND (e.scope = "NATIONAL"';
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

    // AGM Awards for the dashboard trophy card. Empty array if member has no
    // wins (or the awards tables aren't present yet on older deploys).
    $memberAwards = [];
    try {
      $memberAwards = \App\Services\AwardsService::winnersForMember((int) $member['id']);
    } catch (\Throwable $awardErr) {
      $memberAwards = [];
    }
    $awardsAreLive = false;
    try {
      $awardsAreLive = \App\Services\AwardsService::isLive();
    } catch (\Throwable $awardErr) {
      $awardsAreLive = false;
    }

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
        'order_number' => $order['order_number'] ?? null,
        'payment_method' => $order['payment_method'] ?? null,
      ];
    }

    if (!empty($member['id'])) {
      try {
        $agmRegistrations = \App\Services\AgmRegistrationService::listForMember((int) $member['id']);
      } catch (\Throwable $agmErr) {
        $agmRegistrations = [];
      }
      foreach ($agmRegistrations as $reg) {
        $regDetail = \App\Services\AgmRegistrationService::getRegistrationById((int) $reg['id']);
        $itemList = [];
        foreach ((($regDetail['items'] ?? [])) as $it) {
          $label = $it['name_snapshot'];
          if (!empty($it['choice_label_snapshot'])) {
            $label .= ' — ' . $it['choice_label_snapshot'];
          }
          $itemList[] = ['label' => $label, 'quantity' => (int) $it['quantity']];
        }
        $orderHistory[] = [
          'type' => 'agm',
          'date' => $reg['created_at'],
          'title' => 'AGM ' . ((int) ($reg['event_year'] ?? 0)) . ' — ' . ($reg['event_title'] ?? '') . ' (' . $reg['registration_number'] . ')',
          'status' => $reg['payment_status'],
          'amount' => number_format((float) $reg['total'], 2),
          'items' => $itemList,
          'order_id' => $reg['id'],
          'order_number' => $reg['registration_number'] ?? null,
          'payment_method' => $reg['payment_method'] ?? null,
        ];
      }
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

    $noticeChapters = ChapterRepository::listForSelection($pdo, true);

    $fallenWings = [];
    $fallenYears = [];
    $fallenFilterYear = isset($_GET['fallen_year']) ? (int) $_GET['fallen_year'] : 0;
    try {
      $fallenTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'fallen_wings'")->fetch();
    } catch (PDOException $e) {
      $fallenTableExists = false;
    }

    if ($fallenTableExists) {
      $fallenSql = 'SELECT *, SUBSTRING_INDEX(full_name, " ", -1) AS last_name FROM fallen_wings WHERE status = "APPROVED" ORDER BY last_name ASC, full_name ASC';
      $stmt = $pdo->prepare($fallenSql);
      $stmt->execute();
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
  'calendar' => 'Ride Calendar',
  'notices-view' => 'Notice Board',
  'notices-create' => 'Create Notice',
  'fallen-wings' => 'Fallen Wings',
  'billing' => 'Billing & Payments',
  'history' => 'Membership History',
  'store' => 'Store',
  'settings' => 'Personal Settings',
  'directory' => 'Members Directory',
  'committee' => 'Committee & Leadership',
];
$pageTitle = $pageTitles[$page] ?? 'Member Portal';
$activePage = in_array($page, ['notices-view', 'notices-create'], true) ? 'notices' : $page;
$activeSubPage = $page;

require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php require __DIR__ . '/../../app/Views/partials/feedback_widget.php'; ?>
    <?php $topbarTitle = $pageTitle;
    require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($page === 'dashboard'): ?>
        <?php if ($profileMessage): ?>
          <div class="rounded-xl bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm flex items-center gap-2">
            <span class="material-icons-outlined text-base">check_circle</span><?= e($profileMessage) ?>
          </div>
        <?php endif; ?>
        <?php if ($profileError): ?>
          <div class="rounded-xl bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm flex items-center gap-2">
            <span class="material-icons-outlined text-base">error_outline</span><?= e($profileError) ?>
          </div>
        <?php endif; ?>
        <?php
        // --- Dashboard data prep ------------------------------------------------
        $welcomeName = trim((string) ($member['first_name'] ?? ''));
        if ($welcomeName === '') {
          $displayName = trim((string) ($user['name'] ?? ''));
          if ($displayName === '' || strcasecmp($displayName, 'member') === 0) {
            $displayName = trim((string) ($member['last_name'] ?? ''));
          }
          $welcomeName = $displayName !== '' ? $displayName : 'there';
        }
        // Committee/leadership role pills sourced from member_committee_assignments.
        $memberRolePills = $member ? CommitteeService::rolesForMember((int) $member['id']) : [];
        $isLifeMember = ($member['member_type'] ?? '') === 'LIFE';
        $expiryLabel = $isLifeMember
          ? 'No expiry'
          : ($membershipPeriod ? (format_date_au($membershipPeriod['end_date'] ?? null) ?: 'N/A') : 'N/A');
        $renewalDays = null;
        if (!$isLifeMember && !empty($membershipPeriod['end_date'])) {
          $renewalDays = (int) ceil((strtotime($membershipPeriod['end_date']) - time()) / 86400);
        }
        $statusKey = strtolower((string) ($member['status'] ?? ''));
        $statusDot = match ($statusKey) {
          'active' => 'bg-green-500',
          'expired', 'inactive' => 'bg-red-500',
          'pending', 'pending_payment' => 'bg-amber-500',
          default => 'bg-slate-400',
        };

        // Partner (associate) card for the hero right-rail. Reuses associate
        // avatar resolution so it matches the profile household panel.
        $dashboardPartner = null;
        if (in_array($member['member_type'] ?? '', ['FULL', 'LIFE'], true)) {
          $dashboardPartner = !empty($associates) ? $associates[0] : null;
          $dashboardPartnerType = 'ASSOCIATE';
        } elseif (($member['member_type'] ?? '') === 'ASSOCIATE' && $fullMember) {
          $dashboardPartner = $fullMember;
          $dashboardPartnerType = strtoupper((string) ($fullMember['member_type'] ?? 'FULL'));
        }
        $dashboardPartnerAvatar = '';
        if ($dashboardPartner) {
          $partnerUserId = (int) ($dashboardPartner['user_id'] ?? 0);
          $partnerType = strtoupper((string) ($dashboardPartner['member_type'] ?? 'FULL'));
          if ($partnerType === 'ASSOCIATE' && $partnerUserId > 0) {
            $dashboardPartnerAvatar = (string) SettingsService::getUser($partnerUserId, 'associate_avatar_url', '');
            if ($dashboardPartnerAvatar === '') {
              $dashboardPartnerAvatar = (string) SettingsService::getUser($partnerUserId, 'avatar_url', '');
            }
            if ($dashboardPartnerAvatar === '' && !empty($member['user_id'])) {
              $dashboardPartnerAvatar = (string) SettingsService::getUser((int) $member['user_id'], 'associate_avatar_url', '');
            }
          } elseif ($partnerUserId > 0) {
            $dashboardPartnerAvatar = (string) SettingsService::getUser($partnerUserId, 'avatar_url', '');
          }
          if ($dashboardPartnerAvatar === '') {
            $dashboardPartnerAvatar = (string) ($dashboardPartner['avatar_url'] ?? '');
          }
        }
        $dashboardPartnerTypeLabel = [
          'FULL' => 'Full Member',
          'ASSOCIATE' => 'Associate',
          'LIFE' => 'Life Member',
        ][strtoupper((string) ($dashboardPartner['member_type'] ?? ''))] ?? 'Member';
        $dashboardPartnerNumber = $dashboardPartner && !empty($dashboardPartner['member_number_base'])
          ? MembershipService::displayMembershipNumber((int) $dashboardPartner['member_number_base'], (int) ($dashboardPartner['member_number_suffix'] ?? 0))
          : '';

        // Primary bike + remaining bikes for the My Garage section.
        $dashboardPrimaryBike = null;
        if (!empty($bikes) && !empty($bikeHasPrimary)) {
          foreach ($bikes as $b) {
            if ((int) ($b['is_primary'] ?? 0) === 1) {
              $dashboardPrimaryBike = $b;
              break;
            }
          }
        }
        if (!$dashboardPrimaryBike && !empty($bikes)) {
          $dashboardPrimaryBike = $bikes[0];
        }
        $dashboardOtherBikes = array_values(array_filter(
          $bikes ?? [],
          fn($b) => $dashboardPrimaryBike === null || (int) ($b['id'] ?? 0) !== (int) ($dashboardPrimaryBike['id'] ?? -1)
        ));

        // Latest AGM trophy (if any).
        $latestAward = !empty($memberAwards) ? $memberAwards[0] : null;
        $awardsHref = $awardsAreLive ? '/members/awards' : '/members/awards';

        // Recent orders for the click-to-expand list.
        $recentOrders = array_slice($orderHistory, 0, 5);
        $membershipStatusLabel = strtoupper((string) ($membershipPeriod['status'] ?? ($member['status'] ?? '')));
        $isMembershipActive = in_array($membershipStatusLabel, ['ACTIVE', 'PAID'], true);
        ?>

        <!-- HERO: welcome left + partner card right -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-2 bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100 relative overflow-hidden">
            <div class="absolute top-0 right-0 p-4 opacity-10 pointer-events-none">
              <span class="material-icons-outlined text-9xl text-primary transform rotate-12">verified</span>
            </div>
            <div class="relative z-10">
              <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                  <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                    Welcome back, <span class="text-primary"><?= e($welcomeName) ?></span> 👋
                  </h1>
                  <p class="text-gray-500">Here's what's happening with your Goldwing membership today.</p>
                  <?php if ($memberRolePills): ?>
                    <div class="flex flex-wrap gap-1.5 mt-3">
                      <?php foreach ($memberRolePills as $rolePill):
                        $isNational = ($rolePill['category'] ?? '') === 'national';
                        $pillClass = $isNational ? 'bg-red-100 text-red-700' : 'bg-indigo-100 text-indigo-700';
                        $iconName = $isNational ? 'star' : 'place';
                      ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?= $pillClass ?>">
                          <span class="material-icons-outlined text-[14px]"><?= e($iconName) ?></span>
                          <?= e($rolePill['name']) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <span class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-medium bg-primary text-gray-900 shadow-sm self-start md:self-center">
                  <?= e($member['member_type'] ?? 'Member') ?> Member
                </span>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-6 border-t border-gray-100 pt-6">
                <div>
                  <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">Membership #</p>
                  <p class="text-lg font-semibold text-gray-900">
                    <?= $member ? e(MembershipService::displayMembershipNumber((int) $member['member_number_base'], (int) $member['member_number_suffix'])) : 'N/A' ?>
                  </p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">Status</p>
                  <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full <?= $statusDot ?>"></span>
                    <p class="text-lg font-semibold text-gray-900"><?= e(ucfirst(strtolower((string) ($member['status'] ?? 'N/A')))) ?></p>
                  </div>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">
                    <?= $isLifeMember ? 'Tenure' : 'Renews' ?>
                  </p>
                  <p class="text-lg font-semibold text-gray-900"><?= e($expiryLabel) ?></p>
                  <?php if ($renewalDays !== null && $renewalDays <= 60 && $renewalDays > 0): ?>
                    <p class="text-xs text-red-600 font-semibold mt-0.5">Due in <?= e((string) $renewalDays) ?> days</p>
                  <?php elseif ($renewalDays !== null && $renewalDays <= 0): ?>
                    <p class="text-xs text-red-600 font-semibold mt-0.5">Overdue</p>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($membershipPeriod && $membershipPeriod['status'] === 'PENDING_PAYMENT'): ?>
                <a href="/member/index.php?page=billing"
                  class="mt-6 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-primary text-gray-900 font-semibold text-sm hover:bg-primary/90 transition">
                  <span class="material-icons-outlined text-base">payments</span>
                  Complete payment
                </a>
              <?php elseif ($renewalDays !== null && $renewalDays <= 60): ?>
                <button type="button" data-renew-trigger
                  class="mt-6 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold text-sm shadow-md transition">
                  <span class="material-icons-outlined text-base">payments</span>
                  Renew membership now
                </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Partner / associate card -->
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col">
            <div class="flex items-center gap-3 mb-4">
              <div class="p-2 bg-blue-100 rounded-lg text-blue-600">
                <span class="material-icons-outlined">favorite</span>
              </div>
              <h2 class="font-display text-lg font-bold text-gray-900">
                <?= ($member['member_type'] ?? '') === 'ASSOCIATE' ? 'Your Full Member' : 'Your Associate' ?>
              </h2>
            </div>
            <?php if ($dashboardPartner):
              $partnerName = trim(($dashboardPartner['first_name'] ?? '') . ' ' . ($dashboardPartner['last_name'] ?? ''));
              $partnerEditUrl = '/member/index.php?page=profile&member_id=' . urlencode((string) $dashboardPartner['id']);
              $partnerStatusKey = strtolower((string) ($dashboardPartner['status'] ?? ''));
              $partnerStatusClass = $partnerStatusKey === 'active'
                ? 'bg-green-100 text-green-800'
                : ($partnerStatusKey === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700');
            ?>
              <div class="flex items-center gap-4 flex-1">
                <div class="h-16 w-16 rounded-full border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                  <?php if ($dashboardPartnerAvatar !== ''): ?>
                    <img src="<?= e($dashboardPartnerAvatar) ?>" alt="<?= e($partnerName) ?>" class="h-full w-full object-cover">
                  <?php else: ?>
                    <span class="text-gray-400 font-semibold text-lg"><?= e(mb_substr($dashboardPartner['first_name'] ?? '', 0, 1) . mb_substr($dashboardPartner['last_name'] ?? '', 0, 1)) ?></span>
                  <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="font-semibold text-gray-900 truncate"><?= e($partnerName) ?></p>
                  <p class="text-xs text-gray-500"><?= e($dashboardPartnerTypeLabel) ?><?= $dashboardPartnerNumber !== '' ? ' · #' . e($dashboardPartnerNumber) : '' ?></p>
                  <span class="inline-block mt-1.5 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide rounded <?= $partnerStatusClass ?>">
                    <?= e($dashboardPartner['status'] ?? 'Unknown') ?>
                  </span>
                </div>
              </div>
              <a href="<?= e($partnerEditUrl) ?>"
                class="mt-4 inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 hover:border-blue-300 transition">
                <span class="material-icons-outlined text-sm">edit</span>
                Edit their details
              </a>
            <?php else: ?>
              <div class="flex-1 flex flex-col items-center justify-center text-center py-4">
                <span class="material-icons-outlined text-4xl text-gray-300 mb-2">person_add</span>
                <p class="text-sm text-gray-500 mb-3">No associate linked to your membership.</p>
                <?php if (in_array($member['member_type'] ?? '', ['FULL', 'LIFE'], true)): ?>
                  <a href="/?page=membership" class="text-xs font-semibold text-primary hover:underline">Add an associate →</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- WINGS MAGAZINE (slim, directly under the welcome) -->
        <?php if ($wingsLatest): ?>
          <section class="bg-gradient-to-r from-indigo-50 to-white rounded-2xl p-6 shadow-sm border border-indigo-100">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
              <div class="w-20 aspect-[3/4] rounded-lg border border-indigo-100 bg-white shadow-sm overflow-hidden flex-shrink-0">
                <?php if (!empty($wingsLatest['cover_image_url'])): ?>
                  <img src="<?= e($wingsLatest['cover_image_url']) ?>" alt="<?= e($wingsLatest['title']) ?>" class="h-full w-full object-cover">
                <?php else: ?>
                  <div class="h-full w-full flex items-center justify-center text-indigo-300">
                    <span class="material-icons-outlined text-3xl">auto_stories</span>
                  </div>
                <?php endif; ?>
              </div>
              <div class="flex-1">
                <p class="text-[11px] uppercase tracking-wide text-indigo-700 font-bold">Latest Wings issue</p>
                <p class="font-display text-xl font-bold text-gray-900 mt-1"><?= e($wingsLatest['title']) ?></p>
                <p class="text-sm text-gray-500 mt-1">Fresh stories, photos and member spotlights — straight from the chapter.</p>
              </div>
              <div class="flex gap-2 flex-shrink-0">
                <a class="inline-flex items-center gap-1 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition"
                  href="/member/read_wings.php?id=<?= e((string) $wingsLatest['id']) ?>">
                  <span class="material-icons-outlined text-base">menu_book</span> Read now
                </a>
                <a class="inline-flex items-center px-4 py-2.5 rounded-xl border border-indigo-200 text-indigo-700 hover:bg-indigo-50 text-sm font-semibold transition"
                  href="/member/index.php?page=wings">Archive</a>
              </div>
            </div>
          </section>
        <?php endif; ?>

        <!-- SNAPSHOT: full-width member-at-a-glance strip -->
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-orange-100 rounded-lg text-orange-600">
                <span class="material-icons-outlined">badge</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Your Snapshot</h2>
            </div>
            <a class="inline-flex items-center text-sm font-semibold text-secondary hover:text-secondary/80" href="/member/index.php?page=profile">
              Edit profile <span class="material-icons-outlined text-base ml-1">arrow_forward</span>
            </a>
          </div>
          <?php if ($member): ?>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
              <div class="rounded-xl bg-gray-50 px-4 py-3 border border-gray-100">
                <p class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold mb-1 flex items-center gap-1">
                  <span class="material-icons-outlined text-[14px]">mail</span> Email
                </p>
                <p class="text-sm font-medium text-gray-900 truncate" title="<?= e($member['email']) ?>"><?= e($member['email']) ?></p>
              </div>
              <div class="rounded-xl bg-gray-50 px-4 py-3 border border-gray-100">
                <p class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold mb-1 flex items-center gap-1">
                  <span class="material-icons-outlined text-[14px]">phone</span> Phone
                </p>
                <p class="text-sm font-medium text-gray-900 truncate"><?= e($member['phone'] ?? 'N/A') ?></p>
              </div>
              <div class="rounded-xl bg-gray-50 px-4 py-3 border border-gray-100">
                <p class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold mb-1 flex items-center gap-1">
                  <span class="material-icons-outlined text-[14px]">groups</span> Chapter
                </p>
                <p class="text-sm font-medium text-gray-900 truncate"><?= e($member['chapter_name'] ?? 'Unassigned') ?></p>
              </div>
              <div class="rounded-xl bg-gray-50 px-4 py-3 border border-gray-100">
                <p class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold mb-1 flex items-center gap-1">
                  <span class="material-icons-outlined text-[14px]">menu_book</span> Wings
                </p>
                <p class="text-sm font-medium text-gray-900 truncate"><?= e(ucfirst(strtolower((string) ($member['wings_preference'] ?? '')))) ?></p>
              </div>
              <div class="rounded-xl bg-gray-50 px-4 py-3 border border-gray-100">
                <p class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold mb-1 flex items-center gap-1">
                  <span class="material-icons-outlined text-[14px]">visibility</span> Directory
                </p>
                <p class="text-sm font-medium text-gray-900 truncate"><?= e(ucfirst(strtolower((string) ($member['privacy_level'] ?? '')))) ?></p>
              </div>
            </div>
          <?php endif; ?>
        </section>

        <!-- 3-COL: Calendar (left) | Quick Actions | AGM Awards -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Upcoming events / calendar -->
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col h-full">
            <div class="flex items-center justify-between mb-5">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-teal-100 rounded-lg text-teal-600">
                  <span class="material-icons-outlined">event</span>
                </div>
                <h2 class="font-display text-xl font-bold text-gray-900">Upcoming Rides</h2>
              </div>
              <a href="/calendar/" class="text-xs font-semibold text-teal-700 hover:underline">Full calendar →</a>
            </div>
            <?php if ($upcomingEvents): ?>
              <ul class="space-y-2 flex-1">
                <?php foreach ($upcomingEvents as $event): ?>
                  <li>
                    <a class="flex items-center justify-between gap-3 rounded-xl border border-transparent hover:border-teal-200 hover:bg-teal-50 px-3 py-2.5 transition"
                      href="<?= e($event['url']) ?>">
                      <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?= e($event['title']) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5">
                          <span class="material-icons-outlined text-[12px] align-middle">schedule</span>
                          <?= e($event['date_label']) ?>
                          <?php if (!empty($event['location'])): ?>
                            · <?= e($event['location']) ?>
                          <?php endif; ?>
                        </p>
                      </div>
                      <span class="material-icons-outlined text-gray-400">chevron_right</span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="flex-1 flex flex-col items-center justify-center text-center py-6">
                <span class="material-icons-outlined text-5xl text-teal-200 mb-2">explore</span>
                <p class="text-sm font-semibold text-gray-700 mb-1">No rides scheduled yet</p>
                <p class="text-xs text-gray-500 mb-4">Nothing in the next 60 days for your chapter — there may be more on the full calendar.</p>
                <a href="/calendar/" class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700 transition">
                  <span class="material-icons-outlined text-sm">map</span>
                  Browse the calendar
                </a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Quick actions -->
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col h-full">
            <div class="flex items-center gap-3 mb-5">
              <div class="p-2 bg-green-100 rounded-lg text-green-600">
                <span class="material-icons-outlined">bolt</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">Quick Actions</h2>
            </div>
            <div class="space-y-2 flex-1">
              <a class="group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition"
                href="/member/index.php?page=profile">
                <span class="flex items-center gap-2 font-medium text-gray-700 group-hover:text-green-700">
                  <span class="material-icons-outlined text-[18px] text-gray-400 group-hover:text-green-600">person</span>
                  Edit my details
                </span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
              <a class="group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition"
                href="/member/index.php?page=billing">
                <span class="flex items-center gap-2 font-medium text-gray-700 group-hover:text-green-700">
                  <span class="material-icons-outlined text-[18px] text-gray-400 group-hover:text-green-600">credit_card</span>
                  Payment methods
                </span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
              <a class="group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition"
                href="/member/index.php?page=history">
                <span class="flex items-center gap-2 font-medium text-gray-700 group-hover:text-green-700">
                  <span class="material-icons-outlined text-[18px] text-gray-400 group-hover:text-green-600">history</span>
                  Membership history
                </span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
              <a class="group flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition"
                href="/member/index.php?page=directory">
                <span class="flex items-center gap-2 font-medium text-gray-700 group-hover:text-green-700">
                  <span class="material-icons-outlined text-[18px] text-gray-400 group-hover:text-green-600">groups</span>
                  Members directory
                </span>
                <span class="material-icons-outlined text-gray-400 group-hover:text-green-600 text-sm">arrow_forward_ios</span>
              </a>
            </div>
          </div>

          <!-- AGM Award trophy card -->
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col h-full relative overflow-hidden">
            <?php if ($latestAward): ?>
              <div class="absolute -top-4 -right-4 opacity-10 pointer-events-none">
                <span class="material-icons text-9xl text-yellow-500">emoji_events</span>
              </div>
            <?php endif; ?>
            <div class="flex items-center gap-3 mb-5 relative z-10">
              <div class="p-2 bg-yellow-100 rounded-lg text-yellow-700">
                <span class="material-icons-outlined">emoji_events</span>
              </div>
              <h2 class="font-display text-xl font-bold text-gray-900">AGM Awards</h2>
            </div>
            <?php if ($latestAward):
              $awardCount = count($memberAwards);
              $awardYear = (int) ($latestAward['year'] ?? 0);
              $awardName = $latestAward['category_name'] ?? 'AGM Award';
              $awardPhoto = $latestAward['primary_photo'] ?? '';
              $awardMemorial = $latestAward['memorial_trophy_name'] ?? '';
            ?>
              <div class="relative z-10 flex-1 flex flex-col">
                <div class="flex items-start gap-3">
                  <div class="h-20 w-20 rounded-xl border-2 border-yellow-300 bg-yellow-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                    <?php if ($awardPhoto !== ''): ?>
                      <img src="<?= e($awardPhoto) ?>" alt="<?= e($awardName) ?>" class="h-full w-full object-cover">
                    <?php else: ?>
                      <span class="material-icons text-yellow-500 text-4xl">emoji_events</span>
                    <?php endif; ?>
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="text-[11px] uppercase tracking-wide text-yellow-700 font-bold"><?= e((string) $awardYear) ?> Winner</p>
                    <p class="text-sm font-bold text-gray-900 leading-tight mt-0.5"><?= e($awardName) ?></p>
                    <?php if ($awardMemorial !== ''): ?>
                      <p class="text-xs text-gray-500 mt-0.5 italic"><?= e($awardMemorial) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($awardCount > 1): ?>
                  <p class="text-xs text-gray-600 mt-3"><span class="font-bold text-yellow-700"><?= e((string) $awardCount) ?></span> trophies in your cabinet 🏆</p>
                <?php endif; ?>
                <a href="<?= e($awardsHref) ?>" class="mt-auto pt-4 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-yellow-400 hover:bg-yellow-500 text-yellow-900 text-sm font-semibold transition">
                  View my trophies
                  <span class="material-icons-outlined text-base">arrow_forward</span>
                </a>
              </div>
            <?php else: ?>
              <div class="relative z-10 flex-1 flex flex-col items-center justify-center text-center">
                <span class="material-icons text-yellow-300 text-5xl mb-2">emoji_events</span>
                <p class="text-sm font-semibold text-gray-700">No trophies yet</p>
                <p class="text-xs text-gray-500 mt-1 mb-4">Take a look at past winners and the memorial trophies awarded each year.</p>
                <a href="<?= e($awardsHref) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border-2 border-yellow-300 text-yellow-800 hover:bg-yellow-50 text-sm font-semibold transition">
                  Browse past winners
                  <span class="material-icons-outlined text-base">arrow_forward</span>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- MY GARAGE -->
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-yellow-50 rounded-lg text-yellow-700">
                <span class="material-icons-outlined">two_wheeler</span>
              </div>
              <div>
                <h2 class="font-display text-xl font-bold text-gray-900">My Garage</h2>
                <p class="text-xs text-gray-500"><?= count($bikes ?? []) ?> <?= count($bikes ?? []) === 1 ? 'bike' : 'bikes' ?> in your stable</p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" data-bike-modal-open
                class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-yellow-400 hover:bg-yellow-500 text-yellow-900 text-sm font-semibold transition">
                <span class="material-icons-outlined text-base">add</span> Add bike
              </button>
              <a href="/member/index.php?page=profile" class="inline-flex items-center text-sm font-semibold text-yellow-700 hover:text-yellow-800">
                Manage <span class="material-icons-outlined text-base ml-1">arrow_forward</span>
              </a>
            </div>
          </div>
          <?php if ($dashboardPrimaryBike): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <!-- Primary bike hero card -->
              <div class="lg:col-span-2 rounded-2xl border border-yellow-200 bg-gradient-to-br from-yellow-50 to-white p-5 flex gap-4">
                <div class="h-28 w-40 lg:h-32 lg:w-48 rounded-xl bg-white border border-yellow-100 overflow-hidden flex items-center justify-center flex-shrink-0 shadow-sm">
                  <?php if (!empty($dashboardPrimaryBike['image_url'])): ?>
                    <img src="<?= e($dashboardPrimaryBike['image_url']) ?>" alt="<?= e(($dashboardPrimaryBike['make'] ?? '') . ' ' . ($dashboardPrimaryBike['model'] ?? '')) ?>" class="h-full w-full object-cover">
                  <?php else: ?>
                    <span class="material-icons-outlined text-yellow-300 text-5xl">two_wheeler</span>
                  <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-yellow-200 text-yellow-900 text-[10px] font-bold uppercase tracking-wide">
                    <span class="material-icons-outlined text-[12px]">star</span> Primary
                  </span>
                  <p class="font-display text-xl font-bold text-gray-900 mt-2 leading-tight">
                    <?= e(trim(($dashboardPrimaryBike['make'] ?? '') . ' ' . ($dashboardPrimaryBike['model'] ?? ''))) ?>
                  </p>
                  <p class="text-sm text-gray-600 mt-0.5"><?= e($dashboardPrimaryBike['year'] ?? 'Year not set') ?></p>
                  <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <?php if (!empty($dashboardPrimaryBike['rego'])): ?>
                      <span class="inline-flex items-center gap-1 rounded-full bg-white border border-yellow-200 px-2.5 py-1 font-semibold text-yellow-800">
                        <span class="material-icons-outlined text-[12px]">badge</span> <?= e($dashboardPrimaryBike['rego']) ?>
                      </span>
                    <?php endif; ?>
                    <?php $primaryColor = $dashboardPrimaryBike['color'] ?? ($dashboardPrimaryBike['colour'] ?? ''); ?>
                    <?php if (!empty($primaryColor)): ?>
                      <span class="inline-flex items-center gap-1 rounded-full bg-white border border-gray-200 px-2.5 py-1 font-semibold text-slate-700">
                        <span class="material-icons-outlined text-[12px]">palette</span> <?= e($primaryColor) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <!-- Other bikes -->
              <?php if ($dashboardOtherBikes): ?>
                <div class="space-y-3">
                  <?php foreach (array_slice($dashboardOtherBikes, 0, 2) as $bike): ?>
                    <div class="rounded-xl border border-gray-200 bg-white p-3 flex items-center gap-3">
                      <div class="h-14 w-16 rounded-lg bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                        <?php if (!empty($bike['image_url'])): ?>
                          <img src="<?= e($bike['image_url']) ?>" alt="<?= e($bike['make'] . ' ' . $bike['model']) ?>" class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-gray-300">two_wheeler</span>
                        <?php endif; ?>
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?= e(trim(($bike['make'] ?? '') . ' ' . ($bike['model'] ?? ''))) ?></p>
                        <p class="text-xs text-gray-500"><?= e($bike['year'] ?? 'Year not set') ?><?= !empty($bike['rego']) ? ' · ' . e($bike['rego']) : '' ?></p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (count($dashboardOtherBikes) > 2): ?>
                    <a href="/member/index.php?page=profile" class="block text-center text-xs font-semibold text-gray-500 hover:text-yellow-700">
                      + <?= e((string) (count($dashboardOtherBikes) - 2)) ?> more in the garage
                    </a>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <button type="button" data-bike-modal-open
                  class="w-full rounded-xl border-2 border-dashed border-gray-200 hover:border-yellow-300 hover:bg-yellow-50 flex flex-col items-center justify-center text-center p-5 transition group">
                  <span class="material-icons-outlined text-gray-300 group-hover:text-yellow-500 text-4xl mb-1">add_circle</span>
                  <p class="text-sm font-semibold text-gray-600 group-hover:text-yellow-800">Add another bike</p>
                  <p class="text-xs text-gray-400 mt-0.5">Keep your stable up to date</p>
                </button>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <button type="button" data-bike-modal-open
              class="w-full block rounded-2xl border-2 border-dashed border-gray-200 hover:border-yellow-300 hover:bg-yellow-50 p-8 text-center transition group">
              <span class="material-icons-outlined text-5xl text-gray-300 group-hover:text-yellow-500 mb-2">two_wheeler</span>
              <p class="font-semibold text-gray-700 group-hover:text-yellow-800">Tell us about your ride</p>
              <p class="text-sm text-gray-500 mt-1">Add your first bike to start your garage.</p>
            </button>
          <?php endif; ?>
        </section>

        <!-- NOTICES + ORDERS -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Notice board (clickable list, no inline content) -->
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-5">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-red-100 rounded-lg text-red-600">
                  <span class="material-icons-outlined">campaign</span>
                </div>
                <h2 class="font-display text-xl font-bold text-gray-900">Notice Board</h2>
              </div>
              <a href="/member/index.php?page=notices-view" class="text-xs font-semibold text-red-700 hover:underline">See all →</a>
            </div>
            <?php if ($dashboardNotices): ?>
              <ul class="divide-y divide-gray-100">
                <?php foreach ($dashboardNotices as $notice):
                  $avatar = $noticeAvatars[$notice['created_by']] ?? '';
                  $categoryLabel = ucfirst($notice['category'] ?? 'notice');
                  $isPinned = !empty($notice['is_pinned']);
                  $noticeDate = !empty($notice['published_at']) ? $notice['published_at'] : ($notice['created_at'] ?? '');
                  $noticeDateLabel = $noticeDate ? format_date_au($noticeDate) : '';
                  $noticeHref = '/member/index.php?page=notices-view#notice-' . urlencode((string) $notice['id']);
                ?>
                  <li>
                    <a href="<?= e($noticeHref) ?>" class="flex items-center gap-3 py-3 hover:bg-red-50/40 rounded-lg px-2 -mx-2 transition group">
                      <div class="h-10 w-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                        <?php if (!empty($avatar)): ?>
                          <img src="<?= e($avatar) ?>" alt="<?= e($notice['created_by_name'] ?? 'Member') ?>" class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-sm">person</span>
                        <?php endif; ?>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                          <?php if ($isPinned): ?>
                            <span class="material-icons-outlined text-[14px] text-red-500 flex-shrink-0" title="Pinned">push_pin</span>
                          <?php endif; ?>
                          <p class="text-sm font-semibold text-gray-900 truncate group-hover:text-red-700"><?= e($notice['title']) ?></p>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5 truncate">
                          <?= e($categoryLabel) ?> · <?= e($notice['created_by_name'] ?? 'Member') ?>
                          <?php if ($noticeDateLabel !== ''): ?> · <?= e($noticeDateLabel) ?><?php endif; ?>
                        </p>
                      </div>
                      <span class="material-icons-outlined text-gray-300 group-hover:text-red-500 text-sm flex-shrink-0">arrow_forward_ios</span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-center py-6">
                <span class="material-icons-outlined text-5xl text-gray-200 mb-2">campaign</span>
                <p class="text-sm text-gray-500">No notices on the board.</p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Order history with merged billing status + expandable rows -->
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between mb-5">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-slate-100 rounded-lg text-slate-600">
                  <span class="material-icons-outlined">receipt_long</span>
                </div>
                <h2 class="font-display text-xl font-bold text-gray-900">Orders &amp; Billing</h2>
              </div>
              <a href="/member/index.php?page=billing" class="text-xs font-semibold text-slate-700 hover:underline">See all →</a>
            </div>
            <!-- Merged billing status pill -->
            <?php if ($membershipPeriod): ?>
              <div class="rounded-xl border <?= $isMembershipActive ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' ?> p-3 mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-sm">
                  <span class="material-icons-outlined <?= $isMembershipActive ? 'text-green-600' : 'text-amber-600' ?> text-base">
                    <?= $isMembershipActive ? 'verified' : 'schedule' ?>
                  </span>
                  <span class="font-semibold <?= $isMembershipActive ? 'text-green-800' : 'text-amber-800' ?>">
                    Membership <?= e(ucfirst(strtolower((string) ($membershipPeriod['status'] ?? '')))) ?>
                  </span>
                  <?php if (!empty($membershipPeriod['paid_at'])): ?>
                    <span class="text-xs text-gray-600 hidden sm:inline">· Last paid <?= e(format_date_au($membershipPeriod['paid_at'])) ?></span>
                  <?php endif; ?>
                </div>
                <a href="/member/index.php?page=billing" class="text-xs font-semibold text-secondary hover:underline whitespace-nowrap">Update card</a>
              </div>
            <?php endif; ?>

            <?php if ($recentOrders): ?>
              <ul class="divide-y divide-gray-100" data-order-list>
                <?php foreach ($recentOrders as $idx => $order):
                  $orderUid = 'order-' . (int) $idx;
                  $orderType = ucfirst($order['type'] ?? '');
                  $orderStatusKey = strtolower((string) ($order['status'] ?? ''));
                  $orderStatusClass = match (true) {
                    in_array($orderStatusKey, ['paid', 'completed', 'active'], true) => 'bg-green-100 text-green-700',
                    in_array($orderStatusKey, ['pending', 'pending_payment', 'processing'], true) => 'bg-amber-100 text-amber-700',
                    in_array($orderStatusKey, ['failed', 'cancelled', 'refunded'], true) => 'bg-red-100 text-red-700',
                    default => 'bg-slate-100 text-slate-700',
                  };
                  $typeIcon = match ($order['type'] ?? '') {
                    'membership' => 'card_membership',
                    'store' => 'shopping_bag',
                    'agm' => 'event_available',
                    default => 'receipt',
                  };
                  $typeColor = match ($order['type'] ?? '') {
                    'membership' => 'bg-green-50 text-green-600',
                    'store' => 'bg-indigo-50 text-indigo-600',
                    'agm' => 'bg-purple-50 text-purple-600',
                    default => 'bg-slate-50 text-slate-500',
                  };
                  $paymentMethodLabel = trim((string) ($order['payment_method'] ?? ''));
                  $paymentMethodLabel = $paymentMethodLabel !== '' ? ucwords(str_replace('_', ' ', $paymentMethodLabel)) : '—';
                ?>
                  <li>
                    <button type="button"
                      data-order-toggle="<?= e($orderUid) ?>"
                      class="w-full flex items-center gap-3 py-3 px-2 -mx-2 hover:bg-slate-50 rounded-lg transition text-left">
                      <div class="h-9 w-9 rounded-lg <?= $typeColor ?> flex items-center justify-center flex-shrink-0">
                        <span class="material-icons-outlined text-[18px]"><?= e($typeIcon) ?></span>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                          <p class="text-sm font-semibold text-gray-900 truncate"><?= e($orderType) ?></p>
                          <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide <?= $orderStatusClass ?>">
                            <?= e($order['status'] ?? '') ?>
                          </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5"><?= e(format_date_au($order['date']) ?: $order['date']) ?></p>
                      </div>
                      <div class="text-right flex-shrink-0">
                        <p class="text-sm font-bold text-gray-900">$<?= e($order['amount']) ?></p>
                      </div>
                      <span class="material-icons-outlined text-gray-300 text-base flex-shrink-0 transition-transform" data-order-chevron="<?= e($orderUid) ?>">expand_more</span>
                    </button>
                    <div id="<?= e($orderUid) ?>" class="hidden px-2 pb-3 -mx-2">
                      <div class="ml-12 rounded-lg bg-slate-50 border border-slate-100 p-3 space-y-2 text-xs">
                        <?php if (!empty($order['items'])): ?>
                          <div>
                            <p class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Items</p>
                            <ul class="space-y-0.5 text-gray-700">
                              <?php foreach ($order['items'] as $item): ?>
                                <li class="flex justify-between gap-2">
                                  <span class="truncate"><?= e($item['label']) ?></span>
                                  <span class="text-gray-500 flex-shrink-0">×<?= e((string) $item['quantity']) ?></span>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                        <?php endif; ?>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 pt-1 border-t border-slate-200">
                          <?php if (!empty($order['order_number'])): ?>
                            <span><span class="text-gray-500">Order #</span> <span class="font-semibold text-gray-900"><?= e((string) $order['order_number']) ?></span></span>
                          <?php endif; ?>
                          <span><span class="text-gray-500">Paid via</span> <span class="font-semibold text-gray-900"><?= e($paymentMethodLabel) ?></span></span>
                          <?php if (!empty($order['days_remaining_label']) && $order['type'] === 'membership'): ?>
                            <span><span class="text-gray-500">Renewal</span> <span class="font-semibold text-gray-900"><?= e($order['days_remaining_label']) ?></span></span>
                          <?php endif; ?>
                        </div>
                        <div class="pt-1">
                          <a href="/member/index.php?page=billing#orders" class="inline-flex items-center gap-1 text-secondary font-semibold hover:underline">
                            Full receipt <span class="material-icons-outlined text-[14px]">arrow_forward</span>
                          </a>
                        </div>
                      </div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-center py-6">
                <span class="material-icons-outlined text-5xl text-gray-200 mb-2">receipt_long</span>
                <p class="text-sm text-gray-500">No orders yet.</p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- Add-bike lightbox modal. Posts to the existing add_bike handler;
             after success the POST handler redirects back to ?page=dashboard. -->
        <div id="bike-add-modal"
          class="hidden fixed inset-0 z-50 items-start justify-center bg-black/60 backdrop-blur-sm overflow-y-auto p-4">
          <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-100 mt-12 mb-12">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
              <div class="flex items-center gap-3">
                <div class="p-2 bg-yellow-100 rounded-lg text-yellow-700">
                  <span class="material-icons-outlined">two_wheeler</span>
                </div>
                <div>
                  <h3 class="font-display text-lg font-bold text-gray-900">Add a bike to your garage</h3>
                  <p class="text-xs text-gray-500">Tell us what you ride. You can edit details later from your profile.</p>
                </div>
              </div>
              <button type="button" data-bike-modal-close class="text-gray-400 hover:text-gray-600">
                <span class="material-icons-outlined">close</span>
              </button>
            </div>
            <form method="post" action="/member/index.php?page=dashboard" class="p-6 space-y-3">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="add_bike">
              <input type="hidden" name="profile_member_id" value="<?= e((string) $member['id']) ?>">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block">
                  <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Make *</span>
                  <input type="text" name="bike_make" placeholder="Honda" required
                    class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/20">
                </label>
                <label class="block">
                  <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Model *</span>
                  <input type="text" name="bike_model" placeholder="GL1800" required
                    class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/20">
                </label>
                <label class="block">
                  <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Year</span>
                  <input type="number" name="bike_year" placeholder="2023" min="1970" max="2030"
                    class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/20">
                </label>
                <label class="block">
                  <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Colour</span>
                  <input type="text" name="bike_color" placeholder="Black Metallic"
                    class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/20">
                </label>
              </div>
              <label class="block">
                <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Rego / Registration Number</span>
                <input type="text" name="bike_rego" placeholder="ABC123"
                  class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/20">
              </label>
              <div>
                <span class="text-xs font-semibold text-gray-700 uppercase tracking-wide block mb-1">Photo</span>
                <div class="flex items-center gap-3">
                  <div id="bike-modal-image-preview"
                    class="h-16 w-20 rounded-lg bg-gray-50 text-gray-300 flex items-center justify-center overflow-hidden border border-gray-200">
                    <span class="material-icons-outlined">image</span>
                  </div>
                  <input type="hidden" name="bike_image_url" id="bike-modal-image-url-input">
                  <button type="button"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                    data-upload-trigger data-upload-target="bike-modal-image-url-input"
                    data-upload-preview="bike-modal-image-preview" data-upload-context="bikes">
                    <span class="material-icons-outlined text-sm">cloud_upload</span> Upload photo
                  </button>
                </div>
              </div>
              <div class="flex items-center justify-end gap-2 pt-3 border-t border-gray-100">
                <button type="button" data-bike-modal-close
                  class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                  Cancel
                </button>
                <button type="submit"
                  class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-yellow-400 hover:bg-yellow-500 text-yellow-900 text-sm font-semibold shadow-sm">
                  <span class="material-icons-outlined text-base">add</span> Add to garage
                </button>
              </div>
            </form>
          </div>
        </div>

        <script>
          // Click-to-expand order rows on the dashboard.
          document.querySelectorAll('[data-order-toggle]').forEach(btn => {
            btn.addEventListener('click', () => {
              const id = btn.getAttribute('data-order-toggle');
              const panel = document.getElementById(id);
              const chevron = document.querySelector('[data-order-chevron="' + id + '"]');
              if (!panel) return;
              const isOpen = !panel.classList.contains('hidden');
              panel.classList.toggle('hidden');
              if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
            });
          });

          // Add-bike lightbox open/close.
          (() => {
            const modal = document.getElementById('bike-add-modal');
            if (!modal) return;
            const open = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden'; };
            const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = ''; };
            document.querySelectorAll('[data-bike-modal-open]').forEach(b => b.addEventListener('click', open));
            modal.querySelectorAll('[data-bike-modal-close]').forEach(b => b.addEventListener('click', close));
            modal.addEventListener('click', e => { if (e.target === modal) close(); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
          })();
        </script>
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
          trim((string) ($profileMember['suburb'] ?? $profileMember['city'] ?? '')),
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
        $joinedLabel = format_date($profileMember['join_date'] ?? $profileMember['created_at'] ?? null);
        $joinDateValue = !empty($profileMember['join_date'])
          ? substr((string) $profileMember['join_date'], 0, 10)
          : (!empty($profileMember['created_at']) ? substr((string) $profileMember['created_at'], 0, 10) : '');
        $pendingJoinDateRequest = \App\Services\PendingRequestsService::latestPendingProfileChange((int) $profileMemberId, 'join_date');
        $twofaStatusLabel = $twofaEnabled ? 'Enabled' : 'Not enabled';
        $twofaActionHref = $twofaEnabled ? '/member/2fa_verify.php' : '/member/2fa_enroll.php';
        $twofaActionLabel = $twofaEnabled ? 'Manage 2FA' : 'Setup 2FA';
        $profileActionUrl = '/member/index.php?page=profile';
        if ($profileMemberId !== $member['id']) {
          $profileActionUrl .= '&member_id=' . urlencode((string) $profileMemberId);
        }
        // Household header
        $householdMain = null;
        $householdAssoc = null;
        if (in_array($member['member_type'], ['FULL', 'LIFE'], true)) {
          $householdMain = $member;
          $householdAssoc = !empty($associates) ? $associates[0] : null;
        } elseif ($member['member_type'] === 'ASSOCIATE' && $fullMember) {
          $householdMain = $fullMember;
          $householdAssoc = $member;
        }
        $isHousehold = $householdMain !== null && $householdAssoc !== null;
        $memberNumberForPanel = static function(array $m): string {
          if (!empty($m['member_number_base'])) {
            return MembershipService::displayMembershipNumber((int) $m['member_number_base'], (int) ($m['member_number_suffix'] ?? 0));
          }
          return (string) ($m['member_number'] ?? '—');
        };
        $typeLabelsPanel = ['FULL' => 'Full Member', 'ASSOCIATE' => 'Associate', 'LIFE' => 'Life Member'];
        // Resolve avatar for any household member from THEIR own user settings,
        // not the logged-in user's. Falls back to legacy members.avatar_url and,
        // for associates, to the linked full member's associate_avatar_url.
        $panelAvatarFor = static function(array $m, ?array $owner): string {
          $userId = (int) ($m['user_id'] ?? 0);
          $type = strtoupper((string) ($m['member_type'] ?? 'FULL'));
          if ($type === 'ASSOCIATE') {
            if ($userId > 0) {
              $url = (string) SettingsService::getUser($userId, 'associate_avatar_url', '');
              if ($url === '') {
                $url = (string) SettingsService::getUser($userId, 'avatar_url', '');
              }
              if ($url !== '') return $url;
            }
            if ($owner && !empty($owner['user_id'])) {
              $url = (string) SettingsService::getUser((int) $owner['user_id'], 'associate_avatar_url', '');
              if ($url !== '') return $url;
            }
          } else {
            if ($userId > 0) {
              $url = (string) SettingsService::getUser($userId, 'avatar_url', '');
              if ($url !== '') return $url;
            }
          }
          return (string) ($m['avatar_url'] ?? '');
        };
        ?>
        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="border-b border-gray-100 px-8 py-6">
            <div class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-2 mb-5">
              <span class="material-icons-outlined text-[16px]">groups</span>
              Household Profile
              <?php if ($profileMemberId !== $member['id']): ?>
                <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-[10px] font-semibold"><?= e($profileContextLabel) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($isHousehold): ?>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <?php foreach ([$householdMain, $householdAssoc] as $panelMember):
                  $panelIsActive = (int) $panelMember['id'] === $profileMemberId;
                  $panelType = strtoupper((string) ($panelMember['member_type'] ?? 'FULL'));
                  $panelIsLife = $panelType === 'LIFE';
                  $panelIsAssoc = $panelType === 'ASSOCIATE';
                  $panelAvatar = $panelAvatarFor($panelMember, $panelIsAssoc ? $householdMain : null);
                  $panelName = trim(($panelMember['first_name'] ?? '') . ' ' . ($panelMember['last_name'] ?? ''));
                  $panelNumber = $memberNumberForPanel($panelMember);
                  $panelTypeLabel = $typeLabelsPanel[$panelType] ?? ucfirst(strtolower($panelType));
                  $panelPhone = $panelMember['phone'] ?? '';
                  $panelHistoric = !empty($panelMember['is_historic']);
                  $panelEditUrl = '/member/index.php?page=profile&member_id=' . urlencode((string) $panelMember['id']);
                ?>
                <div class="rounded-xl border-2 <?= $panelIsActive ? 'border-primary' : 'border-gray-200' ?> <?= $panelIsLife ? 'bg-yellow-50' : 'bg-white' ?> overflow-hidden flex flex-col">
                  <div class="p-5 flex items-start gap-4 flex-1">
                    <div class="shrink-0">
                      <div class="h-20 w-20 rounded-full <?= $panelIsLife ? 'border-2 border-yellow-300 bg-yellow-200' : 'border border-gray-200 bg-gray-50' ?> overflow-hidden flex items-center justify-center">
                        <?php if (!empty($panelAvatar)): ?>
                          <img src="<?= e($panelAvatar) ?>" alt="<?= e($panelName) ?>" class="h-full w-full object-cover">
                        <?php elseif ($panelIsLife): ?>
                          <span class="material-icons-outlined text-yellow-600 text-3xl">star</span>
                        <?php else: ?>
                          <span class="text-gray-400 font-semibold text-xl"><?= e(mb_substr($panelMember['first_name'] ?? '', 0, 1) . mb_substr($panelMember['last_name'] ?? '', 0, 1)) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="flex flex-wrap items-center gap-2 mb-1">
                        <h2 class="text-lg font-bold <?= $panelIsLife ? 'text-yellow-900' : 'text-gray-900' ?> leading-tight"><?= e($panelName) ?></h2>
                        <?php if ($panelIsLife): ?>
                          <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-yellow-200 text-yellow-800 shrink-0">
                            <span class="material-icons-outlined text-[10px] leading-none">star</span>Life
                          </span>
                        <?php endif; ?>
                      </div>
                      <p class="text-xs text-gray-500 mb-2">#<?= e($panelNumber) ?> &middot; <?= e($panelTypeLabel) ?></p>
                      <?php if ($panelPhone !== ''): ?>
                        <p class="text-sm text-gray-700 flex items-center gap-1 mb-1">
                          <span class="material-icons-outlined text-[14px] text-gray-400">phone</span><?= e($panelPhone) ?>
                        </p>
                      <?php endif; ?>
                      <?php if ($panelHistoric): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-stone-100 text-stone-700 border border-stone-200">
                          <span class="material-icons-outlined text-[10px] leading-none">history</span>Historic Vehicle
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($panelIsActive): ?>
                    <div class="border-t border-primary/20 bg-primary/5 px-5 py-2.5 flex items-center gap-1.5 text-xs font-semibold text-primary">
                      <span class="material-icons-outlined text-[14px]">edit</span>Editing this profile below
                    </div>
                  <?php else: ?>
                    <a href="<?= e($panelEditUrl) ?>" class="border-t border-gray-100 px-5 py-2.5 flex items-center justify-between text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-primary transition-colors">
                      Edit profile
                      <span class="material-icons-outlined text-[14px]">chevron_right</span>
                    </a>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
            <?php else:
              $profileAvatar = $panelAvatarFor($profileMember, $fullMember);
            ?>
              <div class="flex items-center gap-4 mb-6">
                <div class="h-16 w-16 rounded-full border border-gray-200 bg-gray-50 overflow-hidden">
                  <?php if (!empty($profileAvatar)): ?>
                    <img src="<?= e($profileAvatar) ?>" alt="<?= e($profileMember['first_name'] . ' ' . $profileMember['last_name']) ?>" class="h-full w-full object-cover">
                  <?php else: ?>
                    <span class="flex h-full w-full items-center justify-center text-gray-400 font-semibold text-lg">
                      <?= e(substr($profileMember['first_name'] ?? '', 0, 1) . substr($profileMember['last_name'] ?? '', 0, 1)) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div>
                  <h1 class="text-3xl font-bold text-gray-900 font-display"><?= e($profileMember['first_name'] . ' ' . $profileMember['last_name']) ?></h1>
                  <p class="text-sm text-gray-500"><?= e($profileContextNote !== '' ? $profileContextNote : 'Manage your personal profile information.') ?></p>
                </div>
              </div>
            <?php endif; ?>
            <div class="flex flex-wrap items-center gap-3">
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
              <div class="inline-flex items-center gap-2 rounded-lg bg-yellow-100 px-4 py-2 text-sm font-semibold text-yellow-700 border border-yellow-200 uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-yellow-500"></span>
                <?= e($currentStatusLabel) ?>
              </div>
              <?php if ($profileMemberId !== $member['id'] && !$isHousehold): ?>
                <a href="/member/index.php?page=profile" class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                  <span class="material-icons-outlined text-[16px]">arrow_back</span>Back to my profile
                </a>
              <?php endif; ?>
            </div>
          </div>
          <div class="p-5 space-y-6">
            <?php if ($profileMessage): ?>
              <div
                class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700">
                <?= e($profileMessage) ?>
              </div>
            <?php endif; ?>
            <?php if ($profileError): ?>
              <div class="rounded-2xl border border-red-100 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700">
                <?= e($profileError) ?>
              </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
              <div class="lg:col-span-2 space-y-6">
                <form data-tour="profile-form" method="post" action="/member/index.php?page=profile" class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="update_profile">
                  <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                  <div class="sticky top-16 z-10 bg-white/95 backdrop-blur-sm p-6 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                      <div class="bg-primary/10 p-2 rounded-lg text-primary">
                        <span class="material-icons-outlined">badge</span>
                      </div>
                      <div>
                        <h2 class="text-lg font-bold text-gray-900">Contact &amp; billing</h2>
                        <p class="text-xs text-gray-500">Update contact details, billing address, and preferences for your membership.</p>
                      </div>
                    </div>
                    <?php if ($canEditProfile): ?>
                      <button data-tour="profile-save" type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold shadow-sm hover:bg-primary/90">Save changes</button>
                    <?php endif; ?>
                  </div>
                  <fieldset class="p-8 space-y-8" <?php if (!$canEditProfile) echo 'disabled'; ?>>
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label class="text-sm font-medium text-gray-700">Email</label>
                          <input data-tour="profile-email" type="email" name="email" value="<?= e($profileMember['email']) ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20"
                            required>
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Phone</label>
                          <input data-tour="profile-phone" type="text" name="phone" value="<?= e($profileMember['phone'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="md:col-span-2">
                          <label class="text-sm font-medium text-gray-700">Billing address line 1</label>
                          <input id="member_profile_address_line1" data-google-autocomplete="address"
                            data-google-autocomplete-city="#member_profile_suburb"
                            data-google-autocomplete-state="#member_profile_state"
                            data-google-autocomplete-postal="#member_profile_postal"
                            data-google-autocomplete-country="#member_profile_country" type="text" name="address_line1"
                            value="<?= e($profileMember['address_line1'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="md:col-span-2">
                          <label class="text-sm font-medium text-gray-700">Billing address line 2</label>
                          <input id="member_profile_address_line2" type="text" name="address_line2"
                            value="<?= e($profileMember['address_line2'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Suburb</label>
                          <input id="member_profile_suburb" type="text" name="suburb"
                            value="<?= e($profileMember['suburb'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">State</label>
                          <input id="member_profile_state" type="text" name="state"
                            value="<?= e($profileMember['state'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Postal code</label>
                          <input id="member_profile_postal" type="text" name="postal_code"
                            value="<?= e($profileMember['postal_code'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                          <label class="text-sm font-medium text-gray-700">Country</label>
                          <input id="member_profile_country" type="text" name="country"
                            value="<?= e($profileMember['country'] ?? '') ?>"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                        </div>
                      </div>
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php
                          // Wings preference is read-only for members — it controls the
                          // printed-magazine surcharge on their membership cost, so only
                          // admins can change it. Members see their current value with a
                          // helper note pointing them at the webmaster.
                          $wp = strtolower((string) ($profileMember['wings_preference'] ?? 'digital'));
                          $wpLabel = match ($wp) {
                            'print' => 'Print',
                            'both'  => 'Both (Digital + Print)',
                            default => 'Digital',
                          };
                        ?>
                        <div class="text-sm font-medium text-gray-700">
                          Wings preference
                          <div class="mt-1 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 flex items-center gap-2">
                            <span class="material-icons-outlined text-gray-400 text-base">lock</span>
                            <span class="font-semibold"><?= e($wpLabel) ?></span>
                          </div>
                          <p class="mt-1 text-xs text-gray-500">Affects your membership cost — contact the webmaster to change.</p>
                        </div>
                        <label class="text-sm font-medium text-gray-700">
                          Directory privacy
                          <select name="privacy_level"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                            <option value="A" <?= $profileMember['privacy_level'] === 'A' ? 'selected' : '' ?>>A — Name only
                            </option>
                            <option value="B" <?= $profileMember['privacy_level'] === 'B' ? 'selected' : '' ?>>B — Name +
                              Address</option>
                            <option value="C" <?= $profileMember['privacy_level'] === 'C' ? 'selected' : '' ?>>C — Name +
                              Address + Phone</option>
                            <option value="D" <?= $profileMember['privacy_level'] === 'D' ? 'selected' : '' ?>>D — Name +
                              Address + Email</option>
                            <option value="E" <?= $profileMember['privacy_level'] === 'E' ? 'selected' : '' ?>>E — Name +
                              Address + Phone + Email</option>
                            <option value="F" <?= $profileMember['privacy_level'] === 'F' ? 'selected' : '' ?>>F — Exclude
                              from directory</option>
                          </select>
                        </label>
                      </div>
                      <div>
                        <p class="text-sm font-medium text-gray-700 mb-3">Directory preferences &amp; assistance</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-gray-600">
                          <?php foreach ($directoryPrefs as $letter => $info): ?>
                            <label class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                              <input type="checkbox" name="directory_pref_<?= e($letter) ?>" value="1"
                                <?= !empty($profileMember[$info['column']] ?? null) ? 'checked' : '' ?>
                                class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                              <span><?= e($letter) ?> — <?= e($info['label']) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="rounded-xl border border-stone-200 bg-stone-50 px-4 py-4">
                        <p class="text-sm font-medium text-gray-700 mb-1">Historic vehicle registration</p>
                        <p class="text-xs text-gray-500 mb-3">Tick if this member's motorcycle qualifies as a historic vehicle (25+ years old). AGA club membership proof may be required for state-based historic registration concessions.</p>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                          <input type="checkbox" name="is_historic" value="1"
                            <?= !empty($profileMember['is_historic']) ? 'checked' : '' ?>
                            class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                          <span class="font-medium">Historic vehicle member</span>
                        </label>
                      </div>
                      <?php if ($canEditProfile): ?>
                        <div class="flex justify-end">
                          <button type="submit"
                            class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold">Save
                            changes</button>
                        </div>
                      <?php endif; ?>
                  </fieldset>
                </form>
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
                      <span
                        class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold <?= $profileStatusClasses ?>">
                        <span
                          class="w-2 h-2 rounded-full <?= strpos($profileStatusClasses, 'text-green') !== false ? 'bg-green-500' : 'bg-yellow-500' ?>"></span>
                        <?= e($profileMembershipStatusLabel) ?>
                      </span>
                    </div>
                    <div>
                      <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Joined</p>
                      <p class="text-sm font-medium text-gray-900"><?= e($joinedLabel) ?></p>
                      <?php if ($profileMemberId === (int) $member['id']): ?>
                        <?php if ($pendingJoinDateRequest): ?>
                          <p class="mt-1 text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-md px-2 py-1">
                            Pending review:
                            <?= e(\App\Services\PendingRequestsService::formatProfileValue($pendingJoinDateRequest['requested_value'] ?? null)) ?>
                          </p>
                        <?php else: ?>
                          <details class="mt-1 text-xs">
                            <summary class="cursor-pointer text-primary hover:underline">Request correction</summary>
                            <form method="post" class="mt-2 flex flex-wrap items-center gap-2">
                              <input type="hidden" name="action" value="request_profile_change">
                              <input type="hidden" name="field_name" value="join_date">
                              <input type="date" name="requested_value" value="<?= e($joinDateValue) ?>"
                                class="rounded-md border border-gray-200 px-2 py-1 text-xs">
                              <button type="submit" class="rounded-md bg-primary px-2 py-1 text-xs font-semibold text-ink hover:bg-primary/90">
                                Submit
                              </button>
                            </form>
                            <p class="mt-1 text-[11px] text-gray-500">An admin reviews your request before the date changes.</p>
                          </details>
                        <?php endif; ?>
                      <?php endif; ?>
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
                  <?php
                    $isOwnProfile = (int) $profileMemberId === (int) $member['id'];
                    $isAssociate = strtoupper((string) ($profileMember['member_type'] ?? '')) === 'ASSOCIATE';
                    $upgradePriceCents = ($isOwnProfile && $isAssociate)
                      ? MembershipUpgradeService::getUpgradePriceCents($profileMember) : null;
                    $showUpgradeButton = $isOwnProfile && $isAssociate;
                  ?>
                  <?php if ($showUpgradeButton): ?>
                    <div class="rounded-xl border border-primary/30 bg-primary/5 p-4 space-y-2">
                      <div class="flex items-start gap-3">
                        <span class="material-icons-outlined text-primary">upgrade</span>
                        <div class="flex-1">
                          <p class="text-sm font-semibold text-gray-900">Upgrade to Full Membership</p>
                          <p class="text-xs text-gray-600">
                            Become a Full member in your own right. You'll be issued a new member number and your link to the primary member's household will be removed.
                            <?php if ($upgradePriceCents !== null): ?>
                              The current upgrade fee is <strong>A$<?= e(number_format($upgradePriceCents / 100, 2)) ?></strong>.
                            <?php else: ?>
                              <span class="text-amber-700">(Upgrade pricing not yet configured — please contact the administrator.)</span>
                            <?php endif; ?>
                          </p>
                        </div>
                      </div>
                      <?php if ($upgradePriceCents !== null): ?>
                        <form method="post" class="flex justify-end">
                          <input type="hidden" name="action" value="upgrade_membership">
                          <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink hover:bg-primary/90">
                            <span class="material-icons-outlined text-base">shopping_cart_checkout</span>
                            Upgrade now (A$<?= e(number_format($upgradePriceCents / 100, 2)) ?>)
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
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
                    <a class="w-full inline-flex items-center justify-center rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                      href="/member/reset_password.php">
                      <span class="material-icons-outlined text-base">lock_reset</span>
                      <span class="ml-2">Reset password</span>
                    </a>
                    <div
                      class="flex items-center justify-between rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-900">
                      <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-gray-400">Two-factor authentication</p>
                        <p><?= e($twofaStatusLabel) ?>
                          (<?= strtolower($twofaRequirement) === 'required' ? 'required' : 'optional' ?>)</p>
                      </div>
                      <a class="inline-flex items-center gap-1 text-xs font-semibold text-primary"
                        href="<?= e($twofaActionHref) ?>">
                        <?= e($twofaActionLabel) ?>
                        <span class="material-icons-outlined text-[16px]">open_in_new</span>
                      </a>
                    </div>
                    <a class="inline-flex items-center justify-center gap-2 rounded-full border border-primary bg-primary/10 px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/20"
                      href="/?page=membership">
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
                          <li
                            class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-white px-3 py-2">
                            <div>
                              <p class="font-medium text-gray-900"><?= e($assoc['first_name'] . ' ' . $assoc['last_name']) ?>
                              </p>
                              <p class="text-xs text-gray-500">
                                <?= e(MembershipService::displayMembershipNumber((int) $assoc['member_number_base'], (int) $assoc['member_number_suffix'])) ?>
                              </p>
                            </div>
                            <a class="inline-flex items-center text-xs font-semibold text-secondary"
                              href="/member/index.php?page=profile&member_id=<?= e((string) $assoc['id']) ?>">Edit</a>
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
                        <?= e(MembershipService::displayMembershipNumber((int) $fullMember['member_number_base'], (int) $fullMember['member_number_suffix'])) ?>
                      </p>
                      <a class="mt-3 inline-flex items-center text-xs font-semibold text-secondary"
                        href="/member/index.php?page=profile&member_id=<?= e((string) $fullMember['id']) ?>">Edit</a>
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
                    <?php
                      $hasPendingChapterRequest = count(array_filter($chapterRequests, fn($r) => strtoupper($r['status']) === 'PENDING')) > 0;
                    ?>
                    <form method="post" class="space-y-3">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="request_chapter">
                      <select name="requested_chapter_id"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" <?php if ($hasPendingChapterRequest) echo 'disabled'; ?>>
                        <option value="">Select chapter</option>
                        <?php foreach (ChapterRepository::listForSelection($pdo, true) as $chapter): ?>
                          <?php
                          $chapterLabel = $chapter['display_label'] ?? $chapter['name'];
                          if (!empty($chapter['state'])) {
                            $chapterLabel .= ' (' . $chapter['state'] . ')';
                          }
                          ?>
                          <option value="<?= e((string) $chapter['id']) ?>"><?= e($chapterLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if ($hasPendingChapterRequest): ?>
                        <div class="mt-2 text-xs text-amber-600 font-semibold bg-amber-50 rounded-lg p-2.5 border border-amber-100 flex items-center gap-2">
                          <span class="material-icons-outlined text-[16px]">info</span>
                          You already have a pending chapter change request.
                        </div>
                      <?php else: ?>
                        <button
                          class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
                          type="submit">Submit request</button>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                  <?php if ($chapterRequests): ?>
                    <div class="mt-4 space-y-3 text-sm text-gray-600">
                      <?php foreach ($chapterRequests as $request): ?>
                        <?php 
                        $statusClass = match(strtoupper($request['status'])) {
                          'PENDING' => 'bg-amber-100 text-amber-700 border-amber-200',
                          'APPROVED' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                          'REJECTED' => 'bg-red-100 text-red-700 border-red-200',
                          default => 'bg-gray-100 text-gray-700 border-gray-200'
                        };
                        ?>
                        <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                          <div class="flex items-center justify-between mb-2">
                            <p class="font-bold text-gray-900"><?= e($request['name']) ?></p>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border <?= $statusClass ?>">
                              <?= e($request['status']) ?>
                            </span>
                          </div>
                          <p class="text-xs text-gray-500 flex items-center gap-1.5">
                            <span class="material-icons-outlined text-[14px]">calendar_today</span>
                            Requested: <?= e(format_datetime($request['requested_at'])) ?>
                          </p>
                          <?php if (!empty($request['rejection_reason'])): ?>
                            <div class="mt-3 bg-red-50 text-red-600 text-xs p-2.5 rounded-lg border border-red-100">
                              <span class="font-semibold">Reason for rejection:</span> <?= e($request['rejection_reason']) ?>
                            </div>
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
                                    class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-0.5 font-semibold text-yellow-700">Rego
                                    / Reg#:
                                    <?= e($bike['rego']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($bikeColor)): ?>
                                  <span
                                    class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-600">Colour:
                                    <?= e($bikeColor) ?></span>
                                <?php endif; ?>
                              </div>
                            </div>
                            <?php if ($canManageProfileBikes): ?>
                              <form method="post" action="<?= e($profileActionUrl) ?>" class="ml-auto">
                                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="delete_bike">
                                <input type="hidden" name="bike_id" value="<?= e((string) $bike['id']) ?>">
                                <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                                <button
                                  class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50"
                                  type="submit" onclick="return confirm('Remove this bike?');">Remove</button>
                              </form>
                            <?php endif; ?>
                          </div>
                          <?php if ($canManageProfileBikes): ?>
                            <?php $bikeId = (int) $bike['id']; ?>
                            <form method="post" action="<?= e($profileActionUrl) ?>"
                              class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                              <input type="hidden" name="action" value="update_bike">
                              <input type="hidden" name="bike_id" value="<?= e((string) $bikeId) ?>">
                              <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
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
                              <input type="text" name="bike_rego" value="<?= e($bike['rego'] ?? '') ?>"
                                placeholder="Rego / Registration Number"
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
                              <?php if (!empty($bikeHasPrimary)): ?>
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
                  <?php if ($canManageProfileBikes): ?>
                    <form method="post" action="<?= e($profileActionUrl) ?>" class="mt-4 space-y-2">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="add_bike">
                      <input type="hidden" name="profile_member_id" value="<?= e((string) $profileMemberId) ?>">
                      <input type="text" name="bike_make" placeholder="Make" required
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_model" placeholder="Model" required
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="number" name="bike_year" placeholder="Year"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_color" placeholder="Colour"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                      <input type="text" name="bike_rego" placeholder="Rego / Registration Number"
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
            <div
              class="h-10 w-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center overflow-hidden">
              <?php if (!empty($avatarUrl)): ?>
                <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['name'] ?? 'Member') ?>"
                  class="h-full w-full object-cover">
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
                    <input name="user_timezone"
                      class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20"
                      value="<?= e($userTimezone) ?>" placeholder="Australia/Sydney">
                  </label>
                  <div>
                    <p class="text-sm font-medium text-gray-700">Profile image</p>
                    <div class="mt-2 flex items-center gap-3">
                      <div id="avatar-preview"
                        class="h-14 w-14 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($avatarUrl)): ?>
                          <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['name'] ?? 'Member') ?>"
                            class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-base">person</span>
                        <?php endif; ?>
                      </div>
                      <input type="hidden" name="avatar_url" id="avatar-url-input" value="<?= e($avatarUrl) ?>">
                      <button type="button"
                        class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700"
                        data-upload-trigger data-upload-target="avatar-url-input" data-upload-preview="avatar-preview"
                        data-upload-context="avatars">Upload image</button>
                    </div>
                  </div>
                </div>
                <?php if (!empty($associates) || ($member && $member['member_type'] === 'ASSOCIATE')): ?>
                <div>
                  <p class="text-sm font-medium text-gray-700">Associate member photo</p>
                  <p class="text-xs text-gray-500 mt-0.5 mb-2">Photo for the linked associate member on this household account.</p>
                  <div class="flex items-center gap-3">
                    <div id="assoc-avatar-preview"
                      class="h-14 w-14 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center overflow-hidden">
                      <?php if (!empty($associateAvatarUrl)): ?>
                        <img src="<?= e($associateAvatarUrl) ?>" alt="Associate"
                          class="h-full w-full object-cover">
                      <?php else: ?>
                        <span class="material-icons-outlined text-base">person</span>
                      <?php endif; ?>
                    </div>
                    <input type="hidden" name="associate_avatar_url" id="assoc-avatar-url-input" value="<?= e($associateAvatarUrl) ?>">
                    <button type="button"
                      class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700"
                      data-upload-trigger data-upload-target="assoc-avatar-url-input" data-upload-preview="assoc-avatar-preview"
                      data-upload-context="avatars">Upload image</button>
                  </div>
                </div>
                <?php endif; ?>
                <div>
                  <p class="text-sm font-medium text-gray-700 mb-2">Account Notifications</p>
                  <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 space-y-3">
                    <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
                      <span class="font-medium">Email notifications</span>
                      <input type="checkbox" name="notify_master_enabled" <?= $masterNotificationsEnabled ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                    </label>
                    <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
                      <span class="font-medium">Unsubscribe from all non-essential emails</span>
                      <input type="checkbox" name="notify_unsubscribe_all" <?= $unsubscribeAll ? 'checked' : '' ?>
                        class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                    </label>
                    <p class="text-xs text-gray-500">Mandatory security and billing emails still send when required.</p>
                  </div>
                  <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($notificationCategories as $key => $label): ?>
                      <label
                        class="flex items-center gap-2 rounded-xl border border-gray-100 bg-white px-3 py-2 text-sm text-gray-700">
                        <input type="checkbox" name="notify_category[<?= e($key) ?>]"
                          <?= !empty($notificationPrefs['categories'][$key]) ? 'checked' : '' ?>
                          class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                        <?= e($label) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php
                  // Only surface the committee privacy toggle for members who
                  // actually hold a committee/chapter rep role — there's no
                  // point showing it for everyone else.
                  $settingsCmtRoles = $member ? CommitteeService::rolesForMember((int) $member['id']) : [];
                ?>
                <?php if ($settingsCmtRoles): ?>
                <div>
                  <p class="text-sm font-medium text-gray-700 mb-2">Committee &amp; Leadership Privacy</p>
                  <div class="rounded-xl border border-gray-100 bg-amber-50 p-4">
                    <label class="flex items-start gap-3 text-sm text-gray-700 cursor-pointer">
                      <input type="checkbox" name="committee_private" value="1"
                        <?= !empty($member['committee_private']) ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-gray-300 text-primary focus:ring-2 focus:ring-primary">
                      <span class="flex-1">
                        <span class="block font-semibold text-gray-900">Hide my last name &amp; phone on public listings</span>
                        <span class="block text-xs text-gray-600 mt-1">
                          You hold <?= count($settingsCmtRoles) ?> committee/chapter rep role<?= count($settingsCmtRoles) === 1 ? '' : 's' ?>.
                          When ticked, the public Committee &amp; Chapter Rep cards (and the member-area Committee page) show only your first name and the role-based email. Your role title, chapter, and the persistent role email (e.g. <code>aga.president@…</code>) are still shown so people can still contact you.
                        </span>
                      </span>
                    </label>
                  </div>
                </div>
                <?php endif; ?>
                <div class="flex items-center justify-between">
                  <a class="text-sm text-slate-500" href="/member/index.php?page=settings">Cancel</a>
                  <button
                    class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold"
                    type="submit">Save settings</button>
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
              <a class="mt-4 inline-flex items-center text-sm font-semibold text-secondary"
                href="/member/index.php?page=profile">Edit profile</a>
            </div>
            <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-3">
                <div class="p-2 bg-emerald-100 rounded-lg text-emerald-600">
                  <span class="material-icons-outlined">shield</span>
                </div>
                <h3 class="font-display text-lg font-bold text-gray-900">Password & Security</h3>
              </div>
              <p class="text-sm text-gray-600">Reset your password or review account security.</p>
              <a class="mt-4 inline-flex items-center text-sm font-semibold text-secondary"
                href="/member/reset_password.php">Reset password</a>
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
            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">No activity recorded yet.
            </div>
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
                      <img alt="<?= e($latestIssue['title']) ?>" class="w-full h-full object-cover"
                        src="<?= e($latestIssue['cover_image_url']) ?>">
                    <?php else: ?>
                      <div class="w-full h-full flex items-center justify-center bg-gray-50">
                        <span class="material-icons-outlined text-6xl text-gray-300">import_contacts</span>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="space-y-4">
                  <div class="flex flex-wrap items-center gap-3 text-sm text-gray-500">
                    <span
                      class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-primary/10 text-gray-900">Latest
                      Issue</span>
                    <?php if (!empty($latestIssue['published_at'])): ?>
                      <span>Released <?= e(format_date_au($latestIssue['published_at'])) ?></span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h2 class="font-display text-3xl md:text-4xl font-bold text-gray-900"><?= e($latestIssue['title']) ?>
                    </h2>
                    <p class="text-base text-gray-600 mt-3">Catch up on the newest issue and explore past editions in the
                      archive.</p>
                  </div>
                  <div class="flex flex-wrap gap-3">
                    <a class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold"
                      href="/member/read_wings.php?id=<?= $latestIssue['id'] ?>">
                      <span class="material-icons-outlined text-base">menu_book</span>
                      <span class="ml-2">Read Online</span>
                    </a>
                    <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
                      href="/member/download_wings.php?id=<?= $latestIssue['id'] ?>" download>
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
                    <span
                      class="material-icons-outlined text-base text-gray-400 absolute left-3 top-1/2 -translate-y-1/2">search</span>
                    <input id="wings-search" class="pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg bg-white"
                      placeholder="Search issues..." type="search">
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
                  <article
                    class="wings-issue-card group bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow"
                    data-title="<?= e($issueTitle) ?>" data-year="<?= e($publishedYear) ?>">
                    <div class="aspect-[3/4] bg-gray-50 overflow-hidden">
                      <?php if (!empty($issue['cover_image_url'])): ?>
                        <img alt="<?= e($issue['title']) ?>"
                          class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                          src="<?= e($issue['cover_image_url']) ?>">
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
                      <a class="inline-flex items-center text-sm font-semibold text-secondary"
                        href="/member/read_wings.php?id=<?= $issue['id'] ?>">
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
      <?php elseif ($page === 'calendar'): ?>
        <section class="space-y-6">
          <div>
            <h2 class="font-display text-2xl font-bold text-gray-900">Ride Calendar</h2>
            <p class="text-sm text-gray-500">Upcoming rides and events from across the network.</p>
          </div>
          <div class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <iframe
              src="/calendar/events_public.php"
              title="Ride Calendar"
              loading="lazy"
              class="w-full block bg-transparent"
              style="border: 0; min-height: 1200px;"
              id="member-calendar-frame"></iframe>
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
                <input type="hidden" name="notice_attachment_url" id="notice-attachment-url">
                <input type="hidden" name="notice_attachment_type" id="notice-attachment-type">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <label class="text-sm font-medium text-gray-700">Title
                    <input type="text" name="notice_title"
                      class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
                  </label>
                  <label class="text-sm font-medium text-gray-700">Category
                    <select name="notice_category"
                      class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <option value="notice">Notice</option>
                      <option value="advert">Advert</option>
                      <option value="announcement">Important Announcement</option>
                    </select>
                  </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <label class="text-sm font-medium text-gray-700">Audience
                    <select name="notice_audience_scope" id="notice-audience-scope"
                      class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <option value="all">All members</option>
                      <option value="state">State</option>
                      <option value="chapter">Chapter</option>
                    </select>
                  </label>
                  <label class="text-sm font-medium text-gray-700">State
                    <select name="notice_audience_state" id="notice-audience-state"
                      class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" disabled>
                      <option value="">Select state</option>
                      <?php foreach ($noticeStates as $state): ?>
                        <option value="<?= e($state['code']) ?>" <?= ($noticeFormState ?? '') === $state['code'] ? 'selected' : '' ?>><?= e($state['label']) ?> (<?= e($state['code']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="text-sm font-medium text-gray-700">Chapter
                    <select name="notice_audience_chapter" id="notice-audience-chapter"
                      class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" disabled>
                      <option value="">Select chapter</option>
                      <?php foreach ($noticeChapters as $chapter): ?>
                        <option value="<?= e((string) $chapter['id']) ?>">
                          <?= e($chapter['display_label'] ?? $chapter['name']) ?>
                          <?= !empty($chapter['state']) ? ' (' . e($chapter['state']) . ')' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <div>
                  <p class="text-sm font-medium text-gray-700 mb-2">Description</p>
                  <textarea name="notice_content" id="notice-content-input" data-wysiwyg rows="6"
                    placeholder="Write the notice details…" required></textarea>
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
                <button
                  class="inline-flex items-center px-5 py-2.5 rounded-lg bg-primary text-gray-900 text-sm font-semibold"
                  type="submit">Submit for approval</button>
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
        <section class="space-y-6" data-tour="read-notices-section">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div data-tour="read-notices-heading">
              <h2 class="font-display text-2xl font-bold text-gray-900">Notice Board</h2>
              <p class="text-sm text-gray-500">Browse the latest approved notices.</p>
            </div>
            <div data-tour="read-notices-view-toggle" class="inline-flex items-center rounded-lg border border-gray-200 bg-white p-1 text-sm">
              <button type="button" data-notice-view="list"
                class="px-3 py-1.5 rounded-md font-semibold text-gray-700">List view</button>
              <button type="button" data-notice-view="grid"
                class="px-3 py-1.5 rounded-md font-semibold text-gray-700">Grid view</button>
            </div>
          </div>
          <div data-tour="read-notices-board" class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
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
                  <article id="notice-<?= e((string) $notice['id']) ?>" class="border border-gray-100 rounded-2xl p-5 bg-white scroll-mt-24">
                    <div class="flex items-center gap-3 mb-3">
                      <div
                        class="h-10 w-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($avatar)): ?>
                          <img src="<?= e($avatar) ?>" alt="<?= e($notice['created_by_name'] ?? 'Member') ?>"
                            class="h-full w-full object-cover">
                        <?php else: ?>
                          <span class="material-icons-outlined text-sm">person</span>
                        <?php endif; ?>
                      </div>
                      <div class="flex-1">
                        <p class="text-lg font-semibold text-gray-900"><?= e($notice['title']) ?></p>
                        <p class="text-xs text-gray-500"><?= e($categoryLabel) ?> •
                          <?= e($notice['created_by_name'] ?? 'Member') ?>
                        </p>
                      </div>
                      <span
                        class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?= e(format_date_au($notice['published_at'] ?? $notice['created_at'])) ?></span>
                    </div>
                    <?php if (!empty($notice['attachment_url'])): ?>
                      <div class="mb-3 rounded-xl border border-gray-100 overflow-hidden">
                        <?php if (($notice['attachment_type'] ?? '') === 'pdf'): ?>
                          <object data="<?= e($notice['attachment_url']) ?>#page=1&zoom=page-fit" type="application/pdf"
                            class="w-full h-72">
                            <div class="p-4 text-sm text-gray-500">PDF attached. <a class="text-secondary font-semibold"
                                href="<?= e($notice['attachment_url']) ?>">Open</a></div>
                          </object>
                        <?php else: ?>
                          <img src="<?= e($notice['attachment_url']) ?>" alt="<?= e($notice['title']) ?>"
                            class="w-full h-72 object-cover">
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
                  <article id="notice-grid-<?= e((string) $notice['id']) ?>" class="bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm scroll-mt-24">
                    <div class="relative aspect-[210/297] bg-gray-50">
                      <?php if (!empty($notice['attachment_url'])): ?>
                        <?php if (($notice['attachment_type'] ?? '') === 'pdf'): ?>
                          <object data="<?= e($notice['attachment_url']) ?>#page=1&zoom=page-fit" type="application/pdf"
                            class="w-full h-full">
                            <div class="absolute inset-0 flex items-center justify-center text-gray-400 text-sm">PDF</div>
                          </object>
                        <?php else: ?>
                          <img src="<?= e($notice['attachment_url']) ?>" alt="<?= e($notice['title']) ?>"
                            class="w-full h-full object-cover">
                        <?php endif; ?>
                      <?php else: ?>
                        <div class="h-full w-full flex items-center justify-center text-gray-300">
                          <span class="material-icons-outlined text-5xl">campaign</span>
                        </div>
                      <?php endif; ?>
                      <span
                        class="absolute top-3 left-3 inline-flex items-center rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-gray-700"><?= e($categoryLabel) ?></span>
                    </div>
                    <div class="p-4 space-y-2">
                      <h4 class="text-base font-semibold text-gray-900"><?= e($notice['title']) ?></h4>
                      <p class="text-xs text-gray-500">
                        <?= e(format_date_au($notice['published_at'] ?? $notice['created_at'])) ?>
                      </p>
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
        <?php
        $fallenByYear = [];
        foreach (($fallenWings ?? []) as $entry) {
            $yr = (int) ($entry['year_of_passing'] ?? 0);
            $fallenByYear[$yr][] = $entry;
        }
        krsort($fallenByYear);
        $totalHonored = is_array($fallenWings ?? null) ? count($fallenWings) : 0;
        $hasOpenError = !empty($fallenError);
        ?>
        <section class="space-y-6">
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

          <div class="bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 pb-5 border-b border-gray-200">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Registry Archive</p>
                <h2 class="font-display text-3xl font-bold text-gray-900 mt-1">Memorial Roll</h2>
              </div>
              <span class="inline-flex items-center self-start px-3 py-1.5 rounded-full bg-amber-100 text-amber-800 text-xs font-semibold">
                Total Honored: <?= e((string) $totalHonored) ?>
              </span>
            </div>

            <div class="mt-6 rounded-xl bg-slate-50 border border-slate-100 p-3 flex flex-col md:flex-row gap-3">
              <div class="relative flex-1">
                <span class="material-icons-outlined text-base text-gray-400 absolute left-3 top-1/2 -translate-y-1/2">search</span>
                <input id="fallen-search"
                  class="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-200 rounded-lg bg-white"
                  placeholder="Search by name..." type="search">
              </div>
              <select id="fallen-year" class="py-2.5 pl-3 pr-8 text-sm border border-gray-200 rounded-lg bg-white md:w-56">
                <option value="all">Filter by year</option>
                <?php foreach ($fallenYears as $yearOption): ?>
                  <option value="<?= e((string) $yearOption) ?>"><?= e((string) $yearOption) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" data-fallen-open="member"
                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800">
                <span class="material-icons-outlined text-base">add</span>
                Submit a Memorial
              </button>
            </div>

            <?php if ($fallenByYear): ?>
              <div class="mt-6 space-y-8" id="fallen-year-groups">
                <?php foreach ($fallenByYear as $yearGroup => $entries): ?>
                  <div class="fallen-year-block" data-year-group="<?= e((string) $yearGroup) ?>">
                    <div class="flex items-center gap-3 mb-2">
                      <span class="inline-block w-1 h-5 bg-amber-400 rounded-sm"></span>
                      <h3 class="font-display text-lg font-bold text-gray-900">Final Rides in <?= e((string) $yearGroup) ?></h3>
                    </div>
                    <ul class="divide-y divide-gray-100">
                      <?php foreach ($entries as $entry): ?>
                        <?php
                        $parts = explode(' ', trim($entry['full_name'] ?? ''));
                        $displayName = $entry['full_name'] ?? '';
                        ?>
                        <li class="py-4 fallen-wing-card"
                            data-name="<?= e(strtolower($displayName)) ?>"
                            data-year="<?= e((string) ($entry['year_of_passing'] ?? '')) ?>">
                          <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-4 min-w-0">
                              <?php if (!empty($entry['image_url'])): ?>
                                <a href="/fallen-wings.php?id=<?= e((string) $entry['id']) ?>" class="flex-shrink-0">
                                  <img src="<?= e($entry['image_url']) ?>" alt="Tribute image for <?= e($displayName) ?>"
                                    class="w-11 h-11 object-cover rounded-full bg-slate-100">
                                </a>
                              <?php else: ?>
                                <div class="w-11 h-11 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center flex-shrink-0">
                                  <span class="material-icons-outlined text-lg">person</span>
                                </div>
                              <?php endif; ?>
                              <div class="min-w-0">
                                <a href="/fallen-wings.php?id=<?= e((string) $entry['id']) ?>"
                                  class="text-base font-semibold text-gray-900 hover:text-primary hover:underline block truncate"><?= e($displayName) ?></a>
                                <?php if (!empty($entry['member_number'])): ?>
                                  <p class="text-xs text-gray-500">Member ID: <?= e($entry['member_number']) ?></p>
                                <?php endif; ?>
                              </div>
                            </div>
                            <div class="flex items-center gap-4 text-right flex-shrink-0">
                              <span class="text-sm text-gray-500 whitespace-nowrap"><?= e((string) ($entry['year_of_passing'] ?? '')) ?></span>
                              <?php if (!empty($entry['tribute']) || !empty($entry['pdf_url']) || !empty($entry['image_url'])): ?>
                                <a href="/fallen-wings.php?id=<?= e((string) $entry['id']) ?>"
                                  class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md border border-gray-200 text-[11px] font-semibold uppercase tracking-wide text-gray-700 hover:bg-gray-50">
                                  Read Tribute
                                  <span class="material-icons-outlined text-[14px]">north_east</span>
                                </a>
                              <?php else: ?>
                                <span class="text-xs italic text-gray-400">No Tribute Provided</span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endforeach; ?>
              </div>
              <p id="fallen-empty" class="hidden text-sm text-gray-500 mt-6">No memorial entries match those filters.</p>
              <script>
                (() => {
                  const searchInput = document.getElementById('fallen-search');
                  const yearSelect = document.getElementById('fallen-year');
                  const cards = document.querySelectorAll('.fallen-wing-card');
                  const yearBlocks = document.querySelectorAll('.fallen-year-block');
                  const emptyState = document.getElementById('fallen-empty');
                  if (!searchInput || !yearSelect || !cards.length) return;

                  const applyFilters = () => {
                    const term = searchInput.value.trim().toLowerCase();
                    const yearValue = yearSelect.value;
                    let visibleCount = 0;
                    cards.forEach((card) => {
                      const name = card.dataset.name || '';
                      const cardYear = card.dataset.year || '';
                      const matchesTerm = term === '' || name.includes(term);
                      const matchesYear = yearValue === 'all' || cardYear === yearValue;
                      const isVisible = matchesTerm && matchesYear;
                      card.classList.toggle('hidden', !isVisible);
                      if (isVisible) visibleCount += 1;
                    });
                    yearBlocks.forEach((block) => {
                      const anyVisible = block.querySelectorAll('.fallen-wing-card:not(.hidden)').length > 0;
                      block.classList.toggle('hidden', !anyVisible);
                    });
                    if (emptyState) emptyState.classList.toggle('hidden', visibleCount !== 0);
                  };
                  searchInput.addEventListener('input', applyFilters);
                  yearSelect.addEventListener('change', applyFilters);
                })();
              </script>
            <?php else: ?>
              <p class="text-sm text-gray-500 mt-6">No memorial entries found.</p>
            <?php endif; ?>
          </div>
        </section>

        <div id="fallen-submit-modal"
          class="fixed inset-0 z-50 <?= $hasOpenError ? 'flex' : 'hidden' ?> items-center justify-center bg-black/50 p-4"
          data-fallen-modal>
          <div class="w-full max-w-3xl rounded-2xl bg-white shadow-xl border border-gray-200 max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between px-6 pt-6 pb-4 border-b border-gray-100">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Contribution Portal</p>
                <h3 class="font-display text-2xl font-bold text-gray-900 mt-1">Submit a Memorial</h3>
                <p class="text-sm text-gray-500 mt-1">Honoring our fallen wings with dignity. Please provide accurate details for the registry.</p>
              </div>
              <button type="button" class="text-gray-400 hover:text-gray-600 -mt-1" data-fallen-close>
                <span class="material-icons-outlined">close</span>
              </button>
            </div>
            <form method="post" enctype="multipart/form-data" class="px-6 py-5 space-y-4" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="submit_fallen_wings">
              <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-700 mb-1.5">Member Full Name</label>
                <input type="text" name="fallen_name" placeholder="First and Last Name"
                  value="<?= e(empty($fallenMessage) ? ($_POST['fallen_name'] ?? '') : '') ?>"
                  autocomplete="off"
                  class="w-full rounded-lg border border-gray-200 bg-slate-50 px-3 py-2.5 text-sm" required>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-700 mb-1.5">Date of Passing</label>
                  <input type="date" name="fallen_date"
                    value="<?= e(empty($fallenMessage) ? ($_POST['fallen_date'] ?? '') : '') ?>"
                    autocomplete="off"
                    class="w-full rounded-lg border border-gray-200 bg-slate-50 px-3 py-2.5 text-sm"
                    min="1900-01-01" max="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div>
                  <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-700 mb-1.5">
                    Member Number <span class="text-gray-400 italic normal-case font-normal lowercase">(optional)</span>
                  </label>
                  <input type="text" name="fallen_member_number" maxlength="120" placeholder="e.g. GW-000000"
                    value="<?= e(empty($fallenMessage) ? ($_POST['fallen_member_number'] ?? '') : '') ?>"
                    autocomplete="off" data-1p-ignore data-lpignore="true"
                    class="w-full rounded-lg border border-gray-200 bg-slate-50 px-3 py-2.5 text-sm" pattern="[A-Za-z0-9.\\-]+">
                </div>
              </div>
              <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-700 mb-1.5">Tribute or Note</label>
                <textarea name="fallen_tribute" rows="4" placeholder="Share a brief memory or tribute..."
                  autocomplete="off"
                  class="w-full rounded-lg border border-gray-200 bg-slate-50 px-3 py-2.5 text-sm"><?= e(empty($fallenMessage) ? ($_POST['fallen_tribute'] ?? '') : '') ?></textarea>
              </div>
              <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-700 mb-1.5">Memorial Photo</label>
                <label class="block rounded-xl border-2 border-dashed border-gray-200 bg-slate-50/50 px-4 py-8 text-center cursor-pointer hover:border-gray-300">
                  <span class="material-icons-outlined text-3xl text-gray-400">cloud_upload</span>
                  <p class="text-sm font-semibold text-gray-700 mt-1" data-fallen-file-label>Click to upload or drag and drop</p>
                  <p class="text-xs text-gray-400 mt-1">Maximum file size 5MB (JPG, PNG)</p>
                  <input type="file" name="tribute_image" accept=".jpg,.jpeg,.png,.webp" class="hidden" data-fallen-file-input>
                </label>
              </div>
              <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-700 mb-1.5">Tribute Document <span class="text-gray-400 italic normal-case font-normal lowercase">(optional PDF)</span></label>
                <input type="file" name="tribute_pdf" accept=".pdf"
                  class="w-full rounded-lg border border-gray-200 bg-slate-50 px-3 py-2 text-sm">
              </div>
              <div class="pt-2">
                <button type="submit"
                  class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800">
                  <span class="material-icons-outlined text-base">file_upload</span>
                  Submit to Registry
                </button>
              </div>
            </form>
          </div>
        </div>
        <script>
          (() => {
            const modal = document.getElementById('fallen-submit-modal');
            if (!modal) return;
            const open = () => { modal.classList.remove('hidden'); modal.classList.add('flex'); };
            const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
            document.querySelectorAll('[data-fallen-open="member"]').forEach((btn) => btn.addEventListener('click', open));
            modal.querySelectorAll('[data-fallen-close]').forEach((btn) => btn.addEventListener('click', close));
            modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
            const fileInput = modal.querySelector('[data-fallen-file-input]');
            const fileLabel = modal.querySelector('[data-fallen-file-label]');
            if (fileInput && fileLabel) {
              fileInput.addEventListener('change', () => {
                fileLabel.textContent = fileInput.files && fileInput.files[0]
                  ? fileInput.files[0].name
                  : 'Click to upload or drag and drop';
              });
            }
          })();
        </script>
      <?php elseif ($page === 'dealers'): ?>
        <?php
        $stmt = $pdo->query("SELECT * FROM honda_dealers ORDER BY state ASC, name ASC");
        $allDealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dealersByState = [];
        foreach ($allDealers as $d) {
            $dealersByState[$d['state']][] = $d;
        }
        ?>
        <section class="space-y-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Honda Dealers</h2>
              <p class="text-sm text-gray-500">Find authorised Honda dealers across Australia.</p>
            </div>
          </div>
          <div class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
            <div class="space-y-8">
              <?php foreach ($dealersByState as $state => $dealers): ?>
                <div>
                  <h3 class="font-display text-xl font-bold text-gray-900 mb-4 border-b border-gray-100 pb-2"><?= e($state) ?> Dealers</h3>
                  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <?php foreach ($dealers as $dealer): ?>
                      <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 transition-shadow hover:shadow-md">
                        <div class="flex items-start justify-between gap-2">
                          <h4 class="font-bold text-gray-900 text-lg leading-tight mb-2"><?= e($dealer['name']) ?></h4>
                        </div>
                        <div class="space-y-2 mt-3">
                          <?php if (!empty($dealer['address'])): ?>
                            <p class="text-sm text-gray-600 flex items-start gap-2">
                              <span class="material-icons-outlined text-[16px] text-gray-400 mt-0.5">place</span>
                              <span><?= e($dealer['address']) ?></span>
                            </p>
                          <?php endif; ?>
                          <?php if (!empty($dealer['phone'])): ?>
                            <p class="text-sm text-gray-600 flex items-center gap-2">
                              <span class="material-icons-outlined text-[16px] text-gray-400">phone</span>
                              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $dealer['phone'])) ?>" class="hover:text-primary transition-colors"><?= e($dealer['phone']) ?></a>
                            </p>
                          <?php endif; ?>
                          <?php if (!empty($dealer['website'])): ?>
                            <p class="text-sm text-gray-600 flex items-center gap-2">
                              <span class="material-icons-outlined text-[16px] text-primary">language</span>
                              <a href="<?= e($dealer['website']) ?>" target="_blank" rel="noopener" class="text-primary font-medium hover:text-primary-dark transition-colors truncate">Visit Website</a>
                            </p>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (empty($dealersByState)): ?>
                <div class="text-center py-8">
                  <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 text-gray-400 mb-3">
                    <span class="material-icons-outlined">two_wheeler</span>
                  </div>
                  <p class="text-sm text-gray-500">No dealers have been added yet.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php elseif ($page === 'store'): ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="font-display text-2xl font-bold text-gray-900 mb-2">Store</h2>
          <p class="text-sm text-gray-500">Browse the members-only store.</p>
          <a class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold mt-4"
            href="/store">Go to Store</a>
        </section>
      <?php elseif ($page === 'billing'): ?>
        <section class="space-y-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Billing & Payments</h2>
              <p class="text-sm text-gray-500">Manage payment methods, shipping details, and recent orders.</p>
            </div>
            <?php if ($customerPortalEnabled): ?>
              <button id="billing-portal"
                class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold">Manage
                payment details</button>
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
            // Translate stored term (1Y/2Y/3Y) into the months key the renewal
            // pricing helper understands. Anything unrecognised falls back to 1Y.
            $termMonthsForPrice = match ($termKey) {
              '3Y' => '36',
              '2Y' => '24',
              default => '12',
            };
            $priceCents = membership_renewal_amount_cents($magazineType, $memberTypeKey, $termMonthsForPrice);
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
            <div data-tour="pay-fees-status-card" class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
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
                  <span
                    class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= status_badge_classes($billingStatusLabel) ?>"><?= e($billingStatusLabel) ?></span>
                </div>
                <div data-tour="pay-fees-expiry">
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-400 mb-1">Expiry</p>
                  <p class="text-sm font-semibold text-gray-900"><?= e($billingExpiryLabel) ?></p>
                </div>
              </div>
              <?php if ($pendingMembershipOrder): ?>
                <?php
                $pendingPaymentMethod = strtolower((string) ($pendingMembershipOrder['payment_method'] ?? 'stripe'));
                $pendingStatus = strtolower((string) ($pendingMembershipOrder['payment_status'] ?? 'pending'));
                ?>
                <div
                  class="rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 space-y-2">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      Pending order <span
                        class="font-semibold"><?= e($pendingMembershipOrder['order_number'] ?? '') ?></span>
                    </div>
                    <span
                      class="inline-flex rounded-full px-2 py-1 text-xs font-semibold <?= status_badge_classes($pendingStatus) ?>"><?= ucfirst($pendingStatus) ?></span>
                  </div>
                  <?php if ($pendingPaymentMethod === 'stripe'): ?>
                    <form method="post" class="mt-2" data-tour="pay-fees-pay-now">
                      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                      <input type="hidden" name="action" value="membership_order_pay">
                      <input type="hidden" name="order_id" value="<?= e((string) $pendingMembershipOrder['id']) ?>">
                      <button
                        class="inline-flex items-center rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900"
                        type="submit">Pay now</button>
                    </form>
                  <?php elseif ($pendingPaymentMethod === 'bank_transfer'): ?>
                    <?php if ($bankInstructions !== ''): ?>
                      <div class="rounded-lg bg-white/80 px-3 py-2 text-xs text-gray-700 whitespace-pre-line">
                        <?= e($bankInstructions) ?>
                      </div>
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
                <div data-tour="pay-fees-renew" class="flex flex-col items-start gap-2">
                  <button type="button" data-renew-trigger
                    class="inline-flex items-center gap-2 rounded-xl bg-red-600 hover:bg-red-700 px-5 py-3 text-sm font-bold text-white shadow-md hover:shadow-lg ring-2 ring-red-600/40 transition-all">
                    <span class="material-icons-outlined text-base">payments</span>
                    Renew my membership
                  </button>
                  <p class="text-xs text-red-700 font-medium">Your membership <?= $membershipPeriod && strtoupper((string) ($membershipPeriod['status'] ?? '')) === 'LAPSED' ? 'has lapsed' : 'is due for renewal' ?>.</p>
                </div>
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
                  <input id="member_shipping_address_line1" data-google-autocomplete="address"
                    data-google-autocomplete-city="#member_shipping_city"
                    data-google-autocomplete-state="#member_shipping_state"
                    data-google-autocomplete-postal="#member_shipping_postal"
                    data-google-autocomplete-country="#member_shipping_country" type="text" name="shipping_address_line1"
                    placeholder="Address line 1"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"
                    value="<?= e($member['address_line1'] ?? '') ?>">
                  <input id="member_shipping_address_line2" type="text" name="shipping_address_line2"
                    placeholder="Address line 2"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"
                    value="<?= e($member['address_line2'] ?? '') ?>">
                  <input id="member_shipping_city" type="text" name="shipping_city" placeholder="City"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"
                    value="<?= e($member['city'] ?? '') ?>">
                  <input id="member_shipping_state" type="text" name="shipping_state" placeholder="State"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"
                    value="<?= e($member['state'] ?? '') ?>">
                  <input id="member_shipping_postal" type="text" name="shipping_postal_code" placeholder="Postal code"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"
                    value="<?= e($member['postal_code'] ?? '') ?>">
                  <input id="member_shipping_country" type="text" name="shipping_country" placeholder="Country"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"
                    value="<?= e($member['country'] ?? '') ?>">
                </div>
                <button
                  class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold"
                  type="submit">Save shipping address</button>
              </form>
            </div>
          </div>
          <div data-saved-cards class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-amber-100 rounded-lg text-amber-700">
                <span class="material-icons-outlined">credit_card</span>
              </div>
              <div class="flex-1">
                <h3 class="font-display text-lg font-bold text-gray-900">Saved cards</h3>
                <p class="text-sm text-gray-500">Securely save a card on file via Stripe for faster checkout. Card details are stored by Stripe — never on our servers.</p>
              </div>
              <button id="saved-cards-add" type="button"
                class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold">
                <span class="material-icons-outlined text-base">add</span>Add card
              </button>
            </div>
            <div id="saved-cards-message" class="hidden rounded-lg px-4 py-2 text-sm"></div>
            <div id="saved-cards-list" class="space-y-2">
              <p class="text-sm text-gray-500" data-saved-cards-loading>Loading saved cards…</p>
            </div>
            <div id="saved-cards-form" class="hidden rounded-xl border border-gray-200 bg-white p-4 space-y-3">
              <div>
                <label class="block text-xs uppercase tracking-[0.2em] text-gray-500 mb-2">Card details</label>
                <div id="saved-cards-element" class="rounded-lg border border-gray-200 px-3 py-3"></div>
                <div id="saved-cards-form-error" class="hidden mt-2 text-sm text-red-600"></div>
              </div>
              <div class="flex items-center justify-end gap-2">
                <button id="saved-cards-cancel" type="button"
                  class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700">Cancel</button>
                <button id="saved-cards-save" type="button"
                  class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold disabled:opacity-60">Save card</button>
              </div>
            </div>
          </div>
          <div data-tour="pay-fees-history" class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
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
                              <div><?= e($item['label']) ?> <span
                                  class="text-xs text-gray-500">x<?= e((string) $item['quantity']) ?></span></div>
                            <?php endforeach; ?>
                            <?php if (!empty($order['is_manual'])): ?>
                              <span
                                class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-amber-700">Manual</span>
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
                              <button
                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-primary text-xs font-semibold text-primary hover:bg-primary/10"
                                type="submit">Pay now</button>
                            </form>
                          <?php elseif ($order['type'] === 'store' && !empty($order['order_id'])): ?>
                            <form method="post" action="/store/cart">
                              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                              <input type="hidden" name="action" value="reorder">
                              <input type="hidden" name="order_id" value="<?= e((string) $order['order_id']) ?>">
                              <button
                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                type="submit">Reorder</button>
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
        <script>
          (function () {
            const csrfToken = <?= json_encode(Csrf::token()) ?>;
            const listEl = document.getElementById('saved-cards-list');
            const messageEl = document.getElementById('saved-cards-message');
            const addButton = document.getElementById('saved-cards-add');
            const formEl = document.getElementById('saved-cards-form');
            const cancelButton = document.getElementById('saved-cards-cancel');
            const saveButton = document.getElementById('saved-cards-save');
            const elementContainer = document.getElementById('saved-cards-element');
            const formErrorEl = document.getElementById('saved-cards-form-error');
            if (!listEl) return;

            let stripe = null;
            let elements = null;
            let cardElement = null;
            let publishableKey = '';

            const showMessage = (text, type) => {
              if (!messageEl) return;
              messageEl.textContent = text || '';
              messageEl.classList.toggle('hidden', !text);
              messageEl.classList.remove('bg-green-50', 'text-green-700', 'bg-red-50', 'text-red-700');
              if (type === 'success') {
                messageEl.classList.add('bg-green-50', 'text-green-700');
              } else if (type === 'error') {
                messageEl.classList.add('bg-red-50', 'text-red-700');
              }
            };

            const showFormError = (text) => {
              if (!formErrorEl) return;
              formErrorEl.textContent = text || '';
              formErrorEl.classList.toggle('hidden', !text);
            };

            const brandLabel = (brand) => {
              if (!brand) return 'Card';
              const map = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express', discover: 'Discover', jcb: 'JCB', diners: 'Diners', unionpay: 'UnionPay' };
              return map[String(brand).toLowerCase()] || (brand.charAt(0).toUpperCase() + brand.slice(1));
            };

            const renderCards = (methods) => {
              listEl.innerHTML = '';
              if (!methods || methods.length === 0) {
                listEl.innerHTML = '<p class="text-sm text-gray-500">No cards saved yet. Add one to enable faster checkout.</p>';
                return;
              }
              methods.forEach((pm) => {
                const row = document.createElement('div');
                row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3';
                const monthLabel = pm.exp_month ? String(pm.exp_month).padStart(2, '0') : '--';
                const yearLabel = pm.exp_year ? String(pm.exp_year).slice(-2) : '--';
                row.innerHTML = `
                  <div class="flex items-center gap-3">
                    <span class="material-icons-outlined text-gray-500">credit_card</span>
                    <div>
                      <div class="text-sm font-semibold text-gray-900">${brandLabel(pm.brand)} •••• ${pm.last4 || '----'}</div>
                      <div class="text-xs text-gray-500">Expires ${monthLabel}/${yearLabel}${pm.is_default ? ' · Default' : ''}</div>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    ${pm.is_default ? '' : `<button type="button" data-default="${pm.id}" class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">Set default</button>`}
                    <button type="button" data-remove="${pm.id}" class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-200 text-xs font-semibold text-red-600 hover:bg-red-50">Remove</button>
                  </div>
                `;
                listEl.appendChild(row);
              });
            };

            const loadCards = async () => {
              try {
                const response = await fetch('/api/billing/payment-methods', { credentials: 'include' });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to load saved cards.');
                renderCards(data.payment_methods || []);
              } catch (err) {
                listEl.innerHTML = `<p class="text-sm text-red-600">${err.message || 'Unable to load saved cards.'}</p>`;
              }
            };

            const loadStripeJs = () => new Promise((resolve, reject) => {
              if (window.Stripe) { resolve(); return; }
              const script = document.createElement('script');
              script.src = 'https://js.stripe.com/v3/';
              script.onload = () => resolve();
              script.onerror = () => reject(new Error('Unable to load Stripe.'));
              document.head.appendChild(script);
            });

            const ensureStripe = async () => {
              if (!publishableKey) {
                const response = await fetch('/api/stripe/config');
                const data = await response.json();
                if (!response.ok || !data.publishableKey) throw new Error(data.error || 'Stripe is not configured.');
                publishableKey = data.publishableKey;
              }
              await loadStripeJs();
              if (!stripe) stripe = window.Stripe(publishableKey);
              if (!elements) elements = stripe.elements();
              if (!cardElement) {
                cardElement = elements.create('card', { hidePostalCode: false });
                cardElement.mount(elementContainer);
                cardElement.on('change', (event) => showFormError(event.error ? event.error.message : ''));
              }
            };

            const openForm = async () => {
              showMessage('', null);
              showFormError('');
              try {
                hadCardsBeforeSave = listEl.querySelector('button[data-remove]') !== null;
                await ensureStripe();
                formEl.classList.remove('hidden');
                addButton.disabled = true;
              } catch (err) {
                showMessage(err.message || 'Unable to open card form.', 'error');
              }
            };

            const closeForm = () => {
              formEl.classList.add('hidden');
              addButton.disabled = false;
              showFormError('');
              if (cardElement) cardElement.clear();
            };

            let hadCardsBeforeSave = false;
            const saveCard = async () => {
              showFormError('');
              saveButton.disabled = true;
              try {
                const intentRes = await fetch('/api/billing/setup-intent', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                  credentials: 'include',
                  body: JSON.stringify({}),
                });
                const intentData = await intentRes.json();
                if (!intentRes.ok || !intentData.client_secret) {
                  throw new Error(intentData.error || 'Unable to start card setup.');
                }
                const result = await stripe.confirmCardSetup(intentData.client_secret, {
                  payment_method: { card: cardElement },
                });
                if (result.error) throw new Error(result.error.message || 'Card could not be saved.');
                const newPmId = result.setupIntent && result.setupIntent.payment_method;
                if (!hadCardsBeforeSave && newPmId) {
                  // First card on the account — promote to default so future
                  // charges (renewals, store) know which card to use.
                  await fetch(`/api/billing/payment-methods/${encodeURIComponent(newPmId)}/default`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    credentials: 'include',
                    body: JSON.stringify({}),
                  }).catch(() => {});
                }
                showMessage('Card saved.', 'success');
                closeForm();
                await loadCards();
              } catch (err) {
                showFormError(err.message || 'Card could not be saved.');
              } finally {
                saveButton.disabled = false;
              }
            };

            const setDefault = async (id) => {
              showMessage('', null);
              try {
                const response = await fetch(`/api/billing/payment-methods/${encodeURIComponent(id)}/default`, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                  credentials: 'include',
                  body: JSON.stringify({}),
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to set default card.');
                showMessage('Default card updated.', 'success');
                await loadCards();
              } catch (err) {
                showMessage(err.message || 'Unable to set default card.', 'error');
              }
            };

            const removeCard = async (id) => {
              if (!window.confirm('Remove this card?')) return;
              showMessage('', null);
              try {
                const response = await fetch(`/api/billing/payment-methods/${encodeURIComponent(id)}`, {
                  method: 'DELETE',
                  headers: { 'X-CSRF-Token': csrfToken },
                  credentials: 'include',
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to remove card.');
                showMessage('Card removed.', 'success');
                await loadCards();
              } catch (err) {
                showMessage(err.message || 'Unable to remove card.', 'error');
              }
            };

            addButton && addButton.addEventListener('click', openForm);
            cancelButton && cancelButton.addEventListener('click', closeForm);
            saveButton && saveButton.addEventListener('click', saveCard);
            listEl.addEventListener('click', (event) => {
              const setBtn = event.target.closest('button[data-default]');
              if (setBtn) { setDefault(setBtn.getAttribute('data-default')); return; }
              const rmBtn = event.target.closest('button[data-remove]');
              if (rmBtn) { removeCard(rmBtn.getAttribute('data-remove')); }
            });

            loadCards();
          })();
        </script>
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
                      <td class="py-2 pr-3 text-gray-600"><?= e(format_date_au($period['start_date'])) ?></td>
                      <td class="py-2 pr-3 text-gray-600"><?= e($period['end_date'] ? format_date_au($period['end_date']) : 'No expiry') ?></td>
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
      <?php elseif ($page === 'directory'): ?>
        <?php
        // Resolve which directory pref columns actually exist on this server.
        $dirPrefs   = \App\Services\MemberRepository::directoryPreferences();
        $dirPrefCols = [];
        foreach ($dirPrefs as $letter => $info) {
            $dirPrefCols[$letter] = $info['column'];
        }
        $colAcceptCalls  = $dirPrefCols['B'] ?? null;
        $colCollectMoto  = $dirPrefCols['A'] ?? null;
        $colBedOrTent    = $dirPrefCols['C'] ?? null;
        $colTools        = $dirPrefCols['D'] ?? null;
        $colExcludeElec   = $dirPrefCols['F'] ?? null;  // "Exclude Electronic Directory" (online)

        // Build optional selects for new role columns (added by Migration 003/005).
        $tryCol = static function (string $col) use ($pdo): bool {
            try {
                $s = $pdo->query("SHOW COLUMNS FROM members LIKE '$col'");
                return $s && $s->fetch() !== false;
            } catch (\Throwable $e) {
                return false;
            }
        };
        $hasAreaRep    = $tryCol('is_area_rep');
        $hasCommittee  = $tryCol('is_committee');
        $hasCommRole   = $tryCol('committee_role');
        $hasMemberNumber = $tryCol('member_number');
        $hasPrivacyLevel = $tryCol('privacy_level');

        $roleSelects = '';
        if ($hasAreaRep)   $roleSelects .= ', m.is_area_rep';
        if ($hasCommittee) $roleSelects .= ', m.is_committee';
        if ($hasCommRole)  $roleSelects .= ', m.committee_role';

        // Build the directory exclusion clause. A member is hidden from the
        // online directory if ANY of these are true:
        //   - privacy_level = 'F'           (dropdown "Exclude from directory")
        //   - directory_pref F = 1          ("Exclude Electronic Directory" — online)
        // directory_pref E ("Exclude Member Directory") only applies to the
        // printed/mailed directory — it must NOT hide a member from the
        // online directory, otherwise legacy members default-excluded from
        // print disappear here too.
        $excludeParts = [];
        if ($hasPrivacyLevel) {
            $excludeParts[] = "(m.privacy_level IS NULL OR m.privacy_level <> 'F')";
        }
        if ($colExcludeElec) {
            $excludeParts[] = "(m.$colExcludeElec = 0 OR m.$colExcludeElec IS NULL)";
        }
        $excludeClause = $excludeParts ? 'AND ' . implode(' AND ', $excludeParts) : '';

        $memberNumberExpr = $hasMemberNumber
            ? 'COALESCE(m.member_number, CONCAT(m.member_number_base, CASE WHEN m.member_number_suffix > 0 THEN CONCAT(".", m.member_number_suffix) ELSE "" END)) AS member_number'
            : 'CONCAT(m.member_number_base, CASE WHEN m.member_number_suffix > 0 THEN CONCAT(".", m.member_number_suffix) ELSE "" END) AS member_number';

        $selectCols = implode(",\n                ", array_filter([
            'm.id', 'm.first_name', 'm.last_name', 'm.member_type',
            'm.phone', 'm.email', 'm.member_number_base', 'm.member_number_suffix', $memberNumberExpr,
            $colAcceptCalls ? "m.$colAcceptCalls" : null,
            $colCollectMoto ? "m.$colCollectMoto" : null,
            $colBedOrTent   ? "m.$colBedOrTent"   : null,
            $colTools       ? "m.$colTools"        : null,
            'm.full_member_id',
            \App\Services\ChapterRepository::displayNameSql($pdo) . ' AS chapter_name', 'c.state AS chapter_state',
            'CONCAT(fm.first_name, \' \', fm.last_name) AS full_member_name',
            'fm.member_number_base AS full_member_number_base',
            'fm.member_number_suffix AS full_member_number_suffix',
            // Prefer members.avatar_url (works for legacy members without a
            // linked user account), fall back to settings_user for old data.
            ($tryCol('avatar_url')
                ? 'COALESCE(NULLIF(m.avatar_url, \'\'), JSON_UNQUOTE(su.value_json)) AS avatar_url'
                : 'JSON_UNQUOTE(su.value_json) AS avatar_url'),
        ]));

        try {
            $dirStmt = $pdo->query("
                SELECT $selectCols $roleSelects
                FROM members m
                LEFT JOIN chapters c ON c.id = m.chapter_id
                LEFT JOIN members fm ON fm.id = m.full_member_id
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN settings_user su ON su.user_id = u.id AND su.key_name = 'avatar_url'
                WHERE (m.status IS NULL OR LOWER(m.status) NOT IN ('cancelled', 'archived', 'inactive'))
                $excludeClause
                ORDER BY COALESCE(fm.last_name, m.last_name) ASC, COALESCE(fm.first_name, m.first_name) ASC, CASE WHEN m.full_member_id IS NULL THEN 0 ELSE 1 END ASC, m.last_name ASC, m.first_name ASC
            ");
            $directoryMembers = $dirStmt ? $dirStmt->fetchAll() : [];
        } catch (\Throwable $e) {
            $directoryMembers = [];
        }
        $dirChapters = [];
        foreach ($directoryMembers as $dm) {
          if (!empty($dm['chapter_name'])) {
            $chLabel = $dm['chapter_name'] . (!empty($dm['chapter_state']) ? ' (' . $dm['chapter_state'] . ')' : '');
            $dirChapters[$chLabel] = true;
          }
        }
        ksort($dirChapters);
        $dirTotal = count($directoryMembers);
        ?>
        <section data-tour="find-member-section" class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
            <div>
              <h2 class="font-display text-2xl font-bold text-gray-900">Members Directory</h2>
              <p class="text-sm text-gray-500 mt-1">
                <span id="dir-count"><?= $dirTotal ?></span> of <?= $dirTotal ?> member<?= $dirTotal !== 1 ? 's' : '' ?>
              </p>
            </div>
          </div>

          <!-- Filters -->
          <div class="flex flex-col sm:flex-row gap-3 mb-5">
            <div class="relative flex-1" data-tour="find-member-search">
              <span class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                <span class="material-icons-outlined text-gray-400 text-lg">search</span>
              </span>
              <input type="text" id="dir-search" placeholder="Search by name or member number…"
                class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
            </div>
            <select data-tour="find-member-chapter" id="dir-chapter" class="rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 min-w-[180px]">
              <option value="">All Chapters</option>
              <?php foreach ($dirChapters as $chLabel => $_): ?>
                <option value="<?= e(strtolower($chLabel)) ?>"><?= e($chLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <select data-tour="find-member-type" id="dir-type" class="rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 min-w-[140px]">
              <option value="">All Types</option>
              <option value="full">Full</option>
              <option value="associate">Associate</option>
              <option value="life">Life</option>
            </select>
          </div>

          <!-- Assistance flags legend -->
          <?php if ($colCollectMoto || $colAcceptCalls || $colBedOrTent || $colTools): ?>
            <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
              <span class="font-medium text-gray-600">Assistance flags:</span>
              <?php if ($colCollectMoto): ?>
                <span><span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs font-bold bg-green-100 text-green-800 mr-1">A</span>Collect motorcycle</span>
              <?php endif; ?>
              <?php if ($colAcceptCalls): ?>
                <span><span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs font-bold bg-green-100 text-green-800 mr-1">B</span>Accept phone calls</span>
              <?php endif; ?>
              <?php if ($colBedOrTent): ?>
                <span><span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs font-bold bg-green-100 text-green-800 mr-1">C</span>Provide bed or tent</span>
              <?php endif; ?>
              <?php if ($colTools): ?>
                <span><span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs font-bold bg-green-100 text-green-800 mr-1">D</span>Provide tools/workshop</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Table -->
          <div class="overflow-x-auto -mx-2" data-tour="find-member-table">
            <table class="w-full text-sm min-w-[700px]" id="dir-table">
              <thead class="text-left text-xs uppercase text-gray-500 border-b border-gray-200">
                <tr>
                  <th class="py-2 px-2 w-10"></th>
                  <th class="py-2 px-2">Name</th>
                  <th class="py-2 px-2 whitespace-nowrap">Member #</th>
                  <th class="py-2 px-2">Type</th>
                  <th class="py-2 px-2">Chapter</th>
                  <th class="py-2 px-2">Phone</th>
                  <th class="py-2 px-2">Email</th>
                  <th class="py-2 px-2">Assistance</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100" id="dir-tbody">
                <?php foreach ($directoryMembers as $dm):
                  $dmNumber = '';
                  if (!empty($dm['member_number_base'])) {
                    $dmNumber = MembershipService::displayMembershipNumber((int) $dm['member_number_base'], (int) ($dm['member_number_suffix'] ?? 0));
                  } elseif (!empty($dm['member_number'])) {
                    $dmNumber = $dm['member_number'];
                  }
                  $dmChapterLabel = '';
                  if (!empty($dm['chapter_name'])) {
                    $dmChapterLabel = $dm['chapter_name'] . (!empty($dm['chapter_state']) ? ' (' . $dm['chapter_state'] . ')' : '');
                  }
                  $dmType = strtolower((string) ($dm['member_type'] ?? ''));
                  $dmFullName = trim($dm['first_name'] . ' ' . $dm['last_name']);
                  $dmSearchStr = strtolower($dmFullName . ' ' . $dmNumber);
                  $dmCanContact = $colAcceptCalls && !empty($dm[$colAcceptCalls]);

                  $dmFullMemberName = '';
                  if ($dmType === 'associate' && !empty($dm['full_member_name'])) {
                    $dmFullMemberName = trim($dm['full_member_name']);
                    if (!empty($dm['full_member_number_base'])) {
                      $fmNum = MembershipService::displayMembershipNumber((int) $dm['full_member_number_base'], (int) ($dm['full_member_number_suffix'] ?? 0));
                      $dmFullMemberName .= ' #' . $fmNum;
                    }
                  }

                  $typeLabels = ['full' => 'Full', 'associate' => 'Associate', 'life' => 'Life Member'];
                  $typeColors = [
                    'full' => 'bg-blue-100 text-blue-800',
                    'associate' => 'bg-purple-100 text-purple-800',
                    'life' => 'bg-yellow-200 text-yellow-800',
                  ];
                  $typeLabel = $typeLabels[$dmType] ?? ucfirst($dmType);
                  $typeColor = $typeColors[$dmType] ?? 'bg-gray-100 text-gray-700';

                  $caps = [];
                  if ($colCollectMoto && !empty($dm[$colCollectMoto])) {
                    $caps[] = 'A';
                  }
                  if ($colAcceptCalls && !empty($dm[$colAcceptCalls])) {
                    $caps[] = 'B';
                  }
                  if ($colBedOrTent && !empty($dm[$colBedOrTent])) {
                    $caps[] = 'C';
                  }
                  if ($colTools && !empty($dm[$colTools])) {
                    $caps[] = 'D';
                  }
                ?>
                  <tr class="<?= $dmType === 'life' ? 'bg-yellow-50/60' : ($dmType === 'associate' ? 'bg-purple-50/30 hover:bg-purple-50/60' : 'hover:bg-gray-50') ?> transition-colors"
                    data-search="<?= e($dmSearchStr) ?>"
                    data-chapter="<?= e(strtolower($dmChapterLabel)) ?>"
                    data-type="<?= e($dmType) ?>">
                    <td class="py-3 px-2 <?= $dmType === 'associate' ? 'pl-6' : '' ?>">
                      <?php if ($dmType === 'associate'): ?>
                        <div class="flex items-center gap-1">
                          <span class="text-purple-300 text-base leading-none">↳</span>
                      <?php endif; ?>
                      <?php if (!empty($dm['avatar_url'])): ?>
                        <img src="<?= e($dm['avatar_url']) ?>" alt="<?= e($dmFullName) ?>"
                          class="w-9 h-9 rounded-full object-cover border <?= $dmType === 'life' ? 'border-yellow-300' : ($dmType === 'associate' ? 'border-purple-200' : 'border-gray-200') ?> shrink-0">
                      <?php else: ?>
                        <div class="w-9 h-9 rounded-full <?= $dmType === 'life' ? 'bg-yellow-200 border-yellow-300' : ($dmType === 'associate' ? 'bg-purple-100 border-purple-200' : 'bg-gray-100 border-gray-200') ?> border flex items-center justify-center shrink-0">
                          <?php if ($dmType === 'life'): ?>
                            <span class="material-icons-outlined text-yellow-600 text-xl">star</span>
                          <?php else: ?>
                            <span class="material-icons-outlined <?= $dmType === 'associate' ? 'text-purple-400' : 'text-gray-400' ?> text-xl">person</span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <?php if ($dmType === 'associate'): ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="py-3 px-2">
                      <div class="flex flex-wrap items-center gap-1.5 mb-0.5">
                        <p class="font-medium <?= $dmType === 'life' ? 'text-yellow-900' : ($dmType === 'associate' ? 'text-purple-900' : 'text-gray-900') ?>"><?= e($dm['first_name']) ?> <?= e($dm['last_name']) ?></p>
                        <?php if ($dmType === 'life'): ?>
                          <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-yellow-200 text-yellow-800">
                            <span class="material-icons-outlined text-[10px] leading-none">star</span>Life
                          </span>
                        <?php endif; ?>
                        <?php if (!empty($dm['is_committee'])): ?>
                          <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-red-100 text-red-700">
                            <?= e(!empty($dm['committee_role']) ? $dm['committee_role'] : 'Committee') ?>
                          </span>
                        <?php endif; ?>
                        <?php if (!empty($dm['is_area_rep'])): ?>
                          <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-indigo-100 text-indigo-700">Area Rep</span>
                        <?php endif; ?>
                      </div>
                      <?php if ($dmType === 'associate' && $dmFullMemberName): ?>
                        <p class="text-xs text-gray-400">Associated: <?= e($dmFullMemberName) ?></p>
                      <?php endif; ?>
                    </td>
                    <td class="py-3 px-2 text-gray-600 font-mono whitespace-nowrap"><?= e($dmNumber ?: '—') ?></td>
                    <td class="py-3 px-2">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $typeColor ?>">
                        <?= e($typeLabel) ?>
                      </span>
                    </td>
                    <td class="py-3 px-2 text-gray-600 whitespace-nowrap"><?= e($dmChapterLabel ?: '—') ?></td>
                    <td class="py-3 px-2 whitespace-nowrap">
                      <?php if (!empty($dm['phone'])): ?>
                        <a href="tel:<?= e($dm['phone']) ?>" class="text-primary hover:underline"><?= e($dm['phone']) ?></a>
                      <?php else: ?>
                        <span class="text-gray-300">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="py-3 px-2">
                      <?php if (!empty($dm['email'])): ?>
                        <a href="mailto:<?= e($dm['email']) ?>" class="text-primary hover:underline block truncate max-w-[200px]"><?= e($dm['email']) ?></a>
                      <?php else: ?>
                        <span class="text-gray-300">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="py-3 px-2">
                      <?php if ($caps): ?>
                        <div class="flex gap-1">
                          <?php foreach ($caps as $capLetter): ?>
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold bg-green-100 text-green-800">
                              <?= e($capLetter) ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-gray-300 text-xs">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p id="dir-empty" class="hidden text-center text-sm text-gray-400 py-10">No members match your search.</p>
        </section>
        <script>
        (function () {
          const search = document.getElementById('dir-search');
          const chapterSel = document.getElementById('dir-chapter');
          const typeSel = document.getElementById('dir-type');
          const rows = Array.from(document.querySelectorAll('#dir-tbody tr'));
          const emptyMsg = document.getElementById('dir-empty');
          const countEl = document.getElementById('dir-count');

          function applyFilters() {
            const q = search.value.trim().toLowerCase();
            const ch = chapterSel.value.toLowerCase();
            const tp = typeSel.value.toLowerCase();
            let visible = 0;
            rows.forEach(function (row) {
              const ok = (!q || row.dataset.search.includes(q))
                && (!ch || row.dataset.chapter.includes(ch))
                && (!tp || row.dataset.type === tp);
              row.classList.toggle('hidden', !ok);
              if (ok) visible++;
            });
            emptyMsg.classList.toggle('hidden', visible > 0);
            if (countEl) countEl.textContent = visible;
          }

          search.addEventListener('input', applyFilters);
          chapterSel.addEventListener('change', applyFilters);
          typeSel.addEventListener('change', applyFilters);
        })();
        </script>
      <?php elseif ($page === 'committee'): ?>
        <?php
        // Committee & Leadership page — pulled from committee_roles +
        // member_committee_assignments via CommitteeService. Rendered inline
        // (rather than via the shared partial) so any failure is visible on
        // the page itself instead of producing a blank pane.
        $committeeError = null;
        $cmtNational = [];
        $cmtByState = [];
        try {
            $cmtNational = CommitteeService::nationalRoles();
            $cmtByState  = CommitteeService::chapterRolesByState();
        } catch (\Throwable $e) {
            $committeeError = $e->getMessage();
        }

        $renderCmtCard = function (array $role): string {
            $first   = trim((string) ($role['first_name'] ?? ''));
            $last    = trim((string) ($role['last_name']  ?? ''));
            $vacant  = $first === '' && $last === '';
            $private = !empty($role['committee_private']);
            // Privacy: show first name only, suppress phone, keep email/title/chapter.
            $name    = $vacant ? '' : ($private ? $first : trim($first . ' ' . $last));
            $avatar  = $role['avatar_url'] ?? '';
            $email   = $role['email'] ?? '';
            $phone   = $private ? '' : ((string) ($role['phone'] ?? ''));
            $title   = $role['name'] ?? '';
            $chapter = $role['chapter_name'] ?? '';
            $h  = '<div class="flex items-start gap-3 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">';
            if (!$vacant && $avatar !== '') {
                $h .= '<img src="' . e($avatar) . '" alt="' . e($name) . '" class="w-14 h-14 rounded-full object-cover border border-gray-200 shrink-0">';
            } else {
                $bg = $vacant ? 'bg-gray-50 border-gray-200' : 'bg-red-50 border-red-100';
                $ic = $vacant ? 'text-gray-300' : 'text-red-400';
                $h .= '<div class="w-14 h-14 rounded-full border flex items-center justify-center shrink-0 ' . $bg . '">'
                   .  '<span class="material-icons-outlined ' . $ic . ' text-2xl">person</span></div>';
            }
            $h .= '<div class="flex-1 min-w-0">';
            $h .= $vacant
                ? '<p class="font-semibold text-gray-400 italic truncate">Position vacant</p>'
                : '<p class="font-semibold text-gray-900 truncate">' . e($name) . '</p>';
            $h .= '<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-red-100 text-red-700 mt-0.5">' . e($title) . '</span>';
            if ($chapter !== '') {
                $h .= '<p class="text-xs text-gray-500 mt-1 truncate">' . e($chapter) . '</p>';
            }
            if (!$vacant && $phone !== '') {
                $h .= '<a href="tel:' . e(preg_replace('/\s+/', '', $phone)) . '" class="mt-1 text-xs text-primary hover:underline block">' . e($phone) . '</a>';
            }
            if (!$vacant && $email !== '') {
                $h .= '<a href="mailto:' . e($email) . '" class="text-xs text-primary hover:underline block truncate">' . e($email) . '</a>';
            }
            $h .= '</div></div>';
            return $h;
        };
        ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="mb-6">
            <h2 class="font-display text-2xl font-bold text-gray-900">Committee &amp; Leadership</h2>
            <p class="text-sm text-gray-500 mt-1">Your national committee and chapter representatives.</p>
          </div>

          <?php if ($committeeError !== null): ?>
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 mb-6">
              <p class="text-sm font-semibold text-red-800 mb-1">Couldn't load committee data</p>
              <p class="text-xs text-red-700 break-all"><?= e($committeeError) ?></p>
              <p class="text-xs text-red-600 mt-2">If you're an admin, visit <a href="/admin/run-migration.php" class="underline">/admin/run-migration.php</a> to apply Migration 015.</p>
            </div>
          <?php endif; ?>

          <div class="mb-8">
            <div class="flex items-center gap-2 mb-4">
              <div class="p-1.5 rounded-lg bg-red-100">
                <span class="material-icons-outlined text-red-600 text-base">star</span>
              </div>
              <h3 class="font-display text-lg font-bold text-gray-900">National Committee</h3>
              <span class="text-xs text-gray-400">(<?= count($cmtNational) ?> roles)</span>
            </div>
            <?php if ($cmtNational): ?>
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($cmtNational as $cmtRole) { echo $renderCmtCard($cmtRole); } ?>
              </div>
            <?php else: ?>
              <p class="text-sm text-gray-400 py-6 text-center border border-dashed border-gray-200 rounded-xl">No national committee roles configured yet.</p>
            <?php endif; ?>
          </div>

          <?php if ($cmtByState): ?>
            <div>
              <div class="flex items-center gap-2 mb-4">
                <div class="p-1.5 rounded-lg bg-indigo-100">
                  <span class="material-icons-outlined text-indigo-600 text-base">place</span>
                </div>
                <h3 class="font-display text-lg font-bold text-gray-900">Area Representatives</h3>
              </div>
              <?php foreach ($cmtByState as $stateName => $stateRoles): ?>
                <div class="mb-6">
                  <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-3"><?= e((string) $stateName) ?></p>
                  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($stateRoles as $cmtRole) { echo $renderCmtCard($cmtRole); } ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!$cmtNational && !$cmtByState && $committeeError === null): ?>
            <div class="py-12 text-center">
              <span class="material-icons-outlined text-5xl text-gray-200 mb-3 block">groups</span>
              <p class="text-gray-400 text-sm">No committee or chapter rep roles configured yet.</p>
              <p class="text-xs text-gray-400 mt-1">Admins: run Migration 015 to seed the role catalog.</p>
            </div>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="font-display text-2xl font-bold text-gray-900">Member Portal</h2>
          <p class="text-sm text-gray-500">Page not found.</p>
        </section>
      <?php endif; ?>
    </div>
    <div id="upload-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4"
      data-csrf="<?= e(Csrf::token()) ?>">
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
          <div id="upload-crop-container" class="hidden rounded-xl border border-gray-100 bg-gray-100 w-full h-64 overflow-hidden relative">
            <img id="upload-crop-image" src="" alt="Crop" class="max-w-full block">
          </div>
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
<?php
$renewModalEligible = false;
if ($member
  && strtoupper((string) ($member['member_type'] ?? '')) !== 'LIFE'
  && in_array($page, ['dashboard', 'billing'], true)
) {
  $renewModalEligible = true;
}
if ($renewModalEligible) {
  $renewMemberType = strtoupper((string) ($member['member_type'] ?? 'FULL')) === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL';
  $renewMagazine = strtolower((string) ($member['wings_preference'] ?? 'digital')) === 'digital' ? 'PDF' : 'PRINTED';
  $renewPrices = SettingsService::getGlobal('payments.membership_prices', []);
  if (!is_array($renewPrices)) {
    $renewPrices = [];
  }
  $renewCurrency = MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD';

  $renewPartnerMember = null;
  if (!empty($associates)) {
    $renewPartnerMember = $associates[0];
  } elseif ($fullMember) {
    $renewPartnerMember = $fullMember;
  }
  if ($renewPartnerMember && strtoupper((string) ($renewPartnerMember['member_type'] ?? '')) === 'LIFE') {
    $renewPartnerMember = null;
  }

  // Renewal terms are now defined by admin in the pricing config. Show one
  // option per active renewal period.
  $renewPeriods = MembershipPricingService::getRenewalPeriods(true);
  $renewOptions = [];
  foreach ($renewPeriods as $period) {
    $months = (string) (int) $period['duration_months'];
    $selfCents = membership_renewal_amount_cents($renewMagazine, $renewMemberType, $months);
    $partnerCents = 0;
    if ($renewPartnerMember) {
      $partnerType = strtoupper((string) ($renewPartnerMember['member_type'] ?? '')) === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL';
      $partnerMagazine = strtolower((string) ($renewPartnerMember['wings_preference'] ?? 'digital')) === 'digital' ? 'PDF' : 'PRINTED';
      $partnerCents = membership_renewal_amount_cents($partnerMagazine, $partnerType, $months);
    }
    $renewOptions[$months] = [
      'months' => $months,
      'label' => (string) $period['label'],
      'self_available' => $selfCents > 0,
      'self_amount' => $selfCents / 100,
      'partner_available' => $renewPartnerMember ? ($partnerCents > 0) : false,
      'partner_amount' => $partnerCents / 100,
    ];
  }
  // Safety net: if the admin somehow disabled all periods, fall back to a
  // single 12-month option so renewals still work.
  if (!$renewOptions) {
    $selfCents = membership_renewal_amount_cents($renewMagazine, $renewMemberType, '12');
    $renewOptions['12'] = [
      'months' => '12',
      'label' => '1 year',
      'self_available' => $selfCents > 0,
      'self_amount' => $selfCents / 100,
      'partner_available' => false,
      'partner_amount' => 0.0,
    ];
  }

  $renewMemberName = trim(((string) ($member['first_name'] ?? '')) . ' ' . ((string) ($member['last_name'] ?? '')));
  $renewPartnerName = $renewPartnerMember
    ? trim(((string) ($renewPartnerMember['first_name'] ?? '')) . ' ' . ((string) ($renewPartnerMember['last_name'] ?? '')))
    : '';
  $renewPartnerTypeLabel = $renewPartnerMember
    ? (strtoupper((string) ($renewPartnerMember['member_type'] ?? '')) === 'ASSOCIATE' ? 'Associate' : 'Full')
    : '';
?>
<div id="renew-modal" data-tour="renew-modal" class="hidden fixed inset-0 z-50 items-start justify-center bg-black/60 backdrop-blur-sm overflow-y-auto p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-8 border-t-4 border-red-600">
    <div class="flex items-start justify-between gap-4 p-6 border-b border-gray-100">
      <div>
        <h2 class="font-display text-xl font-bold text-gray-900 flex items-center gap-2">
          <span class="material-icons-outlined text-red-600">payments</span>
          Renew membership
        </h2>
        <p class="mt-1 text-sm text-gray-500">Choose your renewal term and confirm your details.</p>
      </div>
      <button type="button" data-renew-close
        class="p-2 rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100" aria-label="Close">
        <span class="material-icons-outlined">close</span>
      </button>
    </div>
    <form method="post" action="/member/index.php?page=billing" class="p-6 space-y-5" id="renew-form">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="membership_renew">
      <div class="rounded-xl bg-red-50 border border-red-100 px-4 py-3 text-sm text-red-800">
        <p class="font-semibold"><?= e($renewMemberName) ?> &middot; <?= e($renewMemberType === 'ASSOCIATE' ? 'Associate Member' : 'Full Member') ?></p>
        <?php if (!empty($membershipPeriod['end_date'])): ?>
          <p class="text-xs">Current period ends <?= e(format_date($membershipPeriod['end_date'])) ?>.</p>
        <?php endif; ?>
      </div>
      <div data-tour="renew-term">
        <p class="text-sm font-semibold text-gray-900 mb-2">Renewal term</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" data-renew-term-group>
          <?php $firstTerm = true; foreach ($renewOptions as $opt): ?>
            <label class="relative cursor-pointer">
              <input type="radio" name="term" value="<?= e($opt['months']) ?>" class="peer sr-only" data-renew-term-radio
                data-self-amount="<?= e(number_format($opt['self_amount'], 2, '.', '')) ?>"
                data-partner-amount="<?= e(number_format($opt['partner_amount'], 2, '.', '')) ?>"
                <?= !$opt['self_available'] ? 'disabled' : '' ?>
                <?= $firstTerm && $opt['self_available'] ? 'checked' : '' ?>>
              <div class="rounded-xl border-2 border-gray-200 px-4 py-3 text-center peer-checked:border-red-600 peer-checked:bg-red-50 peer-disabled:opacity-40 transition-all">
                <p class="text-sm font-bold text-gray-900"><?= e($opt['label']) ?></p>
                <p class="mt-1 text-lg font-display font-bold text-red-700">$<?= e(number_format($opt['self_amount'], 2)) ?></p>
                <p class="text-xs text-gray-500"><?= e($renewCurrency) ?></p>
                <?php if (!$opt['self_available']): ?>
                  <p class="text-[10px] text-amber-700 mt-1">Not configured</p>
                <?php endif; ?>
              </div>
            </label>
          <?php $firstTerm = false; endforeach; ?>
        </div>
      </div>
      <?php if ($renewPartnerMember): ?>
        <div data-tour="renew-partner">
          <label class="flex items-start gap-3 p-4 rounded-xl border-2 border-gray-200 has-[:checked]:border-red-600 has-[:checked]:bg-red-50 cursor-pointer transition-all">
            <input type="checkbox" name="include_partner" value="1" data-renew-partner-toggle
              class="mt-0.5 h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-600">
            <div class="flex-1">
              <p class="text-sm font-semibold text-gray-900">Also renew my <?= e(strtolower($renewPartnerTypeLabel)) ?> member</p>
              <p class="text-xs text-gray-600 mt-0.5"><?= e($renewPartnerName) ?> &middot; <?= e($renewPartnerTypeLabel) ?> Member</p>
              <p class="text-xs text-red-700 mt-1 font-medium" data-renew-partner-price>+$<?= e(number_format($renewOptions['12']['partner_amount'], 2)) ?> for the same term</p>
            </div>
          </label>
        </div>
      <?php endif; ?>
      <div class="rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-sm space-y-2">
        <div class="flex items-center justify-between">
          <span class="text-gray-600">You pay today</span>
          <span class="text-xl font-display font-bold text-gray-900" data-renew-total>$<?= e(number_format($renewOptions['12']['self_amount'], 2)) ?> <?= e($renewCurrency) ?></span>
        </div>
        <p class="text-xs text-gray-500 flex items-center gap-1">
          <span class="material-icons-outlined text-sm">lock</span>
          Secure payment by card via Stripe.
        </p>
      </div>
      <label class="flex items-start gap-3 p-3 rounded-lg bg-amber-50 border border-amber-200 cursor-pointer">
        <input type="checkbox" name="acknowledged" value="1" required
          class="mt-0.5 h-5 w-5 rounded border-amber-300 text-red-600 focus:ring-red-600">
        <span class="text-sm text-amber-900">
          I confirm my membership details (name, address, contact, bike) are correct and up to date.
          <a href="/member/index.php?page=profile" class="underline font-semibold ml-1">Review my details</a>
        </span>
      </label>
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-2 border-t border-gray-100">
        <button type="button" data-renew-cancel-trigger data-tour="renew-cancel-link"
          class="text-xs text-gray-500 hover:text-red-700 underline self-start">Cancel my membership instead</button>
        <div class="flex items-center gap-2">
          <button type="button" data-renew-close
            class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700">Close</button>
          <button type="submit" data-tour="renew-submit"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-sm font-bold text-white shadow-md">
            <span class="material-icons-outlined text-base">lock</span>
            Continue to payment
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<div id="renew-cancel-modal" class="hidden fixed inset-0 z-[60] items-center justify-center bg-black/60 backdrop-blur-sm p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md border-t-4 border-gray-900">
    <div class="p-6 space-y-4">
      <div class="flex items-start gap-3">
        <span class="material-icons-outlined text-gray-700 text-3xl">warning_amber</span>
        <div>
          <h3 class="font-display text-lg font-bold text-gray-900">Cancel your membership?</h3>
          <p class="text-sm text-gray-600 mt-1">Your membership will not auto-renew. You will keep access until your current paid period ends. Staff will be notified to follow up.</p>
        </div>
      </div>
      <form method="post" action="/member/index.php?page=billing" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="membership_cancel_request">
        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide">Reason (optional)</label>
        <textarea name="reason" rows="2" placeholder="Help us improve — why are you leaving?"
          class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-gray-900 focus:ring-gray-900"></textarea>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" data-renew-cancel-close
            class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700">Keep my membership</button>
          <button type="submit"
            class="inline-flex items-center px-4 py-2 rounded-lg bg-gray-900 hover:bg-black text-sm font-semibold text-white">Request cancellation</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
  (() => {
    const modal = document.getElementById('renew-modal');
    if (!modal) return;
    const cancelModal = document.getElementById('renew-cancel-modal');
    const triggers = document.querySelectorAll('[data-renew-trigger]');
    const closers = modal.querySelectorAll('[data-renew-close]');
    const termRadios = modal.querySelectorAll('[data-renew-term-radio]');
    const partnerToggle = modal.querySelector('[data-renew-partner-toggle]');
    const partnerPriceLabel = modal.querySelector('[data-renew-partner-price]');
    const totalEl = modal.querySelector('[data-renew-total]');
    const cancelTrigger = modal.querySelector('[data-renew-cancel-trigger]');
    const cancelClosers = cancelModal ? cancelModal.querySelectorAll('[data-renew-cancel-close]') : [];
    const currency = <?= json_encode($renewCurrency) ?>;

    const open = (el) => {
      el.classList.remove('hidden');
      el.classList.add('flex');
      document.body.style.overflow = 'hidden';
    };
    const close = (el) => {
      el.classList.add('hidden');
      el.classList.remove('flex');
      document.body.style.overflow = '';
    };
    const fmt = (n) => '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const recalc = () => {
      const selected = modal.querySelector('[data-renew-term-radio]:checked');
      if (!selected) {
        if (totalEl) totalEl.textContent = '—';
        return;
      }
      const self = parseFloat(selected.dataset.selfAmount || '0');
      const partner = parseFloat(selected.dataset.partnerAmount || '0');
      const withPartner = partnerToggle && partnerToggle.checked;
      const total = self + (withPartner ? partner : 0);
      if (totalEl) totalEl.textContent = fmt(total) + ' ' + currency;
      if (partnerPriceLabel) partnerPriceLabel.textContent = '+' + fmt(partner) + ' for the same term';
    };

    triggers.forEach((t) => t.addEventListener('click', (e) => { e.preventDefault(); open(modal); recalc(); }));
    closers.forEach((c) => c.addEventListener('click', () => close(modal)));
    modal.addEventListener('click', (e) => { if (e.target === modal) close(modal); });
    termRadios.forEach((r) => r.addEventListener('change', recalc));
    if (partnerToggle) partnerToggle.addEventListener('change', recalc);
    if (cancelTrigger && cancelModal) {
      cancelTrigger.addEventListener('click', () => { close(modal); open(cancelModal); });
      cancelClosers.forEach((c) => c.addEventListener('click', () => close(cancelModal)));
      cancelModal.addEventListener('click', (e) => { if (e.target === cancelModal) close(cancelModal); });
    }
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        close(modal);
        if (cancelModal) close(cancelModal);
      }
    });
  })();
</script>
<?php } ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
  (() => {
    const modal = document.getElementById('upload-modal');
    const dropzone = document.getElementById('upload-dropzone');
    const fileInput = document.getElementById('upload-file-input');
    const preview = document.getElementById('upload-preview');
    const cropContainer = document.getElementById('upload-crop-container');
    const cropImage = document.getElementById('upload-crop-image');
    const saveBtn = document.querySelector('[data-upload-save]');
    const cancelBtn = document.querySelector('[data-upload-cancel]');
    const closeBtn = document.querySelector('[data-upload-close]');
    const csrfToken = modal ? modal.dataset.csrf : '';
    let activeTargetInput = null;
    let activeTargetPreview = null;
    let activeContext = 'members';
    let selectedFile = null;
    let cropper = null;

    const resetModal = () => {
      selectedFile = null;
      saveBtn.disabled = true;
      preview.innerHTML = '';
      preview.classList.add('hidden');
      if (cropContainer) cropContainer.classList.add('hidden');
      if (dropzone) dropzone.classList.remove('hidden');
      fileInput.value = '';
      if (cropper) {
        cropper.destroy();
        cropper = null;
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
      const accept = activeContext === 'notices' ? 'image/*,application/pdf' : 'image/*';
      fileInput.setAttribute('accept', accept);
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    };

    document.querySelectorAll('[data-upload-trigger]').forEach((trigger) => {
      trigger.addEventListener('click', () => openModal(trigger));
    });

    const setPreview = (file) => {
      preview.innerHTML = '';
      dropzone.classList.add('hidden');
      
      if (file.type === 'application/pdf') {
        preview.classList.remove('hidden');
        if (cropContainer) cropContainer.classList.add('hidden');
        preview.innerHTML = `<div class="flex items-center gap-2 text-gray-600"><span class="material-icons-outlined text-base">picture_as_pdf</span>${file.name}</div>`;
        return;
      }
      
      if (cropContainer && cropImage) {
        preview.classList.add('hidden');
        cropContainer.classList.remove('hidden');
        
        const reader = new FileReader();
        reader.onload = (event) => {
          cropImage.src = event.target.result;
          
          if (cropper) {
            cropper.destroy();
          }
          
          let aspectRatio = NaN;
          if (activeContext === 'avatars') {
            aspectRatio = 1;
          } else if (activeContext === 'bikes') {
            aspectRatio = 4 / 3;
          }
          
          cropper = new Cropper(cropImage, {
            aspectRatio: aspectRatio,
            viewMode: 1,
            autoCropArea: 1,
          });
        };
        reader.readAsDataURL(file);
      } else {
        preview.classList.remove('hidden');
        const reader = new FileReader();
        reader.onload = (event) => {
          preview.innerHTML = `<img src="${event.target.result}" alt="Preview" class="w-full h-48 object-cover rounded-lg">`;
        };
        reader.readAsDataURL(file);
      }
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

        const performUpload = async (fileToUpload) => {
          const result = await uploadFile(fileToUpload, activeContext);
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
          flagFormUnsaved(activeTargetInput);
          closeModal();
        };

        if (cropper && selectedFile.type !== 'application/pdf') {
          const canvas = cropper.getCroppedCanvas();
          canvas.toBlob(async (blob) => {
            const croppedFile = new File([blob], selectedFile.name, { type: selectedFile.type });
            await performUpload(croppedFile);
          }, selectedFile.type);
        } else {
          await performUpload(selectedFile);
        }
      });
    }

    [cancelBtn, closeBtn].forEach((btn) => {
      if (btn) {
        btn.addEventListener('click', closeModal);
      }
    });

    const noticeForm = document.getElementById('notice-create-form');
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

    const noticeSubmitBtn = noticeForm ? noticeForm.querySelector('button[type="submit"]') : null;

    const handleNoticeFile = async (file) => {
      if (!file || !noticeAttachmentUrl || !noticeAttachmentType) {
        return;
      }
      if (noticeAttachmentPreview) {
        noticeAttachmentPreview.classList.remove('hidden');
        noticeAttachmentPreview.innerHTML = '<span class="text-xs text-gray-500">Uploading…</span>';
      }
      if (noticeSubmitBtn) noticeSubmitBtn.disabled = true;
      let result;
      try {
        result = await uploadFile(file, 'notices');
      } catch (e) {
        if (noticeAttachmentPreview) {
          noticeAttachmentPreview.innerHTML = '<span class="text-xs text-red-600">Upload failed. Please try again.</span>';
        }
        if (noticeSubmitBtn) noticeSubmitBtn.disabled = false;
        return;
      }
      if (!result || result.error) {
        if (noticeAttachmentPreview) {
          noticeAttachmentPreview.innerHTML = `<span class="text-xs text-red-600">${result?.error || 'Upload failed. Please try again.'}</span>`;
        }
        if (noticeSubmitBtn) noticeSubmitBtn.disabled = false;
        return;
      }
      noticeAttachmentUrl.value = result.url || '';
      noticeAttachmentType.value = result.type || '';
      setNoticeAttachmentPreview(file, result);
      if (noticeSubmitBtn) noticeSubmitBtn.disabled = false;
    };

    if (noticeUploadInput) {
      noticeUploadInput.addEventListener('change', (event) => {
        const file = event.target.files[0];
        if (file) handleNoticeFile(file);
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
        if (file) handleNoticeFile(file);
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