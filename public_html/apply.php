<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\Validator;
use App\Services\AuditService;
use App\Services\MembershipPricingService;
use App\Services\SettingsService;
use App\Services\MembershipService;
use App\Services\MemberRepository;
use App\Services\ChapterRepository;

if (!function_exists('json_response')) {
    function json_response(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

$pdo = db();
$error = '';
$message = '';
$ajaxRequest = isset($_POST['ajax']) && $_POST['ajax'] === '1';
$showSubmittedMessage = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$pricingData = MembershipPricingService::getMembershipPricing();
$periodDefinitions = MembershipPricingService::periodDefinitions();
$storeSettings = store_get_settings();
$chapters = ChapterRepository::listForSelection($pdo, true);
$requestedChapterId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $fullSelected = isset($_POST['membership_full']);
        $associateSelected = isset($_POST['membership_associate']);
        $associateAdd = $_POST['associate_add'] ?? '';
        $memberType = $fullSelected ? 'FULL' : ($associateSelected ? 'ASSOCIATE' : 'FULL');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $privacy = $_POST['privacy_level'] ?? 'A';
        $fullMemberNumber = trim($_POST['full_member_number'] ?? '');
        $referralSource = trim($_POST['referral_source'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addressLine1 = trim($_POST['address_line1'] ?? '');
        $addressLine2 = trim($_POST['address_line2'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $assistUte = isset($_POST['assist_ute']) ? 1 : 0;
        $assistPhone = isset($_POST['assist_phone']) ? 1 : 0;
        $assistBed = isset($_POST['assist_bed']) ? 1 : 0;
        $assistTools = isset($_POST['assist_tools']) ? 1 : 0;
        $excludePrinted = isset($_POST['exclude_printed']) ? 1 : 0;
        $excludeElectronic = isset($_POST['exclude_electronic']) ? 1 : 0;
        $fullVehiclePayload = $_POST['full_vehicle_payload'] ?? '[]';
        $associateVehiclePayload = $_POST['associate_vehicle_payload'] ?? '[]';
        $associateFirstName = trim($_POST['associate_first_name'] ?? '');
        $associateLastName = trim($_POST['associate_last_name'] ?? '');
        $associateEmail = trim($_POST['associate_email'] ?? '');
        $associatePhone = trim($_POST['associate_phone'] ?? '');
        $associateAddressLine1 = trim($_POST['associate_address_line1'] ?? '');
        $associateCity = trim($_POST['associate_city'] ?? '');
        $associateState = trim($_POST['associate_state'] ?? '');
        $associatePostalCode = trim($_POST['associate_postal_code'] ?? '');
        $associateCountry = trim($_POST['associate_country'] ?? '');
        $associateAddressDiff = $_POST['associate_address_diff'] ?? '';
        $fullMagazineType = strtoupper(trim($_POST['full_magazine_type'] ?? ''));
        $fullPeriodKey = strtoupper(trim($_POST['full_period_key'] ?? ''));
        $associatePeriodKey = strtoupper(trim($_POST['associate_period_key'] ?? ''));
        $paymentMethod = $_POST['payment_method'] ?? '';
        $requestedChapterId = (int) ($_POST['requested_chapter_id'] ?? 0);

        if (!Validator::required($firstName) || !Validator::required($lastName) || !Validator::email($email)) {
            $error = 'Please fill in all required fields.';
        } elseif (!MemberRepository::isEmailAvailable($email)) {
            $error = 'That email address is already linked to another member.';
        } elseif (!$fullSelected && !$associateSelected) {
            $error = 'Select at least one membership type.';
        } else {
            $periodKeys = array_keys($periodDefinitions);
            if ($requestedChapterId !== 0) {
                $chapterIds = array_map(static fn($row) => (int) $row['id'], $chapters);
                if (!in_array($requestedChapterId, $chapterIds, true)) {
                    $error = 'Select a valid chapter.';
                }
            }
            if ($fullSelected) {
                if (!in_array($fullMagazineType, MembershipPricingService::MAGAZINE_TYPES, true)) {
                    $error = 'Select a magazine type for Full membership.';
                } elseif (!in_array($fullPeriodKey, $periodKeys, true)) {
                    $error = 'Select a membership period for Full membership.';
                }
            }
            if (!$error && $associateSelected) {
                if (!in_array($associateAdd, ['yes', 'no'], true)) {
                    $error = 'Choose whether to add an associate member.';
                } elseif ($associateAdd === 'no') {
                    $error = 'To include Associate membership, select Yes and provide associate details.';
                } elseif (!in_array($associatePeriodKey, $periodKeys, true)) {
                    $error = 'Select a membership period for the associate member.';
                }
            }
            if (!$error && $associateSelected && $associateAdd === 'yes' && (!Validator::required($associateFirstName) || !Validator::required($associateLastName))) {
                $error = 'Associate member first and last name are required.';
            }
            if (
                !$error
                && $associateSelected
                && $associateAdd === 'yes'
                && !Validator::email($associateEmail)
            ) {
                $error = 'A valid associate member email is required.';
            }
            if (
                !$error
                && $associateSelected
                && $associateAdd === 'yes'
                && !MemberRepository::isEmailAvailable($associateEmail)
            ) {
                $error = 'Associate member email is already linked to another member.';
            }
        }
        if (!$error) {
            $memberNumberBase = 0;
            $memberNumberSuffix = 0;
            $fullMemberId = null;
            $memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
            $associateSuffixStart = (int) SettingsService::getGlobal('membership.associate_suffix_start', 1);

            if ($memberType === 'ASSOCIATE' && $fullMemberNumber !== '') {
                $parsedFull = MembershipService::parseMemberNumberString($fullMemberNumber);
                if (!$parsedFull || ($parsedFull['suffix'] ?? 0) !== 0) {
                    $error = 'Full member number not found.';
                }
                if (!$error) {
                    $stmt = $pdo->prepare('SELECT id, member_number_base FROM members WHERE member_number_base = :base AND member_number_suffix = 0 LIMIT 1');
                    $stmt->execute(['base' => (int) $parsedFull['base']]);
                    $full = $stmt->fetch();
                    if (!$full) {
                        $error = 'Full member number not found.';
                    } else {
                        $fullMemberId = $full['id'];
                        $memberNumberBase = (int) $full['member_number_base'];
                        $stmt = $pdo->prepare('SELECT MAX(member_number_suffix) as max_suffix FROM members WHERE full_member_id = :full_id');
                        $stmt->execute(['full_id' => $fullMemberId]);
                        $row = $stmt->fetch();
                        $maxSuffix = (int) ($row['max_suffix'] ?? 0);
                        $memberNumberSuffix = max($maxSuffix, $associateSuffixStart - 1) + 1;
                    }
                }
            } else {
                $stmt = $pdo->query('SELECT MAX(member_number_base) as max_base FROM members');
                $row = $stmt->fetch();
                $maxBase = (int) ($row['max_base'] ?? 0);
                $start = max($memberNumberStart, 1);
                $memberNumberBase = max($maxBase, $start - 1) + 1;
            }

            $pricingMatrix = $pricingData['matrix'] ?? [];
            $pricingCurrency = $pricingData['currency'] ?? 'AUD';
            $fullPriceCents = null;
            $associatePriceCents = null;
            $associatePricingMagazine = $fullSelected ? $fullMagazineType : 'PRINTED';

            if (!$error && $fullSelected) {
                $fullPriceCents = $pricingMatrix[$fullMagazineType]['FULL'][$fullPeriodKey] ?? null;
                if ($fullPriceCents === null) {
                    $error = 'Unable to locate full membership pricing.';
                }
            }
            if (!$error && $associateSelected && $associateAdd === 'yes') {
                $associatePriceCents = $pricingMatrix[$associatePricingMagazine]['ASSOCIATE'][$associatePeriodKey] ?? null;
                if ($associatePriceCents === null) {
                    $error = 'Unable to locate associate membership pricing.';
                }
            }
            $totalCents = (int) ($fullPriceCents ?? 0) + (int) ($associatePriceCents ?? 0);
            $processingFeeCents = 0;
            $totalWithFeeCents = $totalCents;
            if ($paymentMethod === 'card' && (int) ($storeSettings['stripe_fee_enabled'] ?? 0) === 1) {
                $processingFee = store_calculate_processing_fee(
                    $totalCents / 100,
                    (float) ($storeSettings['stripe_fee_percent'] ?? 0),
                    (float) ($storeSettings['stripe_fee_fixed'] ?? 0)
                );
                $processingFeeCents = (int) round($processingFee * 100);
                $totalWithFeeCents = $totalCents + $processingFeeCents;
            }

            if (!$error) {
                $stmt = $pdo->prepare('INSERT INTO members (member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, first_name, last_name, email, phone, address_line1, address_line2, city, state, postal_code, country, privacy_level, assist_ute, assist_phone, assist_bed, assist_tools, exclude_printed, exclude_electronic, created_at) VALUES (:member_type, :status, :base, :suffix, :full_id, :chapter_id, :first_name, :last_name, :email, :phone, :address1, :address2, :city, :state, :postal, :country, :privacy, :assist_ute, :assist_phone, :assist_bed, :assist_tools, :exclude_printed, :exclude_electronic, NOW())');
                $stmt->execute([
                    'member_type' => $memberType,
                    'status' => 'PENDING',
                    'base' => $memberNumberBase,
                    'suffix' => $memberNumberSuffix,
                    'full_id' => $fullMemberId,
                    'chapter_id' => null,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone ?: null,
                    'address1' => $addressLine1 ?: null,
                    'address2' => $addressLine2 ?: null,
                    'city' => $city ?: null,
                    'state' => $state ?: null,
                    'postal' => $postalCode ?: null,
                    'country' => $country ?: null,
                    'privacy' => $privacy,
                    'assist_ute' => $assistUte,
                    'assist_phone' => $assistPhone,
                    'assist_bed' => $assistBed,
                    'assist_tools' => $assistTools,
                    'exclude_printed' => $excludePrinted,
                    'exclude_electronic' => $excludeElectronic,
                ]);
                $memberId = (int) $pdo->lastInsertId();
                if (MemberRepository::hasMemberNumberColumn($pdo)) {
                    $memberNumberDisplay = MembershipService::displayMembershipNumber($memberNumberBase, $memberNumberSuffix);
                    $stmt = $pdo->prepare('UPDATE members SET member_number = :member_number WHERE id = :id');
                    $stmt->execute([
                        'member_number' => $memberNumberDisplay,
                        'id' => $memberId,
                    ]);
                }

                $fullVehicles = json_decode($fullVehiclePayload, true);
                $associateVehicles = json_decode($associateVehiclePayload, true);
                if (!is_array($fullVehicles)) {
                    $fullVehicles = [];
                }
                if (!is_array($associateVehicles)) {
                    $associateVehicles = [];
                }

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
                    $primaryStmt->execute(['member_id' => $memberId]);
                    $primarySet = (bool) $primaryStmt->fetchColumn();
                }

                foreach ($fullVehicles as $vehicle) {
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
                            'member_id' => $memberId,
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

                $applicationNotes = [
                    'referral_source' => $referralSource,
                    'associate' => [
                        'first_name' => $associateFirstName,
                        'last_name' => $associateLastName,
                        'email' => $associateEmail,
                        'phone' => $associatePhone,
                        'address_line1' => $associateAddressLine1,
                        'city' => $associateCity,
                        'state' => $associateState,
                        'postal_code' => $associatePostalCode,
                        'country' => $associateCountry,
                        'address_diff' => $associateAddressDiff,
                    ],
                    'vehicles' => [
                        'full' => $fullVehicles,
                        'associate' => $associateVehicles,
                    ],
                    'membership' => [
                        'full_selected' => $fullSelected,
                        'associate_selected' => $associateSelected,
                        'associate_add' => $associateAdd,
                        'full' => [
                            'magazine_type' => $fullMagazineType,
                            'period_key' => $fullPeriodKey,
                            'price_cents' => $fullPriceCents,
                        ],
                        'associate' => [
                            'magazine_type' => $associatePricingMagazine,
                            'period_key' => $associatePeriodKey,
                            'price_cents' => $associatePriceCents,
                        ],
                        'currency' => $pricingCurrency,
                        'total_cents' => $totalCents,
                        'processing_fee_cents' => $processingFeeCents,
                        'total_with_fee_cents' => $totalWithFeeCents,
                    ],
                    'requested_chapter_id' => $requestedChapterId ?: null,
                    'payment_method' => $paymentMethod,
                ];

                $notesJson = json_encode($applicationNotes, JSON_UNESCAPED_SLASHES);

                $stmt = $pdo->prepare('INSERT INTO membership_applications (member_id, member_type, status, notes, created_at) VALUES (:member_id, :member_type, :status, :notes, NOW())');
                $stmt->execute([
                    'member_id' => $memberId,
                    'member_type' => $memberType,
                    'status' => 'PENDING',
                    'notes' => $notesJson ?: null,
                ]);

                AuditService::log(null, 'application_submitted', 'New membership application submitted.');
                $message = 'Application submitted. We will confirm your membership and payment details shortly.';
            }
        }
        if ($ajaxRequest) {
            if ($error) {
                json_response(['error' => $error], 422);
            }
            json_response(['ok' => true, 'message' => $message]);
        }
    }
}

$bankTransferInstructions = trim((string) SettingsService::getGlobal('payments.bank_transfer_instructions', ''));
if ($bankTransferInstructions === '') {
    $bankTransferInstructions = 'Bank transfer details will be provided once your application is submitted.';
}

$successMessageText = $message;
if ($showSubmittedMessage && $successMessageText === '') {
    $successMessageText = 'Application submitted. We will confirm your membership and payment details shortly.';
}

$pageTitle = 'Membership Application';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main form-page">
  <div class="container form-shell">
    <div class="form-header">
      <h1>Membership Application</h1>
      <p>Complete the steps below to join the Goldwing community.</p>
    </div>

    <?php if ($successMessageText): ?>
      <div class="form-card form-card--wizard">
        <h2>Thank you for your application</h2>
        <p><?= e($successMessageText) ?></p>
        <p>Our committee will review your details and contact you with the next steps.</p>
        <a class="form-button primary" href="/">Return to home</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="form-alert error" id="application-form-error"><?= e($error) ?></div>
      <?php else: ?>
        <div class="form-alert error" id="application-form-error" hidden></div>
      <?php endif; ?>

      <div class="form-progress">
        <div class="form-progress-header">
          <div data-step-text>Step 1 of 5: Membership selection</div>
          <span data-next-text>Next: Full options &amp; primary member details</span>
        </div>
        <div class="form-progress-bar">
          <div class="form-progress-fill" data-progress-fill></div>
        </div>
      </div>

      <form class="form-card form-card--wizard" method="post" data-wizard>
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="privacy_level" value="A">
        <input type="hidden" name="full_vehicle_payload" value="[]">
        <input type="hidden" name="associate_vehicle_payload" value="[]">

        <div class="checkout-grid">
          <div class="checkout-main">
            <section class="form-step" data-step data-step-key="membership" data-title="Membership selection">
              <h3>Step 1: Membership selection</h3>
              <p class="form-helper">Select Full membership, Associate membership, or both.</p>
              <div class="option-grid">
                <label class="option-card">
                  <input type="checkbox" name="membership_full" value="1">
                  <span class="option-card-body">
                    <span class="option-card-title">Full Membership</span>
                    <span class="option-card-subtitle">Includes Wings magazine and voting rights.</span>
                    <span class="option-card-check">Selected</span>
                  </span>
                </label>
                <label class="option-card">
                  <input type="checkbox" name="membership_associate" value="1">
                  <span class="option-card-body">
                    <span class="option-card-title">Associate Membership</span>
                    <span class="option-card-subtitle">Add an associate member to the household.</span>
                    <span class="option-card-check">Selected</span>
                  </span>
                </label>
              </div>

              <div class="form-field">
                <span class="form-label">How did you hear about us?</span>
                <input class="form-input" type="text" name="referral_source">
                <span class="form-helper">Optional.</span>
              </div>

              <div class="form-error" data-membership-error hidden></div>

              <div class="form-actions">
                <button class="form-button secondary" type="button" data-action="prev">Previous</button>
                <button class="form-button primary" type="button" data-action="next">Next</button>
              </div>
            </section>

            <section class="form-step" data-step data-step-key="full-options" data-title="Full options & details" hidden>
              <h3>Step 2: Full options &amp; primary member details</h3>

              <div class="form-section" data-full-options hidden>
                <h4>Full membership options</h4>
                <div class="option-grid">
                  <label class="option-card option-card--compact">
                    <input type="radio" name="full_magazine_type" value="PRINTED" data-required="true" data-required-when="full">
                    <span class="option-card-body">
                      <span class="option-card-title">Printed Wings</span>
                      <span class="option-card-subtitle">Magazine posted to you.</span>
                      <span class="option-card-check">Selected</span>
                    </span>
                  </label>
                  <label class="option-card option-card--compact">
                    <input type="radio" name="full_magazine_type" value="PDF" data-required="true" data-required-when="full">
                    <span class="option-card-body">
                      <span class="option-card-title">PDF Only</span>
                      <span class="option-card-subtitle">Magazine emailed to you.</span>
                      <span class="option-card-check">Selected</span>
                    </span>
                  </label>
                </div>

                <label class="form-field">
                  <span class="form-label">Full membership period</span>
                  <select class="form-select" name="full_period_key" data-required="true" data-required-when="full" data-period-select="full">
                    <option value="">Select a period</option>
                  </select>
                  <span class="form-helper" data-period-hint="full"></span>
                </label>
              </div>

              <div class="form-section">
                <h4>Primary member details</h4>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">First Name</span>
                    <input class="form-input" type="text" name="first_name" data-required="true">
                    <span class="form-helper">First</span>
                  </label>
                  <label class="form-field">
                    <span class="form-label">Last Name</span>
                    <input class="form-input" type="text" name="last_name" data-required="true">
                    <span class="form-helper">Last</span>
                  </label>
                </div>

                <label class="form-field">
                  <span class="form-label">Email</span>
                  <input class="form-input" type="email" name="email" data-required="true">
                </label>

                <div class="form-field">
                  <span class="form-label">Date of Birth</span>
                  <div class="form-grid three">
                    <select class="form-select" name="dob_day">
                      <option value="">DD</option>
                      <?php for ($day = 1; $day <= 31; $day++): ?>
                        <option value="<?= $day ?>"><?= $day ?></option>
                      <?php endfor; ?>
                    </select>
                    <select class="form-select" name="dob_month">
                      <option value="">MM</option>
                      <?php for ($month = 1; $month <= 12; $month++): ?>
                        <option value="<?= $month ?>"><?= $month ?></option>
                      <?php endfor; ?>
                    </select>
                    <select class="form-select" name="dob_year">
                      <option value="">YYYY</option>
                      <?php for ($year = (int) date('Y'); $year >= (int) date('Y') - 100; $year--): ?>
                        <option value="<?= $year ?>"><?= $year ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                </div>

                <label class="form-field">
                  <span class="form-label">Postal Address</span>
                  <input id="apply_address_line1" data-google-autocomplete="address" data-google-autocomplete-city="#apply_city" data-google-autocomplete-state="#apply_state" data-google-autocomplete-postal="#apply_postal" data-google-autocomplete-country="#apply_country" class="form-input" type="text" name="address_line1" data-required="true">
                  <span class="form-helper">Address Line 1</span>
                </label>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">City</span>
                    <input id="apply_city" class="form-input" type="text" name="city" data-required="true">
                  </label>
                  <label class="form-field">
                    <span class="form-label">State / Province / Region</span>
                    <select class="form-select" name="state" data-required="true">
                      <option value="">Select</option>
                      <option value="NSW">NSW</option>
                      <option value="VIC">VIC</option>
                      <option value="QLD">QLD</option>
                      <option value="WA">WA</option>
                      <option value="SA">SA</option>
                      <option value="TAS">TAS</option>
                      <option value="ACT">ACT</option>
                      <option value="NT">NT</option>
                    </select>
                  </label>
                </div>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">Postal Code</span>
                    <input class="form-input" type="text" name="postal_code" data-required="true">
                  </label>
                  <label class="form-field">
                    <span class="form-label">Country</span>
                    <select class="form-select" name="country" data-required="true">
                      <option value="">Select</option>
                      <option value="Australia" selected>Australia</option>
                      <option value="New Zealand">New Zealand</option>
                      <option value="Other">Other</option>
                    </select>
                  </label>
                </div>

                <label class="form-field">
                  <span class="form-label">Preferred Chapter (optional)</span>
                  <select class="form-select" name="requested_chapter_id">
                    <option value="">Select chapter</option>
                    <?php foreach ($chapters as $chapter): ?>
                      <?php
                        $chapterLabel = $chapter['name'];
                        if (!empty($chapter['state'])) {
                            $chapterLabel .= ' (' . $chapter['state'] . ')';
                        }
                      ?>
                      <option value="<?= e((string) $chapter['id']) ?>" <?= (int) $chapter['id'] === (int) $requestedChapterId ? 'selected' : '' ?>>
                        <?= e($chapterLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <label class="form-field">
                  <span class="form-label">Phone</span>
                  <input class="form-input" type="tel" name="phone" data-required="true">
                </label>

              </div>

              <div class="form-section">
                <h4>Primary member bike details</h4>
                <p class="form-helper">Add as many bikes, trikes, sidecars, or trailers as you need.</p>
                <div class="vehicle-list" data-vehicle-list="full"></div>
                <button class="form-button secondary" type="button" data-add-vehicle="full">Add another vehicle</button>
              </div>

              <div class="form-section">
                <h4>Membership directory information</h4>
                <p class="form-helper">Select the options that apply to the support you can provide and your privacy preferences.</p>
                <div class="form-checkboxes">
                  <label class="form-checkbox">
                    <input type="checkbox" name="assist_ute" value="1">
                    A: Ute, truck, or trailer to collect a motorcycle.
                  </label>
                  <label class="form-checkbox">
                    <input type="checkbox" name="assist_phone" value="1">
                    B: Accept phone calls.
                  </label>
                  <label class="form-checkbox">
                    <input type="checkbox" name="assist_bed" value="1">
                    C: Provide bed or tent space.
                  </label>
                  <label class="form-checkbox">
                    <input type="checkbox" name="assist_tools" value="1">
                    D: Provide tools or a workshop.
                  </label>
                  <label class="form-checkbox">
                    <input type="checkbox" name="exclude_printed" value="1">
                    E: Do not include in the Member Directory.
                  </label>
                  <label class="form-checkbox">
                    <input type="checkbox" name="exclude_electronic" value="1">
                    F: Do not include in the Electronic Member Directory.
                  </label>
                </div>
              </div>

              <div class="form-actions">
                <button class="form-button secondary" type="button" data-action="prev">Previous</button>
                <button class="form-button primary" type="button" data-action="next">Next</button>
              </div>
            </section>

            <section class="form-step" data-step data-step-key="associate-question" data-title="Associate question" hidden>
              <h3>Step 3: Associate member question</h3>
              <div class="form-field">
                <span class="form-label">Do you want to add an associate member?</span>
                <div class="form-checkboxes">
                  <label class="form-checkbox">
                    <input type="radio" name="associate_add" value="yes" data-required="true" data-required-when="associate">
                    Yes
                  </label>
                  <label class="form-checkbox">
                    <input type="radio" name="associate_add" value="no" data-required="true" data-required-when="associate">
                    No
                  </label>
                </div>
              </div>
              <div class="form-error" data-associate-error hidden></div>
              <div class="form-actions">
                <button class="form-button secondary" type="button" data-action="prev">Previous</button>
                <button class="form-button primary" type="button" data-action="next">Next</button>
              </div>
            </section>

            <section class="form-step" data-step data-step-key="associate-details" data-title="Associate details" hidden>
              <h3>Step 4: Associate details</h3>
              <label class="form-field">
                <span class="form-label">Associate membership period</span>
                <select class="form-select" name="associate_period_key" data-required="true" data-required-when="associate_yes" data-period-select="associate">
                  <option value="">Select a period</option>
                </select>
                <span class="form-helper" data-period-hint="associate"></span>
              </label>

              <div class="form-grid two">
                <label class="form-field">
                  <span class="form-label">Associate Member | First Name</span>
                  <input class="form-input" type="text" name="associate_first_name" data-required="true" data-required-when="associate_yes">
                  <span class="form-helper">First</span>
                </label>
                <label class="form-field">
                  <span class="form-label">Associate Member | Last Name</span>
                  <input class="form-input" type="text" name="associate_last_name" data-required="true" data-required-when="associate_yes">
                  <span class="form-helper">Last</span>
                </label>
              </div>

              <div class="form-field">
                <span class="form-label">Is the associate member address different than members?</span>
                <div class="form-checkboxes">
                  <label class="form-checkbox">
                    <input type="radio" name="associate_address_diff" value="yes">
                    Yes
                  </label>
                  <label class="form-checkbox">
                    <input type="radio" name="associate_address_diff" value="no">
                    No
                  </label>
                </div>
              </div>

              <label class="form-field">
                <span class="form-label">Associate Member | Email</span>
                <input class="form-input" type="email" name="associate_email">
              </label>

              <div class="form-field">
                <span class="form-label">Associate Member | Date of Birth</span>
                <div class="form-grid three">
                  <select class="form-select" name="associate_dob_day">
                    <option value="">DD</option>
                    <?php for ($day = 1; $day <= 31; $day++): ?>
                      <option value="<?= $day ?>"><?= $day ?></option>
                    <?php endfor; ?>
                  </select>
                  <select class="form-select" name="associate_dob_month">
                    <option value="">MM</option>
                    <?php for ($month = 1; $month <= 12; $month++): ?>
                      <option value="<?= $month ?>"><?= $month ?></option>
                    <?php endfor; ?>
                  </select>
                  <select class="form-select" name="associate_dob_year">
                    <option value="">YYYY</option>
                    <?php for ($year = (int) date('Y'); $year >= (int) date('Y') - 100; $year--): ?>
                      <option value="<?= $year ?>"><?= $year ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>

              <label class="form-field">
                <span class="form-label">Associate Member | Phone</span>
                <input class="form-input" type="tel" name="associate_phone">
              </label>

              <label class="form-field">
                <span class="form-label">Associate Member | Postal Address</span>
                <input id="associate_address_line1" data-google-autocomplete="address" data-google-autocomplete-city="#associate_city" data-google-autocomplete-state="#associate_state" data-google-autocomplete-postal="#associate_postal_code" data-google-autocomplete-country="#associate_country" class="form-input" type="text" name="associate_address_line1">
                <span class="form-helper">Address Line 1</span>
              </label>
              <div class="form-grid two">
                <label class="form-field">
                  <span class="form-label">City</span>
                  <input id="associate_city" class="form-input" type="text" name="associate_city">
                </label>
                <label class="form-field">
                  <span class="form-label">State / Province / Region</span>
                  <input id="associate_state" class="form-input" type="text" name="associate_state">
                </label>
              </div>
              <div class="form-grid two">
                <label class="form-field">
                  <span class="form-label">Postal Code</span>
                  <input id="associate_postal_code" class="form-input" type="text" name="associate_postal_code">
                </label>
                <label class="form-field">
                  <span class="form-label">Country</span>
                  <input id="associate_country" class="form-input" type="text" name="associate_country">
                </label>
              </div>

              <div class="form-section">
                <h4>Associate bike details</h4>
                <p class="form-helper">Add as many bikes, trikes, sidecars, or trailers for the associate member.</p>
                <div class="vehicle-list" data-vehicle-list="associate"></div>
                <button class="form-button secondary" type="button" data-add-vehicle="associate">Add another vehicle</button>
              </div>

              <div class="form-actions">
                <button class="form-button secondary" type="button" data-action="prev">Previous</button>
                <button class="form-button primary" type="button" data-action="next">Next</button>
              </div>
            </section>

            <section class="form-step" data-step data-step-key="summary" data-title="Summary & submit" hidden>
              <h3>Step 5: Summary &amp; submit</h3>
              <p class="form-helper">Review your selections in the order summary, then choose a payment method.</p>

              <div class="form-field">
                <span class="form-label">Payment Method</span>
                <div class="form-checkboxes">
                  <label class="form-checkbox">
                    <input type="radio" name="payment_method" value="card" data-required="true" data-payment-toggle="card">
                    Credit Card (Stripe)
                  </label>
                  <label class="form-checkbox">
                    <input type="radio" name="payment_method" value="bank_transfer" data-required="true" data-payment-toggle="bank">
                    Bank Transfer
                  </label>
                </div>
                <div class="form-alert error" id="payment-method-error" role="alert" hidden></div>
              </div>

              <div class="payment-panel stripe-style" data-payment-panel="card" hidden>
                <p id="stripe-payment-note" class="form-helper" hidden>The Stripe Payment Element handles card details securely. Complete it below to pay instantly.</p>
                <div id="stripe-payment-element" class="mt-4 stripe-element" hidden></div>
                <div id="stripe-payment-error" class="form-alert error" hidden></div>
              </div>

              <div class="payment-panel" data-payment-panel="bank_transfer" hidden>
                <div id="bank-transfer-details" class="bank-transfer-instructions">
                  <?= nl2br(e($bankTransferInstructions)) ?>
                </div>
              </div>

              <div class="form-actions">
                <button class="form-button secondary" type="button" data-action="prev">Previous</button>
                <button class="form-button primary" type="submit" data-action="submit">Submit Application</button>
              </div>
            </section>
          </div>

          <aside class="checkout-summary">
            <h4>Order Summary</h4>
            <div class="summary-row">
              <span>Full membership</span>
              <strong data-summary-full-price>Not selected</strong>
            </div>
            <div class="summary-detail" data-summary-full-detail>Not selected</div>
            <div class="summary-row">
              <span>Associate membership</span>
              <strong data-summary-associate-price>Not selected</strong>
            </div>
            <div class="summary-detail" data-summary-associate-detail>Not selected</div>
            <div class="summary-row" data-summary-processing-row hidden>
              <span>Payment processing fee</span>
              <strong data-summary-processing-fee>$0.00</strong>
            </div>
            <div class="summary-row summary-total">
              <span>Total</span>
              <strong data-summary-total>$0.00</strong>
            </div>
            <p class="form-helper"><?= e(MembershipPricingService::pricingNote()) ?></p>
          </aside>
        </div>

        <template id="vehicle-template">
          <div class="vehicle-card" data-vehicle>
            <div class="vehicle-card-header">
              <h4>Vehicle <span data-vehicle-index></span></h4>
              <button class="form-button secondary small" type="button" data-remove-vehicle>Remove</button>
            </div>
            <div class="form-grid two">
              <label class="form-field">
                <span class="form-label">Make</span>
                <input class="form-input" type="text" data-field="make">
              </label>
              <label class="form-field">
                <span class="form-label">Colour</span>
                <input class="form-input" type="text" data-field="colour">
              </label>
              <label class="form-field">
                <span class="form-label">Model</span>
                <input class="form-input" type="text" data-field="model">
              </label>
              <label class="form-field">
                <span class="form-label">Year</span>
                <input class="form-input" type="text" data-field="year">
              </label>
              <label class="form-field">
                <span class="form-label">Bike Rego Number</span>
                <input class="form-input" type="text" data-field="rego" maxlength="20">
              </label>
            </div>
            <div class="form-field">
              <span class="form-label">Do you have the following</span>
              <div class="form-checkboxes">
                <label class="form-checkbox">
                  <input type="checkbox" data-field="trike_conversion" data-toggle="trike">
                  Trike Conversion
                </label>
                <label class="form-checkbox">
                  <input type="checkbox" data-field="sidecar" data-toggle="sidecar">
                  Sidecar
                </label>
                <label class="form-checkbox">
                  <input type="checkbox" data-field="trailer" data-toggle="trailer">
                  Trailer
                </label>
              </div>
            </div>

            <div class="form-grid two" data-conditional="trike" hidden>
              <label class="form-field">
                <span class="form-label">Trike Manufacturer</span>
                <input class="form-input" type="text" data-field="trike_manufacturer">
              </label>
            </div>

            <div class="form-grid two" data-conditional="sidecar" hidden>
              <label class="form-field">
                <span class="form-label">Bike Name</span>
                <input class="form-input" type="text" data-field="sidecar_bike_name">
              </label>
              <label class="form-field">
                <span class="form-label">Sidecar Rego Number</span>
                <input class="form-input" type="text" data-field="sidecar_rego" maxlength="20">
              </label>
              <label class="form-field">
                <span class="form-label">Sidecar Manufacturer</span>
                <input class="form-input" type="text" data-field="sidecar_manufacturer">
              </label>
            </div>

            <div class="form-grid two" data-conditional="trailer" hidden>
              <label class="form-field">
                <span class="form-label">Trailer Rego Number</span>
                <input class="form-input" type="text" data-field="trailer_rego" maxlength="20">
              </label>
              <label class="form-field">
                <span class="form-label">Trailer Manufacturer</span>
                <input class="form-input" type="text" data-field="trailer_manufacturer">
              </label>
            </div>
          </div>
        </template>
      </form>

      <div class="form-secure">
        Your information is securely encrypted.
      </div>
    <?php endif; ?>
  </div>
</main>
<?php if (!$successMessageText): ?>
<style>
  .payment-panel.stripe-style {
    background: #fdfdfd;
    border: 1px solid #d1d5db;
    border-radius: 1rem;
    padding: 1.25rem;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
  }
  .payment-panel.stripe-style .form-field,
  .payment-panel.stripe-style .form-grid {
    margin-bottom: 0.75rem;
  }
  .bank-transfer-instructions {
    background: #f8fafc;
    border: 1px dashed #cbd5f5;
    border-radius: 0.75rem;
    padding: 1rem;
    font-size: 0.95rem;
    line-height: 1.5;
    color: #0f172a;
  }
  .payment-panel[hidden] {
    display: none !important;
  }
  .stripe-element {
    min-height: 48px;
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    background: #fff;
  }
</style>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-wizard]');
    if (!form) {
      return;
    }

    const steps = Array.from(form.querySelectorAll('[data-step]'));
    const progressFill = document.querySelector('[data-progress-fill]');
    const stepText = document.querySelector('[data-step-text]');
    const nextText = document.querySelector('[data-next-text]');
    const vehicleTemplate = document.querySelector('#vehicle-template');
    const vehicleLists = {
      full: form.querySelector('[data-vehicle-list="full"]'),
      associate: form.querySelector('[data-vehicle-list="associate"]'),
    };
    const fullVehicleInput = form.querySelector('input[name="full_vehicle_payload"]');
    const associateVehicleInput = form.querySelector('input[name="associate_vehicle_payload"]');
    const fullToggle = form.querySelector('input[name="membership_full"]');
    const associateToggle = form.querySelector('input[name="membership_associate"]');
    const associateAddInputs = Array.from(form.querySelectorAll('input[name="associate_add"]'));
    const fullOptionsBlock = form.querySelector('[data-full-options]');
    const membershipError = form.querySelector('[data-membership-error]');
    const associateError = form.querySelector('[data-associate-error]');
    const periodSelects = {
      full: form.querySelector('[data-period-select="full"]'),
      associate: form.querySelector('[data-period-select="associate"]'),
    };
    const periodHints = {
      full: form.querySelector('[data-period-hint="full"]'),
      associate: form.querySelector('[data-period-hint="associate"]'),
    };
    const paymentMethodError = document.getElementById('payment-method-error');
    const bankTransferDetails = document.getElementById('bank-transfer-details');
    const stripePaymentNote = document.getElementById('stripe-payment-note');
    const paymentElementContainer = document.getElementById('stripe-payment-element');
    const stripePaymentError = document.getElementById('stripe-payment-error');
    const formErrorAlert = document.getElementById('application-form-error');
    const finalSubmitButton = form.querySelector('button[type="submit"]');
    const summaryFullPrice = document.querySelector('[data-summary-full-price]');
    const summaryFullDetail = document.querySelector('[data-summary-full-detail]');
    const summaryAssociatePrice = document.querySelector('[data-summary-associate-price]');
    const summaryAssociateDetail = document.querySelector('[data-summary-associate-detail]');
    const summaryTotal = document.querySelector('[data-summary-total]');
    const summaryProcessingRow = document.querySelector('[data-summary-processing-row]');
    const summaryProcessingFee = document.querySelector('[data-summary-processing-fee]');
    const pricingData = <?= json_encode($pricingData, JSON_UNESCAPED_SLASHES) ?>;
    const periodDefinitions = <?= json_encode($periodDefinitions, JSON_UNESCAPED_SLASHES) ?>;
    const storeSettings = <?= json_encode([
        'stripe_fee_enabled' => (int) ($storeSettings['stripe_fee_enabled'] ?? 0),
        'stripe_fee_percent' => (float) ($storeSettings['stripe_fee_percent'] ?? 0),
        'stripe_fee_fixed' => (float) ($storeSettings['stripe_fee_fixed'] ?? 0),
    ], JSON_UNESCAPED_SLASHES) ?>;
    let currentStepIndex = 0;

    const updateVehicleIndices = (list) => {
      const cards = Array.from(list.querySelectorAll('[data-vehicle]'));
      cards.forEach((card, index) => {
        const indexSpan = card.querySelector('[data-vehicle-index]');
        if (indexSpan) {
          indexSpan.textContent = index + 1;
        }
      });
    };

    const setupVehicleCard = (card) => {
      const toggles = card.querySelectorAll('[data-toggle]');
      const panels = {
        trike: card.querySelector('[data-conditional="trike"]'),
        sidecar: card.querySelector('[data-conditional="sidecar"]'),
        trailer: card.querySelector('[data-conditional="trailer"]'),
      };

      toggles.forEach((toggle) => {
        const target = panels[toggle.dataset.toggle];
        const update = () => {
          if (target) {
            const isVisible = toggle.checked;
            target.hidden = !isVisible;
            target.classList.toggle('is-visible', isVisible);
          }
        };
        toggle.addEventListener('change', update);
        update();
      });

      const removeButton = card.querySelector('[data-remove-vehicle]');
      if (removeButton) {
        removeButton.addEventListener('click', () => {
          const list = card.closest('[data-vehicle-list]');
          card.remove();
          if (list) {
            updateVehicleIndices(list);
          }
        });
      }
    };

    const addVehicle = (owner) => {
      const list = vehicleLists[owner];
      if (!list || !vehicleTemplate) {
        return;
      }
      const fragment = vehicleTemplate.content.cloneNode(true);
      list.appendChild(fragment);
      const cards = list.querySelectorAll('[data-vehicle]');
      const card = cards[cards.length - 1];
      if (card) {
        setupVehicleCard(card);
        updateVehicleIndices(list);
      }
    };

    const collectVehicles = (list) => {
      if (!list) {
        return [];
      }
      const cards = Array.from(list.querySelectorAll('[data-vehicle]'));
      const vehicles = [];
      cards.forEach((card) => {
        const data = {};
        card.querySelectorAll('[data-field]').forEach((field) => {
          if (field.type === 'checkbox') {
            data[field.dataset.field] = field.checked;
          } else {
            data[field.dataset.field] = field.value.trim();
          }
        });
        const hasValue = Object.values(data).some(
          (value) => value === true || (typeof value === 'string' && value !== '')
        );
        if (hasValue) {
          vehicles.push(data);
        }
      });
      return vehicles;
    };

    const syncVehiclePayloads = () => {
      if (fullVehicleInput) {
        fullVehicleInput.value = JSON.stringify(collectVehicles(vehicleLists.full));
      }
      if (associateVehicleInput) {
        associateVehicleInput.value = JSON.stringify(collectVehicles(vehicleLists.associate));
      }
    };

    const getAssociateAddValue = () => {
      const selected = associateAddInputs.find((input) => input.checked);
      return selected ? selected.value : '';
    };

    const isFullSelected = () => Boolean(fullToggle && fullToggle.checked);
    const isAssociateSelected = () => Boolean(associateToggle && associateToggle.checked);

    const periodOrder = ['ONE_THIRD', 'TWO_THIRDS', 'ONE_YEAR', 'TWO_ONE_THIRDS', 'TWO_TWO_THIRDS', 'THREE_YEARS'];
    const periodOptions = Object.entries(periodDefinitions || {}).map(([key, meta]) => ({
      key,
      label: meta.label || key,
      join_after: meta.join_after || null,
    })).sort((a, b) => {
      const aIndex = periodOrder.indexOf(a.key);
      const bIndex = periodOrder.indexOf(b.key);
      const aRank = aIndex === -1 ? Number.MAX_SAFE_INTEGER : aIndex;
      const bRank = bIndex === -1 ? Number.MAX_SAFE_INTEGER : bIndex;
      return aRank - bRank;
    });
    const setError = (message) => {
      if (!membershipError) {
        return;
      }
      if (!message) {
        membershipError.textContent = '';
        membershipError.hidden = true;
        return;
      }
      membershipError.textContent = message;
      membershipError.hidden = false;
    };

    const getJoinAfterFilter = () => {
      const today = new Date();
      const month = today.getMonth();
      if (month >= 3 && month <= 6) {
        return 'april';
      }
      if (month >= 11 || month <= 2) {
        return 'december';
      }
      return null;
    };

    const getAvailablePeriodKeys = (membershipType, magazineType) => {
      const matrix = pricingData && pricingData.matrix ? pricingData.matrix : {};
      if (magazineType && matrix[magazineType] && matrix[magazineType][membershipType]) {
        return Object.keys(matrix[magazineType][membershipType]);
      }
      const keys = new Set();
      Object.values(matrix).forEach((magazine) => {
        if (magazine && magazine[membershipType]) {
          Object.keys(magazine[membershipType]).forEach((key) => keys.add(key));
        }
      });
      return Array.from(keys);
    };

    const getFullMagazineType = () => {
      const selected = form.querySelector('input[name="full_magazine_type"]:checked');
      if (selected && selected.value) {
        return selected.value;
      }
      const matrix = pricingData && pricingData.matrix ? pricingData.matrix : {};
      if (matrix.PRINTED) {
        return 'PRINTED';
      }
      return Object.keys(matrix)[0] || 'PRINTED';
    };

    const getAssociateMagazineType = () => {
      if (isFullSelected()) {
        return getFullMagazineType();
      }
      const matrix = pricingData && pricingData.matrix ? pricingData.matrix : {};
      if (matrix.PRINTED) {
        return 'PRINTED';
      }
      return Object.keys(matrix)[0] || 'PRINTED';
    };

    const populatePeriodSelect = (select, hintEl, membershipType) => {
      if (!select) {
        return;
      }
      const magazineType = membershipType === 'FULL' ? getFullMagazineType() : getAssociateMagazineType();
      const availableKeys = getAvailablePeriodKeys(membershipType, magazineType);
      const joinAfter = getJoinAfterFilter();
      const previousValue = select.value;
      const filtered = periodOptions.filter((option) => {
        if (availableKeys.length && !availableKeys.includes(option.key)) {
          return false;
        }
        return !option.join_after || option.join_after === joinAfter;
      });
      select.innerHTML = '<option value="">Select a period</option>';
      filtered.forEach((option) => {
        const opt = document.createElement('option');
        opt.value = option.key;
        opt.textContent = option.label;
        select.appendChild(opt);
      });
      if (previousValue && filtered.some((option) => option.key === previousValue)) {
        select.value = previousValue;
      }
      if (hintEl) {
        if (joinAfter === 'december') {
          hintEl.textContent = 'Pro-rata options shown for joins after 1st December.';
        } else if (joinAfter === 'april') {
          hintEl.textContent = 'Pro-rata options shown for joins after 1st April.';
        } else {
          hintEl.textContent = 'Standard periods shown for August to November joins.';
        }
      }
    };

    const updateMembershipVisibility = () => {
      const fullSelected = isFullSelected();
      const associateSelected = isAssociateSelected();
      if (fullOptionsBlock) {
        fullOptionsBlock.hidden = !fullSelected;
      }
      if (!associateSelected) {
        associateAddInputs.forEach((input) => {
          input.checked = false;
        });
      }
      if (membershipError) {
        membershipError.hidden = true;
        membershipError.textContent = '';
      }
      if (associateError) {
        associateError.hidden = true;
        associateError.textContent = '';
      }
    };

    const formatCurrency = (cents) => {
      if (typeof cents !== 'number') {
        return 'Not selected';
      }
      return `$${(cents / 100).toFixed(2)}`;
    };

    const calculateProcessingFeeCents = (baseCents) => {
      const enabled = storeSettings && storeSettings.stripe_fee_enabled === 1;
      const percent = Number(storeSettings && storeSettings.stripe_fee_percent ? storeSettings.stripe_fee_percent : 0);
      const fixed = Number(storeSettings && storeSettings.stripe_fee_fixed ? storeSettings.stripe_fee_fixed : 0);
      if (!enabled || baseCents <= 0 || (percent <= 0 && fixed <= 0)) {
        return 0;
      }
      const rate = percent / 100;
      if (rate >= 1) {
        return 0;
      }
      const baseDollars = baseCents / 100;
      const fee = (rate * baseDollars + fixed) / (1 - rate);
      const rounded = Math.round(fee * 100) / 100;
      return Math.round(rounded * 100);
    };

    const updateSummary = () => {
      const pricingMatrix = pricingData && pricingData.matrix ? pricingData.matrix : {};
      const fullSelected = isFullSelected();
      const associateSelected = isAssociateSelected();
      const associateAdd = getAssociateAddValue();
      const fullMagazineInput = fullSelected
        ? form.querySelector('input[name="full_magazine_type"]:checked')
        : null;
      const fullMagazine = fullMagazineInput ? fullMagazineInput.value : '';
      const fullPeriod = periodSelects.full ? periodSelects.full.value : '';
      const associatePeriod = periodSelects.associate ? periodSelects.associate.value : '';
      const associateMagazine = fullSelected && fullMagazine ? fullMagazine : 'PRINTED';

      let fullPrice = null;
      let associatePrice = null;
      if (fullSelected && fullMagazine && fullPeriod) {
        fullPrice = pricingMatrix[fullMagazine]
          && pricingMatrix[fullMagazine].FULL
          ? pricingMatrix[fullMagazine].FULL[fullPeriod]
          : undefined;
      }
      if (associateSelected && associateAdd === 'yes' && associatePeriod) {
        associatePrice = pricingMatrix[associateMagazine]
          && pricingMatrix[associateMagazine].ASSOCIATE
          ? pricingMatrix[associateMagazine].ASSOCIATE[associatePeriod]
          : undefined;
      }

      if (summaryFullPrice) {
        summaryFullPrice.textContent = fullSelected && fullPrice !== undefined ? formatCurrency(fullPrice) : 'Not selected';
      }
      if (summaryFullDetail) {
        if (fullSelected && fullPeriod && fullMagazine) {
          const label = periodDefinitions && periodDefinitions[fullPeriod] && periodDefinitions[fullPeriod].label
            ? periodDefinitions[fullPeriod].label
            : fullPeriod;
          const magazineLabel = fullMagazine === 'PDF' ? 'PDF only' : 'Printed Wings';
          summaryFullDetail.textContent = `${magazineLabel} · ${label}`;
        } else {
          summaryFullDetail.textContent = 'Not selected';
        }
      }
      if (summaryAssociatePrice) {
        summaryAssociatePrice.textContent = associateSelected && associateAdd === 'yes' && associatePrice !== undefined
          ? formatCurrency(associatePrice)
          : 'Not selected';
      }
      if (summaryAssociateDetail) {
        if (associateSelected && associateAdd === 'yes' && associatePeriod) {
          const label = periodDefinitions && periodDefinitions[associatePeriod] && periodDefinitions[associatePeriod].label
            ? periodDefinitions[associatePeriod].label
            : associatePeriod;
          summaryAssociateDetail.textContent = label;
        } else {
          summaryAssociateDetail.textContent = 'Not selected';
        }
      }
      if (summaryTotal) {
        const baseTotal = (typeof fullPrice === 'number' ? fullPrice : 0)
          + (typeof associatePrice === 'number' ? associatePrice : 0);
        const processingFee = isCardMethod() ? calculateProcessingFeeCents(baseTotal) : 0;
        if (summaryProcessingRow && summaryProcessingFee) {
          summaryProcessingRow.hidden = processingFee <= 0;
          summaryProcessingFee.textContent = processingFee > 0 ? `$${(processingFee / 100).toFixed(2)}` : '$0.00';
        }
        const total = baseTotal + processingFee;
        summaryTotal.textContent = `$${(total / 100).toFixed(2)}`;
      }
      if (isCardMethod()) {
        markPaymentIntentRefresh();
      }
    };

    const paymentPanels = Array.from(document.querySelectorAll('[data-payment-panel]'));
    const loadStripeJs = (() => {
      let loader = null;
      return () => {
        if (window.Stripe) {
          return Promise.resolve(window.Stripe);
        }
        if (loader) {
          return loader;
        }
        loader = new Promise((resolve, reject) => {
          const script = document.createElement('script');
          script.src = 'https://js.stripe.com/v3/';
          script.onload = () => {
            if (window.Stripe) {
              resolve(window.Stripe);
            } else {
              reject(new Error('Stripe.js failed to initialize.'));
            }
          };
          script.onerror = () => reject(new Error('Unable to load Stripe.js.'));
          document.head.appendChild(script);
        });
        return loader;
      };
    })();
    const getSelectedPaymentMethod = () => {
      const methodInput = form.querySelector('input[name="payment_method"]:checked');
      return methodInput ? methodInput.value : '';
    };
    const showPaymentMethodError = (message) => {
      if (!paymentMethodError) {
        return;
      }
      if (!message) {
        paymentMethodError.textContent = '';
        paymentMethodError.hidden = true;
        return;
      }
      paymentMethodError.textContent = message;
      paymentMethodError.hidden = false;
    };
    const updatePaymentExtras = (method) => {
      const isCard = method === 'card';
      if (bankTransferDetails) {
        bankTransferDetails.hidden = method !== 'bank_transfer';
      }
      if (stripePaymentNote) {
        stripePaymentNote.hidden = !isCard;
      }
      if (paymentElementContainer) {
        paymentElementContainer.hidden = !isCard;
      }
      if (stripePaymentError && !isCard) {
        stripePaymentError.hidden = true;
        stripePaymentError.textContent = '';
      }
      if (method) {
        showPaymentMethodError('');
      }
    };
    const updatePaymentPanels = () => {
      const method = getSelectedPaymentMethod();
      paymentPanels.forEach((panel) => {
        panel.hidden = panel.dataset.paymentPanel !== method;
      });
      updatePaymentExtras(method);
    };

    const cardPaymentPanel = form.querySelector('[data-payment-panel="card"]');
    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let clientSecret = null;
    let paymentIntentId = null;
    let stripeConfig = null;
    let needsPaymentIntentRefresh = true;
    let stripeInitializing = false;
    let processingStripePayment = false;

    const createStripeRequestId = () => `apply_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

    const buildStripePaymentIntentPayload = () => {
      const fullMagazine = form.querySelector('input[name="full_magazine_type"]:checked')?.value || 'PRINTED';
      const payload = {
        membership_full: isFullSelected() ? 1 : 0,
        membership_associate: isAssociateSelected() ? 1 : 0,
        associate_add: getAssociateAddValue(),
        full_magazine_type: fullMagazine,
        full_period_key: periodSelects.full ? periodSelects.full.value : '',
        associate_period_key: periodSelects.associate ? periodSelects.associate.value : '',
        first_name: form.querySelector('input[name="first_name"]')?.value || '',
        last_name: form.querySelector('input[name="last_name"]')?.value || '',
        email: form.querySelector('input[name="email"]')?.value || '',
      };
      return payload;
    };

    const showStripeError = (message) => {
      if (!stripePaymentError) {
        return;
      }
      if (!message) {
        stripePaymentError.textContent = '';
        stripePaymentError.hidden = true;
        return;
      }
      stripePaymentError.textContent = message;
      stripePaymentError.hidden = false;
    };

    const showFormError = (message) => {
      if (!formErrorAlert) {
        return;
      }
      if (!message) {
        formErrorAlert.textContent = '';
        formErrorAlert.hidden = true;
        return;
      }
      formErrorAlert.textContent = message;
      formErrorAlert.hidden = false;
    };

    const loadStripeConfig = async () => {
      if (stripeConfig) {
        return stripeConfig;
      }
      const response = await fetch('/api/stripe/config');
      if (!response.ok) {
        throw new Error('Unable to load Stripe configuration.');
      }
      const data = await response.json();
      stripeConfig = data;
      return data;
    };

    const initStripeElements = async (secret) => {
      await loadStripeJs();
      const config = await loadStripeConfig();
      if (!config || !config.publishableKey) {
        throw new Error('Stripe is not configured.');
      }
      if (!stripe) {
        stripe = Stripe(config.publishableKey);
      }
      if (!elements || clientSecret !== secret) {
        if (paymentElement) {
          paymentElement.unmount();
        }
        elements = stripe.elements({
          clientSecret: secret,
          appearance: { theme: 'stripe' },
        });
        const wallets = {
          applePay: config.paymentMethods && config.paymentMethods.applePay ? 'auto' : 'never',
          googlePay: config.paymentMethods && config.paymentMethods.googlePay ? 'auto' : 'never',
        };
        paymentElement = elements.create('payment', { wallets });
        if (paymentElementContainer) {
          paymentElement.mount(paymentElementContainer);
        }
      }
      clientSecret = secret;
    };

    const markPaymentIntentRefresh = () => {
      needsPaymentIntentRefresh = true;
      if (paymentElementContainer) {
        paymentElementContainer.hidden = true;
      }
    };

    const ensureStripePaymentIntent = async () => {
      if (!needsPaymentIntentRefresh && clientSecret) {
        return paymentIntentId;
      }
      if (stripeInitializing) {
        return paymentIntentId;
      }
      stripeInitializing = true;
      showStripeError('');
      const payload = buildStripePaymentIntentPayload();
      const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
      const requestBody = {
        ...payload,
        request_id: createStripeRequestId(),
      };
      let response;
      let data;
      try {
        response = await fetch('/api/stripe/create-application-payment-intent', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          credentials: 'include',
          body: JSON.stringify(requestBody),
        });
        data = await response.json();
        if (!response.ok || data.error) {
          throw new Error(data.error || 'Unable to prepare Stripe payment.');
        }
      } finally {
        stripeInitializing = false;
      }
      clientSecret = data.client_secret;
      paymentIntentId = data.payment_intent_id || null;
      await initStripeElements(clientSecret);
      needsPaymentIntentRefresh = false;
      if (paymentElementContainer) {
        paymentElementContainer.hidden = false;
      }
      return paymentIntentId;
    };

    const isCardMethod = () => getSelectedPaymentMethod() === 'card';

    const prepareStripeForSummary = () => {
      if (!isCardMethod()) {
        return;
      }
      if (stripeInitializing) {
        return;
      }
      ensureStripePaymentIntent().catch((error) => {
        showStripeError(error.message || 'Unable to initialize Stripe payment.');
      });
    };

    const submitApplicationViaAjax = async (intentId) => {
      const ajaxForm = new FormData(form);
      ajaxForm.set('payment_method', 'card');
      ajaxForm.set('payment_intent_id', intentId || '');
      ajaxForm.set('ajax', '1');
      const response = await fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'include',
        body: ajaxForm,
      });
      const data = await response.json();
      if (!response.ok || data.error) {
        throw new Error(data.error || 'Unable to submit application.');
      }
      window.location.href = `${window.location.origin}${window.location.pathname}?submitted=1`;
    };

    const handleStripeReturn = async () => {
      const params = new URLSearchParams(window.location.search);
      const intentSecret = params.get('payment_intent_client_secret');
      if (!intentSecret) {
        return;
      }
      try {
        const config = await loadStripeConfig();
        if (!config || !config.publishableKey) {
          throw new Error('Stripe is not configured.');
        }
        if (!stripe) {
          stripe = Stripe(config.publishableKey);
        }
        const { paymentIntent } = await stripe.retrievePaymentIntent(intentSecret);
        if (!paymentIntent) {
          throw new Error('Unable to verify Stripe payment.');
        }
        if (paymentIntent.status === 'succeeded') {
          await submitApplicationViaAjax(paymentIntent.id);
          return;
        }
        if (paymentIntent.status === 'processing') {
          showStripeError('Your payment is processing. Please wait a moment and try again.');
          return;
        }
        showStripeError('Payment was not completed. Please try again.');
      } catch (error) {
        showStripeError(error.message || 'Unable to verify Stripe payment.');
      }
    };

    const isFieldRequired = (field) => {
      const condition = field.dataset.requiredWhen;
      if (!condition) {
        return true;
      }
      if (condition === 'full') {
        return isFullSelected();
      }
      if (condition === 'associate') {
        return isAssociateSelected();
      }
      if (condition === 'associate_yes') {
        return isAssociateSelected() && getAssociateAddValue() === 'yes';
      }
      if (condition === 'associate_only') {
        return isAssociateSelected() && !isFullSelected();
      }
      return true;
    };

    const getVisibleSteps = () => {
      const associateSelected = isAssociateSelected();
      const associateAdd = getAssociateAddValue();
      return steps.filter((step) => {
        const key = step.dataset.stepKey;
        if (key === 'associate-question') {
          return associateSelected;
        }
        if (key === 'associate-details') {
          return associateSelected && associateAdd === 'yes';
        }
        return true;
      });
    };

    const updateStep = (index) => {
      const visibleSteps = getVisibleSteps();
      if (!visibleSteps.length) {
        return;
      }
      currentStepIndex = Math.min(Math.max(index, 0), visibleSteps.length - 1);
      steps.forEach((step) => {
        step.hidden = true;
        step.setAttribute('aria-hidden', 'true');
        step.querySelectorAll('[data-required]').forEach((field) => {
          field.required = false;
        });
      });

      visibleSteps.forEach((step, idx) => {
        const isActive = idx === currentStepIndex;
        step.hidden = !isActive;
        step.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        step.querySelectorAll('[data-required]').forEach((field) => {
          field.required = isActive && isFieldRequired(field);
        });
      });

      const percent = ((currentStepIndex + 1) / visibleSteps.length) * 100;
      if (progressFill) {
        progressFill.style.width = `${percent}%`;
      }
      if (stepText) {
        stepText.textContent = `Step ${currentStepIndex + 1} of ${visibleSteps.length}: ${visibleSteps[currentStepIndex].dataset.title}`;
      }
      if (nextText) {
        if (currentStepIndex < visibleSteps.length - 1) {
          nextText.textContent = `Next: ${visibleSteps[currentStepIndex + 1].dataset.title}`;
        } else {
          nextText.textContent = 'Ready to submit';
        }
      }

      const activeStep = visibleSteps[currentStepIndex];
      const prevButton = activeStep.querySelector('[data-action="prev"]');
      const nextButton = activeStep.querySelector('[data-action="next"]');
      const submitButton = activeStep.querySelector('[data-action="submit"]');
      if (prevButton) {
        prevButton.hidden = currentStepIndex === 0;
      }
      if (nextButton) {
        nextButton.hidden = currentStepIndex === visibleSteps.length - 1;
      }
      if (submitButton) {
        submitButton.hidden = currentStepIndex !== visibleSteps.length - 1;
        submitButton.disabled = currentStepIndex !== visibleSteps.length - 1;
      }
      prepareStripeForSummary();
    };

    const validateStep = () => {
      const visibleSteps = getVisibleSteps();
      const activeStep = visibleSteps[currentStepIndex];
      if (!activeStep) {
        return true;
      }
      const stepKey = activeStep.dataset.stepKey;
      if (stepKey === 'membership') {
        if (!isFullSelected() && !isAssociateSelected()) {
          if (membershipError) {
            membershipError.textContent = 'Select at least one membership type.';
            membershipError.hidden = false;
          }
          return false;
        }
      }
      if (stepKey === 'associate-question') {
        const associateAdd = getAssociateAddValue();
        if (isAssociateSelected() && associateAdd === 'no') {
          if (associateError) {
            associateError.textContent = 'Select Yes and enter associate details, or untick Associate Membership.';
            associateError.hidden = false;
          }
          return false;
        }
      }
      const fields = Array.from(activeStep.querySelectorAll('input, select, textarea'));
      for (const field of fields) {
        if (field.required && !field.checkValidity()) {
          field.reportValidity();
          return false;
        }
      }
      return true;
    };

    form.addEventListener('click', (event) => {
      const action = event.target.closest('[data-action]');
      if (!action) {
        return;
      }

      if (action.dataset.action === 'next') {
        if (!validateStep()) {
          return;
        }
        updateStep(currentStepIndex + 1);
      } else if (action.dataset.action === 'prev') {
        updateStep(currentStepIndex - 1);
      }

      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    form.addEventListener('submit', async (event) => {
      syncVehiclePayloads();
      const method = getSelectedPaymentMethod();
      if (!method) {
        event.preventDefault();
        showPaymentMethodError('Please select a payment method before submitting.');
        return;
      }
      if (method === 'card') {
        event.preventDefault();
        showPaymentMethodError('');
        showStripeError('');
        showFormError('');
        if (processingStripePayment) {
          return;
        }
        processingStripePayment = true;
        if (finalSubmitButton) {
          finalSubmitButton.disabled = true;
          finalSubmitButton.dataset.originalText = finalSubmitButton.dataset.originalText || finalSubmitButton.textContent;
          finalSubmitButton.textContent = 'Processing...';
        }
        try {
          await ensureStripePaymentIntent();
          if (!stripe || !elements) {
            throw new Error('Stripe is not ready.');
          }
          const returnUrl = `${window.location.origin}${window.location.pathname}`;
          const { error, paymentIntent } = await stripe.confirmPayment({
            elements,
            confirmParams: {
              return_url: returnUrl,
            },
            redirect: 'if_required',
          });
          if (error) {
            throw error;
          }
          const intentId = (paymentIntent && paymentIntent.id) ? paymentIntent.id : paymentIntentId;
          await submitApplicationViaAjax(intentId);
        } catch (apiError) {
          const message = (apiError && apiError.message) ? apiError.message : 'Stripe payment failed.';
          showStripeError(message);
          showFormError(message);
          if (finalSubmitButton) {
            finalSubmitButton.disabled = false;
            finalSubmitButton.textContent = finalSubmitButton.dataset.originalText || 'Submit application';
          }
          processingStripePayment = false;
        }
      }
    });

    form.querySelectorAll('[data-add-vehicle]').forEach((button) => {
      button.addEventListener('click', () => {
        addVehicle(button.dataset.addVehicle);
      });
    });

    if (fullToggle) {
      fullToggle.addEventListener('change', () => {
        updateMembershipVisibility();
        populatePeriodSelect(periodSelects.full, periodHints.full, 'FULL');
        populatePeriodSelect(periodSelects.associate, periodHints.associate, 'ASSOCIATE');
        updateSummary();
        updateStep(currentStepIndex);
      });
    }
    if (associateToggle) {
      associateToggle.addEventListener('change', () => {
        updateMembershipVisibility();
        populatePeriodSelect(periodSelects.full, periodHints.full, 'FULL');
        populatePeriodSelect(periodSelects.associate, periodHints.associate, 'ASSOCIATE');
        updateSummary();
        updateStep(currentStepIndex);
      });
    }
    associateAddInputs.forEach((input) => {
      input.addEventListener('change', () => {
        if (associateError) {
          associateError.hidden = true;
          associateError.textContent = '';
        }
        updateSummary();
        updateStep(currentStepIndex);
      });
    });

    form.querySelectorAll('input[name="full_magazine_type"]').forEach((input) => {
      input.addEventListener('change', () => {
        populatePeriodSelect(periodSelects.full, periodHints.full, 'FULL');
        populatePeriodSelect(periodSelects.associate, periodHints.associate, 'ASSOCIATE');
        updateSummary();
      });
    });

    Object.values(periodSelects).forEach((select) => {
      if (select) {
        select.addEventListener('change', updateSummary);
      }
    });

    form.querySelectorAll('input[name="payment_method"]').forEach((input) => {
      input.addEventListener('change', () => {
        updatePaymentPanels();
        updateSummary();
        if (input.checked && input.value === 'card') {
          prepareStripeForSummary();
        }
      });
    });

    Object.keys(vehicleLists).forEach((key) => {
      if (vehicleLists[key] && vehicleLists[key].children.length === 0) {
        addVehicle(key);
      }
    });

    populatePeriodSelect(periodSelects.full, periodHints.full, 'FULL');
    populatePeriodSelect(periodSelects.associate, periodHints.associate, 'ASSOCIATE');
    updateMembershipVisibility();
    updateStep(0);
    updatePaymentPanels();
    updateSummary();
    handleStripeReturn();
  });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>
