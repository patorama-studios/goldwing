<?php
use App\Services\Csrf;

$slug = $subPage ?? '';
$product = null;
if ($slug !== '') {
    $stmt = $pdo->prepare('SELECT * FROM store_products WHERE slug = :slug AND is_active = 1 LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $product = $stmt->fetch();
}

if (!$product) {
    ?>
    <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
      <span class="material-icons-outlined text-5xl text-gray-300">search_off</span>
      <h2 class="mt-3 text-xl font-semibold text-gray-900">Product not found</h2>
      <p class="mt-1 text-gray-500">The item you were looking for isn't available.</p>
      <a href="/store" class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold transition-colors">Back to store</a>
    </section>
    <?php
    return;
}

$pageTitle = $product['title'];

$stmt = $pdo->prepare('SELECT * FROM store_product_images WHERE product_id = :id ORDER BY sort_order ASC, id ASC');
$stmt->execute(['id' => $product['id']]);
$images = $stmt->fetchAll();

$variants = [];
$variantPrices = [];
if ((int) $product['has_variants'] === 1) {
    $stmt = $pdo->prepare('SELECT v.*, ov.value as option_value, o.name as option_name FROM store_product_variants v LEFT JOIN store_variant_option_values vov ON vov.variant_id = v.id LEFT JOIN store_product_option_values ov ON ov.id = vov.option_value_id LEFT JOIN store_product_options o ON o.id = ov.option_id WHERE v.product_id = :id AND v.is_active = 1 ORDER BY v.id ASC');
    $stmt->execute(['id' => $product['id']]);
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $variantId = $row['id'];
        if (!isset($grouped[$variantId])) {
            $grouped[$variantId] = [
                'id' => $variantId,
                'sku' => $row['sku'],
                'price_override' => $row['price_override'],
                'stock_quantity' => $row['stock_quantity'],
                'options' => [],
            ];
        }
        if ($row['option_name']) {
            $grouped[$variantId]['options'][] = [
                'option_name' => $row['option_name'],
                'value' => $row['option_value'],
            ];
        }
    }
    foreach ($grouped as $variant) {
        $label = store_build_variant_label($variant['options']);
        $price = $variant['price_override'] !== null ? (float) $variant['price_override'] : (float) $product['base_price'];
        $variantPrices[] = $price;
        $variants[] = [
            'id' => $variant['id'],
            'label' => $label,
            'price' => $price,
            'stock_quantity' => $variant['stock_quantity'],
        ];
    }
}

$priceDisplay = '$' . store_money((float) $product['base_price']);
if ($variantPrices) {
    $min = min($variantPrices);
    $max = max($variantPrices);
    $priceDisplay = $min === $max ? '$' . store_money($min) : '$' . store_money($min) . ' – $' . store_money($max);
}

$inStock = true;
if ((int) $product['track_inventory'] === 1) {
    if ((int) $product['has_variants'] === 1) {
        $totalStock = 0;
        foreach ($variants as $variant) {
            $totalStock += (int) ($variant['stock_quantity'] ?? 0);
        }
        $inStock = $totalStock > 0;
    } else {
        $inStock = (int) ($product['stock_quantity'] ?? 0) > 0;
    }
}

$relatedStmt = $pdo->prepare('SELECT p.*, (SELECT image_url FROM store_product_images i WHERE i.product_id = p.id ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) as image_url FROM store_products p WHERE p.is_active = 1 AND p.id != :id ORDER BY p.created_at DESC LIMIT 4');
$relatedStmt->execute(['id' => $product['id']]);
$relatedProducts = $relatedStmt->fetchAll();

$isTicket = ($product['type'] ?? '') === 'ticket';
$typeLabel = $isTicket ? 'Ticket' : 'Apparel';
?>
<nav class="flex items-center gap-2 text-sm text-gray-500" aria-label="Breadcrumb">
  <a href="/store" class="hover:text-gray-700">Store</a>
  <span class="material-icons-outlined text-base">chevron_right</span>
  <span class="text-gray-900 font-semibold truncate"><?= e($product['title']) ?></span>
</nav>

