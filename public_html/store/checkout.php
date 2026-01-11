<?php
header('Location: /checkout');
exit;

use App\Services\Csrf;
use App\Services\OrderService;
use App\Services\PaymentSettingsService;
use App\Services\StripeService;
use App\Services\SettingsService;

$cart = store_get_open_cart((int) $user['id']);
$itemsStmt = $pdo->prepare('SELECT ci.*, p.type, p.event_name, p.track_inventory, p.stock_quantity, v.stock_quantity as variant_stock FROM store_cart_items ci JOIN store_products p ON p.id = ci.product_id LEFT JOIN store_product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = :cart_id');
$itemsStmt->execute(['cart_id' => $cart['id']]);
$items = $itemsStmt->fetchAll();
$channel = PaymentSettingsService::getChannelByCode('primary');
 
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
if (empty($user['member_id'])) {
    echo '<div class="alert error">Store checkout is available to members only.</div>';
    return;
}

$fulfillment = $_POST['fulfillment'] ?? 'shipping';
$pickupEnabled = (int) ($settings['pickup_enabled'] ?? 0) === 1;
if (!$requiresShipping) {
    $fulfillment = 'pickup';
}
if ($requiresShipping && !$pickupEnabled && $fulfillment === 'pickup') {
    $fulfillment = 'shipping';
}
$checkoutEnabled = true;
$shippingRegion = strtoupper((string) ($settings['shipping_region'] ?? 'AU'));

