<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\StripeSettingsService;
use App\Services\SettingsService;

require_login();

// Deploy marker so we can verify the right code is running.
define('CHECKOUT_VERSION', 'rename-items-to-cartItems');

$pdo = db();
$user = current_user();
$settings = store_get_settings();
$stripeSettings = StripeSettingsService::getSettings();
$checkoutEnabled = !empty($stripeSettings['checkout_enabled']);
$bankTransferInstructions = trim((string) SettingsService::getGlobal('payments.bank_transfer_instructions', ''));
$bankTransferEnabled = $bankTransferInstructions !== '';
$cardEnabled = $checkoutEnabled;

$cart = store_get_open_cart((int) ($user['id'] ?? 0));

// Self-heal: remove ghost rows left behind by old update_cart code paths
// (qty clamped to 0 on out-of-stock items, or orphaned title_snapshots).
$cleanup = $pdo->prepare('DELETE FROM store_cart_items WHERE cart_id = :cart_id AND (quantity <= 0 OR unit_price < 0 OR title_snapshot IS NULL OR title_snapshot = "")');
$cleanup->execute(['cart_id' => $cart['id']]);

// Bypass the JOIN: the previous query joined store_products and
// store_product_variants and was returning 9x duplicate rows on this
// install, producing 18 phantom <li> rows in the order summary for a
// cart that only had 2 real items. Fetch cart items alone, then look
// up product/variant metadata in separate queries and merge by id —
// guaranteed one $cartItems entry per store_cart_items row.
$cartItemsStmt = $pdo->prepare('SELECT * FROM store_cart_items WHERE cart_id = :cart_id AND quantity > 0 AND title_snapshot IS NOT NULL AND title_snapshot != \'\' ORDER BY id ASC');
$cartItemsStmt->execute(['cart_id' => $cart['id']]);
$cartItems = $cartItemsStmt->fetchAll();

// Capture debug values so we can emit them next to the order summary.
$DEBUG_user_id_used = (int) ($user['id'] ?? 0);
$DEBUG_cart_id_used = (int) ($cart['id'] ?? 0);
$DEBUG_items_count_at_fetch = is_array($cartItems) ? count($cartItems) : -1;
$DEBUG_first_item_keys = (is_array($cartItems) && !empty($cartItems)) ? implode(',', array_keys($cartItems[0])) : '';
$DEBUG_first_item_id = (is_array($cartItems) && !empty($cartItems)) ? ($cartItems[0]['id'] ?? 'no-id-key') : '';
$DEBUG_first_item_title = (is_array($cartItems) && !empty($cartItems)) ? ($cartItems[0]['title_snapshot'] ?? 'no-title') : '';
$DEBUG_first_item_qty = (is_array($cartItems) && !empty($cartItems)) ? ($cartItems[0]['quantity'] ?? 'no-qty') : '';
$DEBUG_user_email = (string) ($user['email'] ?? '');

if ($cartItems) {
    $productIds = array_values(array_unique(array_map(function ($i) { return (int) $i['product_id']; }, $cartItems)));
    $variantIds = array_values(array_filter(array_unique(array_map(function ($i) { return (int) ($i['variant_id'] ?? 0); }, $cartItems))));

    $productInfo = [];
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $pStmt = $pdo->prepare('SELECT id, type, event_name, track_inventory, stock_quantity FROM store_products WHERE id IN (' . $placeholders . ')');
        $pStmt->execute($productIds);
        foreach ($pStmt->fetchAll() as $p) {
            $productInfo[(int) $p['id']] = $p;
        }
    }

    $variantInfo = [];
    if ($variantIds) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $vStmt = $pdo->prepare('SELECT id, stock_quantity FROM store_product_variants WHERE id IN (' . $placeholders . ')');
        $vStmt->execute($variantIds);
        foreach ($vStmt->fetchAll() as $v) {
            $variantInfo[(int) $v['id']] = $v;
        }
    }

    foreach ($cartItems as &$item) {
        $pid = (int) $item['product_id'];
        $vid = (int) ($item['variant_id'] ?? 0);
        $p = $productInfo[$pid] ?? [];
        $v = $variantInfo[$vid] ?? [];
        $item['type'] = $p['type'] ?? 'physical';
        $item['event_name'] = $p['event_name'] ?? null;
        $item['track_inventory'] = $p['track_inventory'] ?? 0;
        $item['stock_quantity'] = $p['stock_quantity'] ?? null;
        $item['variant_stock'] = $v['stock_quantity'] ?? null;
    }
    unset($item);
}

