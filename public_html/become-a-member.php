<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\StripeSettingsService;

$pdo = db();
$user = current_user();
$stripeSettings = StripeSettingsService::getSettings();
$prices = $stripeSettings['membership_prices'] ?? [];
if (!is_array($prices)) {
  $prices = [];
}
$allowBoth = !empty($stripeSettings['membership_allow_both_types']);
$defaultTerm = (string) ($stripeSettings['membership_default_term'] ?? '12M');
$show24 = !empty($prices['FULL_24']) || !empty($prices['ASSOCIATE_24']);

$member = null;
if (!empty($user['member_id'])) {
  $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
  $stmt->execute(['id' => $user['member_id']]);
  $member = $stmt->fetch();
}

$primaryName = $user['name'] ?? '';
if ($primaryName === '' && $member) {
  $primaryName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
}
$nameParts = preg_split('/\s+/', trim((string) $primaryName));
$firstDefault = $nameParts && count($nameParts) > 1 ? array_shift($nameParts) : (string) $primaryName;
$lastDefault = $nameParts && count($nameParts) > 1 ? implode(' ', $nameParts) : '';

$pageTitle = 'Become a Member';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main form-page">
  <div class="container form-shell">
    <div class="form-header">
      <h1>Become a Member</h1>
      <p>Choose your membership, add any associates, and pay securely with Stripe.</p>
    </div>

    <form id="membership-form" class="form-card" data-allow-both="<?= $allowBoth ? '1' : '0' ?>"
      data-guest="<?= $user ? '0' : '1' ?>">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="full_vehicle_payload" value="[]">
      <input type="hidden" name="associate_vehicle_payload" value="[]">

      <section class="form-section">
        <h3>Membership selection</h3>
        <div class="option-grid">
          <label class="option-card">
            <input type="checkbox" name="membership_full" value="1">
            <span class="option-card-body">
              <span class="option-card-title">Full Membership</span>
              <span class="option-card-subtitle">Primary member with voting rights.</span>
              <span class="option-card-check">Selected</span>
            </span>
          </label>
          <label class="option-card">
            <input type="checkbox" name="membership_associate" value="1">
            <span class="option-card-body">
              <span class="option-card-title">Associate Membership</span>
              <span class="option-card-subtitle">Add an associate member to your household.</span>
              <span class="option-card-check">Selected</span>
            </span>
          </label>
        </div>

        <label class="form-field">
          <span class="form-label">Membership term</span>
          <select class="form-select" name="membership_term">
            <option value="12M" <?= strtoupper($defaultTerm) === '12M' ? 'selected' : '' ?>>12 months</option>
            <?php if ($show24): ?>
              <option value="24M" <?= strtoupper($defaultTerm) === '24M' ? 'selected' : '' ?>>24 months</option>
            <?php endif; ?>
          </select>
        </label>

        <div class="form-field" data-associate-toggle hidden>
          <span class="form-label">Do you want to add an associate member?</span>
          <div class="form-grid two">
            <label class="option-card option-card--compact">
              <input type="radio" name="associate_add" value="yes">
              <span class="option-card-body">
                <span class="option-card-title">Yes</span>
                <span class="option-card-subtitle">Include associate details below.</span>
                <span class="option-card-check">Selected</span>
              </span>
            </label>
            <label class="option-card option-card--compact">
              <input type="radio" name="associate_add" value="no">
              <span class="option-card-body">
                <span class="option-card-title">No</span>
                <span class="option-card-subtitle">Skip associate details.</span>
                <span class="option-card-check">Selected</span>
              </span>
            </label>
          </div>
        </div>
      </section>

      <section class="form-section">
        <h3>Primary member details</h3>
        <div class="form-grid two">
          <label class="form-field">
            <span class="form-label">First name</span>
            <input class="form-input" name="first_name"
              value="<?= e(trim((string) ($_POST['first_name'] ?? $firstDefault))) ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Last name</span>
            <input class="form-input" name="last_name"
              value="<?= e(trim((string) ($_POST['last_name'] ?? $lastDefault))) ?>">
          </label>
        </div>
        <div class="form-grid two">
          <label class="form-field">
            <span class="form-label">Email</span>
            <input class="form-input" type="email" name="email"
              value="<?= e(trim((string) ($_POST['email'] ?? ($user['email'] ?? '')))) ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Phone (optional)</span>
            <input class="form-input" name="phone"
              value="<?= e(trim((string) ($_POST['phone'] ?? ($member['phone'] ?? '')))) ?>">
          </label>
        </div>

        <?php if (!$user): ?>
          <label class="form-field">
            <span class="form-label">Create a password</span>
            <input class="form-input" type="password" name="password" minlength="8">
            <span class="form-helper">At least 8 characters.</span>
          </label>
        <?php endif; ?>
      </section>

      <section class="form-section">
        <h3>Address</h3>
        <div class="form-grid two">
          <label class="form-field">
            <span class="form-label">Address line 1</span>
            <input class="form-input" name="address_line1"
              value="<?= e(trim((string) ($_POST['address_line1'] ?? ($member['address_line1'] ?? '')))) ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Address line 2</span>
            <input class="form-input" name="address_line2"
              value="<?= e(trim((string) ($_POST['address_line2'] ?? ($member['address_line2'] ?? '')))) ?>">
          </label>
        </div>
        <div class="form-grid two">
          <label class="form-field">
            <span class="form-label">City</span>
            <input class="form-input" name="city"
              value="<?= e(trim((string) ($_POST['city'] ?? ($member['city'] ?? '')))) ?>">
          </label>
          <label class="form-field">
            <span class="form-label">State</span>
            <input class="form-input" name="state"
              value="<?= e(trim((string) ($_POST['state'] ?? ($member['state'] ?? '')))) ?>">
          </label>
        </div>
        <div class="form-grid two">
          <label class="form-field">
            <span class="form-label">Postcode</span>
            <input class="form-input" name="postal_code"
              value="<?= e(trim((string) ($_POST['postal_code'] ?? ($member['postal_code'] ?? '')))) ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Country</span>
            <input class="form-input" name="country"
              value="<?= e(trim((string) ($_POST['country'] ?? ($member['country'] ?? 'Australia')))) ?>">
          </label>
        </div>
      </section>

      <section class="form-section">
        <h3>Primary bike details</h3>
        <p class="form-helper">Add bikes, trikes, sidecars, or trailers. Rego (Registration Number) fields are optional
          but recommended.</p>
        <div class="vehicle-list" data-vehicle-list="full"></div>
        <button class="form-button secondary" type="button" data-add-vehicle="full">Add another vehicle</button>
      </section>

      <section class="form-section" data-associate-section hidden>
        <h3>Associate member details</h3>
        <div class="form-grid two">
          <label class="form-field">
            <span class="form-label">First name</span>
            <input class="form-input" name="associate_first_name">
          </label>
          <label class="form-field">
            <span class="form-label">Last name</span>
            <input class="form-input" name="associate_last_name">
          </label>
        </div>
        <label class="form-field">
          <span class="form-label">Email (optional)</span>
          <input class="form-input" type="email" name="associate_email">
        </label>

        <h4>Associate bike details</h4>
        <div class="vehicle-list" data-vehicle-list="associate"></div>
        <button class="form-button secondary" type="button" data-add-vehicle="associate">Add another vehicle</button>
      </section>

      <section class="form-section">
        <h3>Payment</h3>
        <div id="membership-payment-element" class="mt-4"></div>
        <div class="form-alert error" id="membership-error" hidden></div>
        <p class="form-helper">Membership activates after Stripe confirms payment.</p>
      </section>

      <div class="form-actions">
        <button class="form-button primary" type="button" data-submit-membership>Start membership</button>
      </div>
    </form>
  </div>
