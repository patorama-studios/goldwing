<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\BaseUrlService;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\MembershipMigrationService;
use App\Services\MembershipPricingService;
use App\Services\MembershipService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\StripeService;
use App\Services\Validator;

$pdo = Database::connection();
$token = trim($_GET['token'] ?? '');
$tokenRow = $token !== '' ? MembershipMigrationService::getByToken($token) : null;
$member = null;
$membershipTypes = [];
$error = '';
$message = '';
$success = isset($_GET['success']);
$cancelled = isset($_GET['cancel']);

if ($tokenRow) {
    $member = MemberRepository::findById((int) $tokenRow['member_id']);
}

try {
    $stmt = $pdo->prepare('SELECT id, name FROM membership_types WHERE is_active = 1 ORDER BY name');
    $stmt->execute();
    $membershipTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $membershipTypes = [];
}

$globalEnabled = (bool) SettingsService::getGlobal('membership.manual_migration_enabled', true);
$expiryDays = (int) SettingsService::getGlobal('membership.manual_migration_expiry_days', 14);
$expiryDays = max(1, $expiryDays);

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

function formatCurrency(?int $cents, string $currency = 'AUD'): string
{
    $cents = $cents ?? 0;
    $prefix = $currency === 'AUD' ? 'A$' : ($currency . ' ');
    return $prefix . number_format($cents / 100, 2);
}

$tokenStatus = 'invalid';
if (!$tokenRow) {
    $tokenStatus = 'invalid';
} elseif (!$globalEnabled) {
    $tokenStatus = 'disabled_global';
} elseif (!$member) {
    $tokenStatus = 'invalid';
} elseif (MembershipMigrationService::isDisabledForMember($member)) {
    $tokenStatus = 'disabled_member';
} elseif (!empty($tokenRow['disabled_at'])) {
    $tokenStatus = 'disabled_token';
} elseif (!empty($tokenRow['used_at'])) {
    $tokenStatus = 'used';
} elseif (strtotime($tokenRow['expires_at']) <= time()) {
    $tokenStatus = 'expired';
} else {
    $tokenStatus = 'active';
}

$memberTypeId = (int) ($member['membership_type_id'] ?? 0);
$memberTypeName = $member['membership_type_name'] ?? '';
$memberTypeCode = $memberTypeName ? mapMembershipTypeName((string) $memberTypeName) : ($member['member_type'] ?? 'FULL');

$lifeMember = $memberTypeCode === 'LIFE';
$memberLifeLocked = $lifeMember;
$periodKey = 'ONE_YEAR';
$periodDefinitions = MembershipPricingService::periodDefinitions();
$periodLabel = $periodDefinitions[$periodKey]['label'] ?? '1 Year';
$magazineType = (!empty($member['wings_preference']) && strtolower((string) $member['wings_preference']) === 'digital') || !empty($member['exclude_printed'])
    ? 'PDF'
    : 'PRINTED';
