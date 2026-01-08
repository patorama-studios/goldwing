<?php
use App\Services\Csrf;

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
    }
}

$stmt = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM store_product_variants v WHERE v.product_id = p.id) as variant_count, (SELECT SUM(COALESCE(v.stock_quantity, 0)) FROM store_product_variants v WHERE v.product_id = p.id) as variant_stock FROM store_products p ORDER BY p.created_at DESC');
$products = $stmt->fetchAll();

$filtered = [];
foreach ($products as $product) {
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
<section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-6">
  <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div class="flex flex-wrap items-center gap-3">
      <a class="inline-flex items-center gap-2 rounded-lg bg-ink text-white px-4 py-2 text-sm font-medium" href="/admin/store/product/new">
        <span class="material-icons-outlined text-base">add</span>
        Add product
      </a>
    </div>
    <form method="get" class="flex flex-wrap items-center gap-3 text-sm">
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

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-gray-500 border-b">
        <tr>
          <th class="py-2 pr-3">Product</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Price</th>
          <th class="py-2 pr-3">Stock</th>
          <th class="py-2 pr-3">Status</th>
          <th class="py-2">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($filtered as $product): ?>
          <tr>
            <td class="py-2 pr-3 text-gray-900 font-medium"><?= e($product['title']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= e($product['type']) ?></td>
            <td class="py-2 pr-3 text-gray-600">$<?= e(store_money((float) $product['base_price'])) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= e($product['stock_display']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= $product['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="py-2 flex items-center gap-2">
              <a class="text-sm text-blue-600" href="/admin/store/product/<?= e((string) $product['id']) ?>">Edit</a>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="duplicate_product">
                <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
                <button class="text-sm text-slate-600" type="submit">Duplicate</button>
              </form>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
                <input type="hidden" name="is_active" value="<?= $product['is_active'] ? '0' : '1' ?>">
                <button class="text-sm text-<?= $product['is_active'] ? 'red' : 'green' ?>-600" type="submit"><?= $product['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$filtered): ?>
          <tr>
            <td colspan="6" class="py-4 text-center text-gray-500">No products found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
