<?php
declare(strict_types=1);

/**
 * Imports the AGA store catalogue (products, variants, stock) from a JSON spec.
 *
 * Usage:
 *   php scripts/import_store_catalogue.php [--apply] [--update-shipping] [path/to/catalogue.json]
 *
 *   - Dry-run by default. Pass --apply to write to the database.
 *   - --update-shipping also writes the catalogue's shipping.flat_rate into store_settings.
 *   - If no JSON path is given, defaults to scripts/data/store_catalogue_2026_04.json.
 *
 * Behaviour:
 *   - Matches products by SKU (idempotent). Existing products with a matching SKU are
 *     updated in place; new ones are inserted. Products NOT in the JSON are left alone.
 *   - For variant products, the script replaces all options/variants for the matched
 *     product (mirroring the admin product form). Carts/orders keep their snapshots
 *     because the FKs use ON DELETE SET NULL.
 *   - Categories listed in the JSON are auto-created if missing.
 *   - Does not touch product images. Upload those via the admin UI.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../includes/store_helpers.php';

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$updateShipping = in_array('--update-shipping', $args, true);
$jsonPath = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    $jsonPath = $arg;
    break;
}
if ($jsonPath === null) {
    $jsonPath = __DIR__ . '/data/store_catalogue_2026_04.json';
}

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Catalogue JSON not found: {$jsonPath}\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
$catalogue = json_decode($raw, true);
if (!is_array($catalogue) || empty($catalogue['products'])) {
    fwrite(STDERR, "Catalogue JSON is empty or invalid: {$jsonPath}\n");
    exit(1);
}

$pdo = db();

$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "[{$mode}] Importing catalogue: {$jsonPath}\n";
echo "Catalogue version: " . ($catalogue['version'] ?? 'n/a') . "\n\n";

$categoryIds = catalogue_load_existing_ids($pdo, 'store_categories');
$tagIds = catalogue_load_existing_ids($pdo, 'store_tags');

if (!empty($catalogue['categories']) && is_array($catalogue['categories'])) {
    foreach ($catalogue['categories'] as $name) {
        catalogue_ensure_taxonomy($pdo, 'store_categories', $name, $categoryIds, $apply);
    }
}

$stats = [
    'products_inserted' => 0,
    'products_updated' => 0,
    'variants_written' => 0,
    'variants_removed' => 0,
    'categories_created' => 0,
    'tags_created' => 0,
];

if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($catalogue['products'] as $spec) {
        catalogue_upsert_product($pdo, $spec, $categoryIds, $tagIds, $apply, $stats);
    }

    if ($updateShipping && !empty($catalogue['shipping']['flat_rate'])) {
        $rate = (float) $catalogue['shipping']['flat_rate'];
        echo "Setting store_settings shipping flat rate to \${$rate}\n";
        if ($apply) {
            $pdo->exec('UPDATE store_settings SET shipping_flat_enabled = 1, shipping_flat_rate = ' . $pdo->quote((string) $rate) . ', updated_at = NOW() WHERE id = 1');
        }
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nSummary ({$mode}):\n";
foreach ($stats as $key => $value) {
    echo "  {$key}: {$value}\n";
}
if (!$apply) {
    echo "\nDry-run only. Re-run with --apply to write changes.\n";
}

// ---------------------------------------------------------------------------

function catalogue_load_existing_ids(PDO $pdo, string $table): array
{
    $allowed = ['store_categories', 'store_tags'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported taxonomy table: ' . $table);
    }
    $map = [];
    $stmt = $pdo->query('SELECT id, name FROM ' . $table);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[strtolower(trim((string) $row['name']))] = (int) $row['id'];
    }
    return $map;
}

function catalogue_ensure_taxonomy(PDO $pdo, string $table, string $name, array &$cache, bool $apply): int
{
    $key = strtolower(trim($name));
    if ($key === '') {
        return 0;
    }
    if (isset($cache[$key]) && $cache[$key] > 0) {
        return $cache[$key];
    }
    echo "  + create {$table}: {$name}\n";
    if ($apply) {
        $slug = store_unique_slug($table, store_slugify($name));
        $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (name, slug, created_at) VALUES (:name, :slug, NOW())');
        $stmt->execute(['name' => $name, 'slug' => $slug]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $id = -1;
    }
    $cache[$key] = $id;
    return $id;
}

function catalogue_upsert_product(PDO $pdo, array $spec, array &$categoryIds, array &$tagIds, bool $apply, array &$stats): void
{
    $sku = trim((string) ($spec['sku'] ?? ''));
    $title = trim((string) ($spec['title'] ?? ''));
    if ($sku === '' || $title === '') {
        echo "  ! skip product without SKU or title\n";
        return;
    }

    $existing = null;
    $stmt = $pdo->prepare('SELECT * FROM store_products WHERE sku = :sku LIMIT 1');
    $stmt->execute(['sku' => $sku]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $type = ($spec['type'] ?? 'physical') === 'ticket' ? 'ticket' : 'physical';
    $basePrice = (float) ($spec['base_price'] ?? 0);
    $description = trim((string) ($spec['description'] ?? ''));
    $eventName = $type === 'ticket' ? trim((string) ($spec['event_name'] ?? '')) : null;
    $hasVariants = !empty($spec['variants']) && is_array($spec['variants']);
    $trackInventory = !empty($spec['track_inventory']);
    $stockQuantity = $hasVariants ? null : (isset($spec['stock']) ? (int) $spec['stock'] : null);
    $lowStock = isset($spec['low_stock_threshold']) ? (int) $spec['low_stock_threshold'] : null;
    $isActive = array_key_exists('is_active', $spec) ? (int) (bool) $spec['is_active'] : 1;

    if ($existing) {
        echo "~ update product: {$sku} — {$title}\n";
        $productId = (int) $existing['id'];
        $slug = $existing['slug'];
        if ($apply) {
            $stmt = $pdo->prepare('UPDATE store_products SET title = :title, description = :description, type = :type, base_price = :base_price, has_variants = :has_variants, track_inventory = :track_inventory, stock_quantity = :stock_quantity, low_stock_threshold = :low_stock_threshold, event_name = :event_name, is_active = :is_active, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'type' => $type,
                'base_price' => $basePrice,
                'has_variants' => $hasVariants ? 1 : 0,
                'track_inventory' => $trackInventory ? 1 : 0,
                'stock_quantity' => $stockQuantity,
                'low_stock_threshold' => $lowStock,
                'event_name' => $eventName,
                'is_active' => $isActive,
                'id' => $productId,
            ]);
        }
        $stats['products_updated']++;
    } else {
        echo "+ create product: {$sku} — {$title}\n";
        $slug = $apply ? store_unique_slug('store_products', store_slugify($title)) : store_slugify($title);
        $productId = 0;
        if ($apply) {
            $stmt = $pdo->prepare('INSERT INTO store_products (title, slug, description, type, base_price, sku, has_variants, track_inventory, stock_quantity, low_stock_threshold, event_name, is_active, created_at) VALUES (:title, :slug, :description, :type, :base_price, :sku, :has_variants, :track_inventory, :stock_quantity, :low_stock_threshold, :event_name, :is_active, NOW())');
            $stmt->execute([
                'title' => $title,
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'type' => $type,
                'base_price' => $basePrice,
                'sku' => $sku,
                'has_variants' => $hasVariants ? 1 : 0,
                'track_inventory' => $trackInventory ? 1 : 0,
                'stock_quantity' => $stockQuantity,
                'low_stock_threshold' => $lowStock,
                'event_name' => $eventName,
                'is_active' => $isActive,
            ]);
            $productId = (int) $pdo->lastInsertId();
        }
        $stats['products_inserted']++;
    }

    catalogue_sync_categories($pdo, $productId, $spec['categories'] ?? [], $categoryIds, $apply, $stats);
    catalogue_sync_tags($pdo, $productId, $spec['tags'] ?? [], $tagIds, $apply, $stats);
    catalogue_sync_variants($pdo, $productId, $spec, $apply, $stats);
}

function catalogue_sync_categories(PDO $pdo, int $productId, array $names, array &$categoryIds, bool $apply, array &$stats): void
{
    if (!$names) {
        return;
    }
    $ids = [];
    foreach ($names as $name) {
        $before = count($categoryIds);
        $id = catalogue_ensure_taxonomy($pdo, 'store_categories', (string) $name, $categoryIds, $apply);
        if (count($categoryIds) > $before) {
            $stats['categories_created']++;
        }
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (!$apply || $productId <= 0) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM store_product_categories WHERE product_id = :id');
    $stmt->execute(['id' => $productId]);
    $insert = $pdo->prepare('INSERT INTO store_product_categories (product_id, category_id) VALUES (:p, :c)');
    foreach (array_unique($ids) as $cid) {
        $insert->execute(['p' => $productId, 'c' => $cid]);
    }
}

function catalogue_sync_tags(PDO $pdo, int $productId, array $names, array &$tagIds, bool $apply, array &$stats): void
{
    if (!$names) {
        return;
    }
    $ids = [];
    foreach ($names as $name) {
        $before = count($tagIds);
        $id = catalogue_ensure_taxonomy($pdo, 'store_tags', (string) $name, $tagIds, $apply);
        if (count($tagIds) > $before) {
            $stats['tags_created']++;
        }
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (!$apply || $productId <= 0) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM store_product_tags WHERE product_id = :id');
    $stmt->execute(['id' => $productId]);
    $insert = $pdo->prepare('INSERT INTO store_product_tags (product_id, tag_id) VALUES (:p, :t)');
    foreach (array_unique($ids) as $tid) {
        $insert->execute(['p' => $productId, 't' => $tid]);
    }
}

function catalogue_sync_variants(PDO $pdo, int $productId, array $spec, bool $apply, array &$stats): void
{
    $hasVariants = !empty($spec['variants']) && is_array($spec['variants']);
    if (!$hasVariants) {
        if ($apply && $productId > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM store_product_variants WHERE product_id = :id');
            $stmt->execute(['id' => $productId]);
            $existingCount = (int) $stmt->fetchColumn();
            if ($existingCount > 0) {
                $pdo->prepare('DELETE FROM store_product_options WHERE product_id = :id')->execute(['id' => $productId]);
                $pdo->prepare('DELETE FROM store_product_variants WHERE product_id = :id')->execute(['id' => $productId]);
                $stats['variants_removed'] += $existingCount;
            }
        }
        return;
    }

    $optionsSpec = $spec['options'] ?? [];
    if (!$optionsSpec) {
        echo "  ! product {$spec['sku']} has variants but no options[] — skipping variant sync\n";
        return;
    }

    if ($apply && $productId > 0) {
        $pdo->prepare('DELETE FROM store_product_options WHERE product_id = :id')->execute(['id' => $productId]);
        $pdo->prepare('DELETE FROM store_product_variants WHERE product_id = :id')->execute(['id' => $productId]);
    }

    $optionIdMap = [];
    foreach ($optionsSpec as $index => $option) {
        $optionName = trim((string) ($option['name'] ?? ''));
        $values = $option['values'] ?? [];
        if ($optionName === '' || !is_array($values) || !$values) {
            continue;
        }
        $optionId = 0;
        if ($apply && $productId > 0) {
            $stmt = $pdo->prepare('INSERT INTO store_product_options (product_id, name, sort_order, created_at) VALUES (:p, :name, :sort_order, NOW())');
            $stmt->execute(['p' => $productId, 'name' => $optionName, 'sort_order' => $index + 1]);
            $optionId = (int) $pdo->lastInsertId();
        }
        $valueIdMap = [];
        foreach ($values as $valueIndex => $value) {
            $valueId = 0;
            if ($apply && $optionId > 0) {
                $stmt = $pdo->prepare('INSERT INTO store_product_option_values (option_id, value, sort_order, created_at) VALUES (:option_id, :value, :sort_order, NOW())');
                $stmt->execute([
                    'option_id' => $optionId,
                    'value' => (string) $value,
                    'sort_order' => $valueIndex + 1,
                ]);
                $valueId = (int) $pdo->lastInsertId();
            }
            $valueIdMap[(string) $value] = $valueId;
        }
        $optionIdMap[$optionName] = $valueIdMap;
    }

    foreach ($spec['variants'] as $variant) {
        $optionValues = $variant['options'] ?? [];
        if (!is_array($optionValues) || !$optionValues) {
            continue;
        }
        $variantSku = isset($variant['sku']) ? trim((string) $variant['sku']) : null;
        $priceOverride = array_key_exists('price_override', $variant) ? (float) $variant['price_override'] : null;
        $variantStock = array_key_exists('stock', $variant) ? (int) $variant['stock'] : null;

        $variantId = 0;
        if ($apply && $productId > 0) {
            $stmt = $pdo->prepare('INSERT INTO store_product_variants (product_id, sku, price_override, stock_quantity, is_active, created_at) VALUES (:p, :sku, :price_override, :stock, 1, NOW())');
            $stmt->execute([
                'p' => $productId,
                'sku' => $variantSku !== '' ? $variantSku : null,
                'price_override' => $priceOverride,
                'stock' => $variantStock,
            ]);
            $variantId = (int) $pdo->lastInsertId();
        }

        foreach ($optionValues as $optionName => $value) {
            if (!isset($optionIdMap[$optionName][(string) $value])) {
                continue;
            }
            $optionValueId = $optionIdMap[$optionName][(string) $value];
            if ($apply && $variantId > 0 && $optionValueId > 0) {
                $stmt = $pdo->prepare('INSERT INTO store_variant_option_values (variant_id, option_value_id) VALUES (:v, :ov)');
                $stmt->execute(['v' => $variantId, 'ov' => $optionValueId]);
            }
        }

        $stats['variants_written']++;
    }
}
