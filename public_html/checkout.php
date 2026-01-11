<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\StripeSettingsService;

$pdo = db();
$user = current_user();
$settings = store_get_settings();
$stripeSettings = StripeSettingsService::getSettings();
$checkoutEnabled = !empty($stripeSettings['checkout_enabled']);
$allowGuestCheckout = !empty($stripeSettings['allow_guest_checkout']);

if (!$user && !$allowGuestCheckout) {
    $pageTitle = 'Checkout';
    require __DIR__ . '/../app/Views/partials/header.php';
    require __DIR__ . '/../app/Views/partials/nav_public.php';
    ?>
    <main class="site-main">
      <section class="hero hero--compact store-hero">
        <div class="container hero__inner">
          <span class="hero__eyebrow">Australian Goldwing Association</span>
          <h1>Checkout</h1>
          <p class="hero__lead">Guest checkout is currently disabled.</p>
        </div>
      </section>
      <section class="page-section">
        <div class="container">
          <div class="page-card">
            <div class="alert error">Please log in to continue checkout.</div>
            <a class="button" href="/login.php">Log in</a>
          </div>
        </div>
      </section>
    </main>
    <?php
    require __DIR__ . '/../app/Views/partials/footer.php';
    exit;
}

$cart = store_get_open_cart((int) ($user['id'] ?? 0));
$itemsStmt = $pdo->prepare('SELECT ci.*, p.type, p.event_name, p.track_inventory, p.stock_quantity, v.stock_quantity as variant_stock FROM store_cart_items ci JOIN store_products p ON p.id = ci.product_id LEFT JOIN store_product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = :cart_id');
$itemsStmt->execute(['cart_id' => $cart['id']]);
$items = $itemsStmt->fetchAll();

$requiresShipping = false;
foreach ($items as $item) {
    if (($item['type'] ?? 'physical') === 'physical') {
        $requiresShipping = true;
        break;
    }
}

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
$guestFirst = $nameParts && count($nameParts) > 1 ? array_shift($nameParts) : (string) $primaryName;
$guestLast = $nameParts && count($nameParts) > 1 ? implode(' ', $nameParts) : '';

$fulfillment = $_POST['fulfillment'] ?? 'shipping';
$pickupEnabled = (int) ($settings['pickup_enabled'] ?? 0) === 1;
if (!$requiresShipping) {
    $fulfillment = 'pickup';
}
if ($requiresShipping && !$pickupEnabled && $fulfillment === 'pickup') {
    $fulfillment = 'shipping';
}

$address = [
    'name' => trim($_POST['shipping_name'] ?? ($member ? ($member['first_name'] . ' ' . $member['last_name']) : $primaryName)),
    'line1' => trim($_POST['shipping_line1'] ?? ($member['address_line1'] ?? '')),
    'line2' => trim($_POST['shipping_line2'] ?? ($member['address_line2'] ?? '')),
    'city' => trim($_POST['shipping_city'] ?? ($member['city'] ?? '')),
    'state' => trim($_POST['shipping_state'] ?? ($member['state'] ?? '')),
    'postal' => trim($_POST['shipping_postal'] ?? ($member['postal_code'] ?? '')),
    'country' => trim($_POST['shipping_country'] ?? ($member['country'] ?? 'Australia')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $checkoutError = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'apply_discount') {
            $code = trim($_POST['discount_code'] ?? '');
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $result = store_validate_discount_code($code, $subtotal);
            if (!empty($result['error'])) {
                $checkoutError = $result['error'];
            } else {
                $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = :code, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['code' => strtoupper($code), 'id' => $cart['id']]);
                $cart['discount_code'] = strtoupper($code);
                $checkoutSuccess = 'Discount applied.';
            }
        }
        if ($action === 'clear_discount') {
            $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = NULL, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $cart['id']]);
            $cart['discount_code'] = null;
            $checkoutSuccess = 'Discount removed.';
        }
    }
}

