<?php
if (!defined('IN_STORE_ADMIN')) exit('No direct access allowed');
use App\Services\Csrf;

require_once __DIR__ . '/../../../includes/store_catalogue_import.php';

$paths = catalogue_default_paths();
$availablePaths = $paths['available'];
$defaultPath = $paths['default'];

$selectedPath = (string) ($_POST['catalogue_path'] ?? $_GET['catalogue_path'] ?? $defaultPath);
$updateShipping = !empty($_POST['update_shipping']);
$result = null;
$resultError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['dry_run', 'apply'], true)) {
            $apply = $action === 'apply';
            $allowed = false;
            foreach ($availablePaths as $candidate) {
                if (realpath($candidate) === realpath($selectedPath)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $alerts[] = ['type' => 'error', 'message' => 'Catalogue path is not in the allowed list.'];
            } else {
                try {
                    $result = catalogue_import_run($pdo, $selectedPath, $apply, $updateShipping);
                    $msg = $apply ? 'Catalogue applied to the database.' : 'Dry-run complete. No changes written.';
                    $alerts[] = ['type' => 'success', 'message' => $msg];
                } catch (Throwable $e) {
                    $resultError = $e->getMessage();
                    $alerts[] = ['type' => 'error', 'message' => 'Import failed: ' . $resultError];
                }
            }
        }
    }
}

$pageSubtitle = 'Run the bulk catalogue importer (no SSH required).';
?>
<section class="space-y-6">
  <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-gray-900">How this works</h2>
    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
      <li>Dry-run shows exactly what would change without touching the database.</li>
      <li>Apply writes the catalogue to the store. Products are matched by SKU; existing rows are updated, new SKUs are inserted, and unrelated products are left alone.</li>
      <li>For products with sizes (variants), all options/variants are replaced with the spec on each run. Carts and orders keep their snapshots.</li>
      <li>Images are not imported — upload via the product editor after import.</li>
      <li>Optional: tick "Update shipping flat rate" to also push the catalogue's flat postage rate into Store Settings.</li>
    </ul>
  </div>

  <form method="post" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
    <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">

    <div>
      <label class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Catalogue file</label>
      <select name="catalogue_path" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
        <?php foreach ($availablePaths as $path): ?>
          <option value="<?= e($path) ?>" <?= $path === $selectedPath ? 'selected' : '' ?>>
            <?= e(basename($path)) ?>
          </option>
        <?php endforeach; ?>
        <?php if (!$availablePaths): ?>
          <option value="">No catalogue files found in scripts/data/</option>
        <?php endif; ?>
      </select>
      <p class="mt-2 text-xs text-slate-500">Files must live in <code>scripts/data/store_catalogue_*.json</code> on the server.</p>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-600">
      <input type="checkbox" name="update_shipping" value="1" class="h-4 w-4 rounded border-slate-300 text-secondary focus:ring-secondary" <?= $updateShipping ? 'checked' : '' ?>>
      Also update store shipping flat rate from this catalogue
    </label>

    <div class="flex flex-wrap gap-3 pt-2">
      <button type="submit" name="action" value="dry_run" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
        Run dry-run preview
      </button>
      <button type="submit" name="action" value="apply" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink hover:opacity-90" onclick="return confirm('This will write the catalogue to the live store database. Continue?');">
        Apply to database
      </button>
    </div>
  </form>

  <?php if ($result): ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-gray-900">
          Result —
          <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-slate-700">
            <?= e($result['mode']) ?>
          </span>
        </h2>
      </div>

      <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <?php foreach ($result['stats'] as $key => $value): ?>
          <div class="rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= e(str_replace('_', ' ', $key)) ?></div>
            <div class="mt-1 text-xl font-semibold text-slate-800"><?= e((string) $value) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <details open>
        <summary class="cursor-pointer text-sm font-semibold text-slate-700">Log</summary>
        <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><?= e(implode("\n", $result['log'])) ?></pre>
      </details>
    </div>
  <?php elseif ($resultError): ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-700">
      <strong class="font-semibold">Import failed:</strong>
      <pre class="mt-2 whitespace-pre-wrap"><?= e($resultError) ?></pre>
    </div>
  <?php endif; ?>
</section>