</main>

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
        <span class="form-label">Model</span>
        <input class="form-input" type="text" data-field="model">
      </label>
    </div>
    <div class="form-grid three">
      <label class="form-field">
        <span class="form-label">Year</span>
        <input class="form-input" type="text" data-field="year">
      </label>
      <label class="form-field">
        <span class="form-label">Rego / Registration Number</span>
        <input class="form-input" type="text" data-field="rego" maxlength="20">
      </label>
      <label class="form-field">
        <span class="form-label">Colour (optional)</span>
        <input class="form-input" type="text" data-field="colour">
      </label>
    </div>
    <div class="form-grid two">
      <label class="form-field">
        <span class="form-label">Sidecar</span>
        <input type="checkbox" data-field="sidecar" data-toggle="sidecar">
      </label>
      <label class="form-field">
        <span class="form-label">Trailer</span>
        <input type="checkbox" data-field="trailer" data-toggle="trailer">
      </label>
    </div>
    <div class="form-grid two" data-conditional="sidecar" hidden>
      <label class="form-field">
        <span class="form-label">Sidecar rego / Registration Number</span>
        <input class="form-input" type="text" data-field="sidecar_rego" maxlength="20">
      </label>
      <label class="form-field">
        <span class="form-label">Sidecar manufacturer</span>
        <input class="form-input" type="text" data-field="sidecar_manufacturer">
      </label>
    </div>
    <div class="form-grid two" data-conditional="trailer" hidden>
      <label class="form-field">
        <span class="form-label">Trailer rego / Registration Number</span>
        <input class="form-input" type="text" data-field="trailer_rego" maxlength="20">
      </label>
      <label class="form-field">
        <span class="form-label">Trailer manufacturer</span>
        <input class="form-input" type="text" data-field="trailer_manufacturer">
      </label>
    </div>
  </div>