$requiresShipping = false;
foreach ($cartItems as $item) {
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

// Prefer the structured first/last from the members table when available —
// $user['name'] can include the admin role label (e.g. "Pat Lindley - MASTER
// ADMIN") which would otherwise leak into the checkout last_name field and
// onto the order / shipping label.
if ($member && (!empty($member['first_name']) || !empty($member['last_name']))) {
    $guestFirst = trim((string) ($member['first_name'] ?? ''));
    $guestLast  = trim((string) ($member['last_name'] ?? ''));
    $primaryName = trim($guestFirst . ' ' . $guestLast);
} else {
    $primaryName = $user['name'] ?? '';
    $nameParts = preg_split('/\s+/', trim((string) $primaryName));
    $guestFirst = $nameParts && count($nameParts) > 1 ? array_shift($nameParts) : (string) $primaryName;
    $guestLast  = $nameParts && count($nameParts) > 1 ? implode(' ', $nameParts) : '';
}

$fulfillment = 'shipping';

$address = [
    'name' => trim($_POST['shipping_name'] ?? ($member ? ($member['first_name'] . ' ' . $member['last_name']) : $primaryName)),
    'line1' => trim($_POST['shipping_line1'] ?? ($member['address_line1'] ?? '')),
    'line2' => trim($_POST['shipping_line2'] ?? ($member['address_line2'] ?? '')),
    'city' => trim($_POST['shipping_city'] ?? ($member['city'] ?? '')),
    'state' => trim($_POST['shipping_state'] ?? ($member['state'] ?? '')),
    'postal' => trim($_POST['shipping_postal'] ?? ($member['postal_code'] ?? '')),
    'country' => trim($_POST['shipping_country'] ?? ($member['country'] ?? 'Australia')),
];
$memberHasShipping = $member && (
    trim((string) ($member['address_line1'] ?? '')) !== ''
    || trim((string) ($member['city'] ?? '')) !== ''
    || trim((string) ($member['state'] ?? '')) !== ''
    || trim((string) ($member['postal_code'] ?? '')) !== ''
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $checkoutError = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'apply_discount') {
            $code = trim($_POST['discount_code'] ?? '');
            $subtotal = 0.0;
            foreach ($cartItems as $item) {
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
    foreach ($cartItems as $item) {
        $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
    }
    $result = store_validate_discount_code($cart['discount_code'], $subtotal);
    if (!empty($result['discount'])) {
        $discount = $result['discount'];
    }
}

$totals = store_calculate_cart_totals($cartItems, $discount, $settings, $fulfillment);
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
$activePage = 'store';
$activeSubPage = 'checkout';
require __DIR__ . '/../app/Views/partials/backend_head.php';

$cartItemImages = [];
if ($cartItems) {
    $productIds = array_unique(array_map(function ($i) { return (int) $i['product_id']; }, $cartItems));
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $imgStmt = $pdo->prepare("SELECT product_id, image_url FROM store_product_images WHERE product_id IN ($placeholders) ORDER BY product_id, sort_order ASC, id ASC");
        $imgStmt->execute($productIds);
        foreach ($imgStmt->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($cartItemImages[$pid])) {
                $cartItemImages[$pid] = $row['image_url'];
            }
        }
    }
}
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php require __DIR__ . '/../app/Views/partials/feedback_widget.php'; ?>
    <?php $topbarTitle = 'Checkout';
    require __DIR__ . '/../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <section class="bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900">Checkout</h1>
            <nav class="mt-2 flex items-center gap-2 text-sm text-gray-500" aria-label="Checkout progress">
              <a href="/store/cart" class="hover:text-gray-700">Cart</a>
              <span class="material-icons-outlined text-base">chevron_right</span>
              <span class="font-semibold text-gray-900">Checkout</span>
              <span class="material-icons-outlined text-base">chevron_right</span>
              <span>Confirmation</span>
            </nav>
          </div>
          <a href="/store" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm transition-colors">
            <span class="material-icons-outlined">storefront</span>
            Continue shopping
          </a>
        </div>
      </section>

      <?php if (!empty($checkoutError)): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
          <span class="material-icons-outlined text-red-600">error_outline</span>
          <span><?= e($checkoutError) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkoutSuccess)): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
          <span class="material-icons-outlined text-green-600">check_circle</span>
          <span><?= e($checkoutSuccess) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!$cardEnabled && !$bankTransferEnabled): ?>
        <div class="bg-card-light rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
          <span class="material-icons-outlined text-5xl text-red-500">block</span>
          <h2 class="mt-3 text-xl font-semibold text-gray-900">Checkout is currently unavailable.</h2>
          <p class="mt-1 text-gray-500">Please try again later or contact support.</p>
        </div>
      <?php elseif (!$cartItems): ?>
        <div class="bg-card-light rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
          <span class="material-icons-outlined text-5xl text-gray-400">shopping_cart</span>
          <h2 class="mt-3 text-xl font-semibold text-gray-900">Your cart is empty.</h2>
          <p class="mt-1 text-gray-500">Browse the store to add gear to your order.</p>
          <a href="/store" class="inline-flex items-center gap-2 mt-5 px-5 py-3 rounded-lg bg-primary hover:bg-primary/90 text-gray-900 font-semibold text-sm transition-colors">
            <span class="material-icons-outlined">storefront</span>
            Browse products
          </a>
        </div>
      <?php else: ?>

        <form id="checkout-form" class="grid grid-cols-1 lg:grid-cols-[1fr_400px] gap-6 items-start"
              data-guest-required="0"
              data-requires-shipping="<?= $requiresShipping ? '1' : '0' ?>"
              data-require-shipping="<?= !empty($stripeSettings['require_shipping_for_physical']) ? '1' : '0' ?>"
              data-digital-minimal="<?= !empty($stripeSettings['digital_only_minimal']) ? '1' : '0' ?>">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="fulfillment" value="<?= e($fulfillment) ?>">

          <div class="space-y-4" data-checkout-accordion>

            <?php if (!$cardEnabled && $bankTransferEnabled): ?>
              <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
                <span class="material-icons-outlined text-amber-700">info</span>
                <span>Card payments are unavailable. Please use bank transfer.</span>
              </div>
            <?php endif; ?>

            <?php
            $sectionNumber = 0;
            $renderSection = function (string $key, string $title, callable $summaryFn, callable $bodyFn, bool $open = false) use (&$sectionNumber) {
                $sectionNumber++;
                $num = $sectionNumber;
                ?>
                <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                         data-checkout-section="<?= e($key) ?>" <?= $open ? 'data-open="1"' : '' ?>>
                  <button type="button" class="w-full flex items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-gray-50"
                          data-section-toggle="<?= e($key) ?>">
                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-primary text-gray-900 font-bold text-sm shrink-0" data-section-badge>
                      <?= e((string) $num) ?>
                    </span>
                    <span class="flex-1 min-w-0">
                      <span class="block font-display text-lg font-semibold text-gray-900"><?= e($title) ?></span>
                      <span class="block text-sm text-gray-500 truncate" data-section-summary><?php $summaryFn(); ?></span>
                    </span>
                    <span class="material-icons-outlined text-gray-400 transition-transform" data-section-chevron>expand_more</span>
                  </button>
                  <div class="px-5 pb-5 pt-1 border-t border-gray-100 <?= $open ? '' : 'hidden' ?>" data-section-body>
                    <?php $bodyFn(); ?>
                    <div class="mt-5 flex justify-end">
                      <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-900 hover:bg-gray-800 text-white font-semibold text-sm transition-colors" data-section-continue>
                        Continue
                        <span class="material-icons-outlined text-base">arrow_forward</span>
                      </button>
                    </div>
                  </div>
                </section>
                <?php
            };
            ?>

            <?php $renderSection('contact', 'Contact details',
              function () use ($guestFirst, $guestLast, $user) {
                  $name = trim($guestFirst . ' ' . $guestLast);
                  echo e(($name !== '' ? $name . ' · ' : '') . ($user['email'] ?? ''));
              },
              function () use ($guestFirst, $guestLast, $user, $member) { ?>
                <p class="text-xs uppercase tracking-wider text-gray-500 mb-3">From your member profile — edit if needed</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label for="guest-first-name" class="block text-sm font-medium text-gray-700 mb-1">First name</label>
                    <input id="guest-first-name" name="guest_first_name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e(trim((string) ($_POST['guest_first_name'] ?? $guestFirst))) ?>">
                  </div>
                  <div>
                    <label for="guest-last-name" class="block text-sm font-medium text-gray-700 mb-1">Last name</label>
                    <input id="guest-last-name" name="guest_last_name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e(trim((string) ($_POST['guest_last_name'] ?? $guestLast))) ?>">
                  </div>
                  <div>
                    <label for="guest-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input id="guest-email" name="guest_email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e(trim((string) ($_POST['guest_email'] ?? ($user['email'] ?? '')))) ?>">
                  </div>
                  <div>
                    <label for="guest-phone" class="block text-sm font-medium text-gray-700 mb-1">Phone (optional)</label>
                    <input id="guest-phone" name="guest_phone" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e(trim((string) ($_POST['guest_phone'] ?? ($member['phone'] ?? '')))) ?>">
                  </div>
                </div>
              <?php },
              true
            ); ?>

            <?php if ($requiresShipping): ?>
              <?php $renderSection('shipping', 'Shipping address',
                function () use ($address) {
                    $line = trim($address['line1'] . ', ' . $address['city'] . ' ' . $address['state'] . ' ' . $address['postal']);
                    echo e($line !== ', ' ? $line : 'Enter your shipping address');
                },
                function () use ($address, $memberHasShipping) { ?>
                  <div data-shipping-section>
                    <input type="hidden" data-shipping-base name="shipping_name" value="<?= e($address['name']) ?>">
                    <input type="hidden" data-shipping-base name="shipping_line1" value="<?= e($address['line1']) ?>">
                    <input type="hidden" data-shipping-base name="shipping_line2" value="<?= e($address['line2']) ?>">
                    <input type="hidden" data-shipping-base name="shipping_city" value="<?= e($address['city']) ?>">
                    <input type="hidden" data-shipping-base name="shipping_state" value="<?= e($address['state']) ?>">
                    <input type="hidden" data-shipping-base name="shipping_postal" value="<?= e($address['postal']) ?>">
                    <input type="hidden" data-shipping-base name="shipping_country" value="<?= e($address['country']) ?>">
                    <?php if ($memberHasShipping): ?>
                      <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Shipping to your profile address</p>
                        <p class="mt-2 text-sm text-gray-900 font-medium"><?= e($address['name']) ?></p>
                        <p class="text-sm text-gray-700"><?= e($address['line1']) ?><?= $address['line2'] !== '' ? ', ' . e($address['line2']) : '' ?></p>
                        <p class="text-sm text-gray-700"><?= e(trim($address['city'] . ' ' . $address['state'] . ' ' . $address['postal'])) ?></p>
                        <p class="text-sm text-gray-500"><?= e($address['country']) ?></p>
                      </div>
                      <details class="mt-4" data-shipping-override-toggle>
                        <summary class="cursor-pointer text-sm font-medium text-gray-700 hover:text-gray-900">Use a different shipping address</summary>
                        <div class="mt-4 grid grid-cols-1 gap-4">
                    <?php else: ?>
                      <div class="grid grid-cols-1 gap-4">
                    <?php endif; ?>
                          <div>
                            <label for="shipping-name" class="block text-sm font-medium text-gray-700 mb-1">Full name</label>
                            <input id="shipping-name" data-shipping-override name="shipping_name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e($address['name']) ?>">
                          </div>
                          <div>
                            <label for="shipping-line1" class="block text-sm font-medium text-gray-700 mb-1">Address line 1</label>
                            <input id="shipping-line1" data-shipping-override name="shipping_line1"
                                   data-google-autocomplete="address"
                                   data-google-autocomplete-city="#shipping-city"
                                   data-google-autocomplete-state="#shipping-state"
                                   data-google-autocomplete-postal="#shipping-postal"
                                   data-google-autocomplete-country="#shipping-country"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e($address['line1']) ?>">
                          </div>
                          <div>
                            <label for="shipping-line2" class="block text-sm font-medium text-gray-700 mb-1">Address line 2 (optional)</label>
                            <input id="shipping-line2" data-shipping-override name="shipping_line2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e($address['line2']) ?>">
                          </div>
                          <div class="grid grid-cols-3 gap-3">
                            <div>
                              <label for="shipping-city" class="block text-sm font-medium text-gray-700 mb-1">City</label>
                              <input id="shipping-city" data-shipping-override name="shipping_city" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e($address['city']) ?>">
                            </div>
                            <div>
                              <label for="shipping-state" class="block text-sm font-medium text-gray-700 mb-1">State</label>
                              <select id="shipping-state" data-shipping-override name="shipping_state" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                                <?php foreach (['', 'ACT','NSW','NT','QLD','SA','TAS','VIC','WA'] as $st): ?>
                                  <option value="<?= e($st) ?>" <?= strcasecmp($address['state'], $st) === 0 ? 'selected' : '' ?>><?= $st === '' ? 'Select' : e($st) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <div>
                              <label for="shipping-postal" class="block text-sm font-medium text-gray-700 mb-1">Postcode</label>
                              <input id="shipping-postal" data-shipping-override name="shipping_postal" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary" value="<?= e($address['postal']) ?>">
                            </div>
                          </div>
                          <div>
                            <label for="shipping-country" class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                            <input id="shipping-country" data-shipping-override name="shipping_country" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-gray-50 text-gray-600" value="<?= e($address['country']) ?>" readonly>
                          </div>
                    <?php if ($memberHasShipping): ?>
                        </div>
                      </details>
                    <?php else: ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php }
              ); ?>
            <?php endif; ?>

            <?php $renderSection('payment', 'Payment',
              function () { echo 'Choose how you would like to pay'; },
              function () use ($cardEnabled, $bankTransferEnabled, $bankTransferInstructions) { ?>
                <div id="payment-method-error" class="hidden bg-red-50 border border-red-200 text-red-800 rounded-lg px-3 py-2 text-sm mb-3"></div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-label="Payment method">
                  <label class="flex items-center gap-3 p-4 rounded-xl border border-gray-200 cursor-pointer hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-primary/5 transition-colors <?= $cardEnabled ? '' : 'opacity-50 cursor-not-allowed' ?>">
                    <input type="radio" name="payment_method" value="card" data-payment-toggle="card" class="text-primary focus:ring-primary" <?= $cardEnabled ? '' : 'disabled' ?>>
                    <span class="material-icons-outlined text-gray-700">credit_card</span>
                    <span class="flex-1">
                      <span class="block text-sm font-semibold text-gray-900">Credit or debit card</span>
                      <span class="block text-xs text-gray-500">Visa, Mastercard, Amex · Apple Pay · Google Pay</span>
                    </span>
                  </label>
                  <label class="flex items-center gap-3 p-4 rounded-xl border border-gray-200 cursor-pointer hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-primary/5 transition-colors <?= $bankTransferEnabled ? '' : 'opacity-50 cursor-not-allowed' ?>">
                    <input type="radio" name="payment_method" value="bank_transfer" data-payment-toggle="bank_transfer" class="text-primary focus:ring-primary" <?= $bankTransferEnabled ? '' : 'disabled' ?>>
                    <span class="material-icons-outlined text-gray-700">account_balance</span>
                    <span class="flex-1">
                      <span class="block text-sm font-semibold text-gray-900">Bank transfer</span>
                      <span class="block text-xs text-gray-500">Manual EFT — order held until received</span>
                    </span>
                  </label>
                </div>

                <div class="mt-4 hidden" data-payment-panel="card">
                  <?php
                    $_secBlock = __DIR__ . '/../app/Views/partials/stripe_security_block.php';
                    if (file_exists($_secBlock)) { require $_secBlock; }
                  ?>
                  <p id="stripe-payment-note" class="text-xs text-gray-500 mb-2">Enter your card details below — they go straight to Stripe, never to us.</p>
                  <div id="stripe-payment-element"></div>
                  <div id="stripe-payment-error" class="hidden mt-2 bg-red-50 border border-red-200 text-red-800 rounded-lg px-3 py-2 text-sm"></div>
                </div>
                <div class="mt-4 hidden" data-payment-panel="bank_transfer">
                  <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm text-gray-700 whitespace-pre-line"><?= e($bankTransferInstructions) ?></div>
                </div>
              <?php }
            ); ?>

          </div>

          <aside class="lg:sticky lg:top-6 self-start space-y-4">
            <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
              <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h2 class="font-display text-lg font-semibold text-gray-900">Your order</h2>
                <a href="/store/cart" class="text-sm font-medium text-gray-600 hover:text-gray-900">Edit cart</a>
              </div>
              <!-- checkout-version: <?= e(defined('CHECKOUT_VERSION') ? CHECKOUT_VERSION : 'unknown') ?> -->
              <!-- user-id-used: <?= (int) ($DEBUG_user_id_used ?? -1) ?> -->
              <!-- user-email: <?= e($DEBUG_user_email ?? '') ?> -->
              <!-- cart-id-used: <?= (int) ($DEBUG_cart_id_used ?? -1) ?> -->
              <!-- items-count-at-fetch: <?= (int) ($DEBUG_items_count_at_fetch ?? -1) ?> -->
              <!-- first-item-keys: <?= e((string) ($DEBUG_first_item_keys ?? '')) ?> -->
              <!-- first-item-id: <?= e((string) ($DEBUG_first_item_id ?? '')) ?> -->
              <!-- first-item-title: <?= e((string) ($DEBUG_first_item_title ?? '')) ?> -->
              <!-- first-item-qty: <?= e((string) ($DEBUG_first_item_qty ?? '')) ?> -->
              <!-- items-count: <?= (int) (is_array($cartItems) ? count($cartItems) : -1) ?> -->
              <!-- items-keys: <?= e(is_array($cartItems) && !empty($cartItems) ? implode(',', array_keys($cartItems)) : '') ?> -->
              <?php
                // Defensive rebuild: even if something earlier somehow expanded $cartItems,
                // collapse it back to one row per unique store_cart_items.id.
                if (is_array($cartItems)) {
                    $renderItems = [];
                    foreach ($cartItems as $rrow) {
                        $rid = isset($rrow['id']) ? (int) $rrow['id'] : null;
                        if ($rid === null || $rid <= 0) { continue; }
                        if (((int) ($rrow['quantity'] ?? 0)) <= 0) { continue; }
                        if (trim((string) ($rrow['title_snapshot'] ?? '')) === '') { continue; }
                        $renderItems[$rid] = $rrow;
                    }
                    $renderItems = array_values($renderItems);
                } else {
                    $renderItems = [];
                }
              ?>
              <!-- render-items-count: <?= count($renderItems) ?> -->
              <ul class="divide-y divide-gray-100">
                <?php foreach ($renderItems as $item):
                  $imgUrl = $cartItemImages[(int) $item['product_id']] ?? '';
                  $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
                ?>
                  <li class="flex items-start gap-3 px-5 py-4">
                    <div class="w-16 h-16 rounded-lg bg-gray-100 overflow-hidden shrink-0 relative">
                      <?php if ($imgUrl): ?>
                        <img src="<?= e($imgUrl) ?>" alt="" class="w-full h-full object-cover">
                      <?php else: ?>
                        <span class="material-icons-outlined absolute inset-0 m-auto text-gray-400">image</span>
                      <?php endif; ?>
                      <span class="absolute -top-2 -right-2 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-gray-900 text-white text-xs font-bold"><?= e((string) (int) $item['quantity']) ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-semibold text-gray-900 truncate"><?= e($item['title_snapshot']) ?></p>
                      <?php if (!empty($item['variant_snapshot'])): ?>
                        <p class="text-xs text-gray-500 truncate"><?= e($item['variant_snapshot']) ?></p>
                      <?php endif; ?>
                      <p class="text-xs text-gray-500 mt-0.5">Qty <?= e((string) (int) $item['quantity']) ?> · $<?= e(store_money((float) $item['unit_price'])) ?> ea</p>
                    </div>
                    <div class="text-sm font-semibold text-gray-900 whitespace-nowrap">$<?= e(store_money($lineTotal)) ?></div>
                  </li>
                <?php endforeach; ?>
              </ul>

              <div class="px-5 py-4 border-t border-gray-100">
                <?php if (!empty($cart['discount_code'])): ?>
                  <div class="flex items-center justify-between gap-3 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
                    <span class="text-sm text-green-800">Code <strong><?= e($cart['discount_code']) ?></strong> applied</span>
                    <button type="submit" name="action" value="clear_discount" class="text-xs font-semibold text-green-800 hover:text-green-900 underline">Remove</button>
                  </div>
                <?php else: ?>
                  <div class="flex gap-2">
                    <input id="discount-code" name="discount_code" placeholder="Discount code" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                    <button type="submit" name="action" value="apply_discount" class="px-4 py-2 rounded-lg bg-amber-100 hover:bg-amber-200 text-amber-900 text-sm font-semibold transition-colors border border-amber-200">Apply</button>
                  </div>
                <?php endif; ?>
              </div>

              <div class="px-5 py-4 border-t border-gray-100 space-y-2 text-sm">
                <div class="flex justify-between text-gray-700">
                  <span>Subtotal</span>
                  <span>$<?= e(store_money($totals['subtotal'])) ?></span>
                </div>
                <?php if ($totals['discount_total'] > 0): ?>
                  <div class="flex justify-between text-green-700">
                    <span>Discount</span>
                    <span>-$<?= e(store_money($totals['discount_total'])) ?></span>
                  </div>
                <?php endif; ?>
                <div class="flex justify-between text-gray-700">
                  <span>Shipping</span>
                  <span>$<?= e(store_money($totals['shipping_total'])) ?></span>
                </div>
                <?php if (!empty($totals['tax_total'])): ?>
                  <div class="flex justify-between text-gray-700">
                    <span>GST</span>
                    <span>$<?= e(store_money($totals['tax_total'])) ?></span>
                  </div>
                <?php endif; ?>
                <?php if ($totals['processing_fee_total'] > 0): ?>
                  <div class="flex justify-between text-gray-700">
                    <span>Processing fee</span>
                    <span>$<?= e(store_money($totals['processing_fee_total'])) ?></span>
                  </div>
                <?php endif; ?>
                <div class="flex justify-between pt-2 mt-2 border-t border-gray-200 text-base font-bold text-gray-900">
                  <span>Total</span>
                  <span>$<?= e(store_money($totals['total'])) ?></span>
                </div>
              </div>

              <div class="px-5 py-5 border-t border-gray-100 space-y-3">
                <button type="button" data-pay-button class="w-full inline-flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold text-base transition-colors shadow-sm">
                  <!-- Inline SVG instead of icon font: the Material Icons
                       Outlined font occasionally renders the word "lock" as
                       text on this button (FOUC during late font load).
                       SVG is bulletproof. -->
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                  </svg>
                  Pay $<?= e(store_money($totals['total'])) ?>
                </button>
                <p class="flex items-center justify-center gap-1.5 text-xs text-gray-500">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                  </svg>
                  Secure checkout · 256-bit SSL · Powered by Stripe
                </p>
              </div>
            </section>

            <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-4 flex items-start gap-3">
              <span class="material-icons-outlined text-amber-500 mt-0.5">verified</span>
              <div>
                <p class="text-sm font-semibold text-gray-900">Official member benefit</p>
                <p class="text-xs text-gray-500 mt-0.5">Your association membership grants you access to limited-run apparel and official patches.</p>
              </div>
            </section>
          </aside>
        </form>

      <?php endif; ?>
    </div>
  </main>
