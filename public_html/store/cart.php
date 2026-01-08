<?php
use App\Services\Csrf;

$cart = store_get_open_cart((int) $user['id']);
$checkoutEnabled = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        echo '<div class="alert error">Invalid CSRF token.</div>';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_to_cart') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

            $stmt = $pdo->prepare('SELECT * FROM store_products WHERE id = :id AND is_active = 1');
            $stmt->execute(['id' => $productId]);
            $product = $stmt->fetch();

            if (!$product) {
                echo '<div class="alert error">Product not found.</div>';
            } else {
                $variant = null;
                $variantLabel = '';
                $skuSnapshot = $product['sku'] ?? '';
                if ((int) $product['has_variants'] === 1 && $variantId === 0) {
                    echo '<div class="alert error">Please select a variant.</div>';
                    $product = null;
                }
            }

            if ($product) {
                if ($variantId) {
                    $stmt = $pdo->prepare('SELECT * FROM store_product_variants WHERE id = :id AND product_id = :product_id AND is_active = 1');
                    $stmt->execute(['id' => $variantId, 'product_id' => $productId]);
                    $variant = $stmt->fetch();
                    if (!$variant) {
                        echo '<div class="alert error">Variant not found.</div>';
                        $variantId = 0;
                    } else {
                        $stmt = $pdo->prepare('SELECT o.name as option_name, v.value as option_value FROM store_variant_option_values vov JOIN store_product_option_values v ON v.id = vov.option_value_id JOIN store_product_options o ON o.id = v.option_id WHERE vov.variant_id = :variant_id');
                        $stmt->execute(['variant_id' => $variantId]);
                        $optionValues = [];
                        foreach ($stmt->fetchAll() as $row) {
                            $optionValues[] = ['option_name' => $row['option_name'], 'value' => $row['option_value']];
                        }
                        $variantLabel = store_build_variant_label($optionValues);
                        $skuSnapshot = $variant['sku'] ?? $skuSnapshot;
                    }
                }

                if ($product) {
                    $unitPrice = (float) $product['base_price'];
                    if ($variant && $variant['price_override'] !== null) {
                        $unitPrice = (float) $variant['price_override'];
                    }

                    $stockOk = true;
                    if ((int) $product['track_inventory'] === 1) {
                        if ($variantId) {
                            $stockOk = (int) ($variant['stock_quantity'] ?? 0) >= $quantity;
                        } else {
                            $stockOk = (int) ($product['stock_quantity'] ?? 0) >= $quantity;
                        }
                    }

                    if (!$stockOk) {
                        echo '<div class="alert error">Insufficient stock for this item.</div>';
                    } else {
                        $stmt = $pdo->prepare('SELECT * FROM store_cart_items WHERE cart_id = :cart_id AND product_id = :product_id AND variant_id <=> :variant_id');
                        $stmt->execute(['cart_id' => $cart['id'], 'product_id' => $productId, 'variant_id' => $variantId ?: null]);
                        $existing = $stmt->fetch();
                        if ($existing) {
                            $newQty = $existing['quantity'] + $quantity;
                            $stmt = $pdo->prepare('UPDATE store_cart_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id');
                            $stmt->execute(['quantity' => $newQty, 'id' => $existing['id']]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO store_cart_items (cart_id, product_id, variant_id, quantity, unit_price, title_snapshot, variant_snapshot, sku_snapshot, created_at) VALUES (:cart_id, :product_id, :variant_id, :quantity, :unit_price, :title_snapshot, :variant_snapshot, :sku_snapshot, NOW())');
                            $stmt->execute([
                                'cart_id' => $cart['id'],
                                'product_id' => $productId,
                                'variant_id' => $variantId ?: null,
                                'quantity' => $quantity,
                                'unit_price' => $unitPrice,
                                'title_snapshot' => $product['title'],
                                'variant_snapshot' => $variantLabel,
                                'sku_snapshot' => $skuSnapshot,
                            ]);
                        }
                        $stmt = $pdo->prepare('UPDATE store_carts SET updated_at = NOW() WHERE id = :id');
                        $stmt->execute(['id' => $cart['id']]);
                        echo '<div class="alert success">Added to cart.</div>';
                    }
                }
            }
        }

        if ($action === 'reorder') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                echo '<div class="alert error">Invalid order.</div>';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id AND user_id = :user_id');
                $stmt->execute(['id' => $orderId, 'user_id' => $user['id']]);
                $order = $stmt->fetch();
                if (!$order) {
                    echo '<div class="alert error">Order not found.</div>';
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :order_id');
                    $stmt->execute(['order_id' => $orderId]);
                    $items = $stmt->fetchAll();
                    $addedCount = 0;
                    $skippedCount = 0;

                    foreach ($items as $item) {
                        if (empty($item['product_id'])) {
                            $skippedCount++;
                            continue;
                        }
                        $productId = (int) $item['product_id'];
                        $variantId = (int) ($item['variant_id'] ?? 0);
                        $quantity = max(1, (int) ($item['quantity'] ?? 1));

                        $stmt = $pdo->prepare('SELECT * FROM store_products WHERE id = :id AND is_active = 1');
                        $stmt->execute(['id' => $productId]);
                        $product = $stmt->fetch();
                        if (!$product) {
                            $skippedCount++;
                            continue;
                        }

                        $variant = null;
                        $variantLabel = $item['variant_snapshot'] ?? '';
                        $skuSnapshot = $item['sku_snapshot'] ?? ($product['sku'] ?? '');
                        if ($variantId) {
                            $stmt = $pdo->prepare('SELECT * FROM store_product_variants WHERE id = :id AND product_id = :product_id AND is_active = 1');
                            $stmt->execute(['id' => $variantId, 'product_id' => $productId]);
                            $variant = $stmt->fetch();
                            if (!$variant) {
                                $variantId = 0;
                                $variantLabel = '';
                            } else {
                                $skuSnapshot = $variant['sku'] ?? $skuSnapshot;
                            }
                        }

                        $unitPrice = (float) $product['base_price'];
                        if ($variant && $variant['price_override'] !== null) {
                            $unitPrice = (float) $variant['price_override'];
                        }

                        $stockOk = true;
                        if ((int) $product['track_inventory'] === 1) {
                            if ($variantId) {
                                $stockOk = (int) ($variant['stock_quantity'] ?? 0) >= $quantity;
                            } else {
                                $stockOk = (int) ($product['stock_quantity'] ?? 0) >= $quantity;
                            }
                        }
                        if (!$stockOk) {
                            $skippedCount++;
                            continue;
                        }

                        $stmt = $pdo->prepare('SELECT * FROM store_cart_items WHERE cart_id = :cart_id AND product_id = :product_id AND variant_id <=> :variant_id');
                        $stmt->execute(['cart_id' => $cart['id'], 'product_id' => $productId, 'variant_id' => $variantId ?: null]);
                        $existing = $stmt->fetch();
                        if ($existing) {
                            $newQty = $existing['quantity'] + $quantity;
                            $stmt = $pdo->prepare('UPDATE store_cart_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id');
                            $stmt->execute(['quantity' => $newQty, 'id' => $existing['id']]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO store_cart_items (cart_id, product_id, variant_id, quantity, unit_price, title_snapshot, variant_snapshot, sku_snapshot, created_at) VALUES (:cart_id, :product_id, :variant_id, :quantity, :unit_price, :title_snapshot, :variant_snapshot, :sku_snapshot, NOW())');
                            $stmt->execute([
                                'cart_id' => $cart['id'],
                                'product_id' => $productId,
                                'variant_id' => $variantId ?: null,
                                'quantity' => $quantity,
                                'unit_price' => $unitPrice,
                                'title_snapshot' => $product['title'],
                                'variant_snapshot' => $variantLabel,
                                'sku_snapshot' => $skuSnapshot,
                            ]);
                        }
                        $addedCount++;
                    }

                    $stmt = $pdo->prepare('UPDATE store_carts SET updated_at = NOW() WHERE id = :id');
                    $stmt->execute(['id' => $cart['id']]);

                    if ($addedCount > 0) {
                        echo '<div class="alert success">Reorder added ' . $addedCount . ' item(s) to your cart.</div>';
                    }
                    if ($skippedCount > 0) {
                        echo '<div class="alert warning">' . $skippedCount . ' item(s) could not be added.</div>';
                    }
                }
            }
        }

        if ($action === 'update_cart') {
            $removeItem = isset($_POST['remove_item']) ? (int) $_POST['remove_item'] : 0;
            if ($removeItem > 0) {
                $stmt = $pdo->prepare('DELETE FROM store_cart_items WHERE id = :id AND cart_id = :cart_id');
                $stmt->execute(['id' => $removeItem, 'cart_id' => $cart['id']]);
                echo '<div class="alert success">Item removed.</div>';
            }
            $quantities = $_POST['quantities'] ?? [];
            foreach ($quantities as $itemId => $qty) {
                $itemId = (int) $itemId;
                $qty = (int) $qty;
                if ($itemId <= 0) {
                    continue;
                }
                if ($qty <= 0) {
                    $stmt = $pdo->prepare('DELETE FROM store_cart_items WHERE id = :id AND cart_id = :cart_id');
                    $stmt->execute(['id' => $itemId, 'cart_id' => $cart['id']]);
                    continue;
                }
                $stmt = $pdo->prepare('SELECT ci.*, p.track_inventory, p.stock_quantity, v.stock_quantity as variant_stock FROM store_cart_items ci JOIN store_products p ON p.id = ci.product_id LEFT JOIN store_product_variants v ON v.id = ci.variant_id WHERE ci.id = :id AND ci.cart_id = :cart_id');
                $stmt->execute(['id' => $itemId, 'cart_id' => $cart['id']]);
                $row = $stmt->fetch();
                if (!$row) {
                    continue;
                }
                $maxQty = $qty;
                if ((int) $row['track_inventory'] === 1) {
                    $stock = $row['variant_id'] ? (int) ($row['variant_stock'] ?? 0) : (int) ($row['stock_quantity'] ?? 0);
                    $maxQty = min($qty, $stock);
                }
                $stmt = $pdo->prepare('UPDATE store_cart_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['quantity' => $maxQty, 'id' => $itemId]);
            }
            if ($removeItem === 0) {
                echo '<div class="alert success">Cart updated.</div>';
            }
        }

        if ($action === 'apply_discount') {
            $code = trim($_POST['discount_code'] ?? '');
            $items = store_get_cart_items((int) $cart['id']);
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
            }
        }

        if ($action === 'clear_discount') {
            $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = NULL, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $cart['id']]);
            echo '<div class="alert success">Discount removed.</div>';
        }
    }
}

