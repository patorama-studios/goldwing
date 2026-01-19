<?php
use App\Services\Csrf;

$search = trim((string) ($_GET['q'] ?? ''));
$typeFilter = $_GET['type'] ?? '';
$categoryFilter = (int) ($_GET['category'] ?? 0);
$stockFilter = $_GET['stock'] ?? '';
$activeFilter = $_GET['active'] ?? '';

$categories = $pdo->query('SELECT id, name FROM store_categories ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'toggle_active') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE store_products SET is_active = :active, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['active' => $isActive, 'id' => $productId]);
            $alerts[] = ['type' => 'success', 'message' => 'Product status updated.'];
        }
        if ($action === 'duplicate_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM store_products WHERE id = :id');
            $stmt->execute(['id' => $productId]);
            $product = $stmt->fetch();
            if ($product) {
                $pdo->beginTransaction();
                $copyTitle = $product['title'] . ' (Copy)';
                $copySlug = store_unique_slug('store_products', store_slugify($copyTitle));
                $stmt = $pdo->prepare('INSERT INTO store_products (title, slug, description, type, base_price, sku, has_variants, track_inventory, stock_quantity, low_stock_threshold, event_name, is_active, created_at) VALUES (:title, :slug, :description, :type, :base_price, :sku, :has_variants, :track_inventory, :stock_quantity, :low_stock_threshold, :event_name, :is_active, NOW())');
                $stmt->execute([
                    'title' => $copyTitle,
                    'slug' => $copySlug,
                    'description' => $product['description'],
                    'type' => $product['type'],
                    'base_price' => $product['base_price'],
                    'sku' => $product['sku'],
                    'has_variants' => $product['has_variants'],
                    'track_inventory' => $product['track_inventory'],
                    'stock_quantity' => $product['stock_quantity'],
                    'low_stock_threshold' => $product['low_stock_threshold'],
                    'event_name' => $product['event_name'],
                    'is_active' => $product['is_active'],
                ]);
                $newProductId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO store_product_categories (product_id, category_id) SELECT :new_id, category_id FROM store_product_categories WHERE product_id = :old_id');
                $stmt->execute(['new_id' => $newProductId, 'old_id' => $productId]);

                $stmt = $pdo->prepare('INSERT INTO store_product_tags (product_id, tag_id) SELECT :new_id, tag_id FROM store_product_tags WHERE product_id = :old_id');
                $stmt->execute(['new_id' => $newProductId, 'old_id' => $productId]);

                $stmt = $pdo->prepare('INSERT INTO store_product_images (product_id, image_url, sort_order, created_at) SELECT :new_id, image_url, sort_order, NOW() FROM store_product_images WHERE product_id = :old_id');
                $stmt->execute(['new_id' => $newProductId, 'old_id' => $productId]);

                $stmt = $pdo->prepare('SELECT * FROM store_product_options WHERE product_id = :id');
                $stmt->execute(['id' => $productId]);
                $options = $stmt->fetchAll();

                $optionIdMap = [];
                $valueIdMap = [];
                foreach ($options as $option) {
                    $stmt = $pdo->prepare('INSERT INTO store_product_options (product_id, name, sort_order, created_at) VALUES (:product_id, :name, :sort_order, NOW())');
                    $stmt->execute([
                        'product_id' => $newProductId,
                        'name' => $option['name'],
                        'sort_order' => $option['sort_order'],
                    ]);
                    $newOptionId = (int) $pdo->lastInsertId();
                    $optionIdMap[$option['id']] = $newOptionId;

                    $stmtValues = $pdo->prepare('SELECT * FROM store_product_option_values WHERE option_id = :option_id');
                    $stmtValues->execute(['option_id' => $option['id']]);
                    $values = $stmtValues->fetchAll();
                    foreach ($values as $value) {
                        $stmtInsert = $pdo->prepare('INSERT INTO store_product_option_values (option_id, value, sort_order, created_at) VALUES (:option_id, :value, :sort_order, NOW())');
                        $stmtInsert->execute([
                            'option_id' => $newOptionId,
                            'value' => $value['value'],
                            'sort_order' => $value['sort_order'],
                        ]);
                        $valueIdMap[$value['id']] = (int) $pdo->lastInsertId();
                    }
                }

                $stmt = $pdo->prepare('SELECT * FROM store_product_variants WHERE product_id = :id');
                $stmt->execute(['id' => $productId]);
                $variants = $stmt->fetchAll();
                foreach ($variants as $variant) {
                    $stmtInsert = $pdo->prepare('INSERT INTO store_product_variants (product_id, sku, price_override, stock_quantity, is_active, created_at) VALUES (:product_id, :sku, :price_override, :stock_quantity, :is_active, NOW())');
                    $stmtInsert->execute([
                        'product_id' => $newProductId,
                        'sku' => $variant['sku'],
                        'price_override' => $variant['price_override'],
                        'stock_quantity' => $variant['stock_quantity'],
                        'is_active' => $variant['is_active'],
                    ]);
                    $newVariantId = (int) $pdo->lastInsertId();

                    $stmtValues = $pdo->prepare('SELECT option_value_id FROM store_variant_option_values WHERE variant_id = :variant_id');
                    $stmtValues->execute(['variant_id' => $variant['id']]);
                    $valueRows = $stmtValues->fetchAll();
                    foreach ($valueRows as $row) {
                        $oldValueId = (int) $row['option_value_id'];
                        if (!isset($valueIdMap[$oldValueId])) {
                            continue;
                        }
                        $stmtInsertValue = $pdo->prepare('INSERT INTO store_variant_option_values (variant_id, option_value_id) VALUES (:variant_id, :option_value_id)');
                        $stmtInsertValue->execute([
                            'variant_id' => $newVariantId,
                            'option_value_id' => $valueIdMap[$oldValueId],
                        ]);
                    }
                }

                $pdo->commit();
                $alerts[] = ['type' => 'success', 'message' => 'Product duplicated.'];
            }
        }
        if ($action === 'toggle_track_inventory') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $trackInventory = isset($_POST['track_inventory']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE store_products SET track_inventory = :track_inventory, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['track_inventory' => $trackInventory, 'id' => $productId]);
            $alerts[] = ['type' => 'success', 'message' => 'Inventory tracking updated.'];
        }
    }
}

