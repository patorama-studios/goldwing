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
    echo '<div class="alert error">Product not found.</div>';
    return;
}

$pageTitle = $product['title'];
$heroTitle = $product['title'];
$heroLead = $product['description'] ? substr(strip_tags($product['description']), 0, 160) : 'Australian Goldwing Association official merchandise.';

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
    $priceDisplay = $min === $max ? '$' . store_money($min) : '$' . store_money($min) . ' - $' . store_money($max);
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
?>
<div class="store-product">
  <div class="store-product__media">
    <?php if ($images): ?>
      <div class="store-product__image store-zoom">
        <img src="<?= e($images[0]['image_url']) ?>" alt="<?= e($product['title']) ?>" data-zoom>
      </div>
      <?php if (count($images) > 1): ?>
        <div class="store-product__thumbs">
          <?php foreach (array_slice($images, 1) as $image): ?>
            <img src="<?= e($image['image_url']) ?>" alt="<?= e($product['title']) ?>" data-thumb="<?= e($image['image_url']) ?>">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="store-product__image empty">No images yet.</div>
    <?php endif; ?>
  </div>

  <div class="store-product__details">
    <span class="store-pill"><?= e($product['type']) ?> product</span>
    <h2><?= e($product['title']) ?></h2>
    <p class="store-price"><?= e($priceDisplay) ?></p>
    <?php if ((int) $product['track_inventory'] === 1): ?>
      <p class="<?= $inStock ? 'store-stock in' : 'store-stock out' ?>">
        <?= $inStock ? 'In stock' : 'Out of stock' ?>
      </p>
    <?php endif; ?>

    <form method="post" action="/store/cart" class="store-product__form">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="add_to_cart">
      <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">

      <?php if ($variants): ?>
        <div class="form-group">
          <label for="variant-id">Size / options</label>
          <select id="variant-id" name="variant_id" required>
            <option value="">Select</option>
            <?php foreach ($variants as $variant): ?>
              <?php $disabled = (int) $product['track_inventory'] === 1 && (int) ($variant['stock_quantity'] ?? 0) <= 0; ?>
              <option value="<?= e((string) $variant['id']) ?>" <?= $disabled ? 'disabled' : '' ?>>
                <?= e($variant['label']) ?> - $<?= e(store_money($variant['price'])) ?><?= $disabled ? ' (Out of stock)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="form-group quantity">
        <label for="quantity">Quantity</label>
        <div class="store-qty">
          <button type="button" class="store-qty__btn" aria-hidden="true">-</button>
          <input id="quantity" type="number" name="quantity" min="1" value="1">
          <button type="button" class="store-qty__btn" aria-hidden="true">+</button>
        </div>
      </div>

      <div class="store-actions">
        <button class="button primary" type="submit" <?= !$inStock ? 'disabled' : '' ?>>Add to cart</button>
        <a class="button" href="/store/cart">View cart</a>
      </div>
    </form>

    <?php if ($product['description']): ?>
      <div class="store-product__desc">
        <h3>Description</h3>
        <p><?= nl2br(e($product['description'])) ?></p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($relatedProducts): ?>
  <div class="store-related">
    <h3>You might also like</h3>
    <div class="store-grid">
      <?php foreach ($relatedProducts as $related): ?>
        <article class="store-card compact">
          <div class="store-card__media">
            <?php if (!empty($related['image_url'])): ?>
              <img src="<?= e($related['image_url']) ?>" alt="<?= e($related['title']) ?>">
            <?php else: ?>
              <div class="store-card__placeholder">No image</div>
            <?php endif; ?>
          </div>
          <div class="store-card__body">
            <span class="store-pill"><?= e($related['type']) ?></span>
            <h3><?= e($related['title']) ?></h3>
            <p class="store-price">$<?= e(store_money((float) $related['base_price'])) ?></p>
            <a class="button primary store-card__cta" href="/store/product/<?= e($related['slug']) ?>">View product</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<script>
  (() => {
    const qtyWrap = document.querySelector('.store-qty');
    if (!qtyWrap) return;
    const input = qtyWrap.querySelector('input');
    const buttons = qtyWrap.querySelectorAll('.store-qty__btn');
    if (!input || buttons.length !== 2) return;
    buttons[0].addEventListener('click', () => {
      const value = Math.max(1, parseInt(input.value || '1', 10) - 1);
      input.value = value;
    });
    buttons[1].addEventListener('click', () => {
      const value = Math.max(1, parseInt(input.value || '1', 10) + 1);
      input.value = value;
    });

    const heroImage = document.querySelector('[data-zoom]');
    const thumbs = document.querySelectorAll('[data-thumb]');
    const zoomWrap = document.querySelector('.store-zoom');
    if (heroImage && zoomWrap && thumbs.length) {
      thumbs.forEach((thumb) => {
        thumb.addEventListener('click', () => {
          const src = thumb.getAttribute('data-thumb');
          if (src) {
            heroImage.setAttribute('src', src);
          }
          thumbs.forEach((item) => item.classList.remove('is-active'));
          thumb.classList.add('is-active');
        });
      });
    }

    if (heroImage && zoomWrap) {
      zoomWrap.addEventListener('mousemove', (event) => {
        const rect = zoomWrap.getBoundingClientRect();
        const x = ((event.clientX - rect.left) / rect.width) * 100;
        const y = ((event.clientY - rect.top) / rect.height) * 100;
        heroImage.style.transformOrigin = `${x}% ${y}%`;
      });
    }
  })();
</script>
