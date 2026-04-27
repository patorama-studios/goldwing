<?php
if (!defined('IN_STORE_ADMIN')) exit('No direct access allowed');
use App\Services\Csrf;

/*
 * One-time merge: Jan-2026 product stubs (no SKUs, have images, IDs 1–16)
 * into Apr-2026 catalogue imports (have SKUs/variants/stock, IDs 17–30).
 * IDs 9–12 (Chambray shirts) kept as-is.
 *
 * After merge, all remaining product IDs are renumbered starting at 1.
 */

$mergePlan = [
     1 => 18,  // AGA Womens short sleve polo shirt → AGA Polo Shirt (Ladies)
     2 => 17,  // AGA Mens short sleve polo shirt   → AGA Polo Shirt (Mens)
     3 => 26,  // AGA Flag                          → AGA Flag
     4 => 24,  // AGA Baseball Cap                  → AGA Baseball Cap
     5 => 19,  // AGA Long sleve polo shirt         → AGA Polo Shirt (Long Sleeve)
     6 => 20,  // AGA Rugby Jersey                  → AGA Rugby Top
     7 => 21,  // AGA Polo Fleece Jacket            → AGA Polar Fleece
     8 => 22,  // AGA Polo Fleece Vest              → AGA Polar Fleece Vest
    13 => 28,  // Embroidered Patch                 → AGA Patch
    14 => 29,  // Enamel Badge                      → AGA Enamel Badge
    15 => 27,  // Acrylic Sticker                   → AGA Decal
    16 => 23,  // Bucket Hat                        → AGA Bucket Hat
];

// After deletes, surviving IDs in order, mapped to their new sequential IDs.
$renumberMap = [
     9 =>  1,
    10 =>  2,
    11 =>  3,
    12 =>  4,
    17 =>  5,
    18 =>  6,
    19 =>  7,
    20 =>  8,
    21 =>  9,
    22 => 10,
    23 => 11,
    24 => 12,
    25 => 13,
    26 => 14,
    27 => 15,
    28 => 16,
    29 => 17,
    30 => 18,
];

// Child tables with a direct product_id column.
$childTables = [
    'store_product_images',
    'store_product_categories',
    'store_product_tags',
    'store_product_options',
    'store_product_variants',
];

// Detect whether the old stub rows still exist.
$oldIds = array_keys($mergePlan);
$placeholders = implode(',', $oldIds);
$stmt = $pdo->query("SELECT COUNT(*) FROM store_products WHERE id IN ({$placeholders})");
$oldCount = (int) $stmt->fetchColumn();
$alreadyDone = ($oldCount === 0);

// Fetch current product titles for the summary table.
$allIds = array_unique(array_merge($oldIds, array_values($mergePlan), array_keys($renumberMap)));
sort($allIds);
$ph2 = implode(',', array_fill(0, count($allIds), '?'));
$stmt = $pdo->prepare("SELECT id, title FROM store_products WHERE id IN ({$ph2})");
$stmt->execute($allIds);
$productTitles = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $productTitles[(int) $row['id']] = $row['title'];
}

$execResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } elseif ($alreadyDone) {
        $alerts[] = ['type' => 'error', 'message' => 'Old stubs are gone — merge has already been applied.'];
    } else {
        $log = [];
        try {
            // FK checks are session-scoped, not transactional.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->beginTransaction();

            // 1. Migrate images from stub products to their catalogue counterparts.
            $imgStmt = $pdo->prepare('UPDATE store_product_images SET product_id = :new WHERE product_id = :old');
            foreach ($mergePlan as $oldId => $newId) {
                $imgStmt->execute(['old' => $oldId, 'new' => $newId]);
                $n = $imgStmt->rowCount();
                if ($n > 0) {
                    $log[] = "  Migrated {$n} image(s): product {$oldId} → {$newId}";
                }
            }

            // 2. With FK checks off, cascades don't fire automatically.
            //    Manually purge child rows for stub products so renumbering has no collisions.
            $stubCleanupTables = ['store_product_categories', 'store_product_tags', 'store_product_options', 'store_product_variants'];
            foreach ($stubCleanupTables as $tbl) {
                $n = $pdo->exec("DELETE FROM {$tbl} WHERE product_id IN ({$placeholders})");
                if ($n > 0) {
                    $log[] = "  Purged {$n} orphaned row(s) from {$tbl}";
                }
            }

            // 3. Delete stub products.
            $deleted = $pdo->exec("DELETE FROM store_products WHERE id IN ({$placeholders})");
            $log[] = "Deleted {$deleted} stub products (IDs: {$placeholders})";

            // 4. Renumber surviving products and all child product_id references.
            $prodUpd = $pdo->prepare('UPDATE store_products SET id = :new WHERE id = :old');
            $childUpd = [];
            foreach ($childTables as $tbl) {
                $childUpd[$tbl] = $pdo->prepare("UPDATE {$tbl} SET product_id = :new WHERE product_id = :old");
            }
            foreach ($renumberMap as $oldId => $newId) {
                $prodUpd->execute(['old' => $oldId, 'new' => $newId]);
                foreach ($childUpd as $stmt) {
                    $stmt->execute(['old' => $oldId, 'new' => $newId]);
                }
                $log[] = "  Renumbered product {$oldId} → {$newId}";
            }

            $pdo->commit();

            // 5. Reset auto-increment (DDL; MySQL will use max(id)+1, so effectively next insert = 19).
            $pdo->exec('ALTER TABLE store_products AUTO_INCREMENT = 1');
            $log[] = 'Reset AUTO_INCREMENT (next insert will use max(id)+1)';

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            $execResult = ['success' => true, 'log' => $log];
            $alerts[] = ['type' => 'success', 'message' => 'Merge complete. Products renumbered 1–' . count($renumberMap) . '.'];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $execResult = ['success' => false, 'log' => $log];
            $alerts[] = ['type' => 'error', 'message' => 'Merge failed: ' . $e->getMessage()];
        }
    }
}