$stmt = $pdo->query('SELECT p.*, (SELECT image_url FROM store_product_images i WHERE i.product_id = p.id ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) as image_url, (SELECT COUNT(*) FROM store_product_variants v WHERE v.product_id = p.id) as variant_count, (SELECT SUM(COALESCE(v.stock_quantity, 0)) FROM store_product_variants v WHERE v.product_id = p.id) as variant_stock FROM store_products p ORDER BY p.created_at DESC');
$products = $stmt->fetchAll();

$filtered = [];
foreach ($products as $product) {
    if ($search !== '') {
        $haystack = strtolower($product['title'] . ' ' . ($product['slug'] ?? ''));
        if (strpos($haystack, strtolower($search)) === false) {
            continue;
        }
    }
    if ($typeFilter && $product['type'] !== $typeFilter) {
        continue;
    }
    if ($activeFilter !== '' && (int) $product['is_active'] !== (int) $activeFilter) {
        continue;
    }
    if ($categoryFilter) {
        $stmt = $pdo->prepare('SELECT 1 FROM store_product_categories WHERE product_id = :product_id AND category_id = :category_id');
        $stmt->execute(['product_id' => $product['id'], 'category_id' => $categoryFilter]);
        if (!$stmt->fetch()) {
            continue;
        }
    }
    $stockQuantity = null;
    if ((int) $product['track_inventory'] === 1) {
        if ((int) $product['variant_count'] > 0) {
            $stockQuantity = (int) ($product['variant_stock'] ?? 0);
        } else {
            $stockQuantity = (int) ($product['stock_quantity'] ?? 0);
        }
    }
    if ($stockFilter === 'in' && $stockQuantity !== null && $stockQuantity <= 0) {
        continue;
    }
    if ($stockFilter === 'out' && ($stockQuantity === null || $stockQuantity > 0)) {
        continue;
    }
    if ($stockFilter === 'low') {
        $threshold = (int) ($product['low_stock_threshold'] ?? 0);
        if ($stockQuantity === null || $threshold <= 0 || $stockQuantity > $threshold) {
            continue;
        }
    }
    $product['stock_display'] = $stockQuantity === null ? 'Not tracked' : (string) $stockQuantity;
    $filtered[] = $product;
}

