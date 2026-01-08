<?php
use App\Services\Csrf;
use App\Services\SettingsService;

$productId = 0;
if (!empty($subPage) && $subPage !== 'new') {
    $productId = (int) $subPage;
}
if (isset($_GET['id'])) {
    $productId = (int) $_GET['id'];
}

$product = null;
if ($productId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM store_products WHERE id = :id');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
}

$categories = $pdo->query('SELECT id, name FROM store_categories ORDER BY name ASC')->fetchAll();
$tags = $pdo->query('SELECT id, name FROM store_tags ORDER BY name ASC')->fetchAll();

$selectedCategories = [];
$selectedTags = [];
$images = [];
if ($product) {
    $stmt = $pdo->prepare('SELECT category_id FROM store_product_categories WHERE product_id = :id');
    $stmt->execute(['id' => $productId]);
    $selectedCategories = array_column($stmt->fetchAll(), 'category_id');

    $stmt = $pdo->prepare('SELECT tag_id FROM store_product_tags WHERE product_id = :id');
    $stmt->execute(['id' => $productId]);
    $selectedTags = array_column($stmt->fetchAll(), 'tag_id');

    $stmt = $pdo->prepare('SELECT * FROM store_product_images WHERE product_id = :id ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['id' => $productId]);
    $images = $stmt->fetchAll();
}

$optionsInput = $_POST['options'] ?? [];
$existingOptionMap = [];
$variantRows = [];

function store_build_option_matrix(array $options): array
{
    $matrix = [];
    foreach ($options as $option) {
        $name = trim($option['name'] ?? '');
        $values = array_filter(array_map('trim', explode(',', $option['values'] ?? '')));
        if ($name === '' || !$values) {
            continue;
        }
        $matrix[] = [
            'name' => $name,
            'values' => $values,
        ];
    }
    return $matrix;
}

function store_generate_combinations(array $matrix): array
{
    $combos = [[]];
    foreach ($matrix as $option) {
        $newCombos = [];
        foreach ($combos as $combo) {
            foreach ($option['values'] as $value) {
                $newCombos[] = array_merge($combo, [[
                    'option_name' => $option['name'],
                    'value' => $value,
                ]]);
            }
        }
        $combos = $newCombos;
    }
    return $combos;
}

if (!$optionsInput && $product) {
    $stmt = $pdo->prepare('SELECT * FROM store_product_options WHERE product_id = :id ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['id' => $productId]);
    $optionRows = $stmt->fetchAll();
    foreach ($optionRows as $optionRow) {
        $stmtValues = $pdo->prepare('SELECT * FROM store_product_option_values WHERE option_id = :option_id ORDER BY sort_order ASC, id ASC');
        $stmtValues->execute(['option_id' => $optionRow['id']]);
        $values = $stmtValues->fetchAll();
        $valueList = [];
        foreach ($values as $value) {
            $valueList[] = $value['value'];
        }
        $optionsInput[] = [
            'name' => $optionRow['name'],
            'values' => implode(', ', $valueList),
        ];
        $existingOptionMap[$optionRow['name']] = $values;
    }
}
if (!$optionsInput) {
    $optionsInput = [['name' => '', 'values' => '']];
}

$existingVariants = [];
if ($product) {
    $stmt = $pdo->prepare('SELECT v.*, pov.value as option_value, po.name as option_name FROM store_product_variants v LEFT JOIN store_variant_option_values vov ON vov.variant_id = v.id LEFT JOIN store_product_option_values pov ON pov.id = vov.option_value_id LEFT JOIN store_product_options po ON po.id = pov.option_id WHERE v.product_id = :id ORDER BY v.id ASC');
    $stmt->execute(['id' => $productId]);
    $rows = $stmt->fetchAll();
    $variantGrouped = [];
    foreach ($rows as $row) {
        $variantId = $row['id'];
        if (!isset($variantGrouped[$variantId])) {
            $variantGrouped[$variantId] = [
                'sku' => $row['sku'],
                'price_override' => $row['price_override'],
                'stock_quantity' => $row['stock_quantity'],
                'options' => [],
            ];
        }
        if ($row['option_name']) {
            $variantGrouped[$variantId]['options'][] = [
                'option_name' => $row['option_name'],
                'value' => $row['option_value'],
            ];
        }
    }
    foreach ($variantGrouped as $variant) {
        $label = store_build_variant_label($variant['options']);
        $existingVariants[$label] = $variant;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete_image') {
            $imageId = (int) ($_POST['image_id'] ?? 0);
            if ($imageId > 0) {
                $stmt = $pdo->prepare('SELECT image_url FROM store_product_images WHERE id = :id');
                $stmt->execute(['id' => $imageId]);
                $imageRow = $stmt->fetch();
                $stmt = $pdo->prepare('DELETE FROM store_product_images WHERE id = :id');
                $stmt->execute(['id' => $imageId]);
                if ($imageRow && str_starts_with($imageRow['image_url'], '/uploads/store/')) {
                    $filePath = __DIR__ . '/../../' . ltrim($imageRow['image_url'], '/');
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                }
                $alerts[] = ['type' => 'success', 'message' => 'Image removed.'];
            }
        }

        if (in_array($action, ['save_product', 'generate_variants'], true)) {
            $title = trim($_POST['title'] ?? '');
            $slugInput = trim($_POST['slug'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $type = $_POST['type'] ?? 'physical';
            $basePrice = (float) ($_POST['base_price'] ?? 0);
            $sku = trim($_POST['sku'] ?? '');
            $trackInventory = isset($_POST['track_inventory']) ? 1 : 0;
            $lowStockThreshold = trim($_POST['low_stock_threshold'] ?? '') !== '' ? (int) $_POST['low_stock_threshold'] : null;
            $stockQuantity = trim($_POST['stock_quantity'] ?? '') !== '' ? (int) $_POST['stock_quantity'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $eventName = trim($_POST['event_name'] ?? '');

            if ($action === 'save_product' && $title === '') {
                $alerts[] = ['type' => 'error', 'message' => 'Product title is required.'];
            } elseif ($action === 'save_product') {
                $slug = store_slugify($slugInput !== '' ? $slugInput : $title);
                $slug = store_unique_slug('store_products', $slug, $productId);
                $payload = [
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'type' => in_array($type, ['physical', 'ticket'], true) ? $type : 'physical',
                    'base_price' => max(0.0, $basePrice),
                    'sku' => $sku !== '' ? $sku : null,
                    'track_inventory' => $trackInventory,
                    'stock_quantity' => $stockQuantity,
                    'low_stock_threshold' => $lowStockThreshold,
                    'event_name' => $type === 'ticket' ? $eventName : null,
                    'is_active' => $isActive,
                ];

                if ($product) {
                    $payload['id'] = $productId;
                    $stmt = $pdo->prepare('UPDATE store_products SET title = :title, slug = :slug, description = :description, type = :type, base_price = :base_price, sku = :sku, track_inventory = :track_inventory, stock_quantity = :stock_quantity, low_stock_threshold = :low_stock_threshold, event_name = :event_name, is_active = :is_active, updated_at = NOW() WHERE id = :id');
                    $stmt->execute($payload);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO store_products (title, slug, description, type, base_price, sku, track_inventory, stock_quantity, low_stock_threshold, event_name, is_active, created_at) VALUES (:title, :slug, :description, :type, :base_price, :sku, :track_inventory, :stock_quantity, :low_stock_threshold, :event_name, :is_active, NOW())');
                    $stmt->execute($payload);
                    $productId = (int) $pdo->lastInsertId();
                    $product = array_merge(['id' => $productId], $payload);
                }

                $selectedCategories = array_map('intval', $_POST['categories'] ?? []);
                $selectedTags = array_map('intval', $_POST['tags'] ?? []);

                $stmt = $pdo->prepare('DELETE FROM store_product_categories WHERE product_id = :id');
                $stmt->execute(['id' => $productId]);
                foreach ($selectedCategories as $categoryId) {
                    $stmt = $pdo->prepare('INSERT INTO store_product_categories (product_id, category_id) VALUES (:product_id, :category_id)');
                    $stmt->execute(['product_id' => $productId, 'category_id' => $categoryId]);
                }

                $stmt = $pdo->prepare('DELETE FROM store_product_tags WHERE product_id = :id');
                $stmt->execute(['id' => $productId]);
                foreach ($selectedTags as $tagId) {
                    $stmt = $pdo->prepare('INSERT INTO store_product_tags (product_id, tag_id) VALUES (:product_id, :tag_id)');
                    $stmt->execute(['product_id' => $productId, 'tag_id' => $tagId]);
                }

                $optionsInput = $_POST['options'] ?? [];
                $optionsMatrix = store_build_option_matrix($optionsInput);
                $hasVariants = !empty($optionsMatrix);

                $stmt = $pdo->prepare('UPDATE store_products SET has_variants = :has_variants WHERE id = :id');
                $stmt->execute(['has_variants' => $hasVariants ? 1 : 0, 'id' => $productId]);

                $stmt = $pdo->prepare('DELETE FROM store_product_options WHERE product_id = :id');
                $stmt->execute(['id' => $productId]);
                $stmt = $pdo->prepare('DELETE FROM store_product_variants WHERE product_id = :id');
                $stmt->execute(['id' => $productId]);

                $optionIdMap = [];
                foreach ($optionsMatrix as $index => $option) {
                    $stmt = $pdo->prepare('INSERT INTO store_product_options (product_id, name, sort_order, created_at) VALUES (:product_id, :name, :sort_order, NOW())');
                    $stmt->execute([
                        'product_id' => $productId,
                        'name' => $option['name'],
                        'sort_order' => $index + 1,
                    ]);
                    $optionId = (int) $pdo->lastInsertId();
                    foreach ($option['values'] as $valueIndex => $value) {
                        $stmt = $pdo->prepare('INSERT INTO store_product_option_values (option_id, value, sort_order, created_at) VALUES (:option_id, :value, :sort_order, NOW())');
                        $stmt->execute([
                            'option_id' => $optionId,
                            'value' => $value,
                            'sort_order' => $valueIndex + 1,
                        ]);
                        $optionIdMap[$option['name']][$value] = (int) $pdo->lastInsertId();
                    }
                }

                if ($hasVariants) {
                    $combinations = store_generate_combinations($optionsMatrix);
                    $variantInputs = $_POST['variants'] ?? [];
                    foreach ($combinations as $combo) {
                        $label = store_build_variant_label($combo);
                        $hash = md5($label);
                        $variantInput = $variantInputs[$hash] ?? [];
                        $skuInput = trim($variantInput['sku'] ?? '');
                        $priceOverride = trim($variantInput['price_override'] ?? '');
                        $stockInput = trim($variantInput['stock_quantity'] ?? '');

                        $stmt = $pdo->prepare('INSERT INTO store_product_variants (product_id, sku, price_override, stock_quantity, is_active, created_at) VALUES (:product_id, :sku, :price_override, :stock_quantity, 1, NOW())');
                        $stmt->execute([
                            'product_id' => $productId,
                            'sku' => $skuInput !== '' ? $skuInput : null,
                            'price_override' => $priceOverride !== '' ? (float) $priceOverride : null,
                            'stock_quantity' => $stockInput !== '' ? (int) $stockInput : null,
                        ]);
                        $variantId = (int) $pdo->lastInsertId();
                        foreach ($combo as $optionValue) {
                            $optionName = $optionValue['option_name'];
                            $value = $optionValue['value'];
                            if (!isset($optionIdMap[$optionName][$value])) {
                                continue;
                            }
                            $stmt = $pdo->prepare('INSERT INTO store_variant_option_values (variant_id, option_value_id) VALUES (:variant_id, :option_value_id)');
                            $stmt->execute([
                                'variant_id' => $variantId,
                                'option_value_id' => $optionIdMap[$optionName][$value],
                            ]);
                        }
                    }
                }

                if (!empty($_FILES['product_images']['name'][0] ?? '')) {
                    $allowedTypes = SettingsService::getGlobal('media.allowed_types', []);
                    $maxUploadMb = (float) SettingsService::getGlobal('media.max_upload_mb', 10);
                    $maxBytes = (int) max(0, $maxUploadMb) * 1024 * 1024;
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $uploadDir = __DIR__ . '/../../uploads/store/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    foreach ($_FILES['product_images']['name'] as $index => $name) {
                        if ($name === '') {
                            continue;
                        }
                        $tmpName = $_FILES['product_images']['tmp_name'][$index] ?? '';
                        $error = $_FILES['product_images']['error'][$index] ?? UPLOAD_ERR_NO_FILE;
                        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
                            continue;
                        }
                        if ($maxBytes > 0 && (int) ($_FILES['product_images']['size'][$index] ?? 0) > $maxBytes) {
                            $alerts[] = ['type' => 'error', 'message' => 'Image exceeds size limit.'];
                            continue;
                        }
                        $mime = $finfo->file($tmpName) ?: '';
                        if (is_array($allowedTypes) && $allowedTypes && !in_array($mime, $allowedTypes, true)) {
                            $alerts[] = ['type' => 'error', 'message' => 'Image file type is not allowed.'];
                            continue;
                        }
                        if (strpos($mime, 'image/') !== 0) {
                            continue;
                        }
                        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $safeName = 'store_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                        $targetPath = $uploadDir . $safeName;
                        if (move_uploaded_file($tmpName, $targetPath)) {
                            $optimizeImages = SettingsService::getGlobal('media.image_optimization_enabled', false);
                            if ($optimizeImages && strpos($mime, 'image/') === 0) {
                                if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
                                    $img = @imagecreatefromjpeg($targetPath);
                                    if ($img) {
                                        imagejpeg($img, $targetPath, 85);
                                        imagedestroy($img);
                                    }
                                } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
                                    $img = @imagecreatefrompng($targetPath);
                                    if ($img) {
                                        imagepng($img, $targetPath, 6);
                                        imagedestroy($img);
                                    }
                                } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                                    $img = @imagecreatefromwebp($targetPath);
                                    if ($img && function_exists('imagewebp')) {
                                        imagewebp($img, $targetPath, 80);
                                        imagedestroy($img);
                                    }
                                }
                            }
                            $relativePath = '/uploads/store/' . $safeName;
                            $stmt = $pdo->prepare('INSERT INTO store_product_images (product_id, image_url, sort_order, created_at) VALUES (:product_id, :image_url, 0, NOW())');
                            $stmt->execute(['product_id' => $productId, 'image_url' => $relativePath]);
                        }
                    }
                }

                $alerts[] = ['type' => 'success', 'message' => 'Product saved.'];
                $stmt = $pdo->prepare('SELECT * FROM store_products WHERE id = :id');
                $stmt->execute(['id' => $productId]);
                $product = $stmt->fetch();
                $stmt = $pdo->prepare('SELECT * FROM store_product_images WHERE product_id = :id ORDER BY sort_order ASC, id ASC');
                $stmt->execute(['id' => $productId]);
                $images = $stmt->fetchAll();
            }
        }
    }
}