$cart = store_get_open_cart((int) $user['id']);
$items = store_get_cart_items((int) $cart['id']);
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

$totals = store_calculate_cart_totals($items, $discount, $settings, 'shipping');
$pageTitle = 'Your Cart';
?>
<div class="grid gap-6">
  <h2>Your cart</h2>

  <?php if (!$items): ?>
    <p>Your cart is empty.</p>
    <a class="button" href="/store">Browse products</a>
  <?php else: ?>
    <form method="post" class="card">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="update_cart">
      <table class="table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <strong><?= e($item['title_snapshot']) ?></strong>
                <?php if (!empty($item['variant_snapshot'])): ?>
                  <div style="font-size:0.85rem; color: var(--muted);"><?= e($item['variant_snapshot']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <input type="number" name="quantities[<?= e((string) $item['id']) ?>]" min="0" value="<?= e((string) $item['quantity']) ?>">
              </td>
              <td>$<?= e(store_money((float) $item['unit_price'])) ?></td>
              <td>$<?= e(store_money((float) $item['unit_price'] * (int) $item['quantity'])) ?></td>
              <td>
                <button class="button" type="submit" name="remove_item" value="<?= e((string) $item['id']) ?>">Remove</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="form-footer">
        <button class="button primary" type="submit">Update cart</button>
      </div>
    </form>

    <div class="grid gap-4 md:grid-cols-[1.2fr_1fr]">
      <div class="card">
        <h3>Discount</h3>
        <?php if (!empty($cart['discount_code'])): ?>
          <p>Applied code: <strong><?= e($cart['discount_code']) ?></strong></p>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="clear_discount">
            <button class="button" type="submit">Remove discount</button>
          </form>
        <?php else: ?>
          <form method="post" class="form-group">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="apply_discount">
            <label for="discount_code">Discount code</label>
            <input id="discount_code" name="discount_code" placeholder="Enter code">
            <button class="button" type="submit">Apply</button>
          </form>
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
        <p style="margin-top:1rem;">
          <?php if ($checkoutEnabled): ?>
            <a class="button primary" href="/store/checkout">Proceed to checkout</a>
          <?php else: ?>
            <span class="text-sm text-gray-500">Checkout is currently unavailable.</span>
          <?php endif; ?>
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>