$priceCents = MembershipPricingService::getPriceCents($magazineType, $memberTypeCode === 'ASSOCIATE' ? 'ASSOCIATE' : 'FULL', $periodKey);
$pricingCurrency = MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } elseif ($tokenStatus !== 'active') {
        $error = 'This migration link is no longer valid.';
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addressLine1 = trim($_POST['address_line1'] ?? '');
        $addressLine2 = trim($_POST['address_line2'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $termsAccepted = isset($_POST['terms_accepted']);
        $selectedTypeId = (int) ($_POST['membership_type_id'] ?? $memberTypeId);

        if (!Validator::required($firstName) || !Validator::required($lastName) || !Validator::email($email)) {
            $error = 'Please provide your name and a valid email address.';
        } elseif (!$termsAccepted) {
            $error = 'Please accept the terms and conditions.';
        }

        $selectedTypeName = $memberTypeName;
        $selectedTypeCode = $memberTypeCode;
        foreach ($membershipTypes as $type) {
            if ((int) $type['id'] === $selectedTypeId) {
                $selectedTypeName = $type['name'];
                $selectedTypeCode = mapMembershipTypeName((string) $type['name']);
                break;
            }
        }

        if ($selectedTypeCode === 'LIFE') {
            $lifeMember = true;
        }
        if (!$error) {
            if ($memberLifeLocked && $selectedTypeCode !== 'LIFE') {
                $error = 'Life memberships cannot be changed via this link.';
            }
            if (!$memberLifeLocked && $selectedTypeCode === 'LIFE') {
                $error = 'This membership type cannot be selected.';
            }
        }

        if (!$error) {
            $stmt = $pdo->prepare('UPDATE members SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone, address_line1 = :address1, address_line2 = :address2, city = :city, state = :state, postal_code = :postal, country = :country, membership_type_id = :membership_type_id, member_type = :member_type, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'address1' => $addressLine1 !== '' ? $addressLine1 : null,
                'address2' => $addressLine2 !== '' ? $addressLine2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'postal' => $postalCode !== '' ? $postalCode : null,
                'country' => $country !== '' ? $country : null,
                'membership_type_id' => $selectedTypeId ?: null,
                'member_type' => $selectedTypeCode,
                'id' => $member['id'],
            ]);

            if ($selectedTypeCode === 'LIFE') {
                $stmt = $pdo->prepare('UPDATE members SET status = "active" WHERE id = :id');
                $stmt->execute(['id' => $member['id']]);

                $stmt = $pdo->prepare('INSERT INTO membership_periods (member_id, term, start_date, end_date, status, paid_at, created_at) VALUES (:member_id, :term, :start_date, :end_date, :status, NOW(), NOW())');
                $stmt->execute([
                    'member_id' => $member['id'],
                    'term' => 'LIFE',
                    'start_date' => date('Y-m-d'),
                    'end_date' => null,
                    'status' => 'ACTIVE',
                ]);

                $stmt = $pdo->prepare('INSERT INTO payments (member_id, type, description, amount, status, payment_method, order_source, order_reference, created_at) VALUES (:member_id, :type, :description, :amount, :status, :payment_method, :order_source, :order_reference, NOW())');
                $stmt->execute([
                    'member_id' => $member['id'],
                    'type' => 'membership',
                    'description' => 'Life membership activation',
                    'amount' => 0,
                    'status' => 'PAID',
                    'payment_method' => 'Life Member',
                    'order_source' => 'Manual Migration',
                    'order_reference' => null,
                ]);

                MembershipMigrationService::markUsed((int) $tokenRow['id']);

                NotificationService::dispatch('membership_activated_confirmation', [
                    'primary_email' => $email,
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'member_name' => trim($firstName . ' ' . $lastName),
                    'membership_type' => 'Life Member',
                    'renewal_date' => 'N/A',
                    'member_id' => $member['id'],
                ]);

                ActivityLogger::log('member', null, $member['id'], 'membership.migration_completed', [
                    'membership_type' => 'LIFE',
                    'source' => 'manual_migration',
                ]);

                $message = 'Your life membership has been activated. Welcome aboard!';
                $tokenStatus = 'used';
            } else {
                $pendingStmt = $pdo->prepare('SELECT * FROM membership_periods WHERE member_id = :member_id AND status = "PENDING_PAYMENT" ORDER BY created_at DESC LIMIT 1');
                $pendingStmt->execute(['member_id' => $member['id']]);
                $pendingPeriod = $pendingStmt->fetch();

                $term = $pendingPeriod['term'] ?? '1Y';
                $priceKey = $selectedTypeCode . '_' . $term;
                $prices = SettingsService::getGlobal('payments.membership_prices', []);
                $priceId = is_array($prices) ? ($prices[$priceKey] ?? '') : '';

                if ($priceId === '' && $term !== '1Y') {
                    $term = '1Y';
                    $priceKey = $selectedTypeCode . '_' . $term;
                    $priceId = is_array($prices) ? ($prices[$priceKey] ?? '') : '';
                }

                if (!$pendingPeriod || $term !== ($pendingPeriod['term'] ?? '')) {
                    $periodId = MembershipService::createMembershipPeriod((int) $member['id'], $term, date('Y-m-d'));
                } else {
                    $periodId = (int) $pendingPeriod['id'];
                }

                if ($priceId === '') {
                    $error = 'Membership pricing is not configured. Please contact support.';
                } else {
                    $successUrl = BaseUrlService::buildUrl('/migrate.php?token=' . urlencode($token) . '&success=1');
                    $cancelUrl = BaseUrlService::buildUrl('/migrate.php?token=' . urlencode($token) . '&cancel=1');
                    $session = StripeService::createCheckoutSessionForPrice($priceId, $email, $successUrl, $cancelUrl, [
                        'period_id' => $periodId,
                        'member_id' => $member['id'],
                    ]);

                    if (!$session || empty($session['url'])) {
                        $error = 'Unable to start payment. Please try again later.';
                    } else {
                        MembershipMigrationService::markUsed((int) $tokenRow['id']);
                        ActivityLogger::log('member', null, $member['id'], 'membership.migration_started', [
                            'membership_type' => $selectedTypeCode,
                            'period_id' => $periodId,
                            'source' => 'manual_migration',
                        ]);
                        header('Location: ' . $session['url']);
                        exit;
                    }
                }
            }
        }
    }
}