</div>

<script>
(function () {
  const root = document.querySelector('[data-checkout-accordion]');
  if (!root) return;
  const sections = Array.from(root.querySelectorAll('[data-checkout-section]'));

  function setOpen(section, open) {
    const body = section.querySelector('[data-section-body]');
    const chev = section.querySelector('[data-section-chevron]');
    if (!body) return;
    if (open) {
      body.classList.remove('hidden');
      if (chev) chev.style.transform = 'rotate(180deg)';
      section.dataset.open = '1';
    } else {
      body.classList.add('hidden');
      if (chev) chev.style.transform = '';
      delete section.dataset.open;
    }
  }

  function markComplete(section) {
    const badge = section.querySelector('[data-section-badge]');
    if (!badge) return;
    badge.classList.remove('bg-primary');
    badge.classList.add('bg-secondary', 'text-white');
    badge.innerHTML = '<span class="material-icons-outlined text-base">check</span>';
  }

  function updateSummary(section) {
    const summary = section.querySelector('[data-section-summary]');
    const body = section.querySelector('[data-section-body]');
    if (!summary || !body) return;
    const key = section.dataset.checkoutSection;
    if (key === 'contact') {
      const first = body.querySelector('[name="guest_first_name"]')?.value || '';
      const last = body.querySelector('[name="guest_last_name"]')?.value || '';
      const email = body.querySelector('[name="guest_email"]')?.value || '';
      const name = (first + ' ' + last).trim();
      summary.textContent = [name, email].filter(Boolean).join(' · ');
    } else if (key === 'shipping') {
      const v = (n) => (body.querySelector(`[data-shipping-override][name="${n}"]`)?.value || body.querySelector(`[data-shipping-base][name="${n}"]`)?.value || '');
      const line = `${v('shipping_line1')}, ${v('shipping_city')} ${v('shipping_state')} ${v('shipping_postal')}`.trim();
      if (line !== ',  ') summary.textContent = line;
    } else if (key === 'payment') {
      const picked = body.querySelector('input[name="payment_method"]:checked');
      summary.textContent = picked ? (picked.value === 'card' ? 'Credit or debit card' : 'Bank transfer') : 'Choose how you would like to pay';
    }
  }

  sections.forEach((section) => {
    const toggle = section.querySelector('[data-section-toggle]');
    if (toggle) {
      toggle.addEventListener('click', () => {
        const isOpen = !!section.dataset.open;
        sections.forEach((s) => setOpen(s, false));
        if (!isOpen) setOpen(section, true);
      });
    }
    const cont = section.querySelector('[data-section-continue]');
    if (cont) {
      cont.addEventListener('click', () => {
        updateSummary(section);
        markComplete(section);
        const idx = sections.indexOf(section);
        const next = sections[idx + 1];
        setOpen(section, false);
        if (next) setOpen(next, true);
      });
    }
    if (section.dataset.open) {
      const chev = section.querySelector('[data-section-chevron]');
      if (chev) chev.style.transform = 'rotate(180deg)';
    }
  });
})();
</script>