$discount = null;
if (!empty($cart['discount_code'])) {
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
    }
    $result = store_validate_discount_code($cart['discount_code'], $subtotal);
    if (!empty($result['discount'])) {
        $discount = $result['discount'];
    }
}

$totals = store_calculate_cart_totals($items, $discount, $settings, $fulfillment);
$subtotalAfterDiscount = max(0.0, $totals['subtotal'] - $totals['discount_total']);
$shippingAvailable = false;
if ($fulfillment === 'shipping') {
    $threshold = (float) ($settings['shipping_free_threshold'] ?? 0);
    $flatRate = (float) ($settings['shipping_flat_rate'] ?? 0);
    if (!empty($settings['shipping_free_enabled']) && $threshold > 0 && $subtotalAfterDiscount >= $threshold) {
        $shippingAvailable = true;
    } elseif (!empty($settings['shipping_flat_enabled']) && $flatRate > 0) {
        $shippingAvailable = true;
    }
}

$pageTitle = 'Checkout';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact store-hero">
    <div class="container hero__inner">
      <span class="hero__eyebrow">Australian Goldwing Association</span>
      <h1>Checkout</h1>
      <p class="hero__lead">Review your order and pay securely with Stripe.</p>
    </div>
  </section>

  <section class="page-section">
    <div class="container">
      <div class="page-card">
        <div class="grid gap-6">
          <h2>Checkout</h2>
          <?php if (!empty($checkoutError)): ?>
            <div class="alert error"><?= e($checkoutError) ?></div>
          <?php endif; ?>
          <?php if (!empty($checkoutSuccess)): ?>
            <div class="alert success"><?= e($checkoutSuccess) ?></div>
          <?php endif; ?>

          <?php if (!$checkoutEnabled): ?>
            <div class="alert error">Checkout is currently unavailable.</div>
          <?php elseif (!$items): ?>
            <p>Your cart is empty.</p>
            <a class="button" href="/store">Browse products</a>
          <?php else: ?>
            <form id="checkout-form" class="grid gap-6" data-guest-required="<?= $user ? '0' : '1' ?>" data-requires-shipping="<?= $requiresShipping ? '1' : '0' ?>" data-require-shipping="<?= !empty($stripeSettings['require_shipping_for_physical']) ? '1' : '0' ?>" data-digital-minimal="<?= !empty($stripeSettings['digital_only_minimal']) ? '1' : '0' ?>">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">

              <div class="card">
                <h3>Contact details</h3>
                <div class="grid grid-2">
                  <div class="form-group">
                    <label for="guest-first-name">First name</label>
                    <input id="guest-first-name" name="guest_first_name" value="<?= e(trim((string) ($_POST['guest_first_name'] ?? $guestFirst))) ?>">
                  </div>
                  <div class="form-group">
                    <label for="guest-last-name">Last name</label>
                    <input id="guest-last-name" name="guest_last_name" value="<?= e(trim((string) ($_POST['guest_last_name'] ?? $guestLast))) ?>">
                  </div>
                </div>
                <div class="grid grid-2">
                  <div class="form-group">
                    <label for="guest-email">Email</label>
                    <input id="guest-email" name="guest_email" type="email" value="<?= e(trim((string) ($_POST['guest_email'] ?? ($user['email'] ?? '')))) ?>">
                  </div>
                  <div class="form-group">
                    <label for="guest-phone">Phone (optional)</label>
                    <input id="guest-phone" name="guest_phone" value="<?= e(trim((string) ($_POST['guest_phone'] ?? ($member['phone'] ?? '')))) ?>">
                  </div>
                </div>
              </div>

              <div class="card">
                <h3>Fulfillment</h3>
                <?php if ($requiresShipping): ?>
                  <?php $shippingLabel = 'Ship to address (Australia only)'; ?>
                  <label class="form-group">
                    <input type="radio" name="fulfillment" value="shipping" <?= $fulfillment === 'shipping' ? 'checked' : '' ?>>
                    <?= e($shippingLabel) ?>
                  </label>
                  <?php if ($pickupEnabled): ?>
                    <label class="form-group">
                      <input type="radio" name="fulfillment" value="pickup" <?= $fulfillment === 'pickup' ? 'checked' : '' ?>>
                      Pickup
                    </label>
                    <p><?= e($settings['pickup_instructions'] ?? '') ?></p>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="text-sm text-gray-600">No shipping required for this order.</p>
                <?php endif; ?>
              </div>

              <?php if ($requiresShipping): ?>
                <div class="card" data-shipping-section>
                  <h3>Shipping address</h3>
                  <div class="form-group">
                    <label for="shipping-name">Name</label>
                    <input id="shipping-name" name="shipping_name" value="<?= e($address['name']) ?>">
                  </div>
                  <div class="form-group">
                    <label for="shipping-line1">Address line 1</label>
                    <input id="shipping-line1" name="shipping_line1" value="<?= e($address['line1']) ?>">
                  </div>
                  <div class="form-group">
                    <label for="shipping-line2">Address line 2</label>
                    <input id="shipping-line2" name="shipping_line2" value="<?= e($address['line2']) ?>">
                  </div>
                  <div class="grid grid-3">
                    <div class="form-group">
                      <label for="shipping-city">City</label>
                      <input id="shipping-city" name="shipping_city" value="<?= e($address['city']) ?>">
                    </div>
                    <div class="form-group">
                      <label for="shipping-state">State</label>
                      <input id="shipping-state" name="shipping_state" value="<?= e($address['state']) ?>">
                    </div>
                    <div class="form-group">
                      <label for="shipping-postal">Postcode</label>
                      <input id="shipping-postal" name="shipping_postal" value="<?= e($address['postal']) ?>">
                    </div>
                  </div>
                  <div class="form-group">
                    <label for="shipping-country">Country</label>
                    <input id="shipping-country" name="shipping_country" value="<?= e($address['country']) ?>" readonly>
                  </div>
                </div>
              <?php endif; ?>

              <div class="card">
                <h3>Payment</h3>
                <div id="payment-element" class="mt-4"></div>
                <div class="form-error" id="payment-error" hidden></div>
              </div>

              <div class="grid gap-4 md:grid-cols-[1.2fr_1fr]">
                <div class="card">
                  <h3>Discount</h3>
                  <?php if (!empty($cart['discount_code'])): ?>
                    <p>Applied code: <strong><?= e($cart['discount_code']) ?></strong></p>
                    <button type="submit" name="action" value="clear_discount" class="button">Remove discount</button>
                  <?php else: ?>
                    <div class="form-group">
                      <label for="discount-code">Discount code</label>
                      <input id="discount-code" name="discount_code" placeholder="Enter code">
                    </div>
                    <button type="submit" name="action" value="apply_discount" class="button">Apply</button>
                  <?php endif; ?>
                </div>

                <div class="card">
                  <h3>Order summary</h3>
                  <table class="table">
                    <tr>
                      <td>Subtotal</td>
                      <td>$<?= e(store_money($totals['subtotal'])) ?></td>
                    </tr>
                    <?php if ($totals['discount_total'] > 0): ?>
                      <tr>
                        <td>Discount</td>
                        <td>-$<?= e(store_money($totals['discount_total'])) ?></td>
                      </tr>
                    <?php endif; ?>
                    <?php if (!empty($totals['tax_total'])): ?>
                      <tr>
                        <td>GST</td>
                        <td>$<?= e(store_money($totals['tax_total'])) ?></td>
                      </tr>
                    <?php endif; ?>
                    <tr>
                      <td>Shipping</td>
                      <td>$<?= e(store_money($totals['shipping_total'])) ?></td>
                    </tr>
                    <tr>
                      <td>Payment processing fee</td>
                      <td>$<?= e(store_money($totals['processing_fee_total'])) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Total</strong></td>
                      <td><strong>$<?= e(store_money($totals['total'])) ?></strong></td>
                    </tr>
                  </table>
                </div>
              </div>

              <?php if ($requiresShipping && $fulfillment === 'shipping' && !$shippingAvailable): ?>
                <div class="alert error">Shipping is not available for this order. Choose pickup or adjust your cart.</div>
              <?php endif; ?>

              <button type="button" class="button primary" data-pay-button>Pay now</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</main>