$address = [
    'name' => trim($_POST['shipping_name'] ?? ($member ? ($member['first_name'] . ' ' . $member['last_name']) : ($user['name'] ?? ''))),
    'line1' => trim($_POST['shipping_line1'] ?? ($member['address_line1'] ?? '')),
    'line2' => trim($_POST['shipping_line2'] ?? ($member['address_line2'] ?? '')),
    'city' => trim($_POST['shipping_city'] ?? ($member['city'] ?? '')),
    'state' => trim($_POST['shipping_state'] ?? ($member['state'] ?? '')),
    'postal' => trim($_POST['shipping_postal'] ?? ($member['postal_code'] ?? '')),
    'country' => trim($_POST['shipping_country'] ?? ($member['country'] ?? 'Australia')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        echo '<div class="alert error">Invalid CSRF token.</div>';
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
                echo '<div class="alert error">' . e($result['error']) . '</div>';
            } else {
                $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = :code, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['code' => strtoupper($code), 'id' => $cart['id']]);
                echo '<div class="alert success">Discount applied.</div>';
                $cart['discount_code'] = strtoupper($code);
            }
        }

        if ($action === 'clear_discount') {
            $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = NULL, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $cart['id']]);
            $cart['discount_code'] = null;
            echo '<div class="alert success">Discount removed.</div>';
        }

        if ($action === 'create_checkout') {
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
            if ($requiresShipping) {
                $threshold = (float) ($settings['shipping_free_threshold'] ?? 0);
                $flatRate = (float) ($settings['shipping_flat_rate'] ?? 0);
                if (!empty($settings['shipping_free_enabled']) && $threshold > 0 && $subtotalAfterDiscount >= $threshold) {
                    $shippingAvailable = true;
                } elseif (!empty($settings['shipping_flat_enabled']) && $flatRate > 0) {
                    $shippingAvailable = true;
                }
            }

            if (!$checkoutEnabled) {
                echo '<div class="alert error">Checkout is currently unavailable.</div>';
            } elseif (!$items) {
                echo '<div class="alert error">Your cart is empty.</div>';
            } elseif ($requiresShipping && $fulfillment === 'shipping' && !$shippingAvailable) {
                echo '<div class="alert error">Shipping is not available for this order. Choose pickup.</div>';
            } elseif ($requiresShipping && $fulfillment === 'pickup' && !$pickupEnabled) {
                echo '<div class="alert error">Pickup is not available.</div>';
            } else {
                $country = strtolower($address['country']);
                if ($requiresShipping && $fulfillment === 'shipping' && $shippingRegion === 'AU' && $country !== 'australia' && $country !== 'au') {
                    echo '<div class="alert error">Shipping is available in Australia only.</div>';
                } elseif ($requiresShipping && $fulfillment === 'shipping' && ($address['line1'] === '' || $address['city'] === '' || $address['state'] === '' || $address['postal'] === '')) {
                    echo '<div class="alert error">Please complete the shipping address.</div>';
                } else {
                    $stockErrors = [];
                    foreach ($items as $item) {
                        if ((int) $item['track_inventory'] !== 1) {
                            continue;
                        }
                        $available = $item['variant_id'] ? (int) ($item['variant_stock'] ?? 0) : (int) ($item['stock_quantity'] ?? 0);
                        if ($available < (int) $item['quantity']) {
                            $stockErrors[] = $item['title_snapshot'] . ' is out of stock.';
                        }
                    }
                    if ($stockErrors) {
                        echo '<div class="alert error">' . e(implode(' ', $stockErrors)) . '</div>';
                    } else {
                        $orderNumber = store_generate_order_number();
                        $orderPayload = [
                            'order_number' => $orderNumber,
                            'user_id' => $user['id'],
                            'member_id' => $user['member_id'] ?? null,
                            'status' => 'pending',
                            'subtotal' => $totals['subtotal'],
                            'discount_total' => $totals['discount_total'],
                            'shipping_total' => $totals['shipping_total'],
                            'processing_fee_total' => $totals['processing_fee_total'],
                            'total' => $totals['total'],
                            'discount_code' => $cart['discount_code'] ?? null,
                            'discount_id' => $discount['id'] ?? null,
                            'fulfillment_method' => $fulfillment,
                            'shipping_name' => $fulfillment === 'shipping' ? $address['name'] : null,
                            'shipping_address_line1' => $fulfillment === 'shipping' ? $address['line1'] : null,
                            'shipping_address_line2' => $fulfillment === 'shipping' ? $address['line2'] : null,
                            'shipping_city' => $fulfillment === 'shipping' ? $address['city'] : null,
                            'shipping_state' => $fulfillment === 'shipping' ? $address['state'] : null,
                            'shipping_postal_code' => $fulfillment === 'shipping' ? $address['postal'] : null,
                            'shipping_country' => $fulfillment === 'shipping' ? ($shippingRegion === 'AU' ? 'Australia' : $address['country']) : null,
                            'pickup_instructions_snapshot' => $fulfillment === 'pickup' ? ($settings['pickup_instructions'] ?? '') : null,
                            'customer_name' => $address['name'],
                            'customer_email' => $user['email'] ?? '',
                        ];
                        $stmt = $pdo->prepare('INSERT INTO store_orders (order_number, user_id, member_id, status, subtotal, discount_total, shipping_total, processing_fee_total, total, discount_code, discount_id, fulfillment_method, shipping_name, shipping_address_line1, shipping_address_line2, shipping_city, shipping_state, shipping_postal_code, shipping_country, pickup_instructions_snapshot, customer_name, customer_email, created_at) VALUES (:order_number, :user_id, :member_id, :status, :subtotal, :discount_total, :shipping_total, :processing_fee_total, :total, :discount_code, :discount_id, :fulfillment_method, :shipping_name, :shipping_address_line1, :shipping_address_line2, :shipping_city, :shipping_state, :shipping_postal_code, :shipping_country, :pickup_instructions_snapshot, :customer_name, :customer_email, NOW())');
                        $stmt->execute($orderPayload);
                        $orderId = (int) $pdo->lastInsertId();

                        $itemsWithDiscount = store_apply_discount_to_items($items, $totals['discount_total']);
                        foreach ($itemsWithDiscount as $item) {
                            $stmt = $pdo->prepare('INSERT INTO store_order_items (order_id, product_id, variant_id, title_snapshot, variant_snapshot, sku_snapshot, type, event_name_snapshot, quantity, unit_price, unit_price_final, line_total, created_at) VALUES (:order_id, :product_id, :variant_id, :title_snapshot, :variant_snapshot, :sku_snapshot, :type, :event_name_snapshot, :quantity, :unit_price, :unit_price_final, :line_total, NOW())');
                            $stmt->execute([
                                'order_id' => $orderId,
                                'product_id' => $item['product_id'],
                                'variant_id' => $item['variant_id'],
                                'title_snapshot' => $item['title_snapshot'],
                                'variant_snapshot' => $item['variant_snapshot'],
                                'sku_snapshot' => $item['sku_snapshot'],
                                'type' => $item['type'],
                                'event_name_snapshot' => $item['event_name'],
                                'quantity' => $item['quantity'],
                                'unit_price' => $item['unit_price'],
                                'unit_price_final' => $item['unit_price_final'],
                                'line_total' => $item['line_total'],
                            ]);
                        }

                        if ($discount) {
                            $stmt = $pdo->prepare('INSERT INTO store_order_discounts (order_id, discount_id, code, type, value, amount, created_at) VALUES (:order_id, :discount_id, :code, :type, :value, :amount, NOW())');
                            $stmt->execute([
                                'order_id' => $orderId,
                                'discount_id' => $discount['id'],
                                'code' => $discount['code'],
                                'type' => $discount['type'],
                                'value' => $discount['value'],
                                'amount' => $totals['discount_total'],
                            ]);
                        }

                        $orderSubtotal = max(0.0, $totals['subtotal'] - $totals['discount_total'] + $totals['processing_fee_total']);
                        $orderItems = array_map(function ($item) {
                            return [
                                'product_id' => $item['product_id'],
                                'name' => $item['title_snapshot'] . ($item['variant_snapshot'] ? ' (' . $item['variant_snapshot'] . ')' : ''),
                                'quantity' => (int) $item['quantity'],
                                'unit_price' => (float) $item['unit_price_final'],
                                'is_physical' => ($item['type'] ?? '') === 'physical' ? 1 : 0,
                            ];
                        }, $itemsWithDiscount);
                        if ($totals['processing_fee_total'] > 0) {
                            $orderItems[] = [
                                'product_id' => null,
                                'name' => 'Payment processing fee',
                                'quantity' => 1,
                                'unit_price' => (float) $totals['processing_fee_total'],
                                'is_physical' => 0,
                            ];
                        }

                        $paymentOrderId = OrderService::createOrder([
                            'user_id' => $user['id'],
                            'status' => 'pending',
                            'order_type' => 'store',
                            'currency' => 'AUD',
                            'subtotal' => $orderSubtotal,
                            'tax_total' => $totals['tax_total'] ?? 0,
                            'shipping_total' => $totals['shipping_total'],
                            'total' => $totals['total'],
                            'channel_id' => $channel['id'],
                            'shipping_required' => $requiresShipping ? 1 : 0,
                            'shipping_address_json' => json_encode([
                                'fulfillment' => $fulfillment,
                                'shipping' => $fulfillment === 'shipping' ? $address : null,
                                'pickup_instructions' => $fulfillment === 'pickup' ? ($settings['pickup_instructions'] ?? '') : null,
                                'store_order_id' => $orderId,
                                'store_order_number' => $orderNumber,
                            ]),
                        ], $orderItems);

                        $lineItems = [];
                        foreach ($itemsWithDiscount as $item) {
                            $lineItems[] = [
                                'name' => $item['title_snapshot'] . ($item['variant_snapshot'] ? ' (' . $item['variant_snapshot'] . ')' : ''),
                                'unit_amount' => (int) round($item['unit_price_final'] * 100),
                                'quantity' => (int) $item['quantity'],
                                'currency' => 'aud',
                            ];
                        }
                        if ($totals['shipping_total'] > 0) {
                            $lineItems[] = [
                                'name' => 'Shipping',
                                'unit_amount' => (int) round($totals['shipping_total'] * 100),
                                'quantity' => 1,
                                'currency' => 'aud',
                            ];
                        }
                        if (!empty($totals['tax_total'])) {
                            $lineItems[] = [
                                'name' => 'GST',
                                'unit_amount' => (int) round($totals['tax_total'] * 100),
                                'quantity' => 1,
                                'currency' => 'aud',
                            ];
                        }
                        if ($totals['processing_fee_total'] > 0) {
                            $lineItems[] = [
                                'name' => 'Payment processing fee',
                                'unit_amount' => (int) round($totals['processing_fee_total'] * 100),
                                'quantity' => 1,
                                'currency' => 'aud',
                            ];
                        }

                        $baseUrl = SettingsService::getGlobal('site.base_url', '');
                        $successUrl = $baseUrl . '/store/orders/' . $orderNumber . '?success=1';
                        $cancelUrl = $baseUrl . '/store/cart?cancel=1';
                        $session = StripeService::createCheckoutSessionWithLineItems($lineItems, $user['email'] ?? '', $successUrl, $cancelUrl, [
                            'order_id' => $paymentOrderId,
                            'channel_id' => $channel['id'],
                            'order_type' => 'store',
                            'store_order_id' => $orderId,
                            'store_order_number' => $orderNumber,
                        ]);
                        if (!$session || empty($session['url'])) {
                            echo '<div class="alert error">Unable to start checkout. Please contact support.</div>';
                        } else {
                            $stmt = $pdo->prepare('UPDATE store_orders SET stripe_session_id = :session_id WHERE id = :id');
                            $stmt->execute(['session_id' => $session['id'], 'id' => $orderId]);
                            OrderService::updateStripeSession($paymentOrderId, $session['id']);
                            $stmt = $pdo->prepare('UPDATE store_carts SET status = "converted", updated_at = NOW() WHERE id = :id');
                            $stmt->execute(['id' => $cart['id']]);
                            header('Location: ' . $session['url']);
                            exit;
                        }
                    }
                }
            }
        }
    }
}

