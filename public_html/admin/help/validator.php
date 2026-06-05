<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\TourService;

require_login();
require_role(['admin', 'webmaster']);
$user = current_user();

$tours    = TourService::allTours();
$latest   = TourService::latestRunsBySlug();
$staleAfter = TourService::STALE_AFTER_DAYS;
$now      = time();
$attention = TourService::attentionCount();

function gw_tour_status_label(array $tours, array $latest, int $now, int $staleAfter, string $slug): array {
    $run = $latest[$slug] ?? null;
    if (!$run) {
        return ['Never tested', 'bg-gray-100 text-gray-700'];
    }
    $ts = strtotime((string) $run['created_at']) ?: 0;
    if ($run['status'] === 'fail')    return ['Fail', 'bg-rose-100 text-rose-800'];
    if ($run['status'] === 'partial') return ['Partial', 'bg-amber-100 text-amber-800'];
    if ($ts && $ts < ($now - $staleAfter * 86400)) {
        return ['Stale (' . date('j M Y', $ts) . ')', 'bg-amber-100 text-amber-800'];
    }
    return ['Verified ' . date('j M', $ts), 'bg-emerald-100 text-emerald-800'];
}

$pageTitle  = 'Tour Validator';
$activePage = 'help-validator';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php include __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-5xl mx-auto">
      <header class="mb-8">
        <h1 class="font-display text-3xl text-gray-900">Tour Validator</h1>
        <p class="mt-1 text-gray-600">Walk through each tour to confirm it still matches the live page. Failures email <code><?= e(\App\Services\SettingsService::getGlobal('site.support_email', 'admin@goldwing.org.au')) ?></code>.</p>
        <?php if ($attention > 0): ?>
          <div class="mt-4 inline-flex items-center gap-2 rounded-full bg-amber-100 text-amber-800 px-3 py-1 text-sm font-semibold">
            <span class="material-icons-outlined text-base">warning_amber</span>
            <?= (int) $attention ?> tour<?= $attention === 1 ? '' : 's' ?> need attention
          </div>
        <?php endif; ?>
      </header>

      <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
            <tr>
              <th class="px-5 py-3">Tour</th>
              <th class="px-5 py-3">Audience</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3">Last tested</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if (!$tours): ?>
              <tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">No tours in the manifest yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($tours as $slug => $tour): ?>
              <?php [$label, $cls] = gw_tour_status_label($tours, $latest, $now, $staleAfter, $slug); ?>
              <?php $run = $latest[$slug] ?? null; ?>
              <tr>
                <td class="px-5 py-4 align-top">
                  <div class="font-semibold text-gray-900"><?= e($tour['name'] ?? $slug) ?></div>
                  <div class="text-xs text-gray-500 font-mono"><?= e($slug) ?></div>
                  <a href="<?= e($tour['page_url'] ?? '#') ?>" class="text-xs text-secondary hover:underline">Open page →</a>
                </td>
                <td class="px-5 py-4 align-top">
                  <span class="inline-flex px-2 py-1 rounded text-xs bg-gray-100 text-gray-700"><?= e($tour['audience'] ?? 'member') ?></span>
                </td>
                <td class="px-5 py-4 align-top">
                  <span class="inline-flex px-2 py-1 rounded text-xs font-semibold <?= e($cls) ?>"><?= e($label) ?></span>
                </td>
                <td class="px-5 py-4 align-top text-xs text-gray-600">
                  <?php if ($run): ?>
                    <?= e($run['run_kind']) ?> · <?= e(date('j M Y, g:i a', strtotime((string) $run['created_at']))) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="px-5 py-4 align-top text-right">
                  <a href="<?= e($tour['page_url'] ?? '#') ?>?gw_validate=<?= urlencode($slug) ?>"
                     target="_blank" rel="noopener"
                     class="inline-flex items-center px-3 py-2 rounded-lg bg-primary text-gray-900 text-xs font-semibold hover:bg-primary/90">
                    Test now
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-8 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h2 class="font-display text-xl text-gray-900 mb-2">Run the automated linter</h2>
        <p class="text-sm text-gray-600 mb-4">The linter loads each target page server-side and confirms every <code>data-tour</code> selector exists. Run this after any change to a tour's target page.</p>
        <pre class="bg-gray-900 text-emerald-300 text-xs rounded-lg p-4 overflow-x-auto"><code>php scripts/lint_tours.php</code></pre>
        <p class="text-xs text-gray-500 mt-3">Results are written to <code>tour_test_runs</code> with <code>run_kind = 'linter'</code> and show up in the status column above.</p>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
