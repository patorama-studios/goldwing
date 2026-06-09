<?php
use App\Services\Csrf;
use App\Services\StripeSettingsService;

$userId = (int) ($user['id'] ?? 0);
$cart = store_get_open_cart($userId);
$stripeSettings = StripeSettingsService::getSettings();
$checkoutEnabled = !empty($stripeSettings['checkout_enabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Invalid CSRF token.</div>';
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
                echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Product not found.</div>';
            } else {
                $variant = null;
                $variantLabel = '';
                $skuSnapshot = $product['sku'] ?? '';
                if ((int) $product['has_variants'] === 1 && $variantId === 0) {
                    echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Please select a variant.</div>';
                    $product = null;
                }
            }

            if ($product) {
                if ($variantId) {
                    $stmt = $pdo->prepare('SELECT * FROM store_product_variants WHERE id = :id AND product_id = :product_id AND is_active = 1');
                    $stmt->execute(['id' => $variantId, 'product_id' => $productId]);
                    $variant = $stmt->fetch();
                    if (!$variant) {
                        echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Variant not found.</div>';
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
                        echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Insufficient stock for this item.</div>';
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
                        echo '<div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm mb-4">Added to cart.</div>';
                    }
                }
            }
        }

        if ($action === 'reorder') {
            if (!$userId) {
                echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Please log in to reorder past purchases.</div>';
            } else {
                $orderId = (int) ($_POST['order_id'] ?? 0);
                if ($orderId <= 0) {
                    echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Invalid order.</div>';
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id AND user_id = :user_id');
                    $stmt->execute(['id' => $orderId, 'user_id' => $userId]);
                    $order = $stmt->fetch();
                    if (!$order) {
                        echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">Order not found.</div>';
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
                            echo '<div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm mb-4">Reorder added ' . $addedCount . ' item(s) to your cart.</div>';
                        }
                        if ($skippedCount > 0) {
                            echo '<div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl px-4 py-3 text-sm mb-4">' . $skippedCount . ' item(s) could not be added.</div>';
                        }
                    }
                }
            }
        }

        if ($action === 'update_cart') {
            $removeItem = isset($_POST['remove_item']) ? (int) $_POST['remove_item'] : 0;
            if ($removeItem > 0) {
                $stmt = $pdo->prepare('DELETE FROM store_cart_items WHERE id = :id AND cart_id = :cart_id');
                $stmt->execute(['id' => $removeItem, 'cart_id' => $cart['id']]);
                echo '<div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm mb-4">Item removed.</div>';
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
                if ($maxQty <= 0) {
                    $stmt = $pdo->prepare('DELETE FROM store_cart_items WHERE id = :id AND cart_id = :cart_id');
                    $stmt->execute(['id' => $itemId, 'cart_id' => $cart['id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE store_cart_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id');
                    $stmt->execute(['quantity' => $maxQty, 'id' => $itemId]);
                }
            }
            if ($removeItem === 0) {
                echo '<div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm mb-4">Cart updated.</div>';
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
                echo '<div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm mb-4">' . e($result['error']) . '</div>';
            } else {
                $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = :code, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['code' => strtoupper($code), 'id' => $cart['id']]);
                echo '<div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm mb-4">Discount applied.</div>';
            }
        }

        if ($action === 'clear_discount') {
            $stmt = $pdo->prepare('UPDATE store_carts SET discount_code = NULL, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $cart['id']]);
            echo '<div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm mb-4">Discount removed.</div>';
        }
    }
}

$cart = store_get_open_cart($userId);

// Self-heal: drop ghost rows from this cart so they don't render as empty lines.
$cleanup = $pdo->prepare('DELETE FROM store_cart_items WHERE cart_id = :cart_id AND (quantity <= 0 OR unit_price < 0 OR title_snapshot IS NULL OR title_snapshot = "")');
$cleanup->execute(['cart_id' => $cart['id']]);

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

$cartProductImages = [];
if ($items) {
    $productIds = array_unique(array_map(function ($i) { return (int) $i['product_id']; }, $items));
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $imgStmt = $pdo->prepare("SELECT product_id, image_url FROM store_product_images WHERE product_id IN ($placeholders) ORDER BY product_id, sort_order ASC, id ASC");
        $imgStmt->execute($productIds);
        foreach ($imgStmt->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($cartProductImages[$pid])) {
                $cartProductImages[$pid] = $row['image_url'];
            }
        }
    }
}
?>

<?php if (!$items): ?>
  <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
    <span class="material-icons-outlined text-6xl text-gray-300">shopping_cart</span>
    <h2 class="mt-4 font-display text-2xl font-bold text-gray-900">Your cart is empty</h2>
    <p class="mt-2 text-gray-500">Browse the store to add some Goldwing gear.</p>
    <a href="/store" class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors">
      <span class="material-icons-outlined">storefront</span>
      Browse products
    </a>
  </section>
