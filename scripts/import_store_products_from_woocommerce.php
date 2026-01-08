<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../includes/store_helpers.php';

use App\Services\SettingsService;

function usage(): void
{
    echo "Usage: php scripts/import_store_products_from_woocommerce.php <csv_path> [--apply]\n";
    echo "Imports WooCommerce products + images into store tables and media library.\n";
    echo "Dry-run by default. Use --apply to write files and DB.\n";
}

function normalize_name(string $value): string
{
    return strtolower(trim($value));
}

function parse_price(?string $value): float
{
    if ($value === null) {
        return 0.0;
    }
    $clean = trim($value);
    if ($clean === '') {
        return 0.0;
    }
    return (float) $clean;
}

function sanitize_filename(string $name): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return $safe === '' ? 'file' : $safe;
}

function download_file(string $url, string $dest): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => 1,
            'user_agent' => 'GoldwingStoreImporter/1.0',
        ],
        'https' => [
            'timeout' => 30,
            'follow_location' => 1,
            'user_agent' => 'GoldwingStoreImporter/1.0',
        ],
    ]);
    $in = @fopen($url, 'rb', false, $context);
    if ($in === false) {
        return false;
    }
    $out = fopen($dest, 'wb');
    if ($out === false) {
        fclose($in);
        return false;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return is_file($dest) && filesize($dest) > 0;
}

function ensure_media(PDO $pdo, string $type, string $title, string $path, ?string $tags, string $visibility): void
{
    $stmt = $pdo->prepare('SELECT id FROM media WHERE path = :path LIMIT 1');
    $stmt->execute(['path' => $path]);
    if ($stmt->fetch()) {
        return;
    }
    $insert = $pdo->prepare('INSERT INTO media (type, title, path, tags, visibility, uploaded_by, created_at) VALUES (:type, :title, :path, :tags, :visibility, :uploaded_by, NOW())');
    $insert->execute([
        'type' => $type,
        'title' => $title,
        'path' => $path,
        'tags' => $tags,
        'visibility' => $visibility,
        'uploaded_by' => null,
    ]);
}

$args = $argv;
array_shift($args);
$apply = in_array('--apply', $args, true);
$csvPath = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    $csvPath = $arg;
    break;
}

if (!$csvPath) {
    usage();
    exit(1);
}

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV not found: {$csvPath}\n");
    exit(1);
}

$pdo = db();
$defaultVisibility = (string) SettingsService::getGlobal('media.privacy_default', 'member');
$uploadsRoot = __DIR__ . '/../public_html/uploads/store';

$existingBySku = [];
$existingByTitle = [];
$existingStmt = $pdo->query('SELECT sku, title FROM store_products');
foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!empty($row['sku'])) {
        $existingBySku[(string) $row['sku']] = true;
    }
    $existingByTitle[normalize_name((string) $row['title'])] = true;
}

$categoryIds = [];
$catStmt = $pdo->query('SELECT id, name FROM store_categories');
foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $categoryIds[normalize_name((string) $row['name'])] = (int) $row['id'];
}

$tagIds = [];
$tagStmt = $pdo->query('SELECT id, name FROM store_tags');
foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tagIds[normalize_name((string) $row['name'])] = (int) $row['id'];
}