$pageSubtitle = 'One-time: merge Jan 2026 product stubs with Apr 2026 catalogue, then renumber IDs 1–18.';
?>
<section class="space-y-6">

  <?php if ($alreadyDone && !$execResult): ?>
    <div class="rounded-2xl border border-green-200 bg-green-50 p-6 text-sm text-green-700">
      <strong class="font-semibold">Already applied.</strong> The Jan 2026 stub products (IDs 1–8, 13–16) no longer exist — this merge has already been executed.
    </div>
  <?php endif; ?>

  <!-- Merge plan summary -->
  <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="mb-4 text-lg font-semibold text-gray-900">Merge plan</h2>
    <p class="mb-4 text-sm text-slate-600">Images are migrated from the old stub to the catalogue product, then the stub is deleted. Products not in this table (IDs 9–12, Chambray shirts) are kept unchanged.</p>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
            <th class="pb-2 pr-4">Old ID</th>
            <th class="pb-2 pr-4">Old title</th>
            <th class="pb-2 pr-4">→</th>
            <th class="pb-2 pr-4">New ID</th>
            <th class="pb-2">New title</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($mergePlan as $oldId => $newId): ?>
            <tr>
              <td class="py-2 pr-4 font-mono text-slate-400"><?= e((string) $oldId) ?></td>
              <td class="py-2 pr-4 text-slate-600"><?= e($productTitles[$oldId] ?? '(not found)') ?></td>
              <td class="py-2 pr-4 text-slate-400">→</td>
              <td class="py-2 pr-4 font-mono text-slate-400"><?= e((string) $newId) ?></td>
              <td class="py-2 text-slate-800"><?= e($productTitles[$newId] ?? '(not found)') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ID renumber table -->
  <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="mb-4 text-lg font-semibold text-gray-900">ID renumbering (after merge)</h2>
    <p class="mb-4 text-sm text-slate-600">All surviving products are renumbered sequentially. Foreign keys in images, categories, tags, options, and variants are updated in the same operation.</p>
    <div class="flex flex-wrap gap-2 text-xs font-mono">
      <?php foreach ($renumberMap as $old => $new): ?>
        <span class="rounded bg-slate-100 px-2 py-1 text-slate-600"><?= e("{$old}→{$new}") ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Action form -->
  <?php if (!$alreadyDone): ?>
    <form method="post" class="rounded-2xl border border-red-100 bg-white p-6 shadow-sm space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <p class="text-sm text-slate-700">
        <strong class="font-semibold text-red-600">Destructive and irreversible.</strong>
        This deletes 12 stub products and renumbers all remaining product IDs. Run a database backup first if in doubt.
      </p>
      <button
        type="submit"
        class="rounded-lg bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700"
        onclick="return confirm('This will permanently delete 12 stub products and renumber all product IDs. This cannot be undone. Continue?');"
      >
        Execute merge &amp; renumber
      </button>
    </form>
  <?php endif; ?>

  <!-- Result log -->
  <?php if ($execResult): ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <h2 class="mb-3 text-lg font-semibold text-gray-900">
        <?= $execResult['success'] ? 'Merge log' : 'Error log (partial)' ?>
      </h2>
      <pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><?= e(implode("\n", $execResult['log'])) ?></pre>
    </div>
  <?php endif; ?>

</section>