<?php else: ?>
  <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">

    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="update_cart">

      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <h2 class="font-display text-lg font-semibold text-gray-900">Cart items (<?= count($items) ?>)</h2>
          <button type="submit" class="text-sm font-semibold text-gray-700 hover:text-gray-900 inline-flex items-center gap-1">
            <span class="material-icons-outlined text-base">refresh</span>
            Update
          </button>
        </div>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($items as $item):
            $imgUrl = $cartProductImages[(int) $item['product_id']] ?? '';
            $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
          ?>
            <li class="flex items-start gap-4 px-6 py-5">
              <div class="w-20 h-20 rounded-xl bg-gray-50 border border-gray-100 overflow-hidden shrink-0 relative">
                <?php if ($imgUrl): ?>
                  <img src="<?= e($imgUrl) ?>" alt="" class="w-full h-full object-cover">
                <?php else: ?>
                  <div class="absolute inset-0 flex items-center justify-center">
                    <span class="material-icons-outlined text-gray-300 text-3xl">inventory_2</span>
                  </div>
                <?php endif; ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-900"><?= e($item['title_snapshot']) ?></p>
                <?php if (!empty($item['variant_snapshot'])): ?>
                  <p class="text-xs text-gray-500 mt-0.5"><?= e($item['variant_snapshot']) ?></p>
                <?php endif; ?>
                <p class="text-xs text-gray-500 mt-1">$<?= e(store_money((float) $item['unit_price'])) ?> ea</p>
                <div class="mt-3 flex items-center gap-3">
                  <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden bg-white">
                    <button type="button" class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-50" data-qty-step="-1" data-target="qty-<?= e((string) $item['id']) ?>" aria-label="Decrease quantity">
                      <span class="material-icons-outlined text-base">remove</span>
                    </button>
                    <input id="qty-<?= e((string) $item['id']) ?>" type="number" name="quantities[<?= e((string) $item['id']) ?>]" min="0" value="<?= e((string) $item['quantity']) ?>" class="w-12 text-center border-0 focus:ring-0 text-sm font-semibold py-1.5">
                    <button type="button" class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-50" data-qty-step="1" data-target="qty-<?= e((string) $item['id']) ?>" aria-label="Increase quantity">
                      <span class="material-icons-outlined text-base">add</span>
                    </button>
                  </div>
                  <button type="submit" name="remove_item" value="<?= e((string) $item['id']) ?>" class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700">
                    <span class="material-icons-outlined text-base">delete_outline</span>
                    Remove
                  </button>
                </div>
              </div>
              <div class="text-base font-bold text-gray-900 whitespace-nowrap">$<?= e(store_money($lineTotal)) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </form>

    <aside class="lg:sticky lg:top-6 self-start space-y-4">
      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-4">Discount code</h2>
        <?php if (!empty($cart['discount_code'])): ?>
          <div class="flex items-center justify-between gap-3 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
            <span class="text-sm text-green-800">Code <strong><?= e($cart['discount_code']) ?></strong> applied</span>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="clear_discount">
              <button type="submit" class="text-xs font-semibold text-green-800 hover:text-green-900 underline">Remove</button>
            </form>
          </div>
        <?php else: ?>
          <form method="post" class="flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="apply_discount">
            <input id="discount_code" name="discount_code" placeholder="Enter code" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-primary">
            <button type="submit" class="px-4 py-2 rounded-lg bg-amber-100 hover:bg-amber-200 text-amber-900 text-sm font-semibold border border-amber-200 transition-colors">Apply</button>
          </form>
        <?php endif; ?>
      </section>

      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
          <h2 class="font-display text-lg font-semibold text-gray-900">Order summary</h2>
        </div>
        <div class="px-6 py-4 space-y-2 text-sm">
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
          <div class="flex justify-between pt-3 mt-2 border-t border-gray-200 text-base font-bold text-gray-900">
            <span>Total</span>
            <span>$<?= e(store_money($totals['total'])) ?></span>
          </div>
        </div>
        <div class="px-6 py-5 border-t border-gray-100 space-y-3">
          <?php if ($checkoutEnabled): ?>
            <a href="/checkout" class="w-full inline-flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold text-base transition-colors shadow-sm">
              <span class="material-icons-outlined text-lg">lock</span>
              Proceed to checkout
            </a>
          <?php else: ?>
            <div class="text-center text-sm text-gray-500 italic py-2">Checkout is currently unavailable.</div>
          <?php endif; ?>
          <a href="/store" class="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm transition-colors">
            <span class="material-icons-outlined text-base">storefront</span>
            Continue shopping
          </a>
        </div>
      </section>

      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-4 flex items-start gap-3">
        <span class="material-icons-outlined text-amber-500 mt-0.5">verified</span>
        <div>
          <p class="text-sm font-semibold text-gray-900">Members-only store</p>
          <p class="text-xs text-gray-500 mt-0.5">All gear is officially licensed and exclusive to current members.</p>
        </div>
      </section>
    </aside>
  </div>
<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('[data-qty-step]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var step = parseInt(btn.getAttribute('data-qty-step'), 10);
      var target = document.getElementById(btn.getAttribute('data-target'));
      if (!target) return;
      var next = Math.max(0, (parseInt(target.value, 10) || 0) + step);
      target.value = next;
    });
  });
})();
</script>