<script src="https://js.stripe.com/v3/"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('checkout-form');
    if (!form) {
      return;
    }
    const payButton = form.querySelector('[data-pay-button]');
    const errorEl = document.getElementById('payment-error');
    const paymentElementContainer = document.getElementById('payment-element');
    const csrfToken = form.querySelector('input[name="csrf_token"]').value;
    const requiresShipping = form.dataset.requiresShipping === '1';
    const requireShipping = form.dataset.requireShipping === '1';
    const guestRequired = form.dataset.guestRequired === '1';

    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let clientSecret = null;
    let stripeConfig = null;
    let orderId = null;

    const setError = (message) => {
      if (!errorEl) {
        return;
      }
      if (!message) {
        errorEl.textContent = '';
        errorEl.hidden = true;
        return;
      }
      errorEl.textContent = message;
      errorEl.hidden = false;
    };

    const loadStripeConfig = async () => {
      if (stripeConfig) {
        return stripeConfig;
      }
      const response = await fetch('/api/stripe/config');
      const data = await response.json();
      stripeConfig = data;
      return data;
    };

    const collectPayload = () => {
      const fulfillmentInput = form.querySelector('input[name="fulfillment"]:checked');
      const fulfillment = fulfillmentInput ? fulfillmentInput.value : 'shipping';
      return {
        fulfillment,
        guest_email: form.querySelector('input[name="guest_email"]')?.value || '',
        guest_first_name: form.querySelector('input[name="guest_first_name"]')?.value || '',
        guest_last_name: form.querySelector('input[name="guest_last_name"]')?.value || '',
        guest_phone: form.querySelector('input[name="guest_phone"]')?.value || '',
        shipping_name: form.querySelector('input[name="shipping_name"]')?.value || '',
        shipping_line1: form.querySelector('input[name="shipping_line1"]')?.value || '',
        shipping_line2: form.querySelector('input[name="shipping_line2"]')?.value || '',
        shipping_city: form.querySelector('input[name="shipping_city"]')?.value || '',
        shipping_state: form.querySelector('input[name="shipping_state"]')?.value || '',
        shipping_postal: form.querySelector('input[name="shipping_postal"]')?.value || '',
        shipping_country: form.querySelector('input[name="shipping_country"]')?.value || '',
      };
    };

    const validatePayload = (payload) => {
      if (guestRequired) {
        if (!payload.guest_email || !payload.guest_first_name || !payload.guest_last_name) {
          return 'Please enter your name and email.';
        }
      }
      if (requiresShipping && requireShipping && payload.fulfillment === 'shipping') {
        if (!payload.shipping_line1 || !payload.shipping_city || !payload.shipping_state || !payload.shipping_postal) {
          return 'Please complete the shipping address.';
        }
      }
      return '';
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

    if (payButton) {
      payButton.addEventListener('click', async () => {
        setError('');
        const payload = collectPayload();
        const validationError = validatePayload(payload);
        if (validationError) {
          setError(validationError);
          return;
        }
        payButton.disabled = true;
        payButton.textContent = 'Processing...';
        try {
          if (!clientSecret) {
            const response = await fetch('/api/stripe/create-payment-intent', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
              },
              body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok || data.error) {
              throw new Error(data.error || 'Unable to start checkout.');
            }
            orderId = data.orderId || null;
            await initStripeElements(data.client_secret);
          }

          const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
              return_url: `${window.location.origin}/order/success?orderId=${encodeURIComponent(orderId || '')}`,
            },
          });
          if (error) {
            throw error;
          }
        } catch (error) {
          setError(error.message || 'Payment could not be processed.');
          payButton.disabled = false;
          payButton.textContent = 'Pay now';
        }
      });
    }
  });
</script>

<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>
