<?php
// version: 2026-06-03c — nuclear OPcache reset (validate_timestamps may be 0)
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\PendingRequestsService;

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo '<!-- PHP_FATAL:' . base64_encode(json_encode($err)) . ' -->';
    }
});

require_permission('admin.requests.view');

$user = current_user();

$typeFilter   = trim((string) ($_GET['type'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'archived', 'all'], true)) {
    $statusFilter = 'pending';
}
$validTypes = array_keys(PendingRequestsService::types());
if ($typeFilter !== '' && !in_array($typeFilter, $validTypes, true)) {
    $typeFilter = '';
}

$hubItems      = PendingRequestsService::all($typeFilter ?: null, $statusFilter);
$hubCounts     = PendingRequestsService::counts();
$approvedCount = count(PendingRequestsService::all(null, 'approved'));
$rejectedCount = count(PendingRequestsService::all(null, 'rejected'));
$pendingCount  = (int) ($hubCounts['__total'] ?? 0);
$totalAllCount = $pendingCount + $approvedCount + $rejectedCount;

$pageTitle  = 'Notification Hub';
$activePage = 'requests';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';

function reqStatusBadge(?string $status): string {
    return match (strtolower($status ?? '')) {
        'approved'       => 'bg-emerald-100 text-emerald-800',
        'rejected'       => 'bg-rose-100 text-rose-800',
        'pending', 'new' => 'bg-amber-100 text-amber-800',
        default          => 'bg-gray-100 text-gray-700',
    };
}

function reqTimeAgo(?string $dt): string {
    if (!$dt) return '';
    try {
        $diff = (new \DateTime())->getTimestamp() - (new \DateTime($dt))->getTimestamp();
        if ($diff < 120)    return 'Just now';
        if ($diff < 3600)   return round($diff / 60) . ' minutes ago';
        if ($diff < 86400)  return round($diff / 3600) . ' hours ago';
        if ($diff < 172800) return 'Yesterday';
        return round($diff / 86400) . ' days ago';
    } catch (\Exception) { return ''; }
}

function reqIsNew(?string $dt): bool {
    if (!$dt) return false;
    try {
        return (new \DateTime($dt))->getTimestamp() > (new \DateTime())->modify('-24 hours')->getTimestamp();
    } catch (\Exception) { return false; }
}