$stats = [
    'rows' => 0,
    'skipped_type' => 0,
    'skipped_existing' => 0,
    'products' => 0,
    'images_downloaded' => 0,
    'images_failed' => 0,
];

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Failed to read CSV: {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($handle, 0, ',', '"', '\\');
if (!$header) {
    fwrite(STDERR, "Empty CSV: {$csvPath}\n");
    exit(1);
}
$map = array_flip($header);

while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $stats['rows']++;
    $type = $row[$map['Type']] ?? '';
    if (strtolower($type) !== 'simple') {
        $stats['skipped_type']++;
        continue;
    }

    $title = trim((string) ($row[$map['Name']] ?? ''));
    if ($title === '') {
        continue;
    }
    $sku = trim((string) ($row[$map['SKU']] ?? ''));
    if ($sku !== '' && isset($existingBySku[$sku])) {
        $stats['skipped_existing']++;
        continue;
    }
    if (isset($existingByTitle[normalize_name($title)])) {
        $stats['skipped_existing']++;
        continue;
    }

    $description = (string) ($row[$map['Description']] ?? '');
    if (trim($description) === '') {
        $description = (string) ($row[$map['Short description']] ?? '');
    }
    $description = trim(strip_tags(html_entity_decode($description)));

    $sale = parse_price($row[$map['Sale price']] ?? null);
    $regular = parse_price($row[$map['Regular price']] ?? null);
    $basePrice = $sale > 0 ? $sale : $regular;

    $stockValue = trim((string) ($row[$map['Stock']] ?? ''));
    $trackInventory = $stockValue !== '' && is_numeric($stockValue);
    $stockQty = $trackInventory ? (int) $stockValue : null;
    $lowStockValue = trim((string) ($row[$map['Low stock amount']] ?? ''));
    $lowStock = $lowStockValue !== '' && is_numeric($lowStockValue) ? (int) $lowStockValue : null;

    $published = (string) ($row[$map['Published']] ?? '0');
    $isActive = $published === '1' ? 1 : 0;

    $slug = store_unique_slug('store_products', store_slugify($title));

    $categories = [];
    $catRaw = (string) ($row[$map['Categories']] ?? '');
    if (trim($catRaw) !== '') {
        foreach (explode(',', $catRaw) as $cat) {
            $catName = trim($cat);
            if ($catName === '') {
                continue;
            }
            $key = normalize_name($catName);
            if (!isset($categoryIds[$key])) {
                if ($apply) {
                    $catSlug = store_unique_slug('store_categories', store_slugify($catName));
                    $stmt = $pdo->prepare('INSERT INTO store_categories (name, slug, created_at) VALUES (:name, :slug, NOW())');
                    $stmt->execute(['name' => $catName, 'slug' => $catSlug]);
                    $categoryIds[$key] = (int) $pdo->lastInsertId();
                } else {
                    $categoryIds[$key] = -1;
                }
            }
            $categories[] = $categoryIds[$key];
        }
    }

    $tags = [];
    $tagRaw = (string) ($row[$map['Tags']] ?? '');
    if (trim($tagRaw) !== '') {
        foreach (explode(',', $tagRaw) as $tag) {
            $tagName = trim($tag);
            if ($tagName === '') {
                continue;
            }
            $key = normalize_name($tagName);
            if (!isset($tagIds[$key])) {
                if ($apply) {
                    $tagSlug = store_unique_slug('store_tags', store_slugify($tagName));
                    $stmt = $pdo->prepare('INSERT INTO store_tags (name, slug, created_at) VALUES (:name, :slug, NOW())');
                    $stmt->execute(['name' => $tagName, 'slug' => $tagSlug]);
                    $tagIds[$key] = (int) $pdo->lastInsertId();
                } else {
                    $tagIds[$key] = -1;
                }
            }
            $tags[] = $tagIds[$key];
        }
    }

    $productId = null;
    if ($apply) {
        $stmt = $pdo->prepare('INSERT INTO store_products (title, slug, description, type, base_price, sku, track_inventory, stock_quantity, low_stock_threshold, event_name, is_active, created_at) VALUES (:title, :slug, :description, :type, :base_price, :sku, :track_inventory, :stock_quantity, :low_stock_threshold, :event_name, :is_active, NOW())');
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'type' => 'physical',
            'base_price' => $basePrice,
            'sku' => $sku !== '' ? $sku : null,
            'track_inventory' => $trackInventory ? 1 : 0,
            'stock_quantity' => $stockQty,
            'low_stock_threshold' => $lowStock,
            'event_name' => null,
            'is_active' => $isActive,
        ]);
        $productId = (int) $pdo->lastInsertId();

        foreach ($categories as $categoryId) {
            if ($categoryId <= 0) {
                continue;
            }
            $stmt = $pdo->prepare('INSERT INTO store_product_categories (product_id, category_id) VALUES (:product_id, :category_id)');
            $stmt->execute(['product_id' => $productId, 'category_id' => $categoryId]);
        }

        foreach ($tags as $tagId) {
            if ($tagId <= 0) {
                continue;
            }
            $stmt = $pdo->prepare('INSERT INTO store_product_tags (product_id, tag_id) VALUES (:product_id, :tag_id)');
            $stmt->execute(['product_id' => $productId, 'tag_id' => $tagId]);
        }
    }

    $imagesRaw = (string) ($row[$map['Images']] ?? '');
    if (trim($imagesRaw) !== '') {
        $images = array_map('trim', explode(',', $imagesRaw));
        $sortOrder = 0;
        $index = 1;
        foreach ($images as $imageUrl) {
            if ($imageUrl === '' || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $index++;
                continue;
            }
            $basename = sanitize_filename(basename(parse_url($imageUrl, PHP_URL_PATH) ?? "image-{$index}.jpg"));
            $targetDir = $uploadsRoot . '/' . $slug;
            $targetPath = $targetDir . '/' . $basename;
            $relativePath = '/uploads/store/' . $slug . '/' . $basename;

            if ($apply) {
                if (!is_file($targetPath)) {
                    if (!download_file($imageUrl, $targetPath)) {
                        $stats['images_failed']++;
                        $index++;
                        continue;
                    }
                    $stats['images_downloaded']++;
                }

                ensure_media($pdo, 'image', $title . ' Image ' . $index, $relativePath, 'store,product', $defaultVisibility);

                if ($productId) {
                    $stmt = $pdo->prepare('INSERT INTO store_product_images (product_id, image_url, sort_order, created_at) VALUES (:product_id, :image_url, :sort_order, NOW())');
                    $stmt->execute([
                        'product_id' => $productId,
                        'image_url' => $relativePath,
                        'sort_order' => $sortOrder,
                    ]);
                }
            }
            $sortOrder++;
            $index++;
        }
    }

    $stats['products']++;
    $existingByTitle[normalize_name($title)] = true;
    if ($sku !== '') {
        $existingBySku[$sku] = true;
    }
}

fclose($handle);

echo "Done.\n";
foreach ($stats as $key => $value) {
    echo "{$key}: {$value}\n";
}
if (!$apply) {
    echo "Dry-run only. Re-run with --apply to download images and import.\n";
}