<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
  <div class="space-y-3" data-product-gallery>
    <div class="aspect-square rounded-2xl bg-gray-50 border border-gray-100 overflow-hidden relative">
      <?php if ($images): ?>
        <img src="<?= e($images[0]['image_url']) ?>" alt="<?= e($product['title']) ?>" class="w-full h-full object-cover" data-product-hero>
      <?php else: ?>
        <div class="absolute inset-0 flex items-center justify-center">
          <span class="material-icons-outlined text-7xl text-gray-300"><?= $isTicket ? 'confirmation_number' : 'checkroom' ?></span>
        </div>
      <?php endif; ?>
      <span class="absolute top-4 left-4 inline-flex items-center gap-1 px-3 py-1 rounded-full bg-white/95 backdrop-blur text-[11px] font-bold uppercase tracking-wider text-gray-700 shadow-sm">
        <span class="material-icons-outlined text-sm"><?= $isTicket ? 'confirmation_number' : 'checkroom' ?></span>
        <?= e($typeLabel) ?>
      </span>
    </div>
    <?php if (count($images) > 1): ?>
      <div class="grid grid-cols-5 gap-2">
        <?php foreach ($images as $i => $image): ?>
          <button type="button" class="aspect-square rounded-lg overflow-hidden border-2 <?= $i === 0 ? 'border-primary' : 'border-transparent hover:border-gray-300' ?> transition-colors" data-product-thumb="<?= e($image['image_url']) ?>">
            <img src="<?= e($image['image_url']) ?>" alt="<?= e($product['title']) ?> thumbnail" class="w-full h-full object-cover">
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8 space-y-5">
    <div>
      <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900"><?= e($product['title']) ?></h1>
      <p class="mt-3 text-3xl font-bold text-gray-900"><?= e($priceDisplay) ?></p>
      <?php if ((int) $product['track_inventory'] === 1): ?>
        <p class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold <?= $inStock ? 'text-green-700' : 'text-red-700' ?>">
          <span class="material-icons-outlined text-base"><?= $inStock ? 'check_circle' : 'cancel' ?></span>
          <?= $inStock ? 'In stock' : 'Out of stock' ?>
        </p>
      <?php endif; ?>
    </div>

    <form method="post" action="/store/cart" class="space-y-4" data-product-form>
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="add_to_cart">
      <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">

      <?php if ($variants): ?>
        <div>
          <label for="variant-id" class="block text-sm font-semibold text-gray-700 mb-1">Size / option</label>
          <select id="variant-id" name="variant_id" required class="w-full px-3 py-2.5 rounded-lg border border-gray-300 focus:border-primary focus:ring-primary text-sm">
            <option value="">Select an option</option>
            <?php foreach ($variants as $variant): ?>
              <?php $disabled = (int) $product['track_inventory'] === 1 && (int) ($variant['stock_quantity'] ?? 0) <= 0; ?>
              <option value="<?= e((string) $variant['id']) ?>" <?= $disabled ? 'disabled' : '' ?>>
                <?= e($variant['label']) ?> — $<?= e(store_money($variant['price'])) ?><?= $disabled ? ' (Out of stock)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div>
        <label for="quantity" class="block text-sm font-semibold text-gray-700 mb-1">Quantity</label>
        <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden" data-qty-wrap>
          <button type="button" class="px-3 py-2.5 text-gray-600 hover:bg-gray-50" data-qty-step="-1" aria-label="Decrease quantity">
            <span class="material-icons-outlined text-base">remove</span>
          </button>
          <input id="quantity" type="number" name="quantity" min="1" value="1" class="w-16 text-center border-0 focus:ring-0 text-sm font-semibold">
          <button type="button" class="px-3 py-2.5 text-gray-600 hover:bg-gray-50" data-qty-step="1" aria-label="Increase quantity">
            <span class="material-icons-outlined text-base">add</span>
          </button>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-3 pt-2">
        <button type="submit" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors disabled:bg-gray-300 disabled:cursor-not-allowed" <?= !$inStock ? 'disabled' : '' ?>>
          <span class="material-icons-outlined">add_shopping_cart</span>
          Add to cart
        </button>
        <a href="/store/cart" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold transition-colors">
          <span class="material-icons-outlined">shopping_cart</span>
          View cart
        </a>
      </div>
    </form>

    <?php if ($product['description']): ?>
      <div class="pt-5 border-t border-gray-100">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-2">Description</h2>
        <p class="text-sm text-gray-700 leading-6 whitespace-pre-line"><?= e($product['description']) ?></p>
      </div>
    <?php endif; ?>

    <div class="pt-4 border-t border-gray-100 flex items-start gap-3">
      <span class="material-icons-outlined text-amber-500 mt-0.5">verified</span>
      <div>
        <p class="text-sm font-semibold text-gray-900">Members-only merchandise</p>
        <p class="text-xs text-gray-500">Official Australian Goldwing Association gear — exclusive to current members.</p>
      </div>
    </div>
  </div>
</section>

<?php if ($relatedProducts): ?>
  <section class="space-y-4">
    <h2 class="font-display text-2xl font-bold text-gray-900">You might also like</h2>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
      <?php foreach ($relatedProducts as $related):
        $relIsTicket = ($related['type'] ?? '') === 'ticket';
      ?>
        <a href="/store/product/<?= e($related['slug']) ?>" class="group bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md hover:-translate-y-0.5 transition-all">
          <div class="aspect-square bg-gray-50 overflow-hidden relative">
            <?php if (!empty($related['image_url'])): ?>
              <img src="<?= e($related['image_url']) ?>" alt="<?= e($related['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
            <?php else: ?>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="material-icons-outlined text-5xl text-gray-300"><?= $relIsTicket ? 'confirmation_number' : 'checkroom' ?></span>
              </div>
            <?php endif; ?>
          </div>
          <div class="p-4">
            <h3 class="font-display text-sm font-semibold text-gray-900 line-clamp-2"><?= e($related['title']) ?></h3>
            <p class="mt-1.5 text-base font-bold text-gray-900">$<?= e(store_money((float) $related['base_price'])) ?></p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<script>
(function () {
  const gallery = document.querySelector('[data-product-gallery]');
  if (gallery) {
    const hero = gallery.querySelector('[data-product-hero]');
    const thumbs = gallery.querySelectorAll('[data-product-thumb]');
    thumbs.forEach((thumb) => {
      thumb.addEventListener('click', () => {
        const src = thumb.getAttribute('data-product-thumb');
        if (src && hero) hero.setAttribute('src', src);
        thumbs.forEach((t) => {
          t.classList.remove('border-primary');
          t.classList.add('border-transparent');
        });
        thumb.classList.remove('border-transparent');
        thumb.classList.add('border-primary');
      });
    });
  }
  const qtyWrap = document.querySelector('[data-qty-wrap]');
  if (qtyWrap) {
    const input = qtyWrap.querySelector('input[type="number"]');
    qtyWrap.querySelectorAll('[data-qty-step]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const step = parseInt(btn.getAttribute('data-qty-step'), 10);
        const next = Math.max(1, (parseInt(input.value, 10) || 1) + step);
        input.value = next;
      });
    });
  }
})();
</script>