$pageTitle = 'Complete Membership Setup';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main form-page">
  <div class="container form-shell">
    <div class="form-header">
      <h1>Complete your membership setup</h1>
      <p>Confirm your details and finish your membership activation.</p>
    </div>

    <?php if ($message): ?>
      <div class="form-card form-card--wizard">
        <h2>Membership activated</h2>
        <p><?= e($message) ?></p>
        <a class="form-button primary" href="/">Return to home</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="form-alert error"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if ($cancelled): ?>
        <div class="form-alert error">Payment was cancelled. You can try again using this page.</div>
      <?php endif; ?>
      <?php if ($success && $tokenStatus === 'used'): ?>
        <div class="form-card form-card--wizard">
          <h2>Payment received</h2>
          <p>Your payment was successful. Your membership will be activated shortly.</p>
          <a class="form-button primary" href="/">Return to home</a>
        </div>
      <?php elseif ($tokenStatus !== 'active'): ?>
        <div class="form-card form-card--wizard">
          <h2>Link unavailable</h2>
          <p>
            <?php if ($tokenStatus === 'disabled_global'): ?>
              Manual migration is currently disabled. Please contact support.
            <?php elseif ($tokenStatus === 'disabled_member' || $tokenStatus === 'disabled_token'): ?>
              This migration link has been disabled. Please contact support for a new invitation.
            <?php elseif ($tokenStatus === 'expired'): ?>
              This migration link has expired. Please request a new invitation.
            <?php elseif ($tokenStatus === 'used'): ?>
              This migration link has already been used.
            <?php else: ?>
              This migration link is invalid.
            <?php endif; ?>
          </p>
          <a class="form-button primary" href="/">Return to home</a>
        </div>
      <?php else: ?>
        <form class="form-card form-card--wizard" method="post">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <?php if ($lifeMember): ?>
            <input type="hidden" name="membership_type_id" value="<?= e((string) $memberTypeId) ?>">
          <?php endif; ?>
          <div class="checkout-grid">
            <div class="checkout-main">
              <section class="form-step" data-step data-step-key="details" data-title="Member details">
                <h3>Confirm your details</h3>
                <p class="form-helper">Please review and update your contact information.</p>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">First name</span>
                    <input class="form-input" type="text" name="first_name" value="<?= e($member['first_name'] ?? '') ?>" required>
                  </label>
                  <label class="form-field">
                    <span class="form-label">Last name</span>
                    <input class="form-input" type="text" name="last_name" value="<?= e($member['last_name'] ?? '') ?>" required>
                  </label>
                </div>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">Email</span>
                    <input class="form-input" type="email" name="email" value="<?= e($member['email'] ?? '') ?>" required>
                  </label>
                  <label class="form-field">
                    <span class="form-label">Phone</span>
                    <input class="form-input" type="text" name="phone" value="<?= e($member['phone'] ?? '') ?>">
                  </label>
                </div>
                <label class="form-field">
                  <span class="form-label">Address line 1</span>
                  <input id="migrate_address_line1" data-google-autocomplete="address" data-google-autocomplete-city="#migrate_city" data-google-autocomplete-state="#migrate_state" data-google-autocomplete-postal="#migrate_postal_code" data-google-autocomplete-country="#migrate_country" class="form-input" type="text" name="address_line1" value="<?= e($member['address_line1'] ?? '') ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Address line 2</span>
                  <input class="form-input" type="text" name="address_line2" value="<?= e($member['address_line2'] ?? '') ?>">
                </label>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">City</span>
                    <input id="migrate_city" class="form-input" type="text" name="city" value="<?= e($member['city'] ?? '') ?>">
                  </label>
                  <label class="form-field">
                    <span class="form-label">State</span>
                    <input id="migrate_state" class="form-input" type="text" name="state" value="<?= e($member['state'] ?? '') ?>">
                  </label>
                </div>
                <div class="form-grid two">
                  <label class="form-field">
                    <span class="form-label">Postal code</span>
                    <input id="migrate_postal_code" class="form-input" type="text" name="postal_code" value="<?= e($member['postal_code'] ?? '') ?>">
                  </label>
                  <label class="form-field">
                    <span class="form-label">Country</span>
                    <input id="migrate_country" class="form-input" type="text" name="country" value="<?= e($member['country'] ?? '') ?>">
                  </label>
                </div>
              </section>

              <section class="form-step" data-step data-step-key="membership" data-title="Membership">
                <h3>Confirm membership</h3>
                <p class="form-helper">Review your membership type and accept the terms.</p>
                <?php if ($lifeMember): ?>
                  <div class="form-field">
                    <span class="form-label">Membership type</span>
                    <div class="form-input" aria-readonly="true">Life Member</div>
                  </div>
                <?php else: ?>
                  <label class="form-field">
                    <span class="form-label">Membership type</span>
                    <select class="form-input" name="membership_type_id">
                      <?php foreach ($membershipTypes as $type): ?>
                        <?php $typeCode = mapMembershipTypeName((string) $type['name']); ?>
                        <?php if ($typeCode === 'LIFE') { continue; } ?>
                        <option value="<?= e((string) $type['id']) ?>" <?= (int) $type['id'] === $memberTypeId ? 'selected' : '' ?>>
                          <?= e($type['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <div class="form-field">
                    <span class="form-label">Membership term</span>
                    <div class="form-input" aria-readonly="true"><?= e($periodLabel) ?></div>
                  </div>
                <?php endif; ?>

                <label class="form-checkbox">
                  <input type="checkbox" name="terms_accepted" value="1" required>
                  I accept the terms and conditions.
                </label>
              </section>

              <div class="form-actions">
                <button class="form-button primary" type="submit">Continue</button>
              </div>
            </div>

            <aside class="checkout-summary">
              <h4>Membership summary</h4>
              <div class="summary-row">
                <span>Membership type</span>
                <strong><?= e($lifeMember ? 'Life Member' : ($memberTypeName ?: 'Member')) ?></strong>
              </div>
              <?php if ($lifeMember): ?>
                <div class="summary-row">
                  <span>Renewal</span>
                  <strong>N/A</strong>
                </div>
                <div class="summary-row summary-total">
                  <span>Total</span>
                  <strong><?= e(formatCurrency(0, (string) $pricingCurrency)) ?></strong>
                </div>
                <p class="form-helper">No payment required for life membership.</p>
              <?php else: ?>
                <div class="summary-row">
                  <span>Term</span>
                  <strong><?= e($periodLabel) ?></strong>
                </div>
                <div class="summary-row">
                  <span>Magazine</span>
                  <strong><?= e($magazineType === 'PDF' ? 'Digital' : 'Printed') ?></strong>
                </div>
                <div class="summary-row summary-total">
                  <span>Total</span>
                  <strong><?= e(formatCurrency($priceCents, (string) $pricingCurrency)) ?></strong>
                </div>
                <p class="form-helper">Payment is processed securely by Stripe.</p>
              <?php endif; ?>
            </aside>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>