function reqStatusPill(array $item): array {
    return match ($item['type']) {
        'feedback'       => ['label' => 'Requires Triage',       'dot' => 'bg-blue-500',  'text' => 'text-blue-700',  'bg' => 'bg-blue-50'],
        'store_order'    => ['label' => 'Action Required',        'dot' => 'bg-rose-500',  'text' => 'text-rose-700',  'bg' => 'bg-rose-50'],
        'membership_payment' => ['label' => 'Check Bank & Approve', 'dot' => 'bg-rose-500', 'text' => 'text-rose-700', 'bg' => 'bg-rose-50'],
        'chapter_change' => ['label' => 'Pending Admin Approval', 'dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'bg' => 'bg-amber-50'],
        'profile_change' => ['label' => 'Pending Admin Approval', 'dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'bg' => 'bg-amber-50'],
        'profile_update' => ['label' => 'No Action Needed',       'dot' => 'bg-sky-500',   'text' => 'text-sky-700',   'bg' => 'bg-sky-50'],
        default          => ['label' => 'Pending Review',         'dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'bg' => 'bg-amber-50'],
    };
}

function reqActionButtons(array $item): string {
    $status = strtolower($item['status'] ?? '');
    $url    = htmlspecialchars($item['detail_url'], ENT_QUOTES, 'UTF-8');
    $type   = $item['type'];

    $sec    = fn($l) => '<a href="' . $url . '" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition-colors">' . htmlspecialchars($l) . '</a>';
    $green  = fn($l) => '<a href="' . $url . '" class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition-colors">' . htmlspecialchars($l) . '</a>';
    $dark   = fn($l) => '<a href="' . $url . '" class="inline-flex items-center rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800 transition-colors">' . htmlspecialchars($l) . '</a>';
    $orange = fn($l) => '<a href="' . $url . '" class="inline-flex items-center rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 transition-colors">' . htmlspecialchars($l) . '</a>';

    if (!in_array($status, ['pending', 'new'], true)) {
        return $sec('View Details');
    }

    return match ($type) {
        'feedback'       => $sec('Reply') . $dark('Mark Resolved'),
        'store_order'    => $sec('Track Order') . $orange('Resolve Conflict'),
        'chapter_change' => $sec('View History') . $green('Approve Change'),
        'profile_change' => $sec('View Details') . $green('Approve Change'),
        'profile_update' => $sec('View Changes') . $dark('Mark as Read'),
        default          => $sec('View Details') . $green('Approve'),
    };
}
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-gray-50 relative">
    <?php $topbarTitle = 'Notification Hub'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <!-- Header -->
      <header data-tour="notif-hub-header" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="font-display text-2xl font-bold text-gray-900">Notification Hub</h1>
          <p class="text-sm text-gray-600 mt-0.5">
            You have <span class="font-semibold text-primary"><?= (int) $pendingCount ?> pending requests</span> that require your immediate attention.
          </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <a href="?status=archived" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 shadow-sm transition-colors">
            Archive All
          </a>
          <a href="?status=all" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90 shadow-sm transition-colors">
            Mark all as read
          </a>
        </div>
      </header>

      <!-- Stat Cards -->
      <div data-tour="notif-hub-stats" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50">
              <span class="material-icons-outlined text-amber-500">schedule</span>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Pending</p>
              <p class="text-2xl font-bold text-gray-900"><?= (int) $pendingCount ?></p>
            </div>
          </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50">
              <span class="material-icons-outlined text-emerald-500">check_circle</span>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Approved</p>
              <p class="text-2xl font-bold text-gray-900"><?= (int) $approvedCount ?></p>
            </div>
          </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50">
              <span class="material-icons-outlined text-rose-500">cancel</span>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Rejected</p>
              <p class="text-2xl font-bold text-gray-900"><?= (int) $rejectedCount ?></p>
            </div>
          </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-50">
              <span class="material-icons-outlined text-orange-500">visibility</span>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Total</p>
              <p class="text-2xl font-bold text-gray-900"><?= $totalAllCount >= 1000 ? number_format($totalAllCount / 1000, 1) . 'k' : (int) $totalAllCount ?></p>
            </div>
          </div>
        </div>
      </div>

      <!-- Type Filter Blocks -->
      <div data-tour="notif-hub-type-filters" class="flex flex-wrap gap-2">
        <a href="?status=<?= e($statusFilter) ?>"
           class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition-colors <?= $typeFilter === '' ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900' ?>">
          <span class="material-icons-outlined text-[18px]">inbox</span>
          All Notifications
        </a>
        <?php foreach (PendingRequestsService::types() as $type => $meta): ?>
          <?php $cnt = (int) ($hubCounts[$type] ?? 0); ?>
          <a href="?type=<?= e($type) ?>&status=<?= e($statusFilter) ?>"
             class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition-colors <?= $typeFilter === $type ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900' ?>">
            <span class="material-icons-outlined text-[18px]"><?= e($meta['icon']) ?></span>
            <?= e($meta['label']) ?>
            <?php if ($cnt > 0): ?>
              <span class="inline-flex items-center justify-center rounded-full h-5 min-w-[20px] px-1.5 text-[11px] font-bold <?= $typeFilter === $type ? 'bg-primary/20 text-primary' : 'bg-gray-100 text-gray-600' ?>">
                <?= $cnt ?>
              </span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Status Pills (secondary filter) -->
      <div data-tour="notif-hub-status-pills" class="flex flex-wrap items-center gap-2">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'archived' => 'Archived', 'all' => 'All'] as $s => $label): ?>
          <a href="?<?= $typeFilter !== '' ? 'type=' . e($typeFilter) . '&' : '' ?>status=<?= e($s) ?>"
             class="rounded-full px-3 py-1 text-xs font-semibold transition-colors <?= $statusFilter === $s ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Notification Cards -->
      <?php if (empty($hubItems)): ?>
        <div data-tour="notif-hub-list" class="rounded-2xl border border-gray-200 bg-white px-6 py-16 text-center shadow-sm">
          <span class="material-icons-outlined text-5xl text-gray-300">inbox</span>
          <h2 class="mt-3 text-lg font-semibold text-gray-700">Nothing to review</h2>
          <p class="text-sm text-gray-500">
            There are no <?= e($statusFilter) ?> requests<?= $typeFilter ? ' for ' . e(PendingRequestsService::types()[$typeFilter]['label'] ?? $typeFilter) : '' ?>.
          </p>
        </div>
      <?php else: ?>
        <div data-tour="notif-hub-list" class="space-y-3">
          <?php foreach ($hubItems as $item): ?>
            <?php
              $status    = strtolower($item['status'] ?? '');
              $isPending = in_array($status, ['pending', 'new'], true);
              $isNew     = reqIsNew($item['submitted_at'] ?? '');
              $pill      = reqStatusPill($item);
              $timeAgo   = reqTimeAgo($item['submitted_at'] ?? '');
            ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:shadow-md transition-shadow">
              <div class="flex items-start gap-4">
                <!-- Type Icon -->
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50">
                  <span class="material-icons-outlined text-gray-500"><?= e($item['type_icon']) ?></span>
                </div>

                <!-- Body -->
                <div class="flex-1 min-w-0">
                  <!-- Title + badge -->
                  <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-semibold text-gray-900 truncate"><?= e($item['title']) ?></h3>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold tracking-wide <?= $isNew ? 'bg-primary/15 text-primary' : reqStatusBadge($status) ?>">
                      <?= $isNew ? 'NEW' : strtoupper($status) ?>
                    </span>
                  </div>

                  <!-- From + time -->
                  <?php if (!empty($item['submitter_name']) || $timeAgo): ?>
                    <p class="text-xs text-gray-500 mt-0.5">
                      <?php if (!empty($item['submitter_name'])): ?>
                        From: <span class="font-medium text-gray-700"><?= e($item['submitter_name']) ?></span>
                        <?= $timeAgo ? ' · ' . e($timeAgo) : '' ?>
                      <?php else: ?>
                        <?= e($timeAgo) ?>
                      <?php endif; ?>
                    </p>
                  <?php endif; ?>

                  <!-- Summary -->
                  <?php if (!empty($item['summary'])): ?>
                    <p class="text-sm text-gray-600 mt-2 line-clamp-2"><?= e($item['summary']) ?></p>
                  <?php endif; ?>

                  <!-- Status pill + action buttons -->
                  <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                    <?php if ($isPending): ?>
                      <span class="inline-flex items-center gap-1.5 rounded-full <?= $pill['bg'] ?> px-3 py-1 text-xs font-semibold <?= $pill['text'] ?>">
                        <span class="h-1.5 w-1.5 rounded-full <?= $pill['dot'] ?>"></span>
                        <?= e($pill['label']) ?>
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full <?= reqStatusBadge($status) ?> px-3 py-1 text-xs font-semibold">
                        <?= e(ucfirst($status)) ?>
                      </span>
                    <?php endif; ?>
                    <div class="flex items-center gap-2">
                      <?= reqActionButtons($item) ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Load older -->
        <div class="flex justify-center pt-2 pb-4">
          <a href="?<?= $typeFilter !== '' ? 'type=' . e($typeFilter) . '&' : '' ?>status=all"
             class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 shadow-sm transition-colors">
            Load older notifications
            <span class="material-icons-outlined text-base">expand_more</span>
          </a>
        </div>
      <?php endif; ?>

    </div>
  </main>
</div>
<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
</body></html>