<script src="https://js.stripe.com/v3/"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('checkout-form');
    if (!form) {
      return;
    }
    const payButton = form.querySelector('[data-pay-button]');
    const errorEl = document.getElementById('stripe-payment-error');
    const paymentElementContainer = document.getElementById('stripe-payment-element');
    const paymentMethodError = document.getElementById('payment-method-error');
    const paymentPanels = Array.from(document.querySelectorAll('[data-payment-panel]'));
    const paymentMethodInputs = Array.from(form.querySelectorAll('input[name="payment_method"]'));
    const payButtonLabel = payButton ? payButton.textContent : 'Pay now';
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
    let currentOrderNumber = null;
    let stripeInitInProgress = false;

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

    const getShippingValue = (name) => {
      const overrideInput = form.querySelector(`[data-shipping-override][name="${name}"]`);
      if (overrideInput && overrideInput.value.trim() !== '') {
        return overrideInput.value;
      }
      const baseInput = form.querySelector(`[data-shipping-base][name="${name}"]`);
      return baseInput ? baseInput.value : '';
    };

    const collectPayload = () => {
      const fulfillmentInput = form.querySelector('input[name="fulfillment"]');
      const fulfillment = fulfillmentInput ? fulfillmentInput.value : (requiresShipping ? 'shipping' : 'pickup');
      const methodInput = form.querySelector('input[name="payment_method"]:checked');
      const paymentMethod = methodInput ? methodInput.value : '';
      return {
        fulfillment,
        payment_method: paymentMethod,
        guest_email: form.querySelector('input[name="guest_email"]')?.value || '',
        guest_first_name: form.querySelector('input[name="guest_first_name"]')?.value || '',
        guest_last_name: form.querySelector('input[name="guest_last_name"]')?.value || '',
        guest_phone: form.querySelector('input[name="guest_phone"]')?.value || '',
        shipping_name: getShippingValue('shipping_name'),
        shipping_line1: getShippingValue('shipping_line1'),
        shipping_line2: getShippingValue('shipping_line2'),
        shipping_city: getShippingValue('shipping_city'),
        shipping_state: getShippingValue('shipping_state'),
        shipping_postal: getShippingValue('shipping_postal'),
        shipping_country: getShippingValue('shipping_country'),
      };
    };

    const validatePayload = (payload) => {
      if (guestRequired) {
        if (!payload.guest_email || !payload.guest_first_name || !payload.guest_last_name) {
          return 'Please enter your name and email.';
        }
      }
      if (!payload.payment_method) {
        return 'Please select a payment method.';
      }
      if (requiresShipping && requireShipping) {
        if (!payload.shipping_line1 || !payload.shipping_city || !payload.shipping_state || !payload.shipping_postal) {
          return 'Please complete the shipping address.';
        }
      }
      return '';
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

    const setPaymentPanel = (method) => {
      paymentPanels.forEach((panel) => {
        panel.hidden = panel.dataset.paymentPanel !== method;
      });
      if (payButton) {
        payButton.textContent = method === 'bank_transfer' ? 'Place order' : payButtonLabel;
      }
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

    // Eagerly create a PaymentIntent + mount the Stripe Payment Element so the
    // user can enter card details. Called when the card option is selected.
    // Re-entrant: if a request is in flight or the Element is already mounted
    // it returns immediately. Order rows are created on the server during this
    // call; abandoned orders are cleaned by /admin/cleanup-stuck-store-orders.
    const ensureStripeReady = async () => {
      if (clientSecret && paymentElement) {
        return;
      }
      if (stripeInitInProgress) {
        return;
      }
      stripeInitInProgress = true;
      setError('');
      try {
        const payload = collectPayload();
        payload.payment_method = 'card';
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
          throw new Error(data.error || 'Unable to prepare payment.');
        }
        if (!data.client_secret) {
          throw new Error('Unable to prepare payment — no client_secret returned.');
        }
        orderId = data.orderId || null;
        currentOrderNumber = data.orderNumber || null;
        await initStripeElements(data.client_secret);
      } catch (error) {
        setError(error.message || 'Unable to prepare payment.');
      } finally {
        stripeInitInProgress = false;
      }
    };

    if (paymentMethodInputs.length) {
      let defaultMethod = paymentMethodInputs.find((input) => !input.disabled && input.value === 'card');
      if (!defaultMethod) {
        defaultMethod = paymentMethodInputs.find((input) => !input.disabled && input.value === 'bank_transfer');
      }
      if (defaultMethod) {
        defaultMethod.checked = true;
        setPaymentPanel(defaultMethod.value);
        if (defaultMethod.value === 'card') {
          ensureStripeReady();
        }
      }
      paymentMethodInputs.forEach((input) => {
        input.addEventListener('change', () => {
          showPaymentMethodError('');
          setPaymentPanel(input.value);
          if (input.value === 'card') {
            ensureStripeReady();
          }
        });
      });
    }

    if (payButton) {
      payButton.addEventListener('click', async () => {
        setError('');
        showPaymentMethodError('');
        const payload = collectPayload();
        const validationError = validatePayload(payload);
        if (validationError) {
          if (validationError.includes('payment method')) {
            showPaymentMethodError(validationError);
          } else {
            setError(validationError);
          }
          return;
        }
        payButton.disabled = true;
        payButton.textContent = 'Processing...';
        try {
          if (payload.payment_method === 'bank_transfer') {
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
              throw new Error(data.error || 'Unable to place order.');
            }
            const bankOrderId = data.orderId || null;
            window.location.href = `${window.location.origin}/order/success?orderId=${encodeURIComponent(bankOrderId || '')}`;
            return;
          }
          // Card flow: PaymentIntent + Payment Element. The Element was mounted
          // on page load (ensureStripeReady). Now confirm the payment using the
          // card details the user entered. On success Stripe redirects to
          // return_url; the payment_intent.succeeded webhook updates the order
          // status and converts the cart.
          if (!clientSecret || !elements) {
            // Element wasn't ready when Pay was clicked — try to prepare now.
            await ensureStripeReady();
            if (!clientSecret || !elements) {
              throw new Error('Payment is not ready. Please reload the page and try again.');
            }
          }
          const returnPath = currentOrderNumber
            ? `/order/success?order=${encodeURIComponent(currentOrderNumber)}`
            : '/store/cart';
          const { error: confirmError } = await stripe.confirmPayment({
            elements,
            confirmParams: {
              return_url: `${window.location.origin}${returnPath}`,
            },
          });
          if (confirmError) {
            throw confirmError;
          }
        } catch (error) {
          setError(error.message || 'Payment could not be processed.');
          payButton.disabled = false;
          payButton.textContent = payload.payment_method === 'bank_transfer' ? 'Place order' : 'Pay now';
        }
      });
    }
  });
</script>

<?php require __DIR__ . '/../app/Views/partials/backend_footer.php'; ?>
