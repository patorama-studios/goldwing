<?php
/**
 * One-off admin tool: bulk-sync `store_products` rows to Stripe Products so
 * they appear in dashboard.stripe.com/products before the first invoice fires.
 *
 * StoreInvoiceService already syncs lazily on the first checkout that
 * references a product, so this tool isn't strictly required — but running it
 * once after deploying the Stripe-invoice work gives you a populated catalog
 * immediately, and keeps Stripe Product names + descriptions in step with the
 * latest local edits (use `?force=1` to update existing Stripe products too).
 *
 * Usage:
 *   /admin/sync-store-products-to-stripe.php             — dry run, lists candidates
 *   /admin/sync-store-products-to-stripe.php?confirm=1   — execute sync (only NEW products)
 *   /admin/sync-store-products-to-stripe.php?confirm=1&force=1
 *                                                        — also push name/description
 *                                                          updates for products already
 *                                                          synced to Stripe
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ThirdParty/stripe-php/init.php';

use App\Services\Database;
use App\Services\StripeService;

require_permission('admin.settings.general.manage');

header('Content-Type: text/plain; charset=utf-8');

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$force = isset($_GET['force']) && $_GET['force'] === '1';

echo "=== Sync store products to Stripe Products ===\n";
echo "Time:   " . date('c') . "\n";
echo "Mode:   " . ($confirm ? '*** EXECUTING ***' : 'dry run (add ?confirm=1 to execute)') . "\n";
echo "Force:  " . ($force ? 'yes — will update existing Stripe Products too' : 'no — skip rows that already have stripe_product_id') . "\n\n";

$pdo = Database::connection();

$stmt = $pdo->query('SELECT id, title, description, slug, stripe_product_id, is_active FROM store_products ORDER BY title ASC');
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$products) {
    echo "No store_products in the database. Nothing to do.\n";
    exit(0);
}

echo str_pad('#', 4) . str_pad('Title', 36) . str_pad('Active', 8) . str_pad('Stripe ID', 32) . "Action\n";
echo str_repeat('-', 100) . "\n";

$plan = [];
foreach ($products as $row) {
    $action = '';
    $stripeId = (string) ($row['stripe_product_id'] ?? '');

    if ($stripeId === '') {
        $action = 'CREATE';
    } elseif ($force) {
        $action = 'UPDATE';
    } else {
        $action = 'skip (already synced)';
    }

    echo str_pad((string) $row['id'], 4)
       . str_pad(substr((string) $row['title'], 0, 34), 36)
       . str_pad($row['is_active'] ? 'yes' : 'no', 8)
       . str_pad($stripeId !== '' ? substr($stripeId, 0, 30) : '—', 32)
       . $action . "\n";

    if (in_array($action, ['CREATE', 'UPDATE'], true)) {
        $plan[] = ['row' => $row, 'action' => $action];
    }
}

echo "\n";
echo "Planned actions: " . count($plan) . "\n\n";

if (!$confirm) {
    echo "Dry run complete. Add ?confirm=1 to execute.\n";
    exit(0);
}

if (!$plan) {
    echo "Nothing to do.\n";
    exit(0);
}

$created = 0;
$updated = 0;
$errors = 0;
foreach ($plan as $entry) {
    $row = $entry['row'];
    $action = $entry['action'];
    $name = trim((string) $row['title']);
    if ($name === '') {
        $name = 'Store product #' . $row['id'];
    }
    $description = trim((string) ($row['description'] ?? ''));
    if ($description !== '') {
        $description = mb_substr(strip_tags($description), 0, 500);
    }

    $payload = [
        'name' => $name,
        'metadata' => [
            'store_product_id' => (string) $row['id'],
            'store_slug' => (string) ($row['slug'] ?? ''),
        ],
    ];
    if ($description !== '') {
        $payload['description'] = $description;
    }
    // active flag — Stripe Products have an `active` boolean
    $payload['active'] = (bool) ($row['is_active'] ?? 1);

    try {
        if ($action === 'CREATE') {
            $stripe = StripeService::createProduct($payload);
            if ($stripe && !empty($stripe['id'])) {
                $stmt = $pdo->prepare('UPDATE store_products SET stripe_product_id = :spid, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['spid' => $stripe['id'], 'id' => $row['id']]);
                echo "  · CREATE #{$row['id']} → {$stripe['id']}\n";
                $created++;
            } else {
                echo "  ! CREATE #{$row['id']} failed (no id returned)\n";
                $errors++;
            }
        } elseif ($action === 'UPDATE') {
            $stripe = StripeService::updateProduct((string) $row['stripe_product_id'], $payload);
            if ($stripe && !empty($stripe['id'])) {
                echo "  · UPDATE #{$row['id']} → {$stripe['id']}\n";
                $updated++;
            } else {
                echo "  ! UPDATE #{$row['id']} failed\n";
                $errors++;
            }
        }
    } catch (\Throwable $e) {
        echo "  ! Error on #{$row['id']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nDone. Created: {$created} · Updated: {$updated} · Errors: {$errors}\n";