$optionsMatrix = store_build_option_matrix($optionsInput);
if (!empty($optionsMatrix)) {
    $combinations = store_generate_combinations($optionsMatrix);
    $variantInputs = $_POST['variants'] ?? [];
    foreach ($combinations as $combo) {
        $label = store_build_variant_label($combo);
        $hash = md5($label);
        $prefill = $variantInputs[$hash] ?? ($existingVariants[$label] ?? []);
        $variantRows[] = [
            'hash' => $hash,
            'label' => $label,
            'sku' => $prefill['sku'] ?? '',
            'price_override' => $prefill['price_override'] ?? '',
            'stock_quantity' => $prefill['stock_quantity'] ?? '',
        ];
    }
}

$pageSubtitle = $product ? 'Edit product details and variants.' : 'Create a new store product.';
?>
<section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
  <form method="post" enctype="multipart/form-data" class="space-y-8">
    <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">

    <div class="grid gap-6 lg:grid-cols-2">
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Title</label>
        <input name="title" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($product['title'] ?? '') ?>" required>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Slug</label>
        <input name="slug" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($product['slug'] ?? '') ?>">
      </div>
    </div>

    <div>
      <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Description</label>
      <textarea name="description" rows="5" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e($product['description'] ?? '') ?></textarea>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Type</label>
        <select name="type" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
          <option value="physical" <?= ($product['type'] ?? '') === 'physical' ? 'selected' : '' ?>>Physical</option>
          <option value="ticket" <?= ($product['type'] ?? '') === 'ticket' ? 'selected' : '' ?>>Event Ticket</option>
        </select>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Base price</label>
        <input name="base_price" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($product['base_price'] ?? '')) ?>" required>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">SKU</label>
        <input name="sku" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($product['sku'] ?? '') ?>">
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Event name (tickets only)</label>
        <input name="event_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($product['event_name'] ?? '') ?>">
      </div>
      <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" name="is_active" class="rounded border-gray-200" <?= !isset($product['is_active']) || $product['is_active'] ? 'checked' : '' ?>>
          Active
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" name="track_inventory" class="rounded border-gray-200" <?= !empty($product['track_inventory']) ? 'checked' : '' ?>>
          Track inventory
        </label>
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Stock quantity (no variants)</label>
        <input name="stock_quantity" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($product['stock_quantity'] ?? '')) ?>">
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Low stock threshold</label>
        <input name="low_stock_threshold" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($product['low_stock_threshold'] ?? '')) ?>">
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Categories</label>
        <div class="mt-2 grid gap-2">
          <?php foreach ($categories as $category): ?>
            <label class="flex items-center gap-2 text-sm text-slate-600">
              <input type="checkbox" name="categories[]" value="<?= e((string) $category['id']) ?>" class="rounded border-gray-200" <?= in_array($category['id'], $selectedCategories, true) ? 'checked' : '' ?>>
              <?= e($category['name']) ?>
            </label>
          <?php endforeach; ?>
          <?php if (!$categories): ?>
            <span class="text-sm text-slate-500">Add categories first.</span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Tags</label>
        <div class="mt-2 grid gap-2">
          <?php foreach ($tags as $tag): ?>
            <label class="flex items-center gap-2 text-sm text-slate-600">
              <input type="checkbox" name="tags[]" value="<?= e((string) $tag['id']) ?>" class="rounded border-gray-200" <?= in_array($tag['id'], $selectedTags, true) ? 'checked' : '' ?>>
              <?= e($tag['name']) ?>
            </label>
          <?php endforeach; ?>
          <?php if (!$tags): ?>
            <span class="text-sm text-slate-500">Add tags first.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Images</h3>
      <input name="product_images[]" type="file" multiple class="mt-3 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
      <?php if ($images): ?>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <?php foreach ($images as $image): ?>
            <div class="border border-gray-200 rounded-lg p-2 text-center">
              <img src="<?= e($image['image_url']) ?>" alt="Product image" class="w-full h-32 object-cover rounded">
              <form method="post" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="delete_image">
                <input type="hidden" name="image_id" value="<?= e((string) $image['id']) ?>">
                <button type="submit" class="text-xs text-red-600">Remove</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Variations</h3>
      <p class="text-sm text-slate-500">Add options and values, then generate variants to set pricing and stock.</p>
      <div id="option-list" class="mt-4 space-y-3">
        <?php foreach ($optionsInput as $index => $option): ?>
          <div class="grid gap-3 md:grid-cols-[1fr_2fr] option-row">
            <input name="options[<?= e((string) $index) ?>][name]" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Option name (e.g. Size)" value="<?= e($option['name'] ?? '') ?>">
            <input name="options[<?= e((string) $index) ?>][values]" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Values (comma separated)" value="<?= e($option['values'] ?? '') ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 flex gap-2">
        <button type="button" id="add-option" class="rounded-lg border border-gray-200 px-3 py-2 text-sm">Add option</button>
        <button type="submit" name="action" value="generate_variants" class="rounded-lg bg-ink px-3 py-2 text-sm text-white">Generate variants</button>
      </div>

      <?php if ($variantRows): ?>
        <div class="mt-6 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-500 border-b">
              <tr>
                <th class="py-2 pr-3">Variant</th>
                <th class="py-2 pr-3">SKU</th>
                <th class="py-2 pr-3">Price override</th>
                <th class="py-2 pr-3">Stock</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php foreach ($variantRows as $row): ?>
                <tr>
                  <td class="py-2 pr-3 text-gray-700 font-medium"><?= e($row['label']) ?></td>
                  <td class="py-2 pr-3">
                    <input name="variants[<?= e($row['hash']) ?>][sku]" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $row['sku']) ?>">
                  </td>
                  <td class="py-2 pr-3">
                    <input name="variants[<?= e($row['hash']) ?>][price_override]" type="number" step="0.01" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $row['price_override']) ?>">
                  </td>
                  <td class="py-2 pr-3">
                    <input name="variants[<?= e($row['hash']) ?>][stock_quantity]" type="number" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $row['stock_quantity']) ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="flex justify-end">
      <button type="submit" name="action" value="save_product" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save product</button>
    </div>
  </form>
</section>

<script>
  (() => {
    const addButton = document.getElementById('add-option');
    const optionList = document.getElementById('option-list');
    if (!addButton || !optionList) {
      return;
    }
    addButton.addEventListener('click', () => {
      const index = optionList.querySelectorAll('.option-row').length;
      const row = document.createElement('div');
      row.className = 'grid gap-3 md:grid-cols-[1fr_2fr] option-row';
      row.innerHTML = `
        <input name="options[${index}][name]" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Option name (e.g. Size)">
        <input name="options[${index}][values]" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Values (comma separated)">
      `;
      optionList.appendChild(row);
    });
  })();
</script>