$pageSubtitle = 'Manage products, variants, and inventory.';
?>
<section class="space-y-6">
  <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div>
      <h2 class="text-2xl font-semibold text-ink">Store Products</h2>
      <p class="text-sm text-slate-500">Manage physical products, variants, and real-time inventory.</p>
    </div>
    <a class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink shadow-sm shadow-primary/20 hover:bg-primary-strong" href="/admin/store/product/new">
      <span class="material-icons-outlined text-base">add</span>
      Add product
    </a>
  </div>

  <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <form method="get" class="flex flex-wrap items-center gap-3 text-sm">
      <div class="relative flex-1 min-w-[220px]">
        <span class="material-icons-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
        <input name="q" value="<?= e($search) ?>" class="w-full rounded-lg border border-gray-200 bg-slate-50 px-9 py-2 text-sm" placeholder="Search products...">
      </div>
      <select name="type" class="rounded-lg border border-gray-200 bg-white px-3 py-2">
        <option value="">All types</option>
        <option value="physical" <?= $typeFilter === 'physical' ? 'selected' : '' ?>>Physical</option>
        <option value="ticket" <?= $typeFilter === 'ticket' ? 'selected' : '' ?>>Ticket</option>
      </select>
      <select name="category" class="rounded-lg border border-gray-200 bg-white px-3 py-2">
        <option value="">All categories</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= e((string) $category['id']) ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="stock" class="rounded-lg border border-gray-200 bg-white px-3 py-2">
        <option value="">Stock status</option>
        <option value="in" <?= $stockFilter === 'in' ? 'selected' : '' ?>>In stock</option>
        <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
        <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
      </select>
      <select name="active" class="rounded-lg border border-gray-200 bg-white px-3 py-2">
        <option value="">All</option>
        <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inactive</option>
      </select>
      <button class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Filter</button>
    </form>
  </div>

  <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
    <?php foreach ($filtered as $product): ?>
      <?php
        $stockQuantity = $product['stock_display'] === 'Not tracked' ? null : (int) $product['stock_display'];
        $stockLabel = 'Not tracked';
        $stockTone = 'text-slate-500';
        if ($stockQuantity !== null) {
            $stockLabel = $stockQuantity <= 0 ? 'Out of stock' : 'In stock';
            $stockTone = $stockQuantity <= 0 ? 'text-red-600' : 'text-green-600';
            $threshold = (int) ($product['low_stock_threshold'] ?? 0);
            if ($threshold > 0 && $stockQuantity <= $threshold && $stockQuantity > 0) {
                $stockLabel = 'Low stock';
                $stockTone = 'text-amber-600';
            }
        }
        $typeLabel = $product['type'] === 'ticket' ? 'Ticket' : 'Physical';
      ?>
      <div class="group overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm transition hover:shadow-card">
        <div class="relative aspect-square bg-slate-100">
          <?php if (!empty($product['image_url'])): ?>
            <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['title']) ?>" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105">
          <?php else: ?>
            <div class="flex h-full w-full items-center justify-center bg-slate-100 text-slate-400">
              <span class="material-icons-outlined text-4xl">photo</span>
            </div>
          <?php endif; ?>
          <div class="absolute left-3 top-3 flex gap-2">
            <span class="rounded-full px-2 py-1 text-[10px] font-semibold uppercase <?= $product['is_active'] ? 'bg-green-500 text-white' : 'bg-slate-400 text-white' ?>">
              <?= $product['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
            <span class="rounded-full bg-slate-900/80 px-2 py-1 text-[10px] font-semibold uppercase text-white"><?= e($typeLabel) ?></span>
          </div>
          <div class="absolute inset-0 flex items-center justify-center gap-2 bg-slate-900/40 opacity-0 transition group-hover:opacity-100">
            <a class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-900 hover:bg-primary" href="/admin/store/product/<?= e((string) $product['id']) ?>" title="Edit">
              <span class="material-icons-outlined text-xl">edit</span>
            </a>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="duplicate_product">
              <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
              <button class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-900 hover:bg-primary" type="submit" title="Duplicate">
                <span class="material-icons-outlined text-xl">content_copy</span>
              </button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
              <input type="hidden" name="is_active" value="<?= $product['is_active'] ? '0' : '1' ?>">
              <button class="flex h-10 w-10 items-center justify-center rounded-full bg-white <?= $product['is_active'] ? 'text-red-600 hover:bg-red-600 hover:text-white' : 'text-green-600 hover:bg-green-600 hover:text-white' ?>" type="submit" title="<?= $product['is_active'] ? 'Deactivate' : 'Activate' ?>">
                <span class="material-icons-outlined text-xl"><?= $product['is_active'] ? 'block' : 'check_circle' ?></span>
              </button>
            </form>
          </div>
        </div>
        <div class="space-y-3 p-4">
          <div class="flex items-start justify-between gap-2">
            <h3 class="truncate text-sm font-semibold text-slate-900"><?= e($product['title']) ?></h3>
            <span class="text-sm font-bold text-primary">$<?= e(store_money((float) $product['base_price'])) ?></span>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-500">
            <span class="inline-flex items-center gap-1">
              <span class="material-icons-outlined text-sm">inventory_2</span>
              Stock: <span class="font-semibold text-slate-700"><?= e($product['stock_display']) ?></span>
            </span>
            <span class="font-medium <?= $stockTone ?>"><?= e($stockLabel) ?></span>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-500">
            <span>SKU: <span class="font-semibold text-slate-700"><?= e((string) ($product['sku'] ?? '—')) ?></span></span>
            <form method="post" class="inline-flex items-center gap-2">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="toggle_track_inventory">
              <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
              <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="track_inventory" class="rounded border-gray-300" <?= (int) $product['track_inventory'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
                <span><?= (int) $product['track_inventory'] === 1 ? 'Track stock' : 'No tracking' ?></span>
              </label>
            </form>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-500">
            <span>Variants: <span class="font-semibold text-slate-700"><?= e((string) $product['variant_count']) ?></span></span>
            <span class="text-slate-400">Updated <?= e(date('M j, Y', strtotime($product['updated_at'] ?? $product['created_at'] ?? 'now'))) ?></span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$filtered): ?>
      <div class="col-span-full rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-10 text-center text-slate-500">
        No products found.
      </div>
    <?php endif; ?>
  </div>
</section>