</template>

<script src="https://js.stripe.com/v3/"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('membership-form');
    const submitButton = document.querySelector('[data-submit-membership]');
    const errorEl = document.getElementById('membership-error');
    const paymentElementContainer = document.getElementById('membership-payment-element');
    const vehicleTemplate = document.querySelector('#vehicle-template');
    const fullVehicleInput = form.querySelector('input[name="full_vehicle_payload"]');
    const associateVehicleInput = form.querySelector('input[name="associate_vehicle_payload"]');
    const associateToggleWrapper = form.querySelector('[data-associate-toggle]');
    const associateSection = form.querySelector('[data-associate-section]');

    const vehicleLists = {
      full: form.querySelector('[data-vehicle-list="full"]'),
      associate: form.querySelector('[data-vehicle-list="associate"]'),
    };

    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let clientSecret = null;
    let stripeConfig = null;

    const setError = (message) => {
      if (!errorEl) {
        return;
      }
      if (!message) {
        errorEl.hidden = true;
        errorEl.textContent = '';
        return;
      }
      errorEl.hidden = false;
      errorEl.textContent = message;
    };

    const syncVehiclePayloads = () => {
      const collectVehicles = (list) => {
        const cards = Array.from(list.querySelectorAll('[data-vehicle]'));
        const vehicles = [];
        cards.forEach((card) => {
          const data = {};
          card.querySelectorAll('[data-field]').forEach((field) => {
            if (field.type === 'checkbox') {
              data[field.dataset.field] = field.checked;
            } else {
              data[field.dataset.field] = field.value;
            }
          });
          vehicles.push(data);
        });
        return vehicles;
      };
      fullVehicleInput.value = JSON.stringify(collectVehicles(vehicleLists.full));
      associateVehicleInput.value = JSON.stringify(collectVehicles(vehicleLists.associate));
    };

    const updateVehicleIndices = (list) => {
      const cards = Array.from(list.querySelectorAll('[data-vehicle]'));
      cards.forEach((card, index) => {
        const indexSpan = card.querySelector('[data-vehicle-index]');
        if (indexSpan) {
          indexSpan.textContent = String(index + 1);
        }
      });
    };

    const addVehicleCard = (owner) => {
      const list = vehicleLists[owner];
      if (!list || !vehicleTemplate) {
        return;
      }
      const fragment = vehicleTemplate.content.cloneNode(true);
      const card = fragment.querySelector('[data-vehicle]');
      const toggles = card.querySelectorAll('[data-toggle]');
      toggles.forEach((toggle) => {
        toggle.addEventListener('change', () => {
          const target = card.querySelector(`[data-conditional="${toggle.dataset.toggle}"]`);
          if (target) {
            target.hidden = !toggle.checked;
          }
        });
      });
      card.querySelectorAll('input').forEach((input) => {
        input.addEventListener('input', syncVehiclePayloads);
        input.addEventListener('change', syncVehiclePayloads);
      });
      const removeButton = card.querySelector('[data-remove-vehicle]');
      if (removeButton) {
        removeButton.addEventListener('click', () => {
          card.remove();
          updateVehicleIndices(list);
          syncVehiclePayloads();
        });
      }
      list.appendChild(fragment);
      updateVehicleIndices(list);
      syncVehiclePayloads();
    };

    document.querySelectorAll('[data-add-vehicle]').forEach((button) => {
      button.addEventListener('click', () => {
        addVehicleCard(button.dataset.addVehicle);
      });
    });

    Object.keys(vehicleLists).forEach((key) => {
      if (vehicleLists[key] && vehicleLists[key].children.length === 0) {
        addVehicleCard(key);
      }
    });

    const updateAssociateVisibility = () => {
      const associateSelected = form.querySelector('input[name="membership_associate"]')?.checked;
      if (associateToggleWrapper) {
        associateToggleWrapper.hidden = !associateSelected;
      }
      const associateAdd = form.querySelector('input[name="associate_add"]:checked')?.value || '';
      if (associateSection) {
        associateSection.hidden = !(associateSelected && associateAdd === 'yes');
      }
    };

    form.querySelectorAll('input[name="membership_associate"]').forEach((input) => {
      input.addEventListener('change', updateAssociateVisibility);
    });
    form.querySelectorAll('input[name="associate_add"]').forEach((input) => {
      input.addEventListener('change', updateAssociateVisibility);
    });
    updateAssociateVisibility();

    const loadStripeConfig = async () => {
      if (stripeConfig) {
        return stripeConfig;
      }
      const response = await fetch('/api/stripe/config');
      const data = await response.json();
      stripeConfig = data;
      return data;
    };

    const initStripeElements = async (secret) => {
      const config = await loadStripeConfig();
      if (!config || !config.publishableKey) {
        throw new Error('Stripe is not configured.');
      }
      if (!stripe) {
        stripe = Stripe(config.publishableKey);
      }
      if (!elements || clientSecret !== secret) {
        elements = stripe.elements({
          clientSecret: secret,
          appearance: { theme: 'stripe' },
        });
        const wallets = {
          applePay: config.paymentMethods && config.paymentMethods.applePay ? 'auto' : 'never',
          googlePay: config.paymentMethods && config.paymentMethods.googlePay ? 'auto' : 'never',
        };
        paymentElement = elements.create('payment', { wallets });
        paymentElement.mount(paymentElementContainer);
      }
      clientSecret = secret;
    };

    const buildPayload = () => {
      syncVehiclePayloads();
      return {
        membership_full: form.querySelector('input[name="membership_full"]')?.checked ? 1 : 0,
        membership_associate: form.querySelector('input[name="membership_associate"]')?.checked ? 1 : 0,
        associate_add: form.querySelector('input[name="associate_add"]:checked')?.value || '',
        membership_term: form.querySelector('select[name="membership_term"]')?.value || '12M',
        first_name: form.querySelector('input[name="first_name"]')?.value || '',
        last_name: form.querySelector('input[name="last_name"]')?.value || '',
        email: form.querySelector('input[name="email"]')?.value || '',
        phone: form.querySelector('input[name="phone"]')?.value || '',
        address_line1: form.querySelector('input[name="address_line1"]')?.value || '',
        address_line2: form.querySelector('input[name="address_line2"]')?.value || '',
        city: form.querySelector('input[name="city"]')?.value || '',
        state: form.querySelector('input[name="state"]')?.value || '',
        postal_code: form.querySelector('input[name="postal_code"]')?.value || '',
        country: form.querySelector('input[name="country"]')?.value || '',
        password: form.querySelector('input[name="password"]')?.value || '',
        associate_first_name: form.querySelector('input[name="associate_first_name"]')?.value || '',
        associate_last_name: form.querySelector('input[name="associate_last_name"]')?.value || '',
        associate_email: form.querySelector('input[name="associate_email"]')?.value || '',
        full_vehicle_payload: fullVehicleInput.value,
        associate_vehicle_payload: associateVehicleInput.value,
      };
    };

    const validatePayload = (payload) => {
      const allowBoth = form.dataset.allowBoth === '1';
      if (!payload.membership_full && !payload.membership_associate) {
        return 'Select at least one membership type.';
      }
      if (!allowBoth && payload.membership_full && payload.membership_associate) {
        return 'Select one membership type only.';
      }
      if (payload.membership_associate && payload.associate_add !== 'yes') {
        return 'Associate details are required.';
      }
      if (!payload.first_name || !payload.last_name || !payload.email) {
        return 'Primary member name and email are required.';
      }
      if (!payload.address_line1 || !payload.city || !payload.state || !payload.postal_code) {
        return 'Primary address is required.';
      }
      if (form.dataset.guest === '1' && (!payload.password || payload.password.length < 8)) {
        return 'Password must be at least 8 characters.';
      }
      if (payload.membership_associate && payload.associate_add === 'yes' && (!payload.associate_first_name || !payload.associate_last_name)) {
        return 'Associate first and last name are required.';
      }
      return '';
    };

    if (submitButton) {
      submitButton.addEventListener('click', async () => {
        setError('');
        const payload = buildPayload();
        const validation = validatePayload(payload);
        if (validation) {
          setError(validation);
          return;
        }
        submitButton.disabled = true;
        submitButton.textContent = 'Processing...';
        try {
          if (!clientSecret) {
            const response = await fetch('/api/stripe/create-subscription', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': form.querySelector('input[name="csrf_token"]').value,
              },
              body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok || data.error) {
              throw new Error(data.error || 'Unable to start membership payment.');
            }
            await initStripeElements(data.client_secret);
          }

          const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
              return_url: `${window.location.origin}/memberships/success`,
            },
          });
          if (error) {
            throw error;
          }
        } catch (error) {
          setError(error.message || 'Membership payment failed.');
          submitButton.disabled = false;
          submitButton.textContent = 'Start membership';
        }
      });
    }
  });
</script>

<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>