$cart = store_get_open_cart((int) $user['id']);
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
?>
<div class="grid gap-6">
  <h2>Checkout</h2>
  <?php
    $settingsRoles = array_intersect($user['roles'] ?? [], ['admin', 'super_admin', 'store_manager']);
  ?>
  <?php if ($settingsRoles): ?>
    <a class="text-sm text-blue-600" href="/admin/settings/index.php?section=store">Go to Store Settings</a>
  <?php endif; ?>

  <?php if (!$items): ?>
    <p>Your cart is empty.</p>
    <a class="button" href="/store">Browse products</a>
  <?php else: ?>
    <form method="post" class="grid gap-6">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <div class="card">
        <h3>Fulfillment</h3>
        <?php if ($requiresShipping): ?>
          <?php $shippingLabel = $shippingRegion === 'AU' ? 'Ship to address (Australia only)' : 'Ship to address'; ?>
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
        <div class="card">
          <h3>Shipping address</h3>
          <div class="form-group">
            <label for="shipping-name">Name</label>
            <input id="shipping-name" name="shipping_name" value="<?= e($address['name']) ?>">
          </div>
          <div class="form-group">
            <label for="shipping-line1">Address line 1</label>
            <input id="shipping-line1" name="shipping_line1" data-google-autocomplete="address" data-google-autocomplete-city="#shipping-city" data-google-autocomplete-state="#shipping-state" data-google-autocomplete-postal="#shipping-postal" data-google-autocomplete-country="#shipping-country" value="<?= e($address['line1']) ?>">
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

      <button type="submit" name="action" value="create_checkout" class="button primary">Pay with Stripe</button>
    </form>
  <?php endif; ?>
</div>
