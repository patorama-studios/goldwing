<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\PendingRequestsService;

require_permission('admin.requests.view');

$user = current_user();

$typeFilter   = trim((string) ($_GET['type'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
$validTypes = array_keys(PendingRequestsService::types());
if ($typeFilter !== '' && !in_array($typeFilter, $validTypes, true)) {
    $typeFilter = '';
}

$items  = PendingRequestsService::all($typeFilter ?: null, $statusFilter);
$counts = PendingRequestsService::counts();

$pageTitle  = 'Notification Hub';
$activePage = 'requests';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';

function reqStatusBadge(string $status): string {
    $s = strtolower($status);
    return match ($s) {
        'approved' => 'bg-emerald-100 text-emerald-800',
        'rejected' => 'bg-rose-100 text-rose-800',
        'pending', 'new' => 'bg-amber-100 text-amber-800',
        default => 'bg-gray-100 text-gray-700',
    };
}
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Notification Hub'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <header class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="font-display text-2xl font-bold text-gray-900">Notification Hub</h1>
          <p class="text-sm text-gray-500">Review and action all pending requests across the site.</p>
        </div>
        <div class="flex items-center gap-2 text-sm">
          <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 font-semibold text-amber-800">
            <?= (int) ($counts['__total'] ?? 0) ?> pending
          </span>
        </div>
      </header>

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-3 flex flex-wrap items-center gap-2">
          <span class="text-xs uppercase tracking-[0.3em] text-gray-500 mr-2">Type:</span>
          <a class="rounded-full px-3 py-1 text-xs font-semibold <?= $typeFilter === '' ? 'bg-primary text-gray-900' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
             href="?status=<?= e($statusFilter) ?>">All (<?= (int) ($counts['__total'] ?? 0) ?>)</a>
          <?php foreach (PendingRequestsService::types() as $type => $meta): ?>
            <a class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold <?= $typeFilter === $type ? 'bg-primary text-gray-900' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
               href="?type=<?= e($type) ?>&status=<?= e($statusFilter) ?>">
              <span class="material-icons-outlined text-sm"><?= e($meta['icon']) ?></span>
              <?= e($meta['label']) ?> (<?= (int) ($counts[$type] ?? 0) ?>)
            </a>
          <?php endforeach; ?>
        </div>
        <div class="border-b border-gray-100 px-4 py-3 flex flex-wrap items-center gap-2">
          <span class="text-xs uppercase tracking-[0.3em] text-gray-500 mr-2">Status:</span>
          <?php foreach (['pending', 'approved', 'rejected', 'all'] as $s): ?>
            <a class="rounded-full px-3 py-1 text-xs font-semibold <?= $statusFilter === $s ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
               href="?<?= $typeFilter !== '' ? 'type=' . e($typeFilter) . '&' : '' ?>status=<?= e($s) ?>">
              <?= e(ucfirst($s)) ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if (empty($items)): ?>
          <div class="px-6 py-16 text-center">
            <span class="material-icons-outlined text-5xl text-gray-300">inbox</span>
            <h2 class="mt-3 text-lg font-semibold text-gray-700">Nothing to review</h2>
            <p class="text-sm text-gray-500">There are no <?= e($statusFilter) ?> requests<?= $typeFilter ? ' for ' . e(PendingRequestsService::types()[$typeFilter]['label'] ?? $typeFilter) : '' ?>.</p>
          </div>
        <?php else: ?>
          <div class="divide-y divide-gray-100">
            <?php foreach ($items as $item): ?>
              <a class="block px-6 py-4 hover:bg-gray-50 transition-colors" href="<?= e($item['detail_url']) ?>">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div class="flex items-start gap-3 min-w-0">
                    <span class="material-icons-outlined text-gray-400 mt-0.5"><?= e($item['type_icon']) ?></span>
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500"><?= e($item['type_label']) ?></span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold <?= reqStatusBadge($item['status']) ?>"><?= e(strtoupper($item['status'])) ?></span>
                      </div>
                      <p class="font-semibold text-gray-900 truncate mt-0.5"><?= e($item['title']) ?></p>
                      <?php if (!empty($item['summary'])): ?>
                        <p class="text-sm text-gray-600 mt-1 line-clamp-2"><?= e($item['summary']) ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="text-right text-xs text-gray-500 shrink-0">
                    <?php if (!empty($item['submitter_name'])): ?>
                      <p class="font-medium text-gray-700"><?= e($item['submitter_name']) ?></p>
                    <?php endif; ?>
                    <p><?= e($item['submitted_at'] ?? '') ?></p>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
</div>
</body></html>
