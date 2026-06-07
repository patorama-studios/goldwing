<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AuditHubService;

require_permission('admin.logs.view');

$filters = [
    'search' => trim((string) ($_GET['q'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
    'actor' => trim((string) ($_GET['actor'] ?? '')),
    'action' => trim((string) ($_GET['action'] ?? '')),
    'start' => trim((string) ($_GET['start'] ?? '')),
    'end' => trim((string) ($_GET['end'] ?? '')),
];

$allowedLimits = [25, 50, 100, 200];
$limit = (int) ($_GET['limit'] ?? 50);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 50;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$result = AuditHubService::query($filters, $limit, $offset);
$rows = $result['rows'];
$total = $result['total'];
$stats = AuditHubService::stats();
$actions = AuditHubService::distinctActions();

$hasAnyFilter = $filters['search'] !== ''
    || $filters['source'] !== ''
    || $filters['actor'] !== ''
    || $filters['action'] !== ''
    || $filters['start'] !== ''
    || $filters['end'] !== '';

function audit_build_query(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return http_build_query($params);
}

function audit_relative_time(?string $datetime): string
{
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return $diff <= 1 ? 'just now' : $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('j M Y', $ts);
}

$pageTitle = 'Audit Hub';
$activePage = 'audit-hub';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Audit Hub'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <!-- Stats cards -->
      <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Total events</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e(number_format($stats['total'])) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-primary/20 text-primary-strong flex items-center justify-center">
            <span class="material-icons-outlined text-base">receipt_long</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Today</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e(number_format($stats['today'])) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">today</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Last 7 days</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e(number_format($stats['week'])) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">date_range</span>
          </div>
        </div>
        <a class="bg-white rounded-2xl p-4 shadow-sm border flex items-center justify-between hover:border-amber-200 transition-colors <?= $filters['source'] === 'settings' ? 'border-amber-300 ring-2 ring-amber-100' : 'border-gray-100' ?>"
           href="/admin/audit/?<?= e(audit_build_query(['source' => 'settings', 'page' => 1])) ?>">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Settings changes</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e(number_format($stats['by_source']['settings'] ?? 0)) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">tune</span>
          </div>
        </a>
        <a class="bg-white rounded-2xl p-4 shadow-sm border flex items-center justify-between hover:border-emerald-200 transition-colors <?= $filters['source'] === 'activity' ? 'border-emerald-300 ring-2 ring-emerald-100' : 'border-gray-100' ?>"
           href="/admin/audit/?<?= e(audit_build_query(['source' => 'activity', 'page' => 1])) ?>">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Security &amp; activity</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e(number_format($stats['by_source']['activity'] ?? 0)) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">shield</span>
          </div>
        </a>
      </section>

      <!-- Filters + table -->
      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 class="font-display text-2xl font-bold text-gray-900">Audit Hub</h1>
            <p class="text-sm text-gray-500">Every settings change, admin action, and security event in one timeline.</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <?php if ($hasAnyFilter): ?>
              <a class="inline-flex items-center gap-2 rounded-full bg-red-500 px-4 py-2 text-xs font-semibold text-white hover:bg-red-600" href="/admin/audit/">
                <span class="material-icons-outlined text-sm">filter_alt_off</span>
                Clear filters
              </a>
            <?php endif; ?>
          </div>
        </div>

        <form method="get" class="space-y-4 p-5" id="audit-filters">
          <div class="grid gap-4 lg:grid-cols-6">
            <label class="flex flex-col text-sm font-medium text-gray-700 lg:col-span-2">
              Search
              <input type="search" name="q" value="<?= e($filters['search']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-primary focus:ring-2 focus:ring-primary/40" placeholder="Actor, action, payload…">
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Source
              <select name="source" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <option value="">All sources</option>
                <?php foreach (AuditHubService::SOURCES as $key => $meta): ?>
                  <option value="<?= e($key) ?>" <?= $filters['source'] === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Action
              <select name="action" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <option value="">All actions</option>
                <?php foreach ($actions as $actionOption): ?>
                  <option value="<?= e($actionOption) ?>" <?= $filters['action'] === $actionOption ? 'selected' : '' ?>><?= e($actionOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              From
              <input type="date" name="start" value="<?= e($filters['start']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              To
              <input type="date" name="end" value="<?= e($filters['end']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </label>
          </div>
          <div class="flex flex-wrap items-center gap-3 pt-1">
            <label class="flex flex-col text-sm font-medium text-gray-700 max-w-xs flex-1">
              Actor
              <input type="text" name="actor" value="<?= e($filters['actor']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="Name or email contains…">
            </label>
            <button type="submit" class="ml-auto inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">
              <span class="material-icons-outlined text-sm">search</span>
              Filter
            </button>
          </div>
        </form>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-400 bg-gray-50 border-b border-gray-100">
              <tr>
                <th class="py-2.5 px-4">When</th>
                <th class="py-2.5 px-3">Source</th>
                <th class="py-2.5 px-3">Actor</th>
                <th class="py-2.5 px-3">Action</th>
                <th class="py-2.5 px-3">Target</th>
                <th class="py-2.5 px-3">Details</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="6" class="py-12 text-center text-sm text-gray-500">
                    No audit events match the current filters.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $row):
                  $source = (string) ($row['source'] ?? '');
                  $sourceLabel = AuditHubService::sourceLabel($source);
                  $badgeClasses = AuditHubService::sourceBadgeClasses($source);
                  $pairs = AuditHubService::friendlyMetadata($row);
                  $raw = AuditHubService::rawPayload($row);
                  $createdAt = (string) ($row['created_at'] ?? '');
                  $relative = audit_relative_time($createdAt);
                  $rowKey = $source . '-' . (int) ($row['source_id'] ?? 0);
                ?>
                  <tr class="hover:bg-gray-50/70 transition-colors align-top">
                    <td class="px-4 py-3 whitespace-nowrap">
                      <p class="text-sm font-medium text-gray-900"><?= e($relative) ?></p>
                      <p class="text-[11px] text-gray-400"><?= e($createdAt) ?></p>
                    </td>
                    <td class="px-3 py-3">
                      <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold <?= e($badgeClasses) ?>">
                        <?= e($sourceLabel) ?>
                      </span>
                    </td>
                    <td class="px-3 py-3">
                      <p class="text-sm font-medium text-gray-900"><?= e($row['actor_label']) ?></p>
                      <?php if (!empty($row['actor_email']) && $row['actor_email'] !== $row['actor_label']): ?>
                        <p class="text-[11px] text-gray-400"><?= e((string) $row['actor_email']) ?></p>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-3">
                      <p class="text-sm text-gray-800"><?= e($row['action_label']) ?></p>
                      <?php if ($row['action_label'] !== ($row['action'] ?? '')): ?>
                        <p class="text-[11px] text-gray-400 font-mono"><?= e((string) ($row['action'] ?? '')) ?></p>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-sm text-gray-700">
                      <?= e($row['target_label']) ?>
                    </td>
                    <td class="px-3 py-3">
                      <?php if ($pairs): ?>
                        <ul class="space-y-1 text-sm text-gray-700">
                          <?php foreach (array_slice($pairs, 0, 4) as $pair): ?>
                            <li>
                              <span class="text-[11px] uppercase tracking-wide text-gray-400"><?= e($pair['label']) ?></span>
                              <span class="text-gray-800"><?= e($pair['value']) ?></span>
                            </li>
                          <?php endforeach; ?>
                          <?php if (count($pairs) > 4): ?>
                            <li class="text-[11px] text-gray-400">+<?= e((string) (count($pairs) - 4)) ?> more</li>
                          <?php endif; ?>
                        </ul>
                      <?php else: ?>
                        <span class="text-sm text-gray-400">—</span>
                      <?php endif; ?>
                      <?php if ($raw): ?>
                        <details class="mt-2">
                          <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-primary hover:underline">Show raw</summary>
                          <pre class="mt-2 max-w-md whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-[11px] text-gray-600 border border-gray-100"><?= e($raw) ?></pre>
                        </details>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </section>

      <section class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 text-sm">
        <div class="flex flex-wrap items-center gap-3 text-gray-600">
          <span>Showing <?= e((string) ($total ? ($offset + 1) : 0)) ?> to <?= e((string) ($total ? min($page * $limit, $total) : 0)) ?> of <?= e(number_format($total)) ?> events</span>
          <label class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-gray-400">
            Page size
            <select name="limit" class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" form="audit-filters" onchange="this.form.submit()">
              <?php foreach ($allowedLimits as $size): ?>
                <option value="<?= e((string) $size) ?>" <?= $limit === $size ? 'selected' : '' ?>><?= e((string) $size) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="flex items-center gap-2">
          <?php if ($page > 1): ?>
            <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" href="/admin/audit/?<?= e(audit_build_query(['page' => $page - 1])) ?>">&larr; Previous</a>
          <?php endif; ?>
          <?php if ($total > $page * $limit): ?>
            <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" href="/admin/audit/?<?= e(audit_build_query(['page' => $page + 1])) ?>">Next &rarr;</a>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
