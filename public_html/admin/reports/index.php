<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\EngagementReportService;

// ponytail: reuses the Audit Hub permission (the old ?page=reports mapping)
// instead of seeding a new admin.reports.view key into role_permissions.
// Upgrade path: add the key to admin_permission_registry() plus a migration
// that copies every admin.logs.view grant across.
require_permission('admin.logs.view');

$user = current_user();
$rangeKey = (string) ($_GET['range'] ?? '30d');
if (!isset(EngagementReportService::RANGES[$rangeKey])) {
    $rangeKey = '30d';
}

EngagementReportService::prune();
$report = EngagementReportService::build($rangeKey);

// CSV export of the two actionable lists.
$export = (string) ($_GET['export'] ?? '');
if ($export === 'dormant' || $export === 'newcomers') {
    $rows = $export === 'dormant' ? $report['dormant']['chase'] : $report['newcomers']['never'];
    ActivityLogger::log('admin', (int) ($user['id'] ?? 0), null, 'report.export', ['report' => $export, 'rows' => count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="engagement-' . $export . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($export === 'dormant') {
        fputcsv($out, ['Member', 'Email', 'Chapter', 'Last login', 'Renewal due'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, [$r['who'], $r['email'], $r['chapter'] ?? '', $r['last_login'] ?: 'Never', $r['renewal_due']], ',', '"', '\\');
        }
    } else {
        fputcsv($out, ['Member', 'Email', 'Joined', 'Has login account'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, [$r['who'], $r['email'], $r['joined'], $r['has_account'] ? 'Yes' : 'No'], ',', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

$h = $report['headline'];
$launch = $report['since_launch'];
$dash = '—';
$num = fn($v): string => $v === null ? $dash : number_format((float) $v);
$money = fn($v): string => $v === null ? $dash : '$' . number_format((float) $v, 2);
$pct = fn(int $part, int $whole): string => $whole > 0 ? (int) round(100 * $part / $whole) . '%' : $dash;
$memberUrl = fn($id): string => '/admin/members/view.php?id=' . (int) $id;
$ago = function (?string $datetime) use ($dash): string {
    if (!$datetime) {
        return 'Never';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return $dash;
    }
    $days = (int) floor((time() - $ts) / 86400);
    if ($days <= 0) {
        return 'Today';
    }
    if ($days < 30) {
        return $days . 'd ago';
    }
    if ($days < 365) {
        return (int) floor($days / 30) . ' mo ago';
    }
    return date('j M Y', $ts);
};
$delta = null;
if ($h['members_logged_in_prev'] !== null) {
    $delta = (int) $h['members_logged_in'] - (int) $h['members_logged_in_prev'];
}
$medals = ['🥇', '🥈', '🥉'];
$maxViews = $report['areas'] ? max(array_column($report['areas'], 'views')) : 0;
$maxHour = max(1, max($report['hours']['counts']));
$peakHour = array_search(max($report['hours']['counts']), $report['hours']['counts'], true);
$hourLabel = fn(int $hr): string => date('ga', mktime($hr, 0, 0));

$pageTitle = 'Reports';
$activePage = 'reports';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Reports'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm px-5 py-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <nav aria-label="Breadcrumb" class="flex text-xs text-gray-500 mb-1">
            <ol class="flex items-center gap-2">
              <li>Admin</li>
              <li class="material-icons-outlined text-sm text-gray-400">chevron_right</li>
              <li class="font-semibold text-gray-900">Reports</li>
            </ol>
          </nav>
          <h1 class="font-display text-2xl font-bold text-gray-900">Member engagement</h1>
          <p class="text-sm text-gray-500">Who is using the site, what they use, and how it has gone since launch. Admin accounts are left out of member figures and leaderboards.</p>
        </div>
        <form method="get" class="flex items-center gap-2">
          <label for="range" class="text-sm font-medium text-gray-700">Range</label>
          <select id="range" name="range" onchange="this.form.submit()" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm">
            <?php foreach (EngagementReportService::RANGES as $key => $meta): ?>
              <option value="<?= e($key) ?>" <?= $key === $rangeKey ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </section>

      <?php if ($report['errors']): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
          <p class="font-semibold">Some sections could not be calculated</p>
          <ul class="mt-1 text-sm list-disc pl-5">
            <?php foreach ($report['errors'] as $section => $message): ?>
              <li><span class="font-medium"><?= e($section) ?></span>: <code class="text-xs"><?= e($message) ?></code></li>
            <?php endforeach; ?>
          </ul>
          <p class="text-xs mt-2 opacity-80">Those panels show blanks so the rest of the report still renders. The errors are in the PHP log.</p>
        </div>
      <?php endif; ?>

      <?php if (!$report['has_page_views']): ?>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-blue-900 text-sm">
          <p class="font-semibold">Page-view tracking is not switched on yet</p>
          <p class="mt-1">Run <a class="underline" href="/admin/run-migration.php">Migration 051</a> once. From then on the "Where members go" and visit-length panels fill in. Everything else on this page is built from data the site has kept since launch.</p>
        </div>
      <?php elseif ($h['first_view_at']): ?>
        <p class="text-xs text-gray-500">Page-view tracking has been on since <?= e(date('j M Y', strtotime($h['first_view_at']))) ?>. Panels that rely on it only cover that period.</p>
      <?php endif; ?>

      <?php /* ── Headline tiles ─────────────────────────────────────────── */ ?>
      <section class="grid gap-4 grid-cols-2 lg:grid-cols-5">
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-[11px] uppercase tracking-widest text-gray-400 font-semibold">Members who logged in</p>
          <p class="text-3xl font-bold text-gray-900 mt-1"><?= e($num($h['members_logged_in'])) ?> <span class="text-sm font-medium text-gray-400">of <?= e($num($h['active_members'])) ?></span></p>
          <p class="text-xs text-gray-500 mt-1"><?= e($pct((int) $h['members_logged_in'], (int) $h['active_members'])) ?> of active members<?php if ($delta !== null): ?> · <span class="<?= $delta >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $delta >= 0 ? '+' : '' ?><?= (int) $delta ?> vs previous period</span><?php endif; ?></p>
        </div>
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-[11px] uppercase tracking-widest text-gray-400 font-semibold">Visits</p>
          <p class="text-3xl font-bold text-gray-900 mt-1"><?= e($report['has_page_views'] ? $num($h['visits']) : $dash) ?></p>
          <p class="text-xs text-gray-500 mt-1"><?= $h['pages_per_visit'] !== null ? e($h['pages_per_visit']) . ' pages per visit' : 'needs page-view tracking' ?></p>
        </div>
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-[11px] uppercase tracking-widest text-gray-400 font-semibold">Median visit</p>
          <p class="text-3xl font-bold text-gray-900 mt-1"><?= $h['median_visit_minutes'] !== null ? e($h['median_visit_minutes']) . ' <span class="text-sm font-medium text-gray-400">min</span>' : e($dash) ?></p>
          <p class="text-xs text-gray-500 mt-1"><?= $peakHour !== false && max($report['hours']['counts']) > 0 ? 'busiest around ' . e($hourLabel((int) $peakHour)) : 'multi-page visits only' ?></p>
        </div>
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-[11px] uppercase tracking-widest text-gray-400 font-semibold">Wings reads</p>
          <p class="text-3xl font-bold text-gray-900 mt-1"><?= e($num($h['wings_reads'])) ?></p>
          <p class="text-xs text-gray-500 mt-1">flipbook opens and downloads</p>
        </div>
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-[11px] uppercase tracking-widest text-gray-400 font-semibold">Online right now</p>
          <p class="text-3xl font-bold text-gray-900 mt-1"><?= e($num($h['online_now'])) ?></p>
          <p class="text-xs text-gray-500 mt-1"><?= $h['mobile_share'] !== null ? e($h['mobile_share']) . '% of logins from a phone' : 'active in the last 5 minutes' ?></p>
        </div>
      </section>

      <?php /* ── Charts ────────────────────────────────────────────────── */ ?>
      <section class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-lg font-semibold text-gray-900">Logins per <?= $report['logins_series']['daily'] ? 'day' : 'week' ?></h2>
          <p class="text-sm text-slate-500">All member logins, and how many different members they came from.</p>
          <div class="relative h-64 mt-4"><canvas id="logins-chart"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-lg font-semibold text-gray-900">Time of day</h2>
          <p class="text-sm text-slate-500">When members are on the site, from <?= e($report['hours']['source']) ?>.</p>
          <div class="relative h-64 mt-4"><canvas id="hours-chart"></canvas></div>
        </div>
      </section>

      <?php /* ── Areas + leaderboards ──────────────────────────────────── */ ?>
      <section class="grid gap-6 lg:grid-cols-2">
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-lg font-semibold text-gray-900">Where members go</h2>
          <p class="text-sm text-slate-500">Page views by portal area, and how many different members opened each.</p>
          <?php if (!$report['has_page_views']): ?>
            <p class="mt-6 text-sm text-gray-500">Waiting for Migration 051.</p>
          <?php elseif (!$report['areas']): ?>
            <p class="mt-6 text-sm text-gray-500">No page views recorded for this range yet.</p>
          <?php else: ?>
            <div class="mt-4 space-y-2">
              <?php foreach ($report['areas'] as $area): ?>
                <div class="grid grid-cols-[9rem_minmax(0,1fr)_7rem] items-center gap-3 text-sm">
                  <span class="truncate text-gray-700"><?= e($area['label']) ?></span>
                  <div class="h-2.5 rounded-full bg-gray-100"><div class="h-2.5 rounded-full bg-secondary" style="width: <?= $maxViews > 0 ? (int) round(100 * $area['views'] / $maxViews) : 0 ?>%"></div></div>
                  <span class="text-right text-gray-500 tabular-nums"><?= e($num($area['views'])) ?> <span class="text-xs">· <?= e($num($area['members'])) ?> members</span></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-lg font-semibold text-gray-900">Leaderboards</h2>
          <p class="text-sm text-slate-500">Top five in each, for the selected range. Garage and streaks are all-time.</p>
          <div class="mt-4 grid gap-5 sm:grid-cols-2">
            <?php foreach ($report['leaderboards'] as $board): ?>
              <div>
                <p class="text-xs uppercase tracking-widest text-gray-400 font-semibold"><?= e($board['title']) ?> <span class="normal-case tracking-normal font-normal">· <?= e($board['unit']) ?></span></p>
                <?php if (!$board['rows']): ?>
                  <p class="mt-2 text-sm text-gray-400">Nothing yet.</p>
                <?php else: ?>
                  <ol class="mt-2 space-y-1">
                    <?php foreach ($board['rows'] as $i => $row): ?>
                      <li class="flex items-center justify-between text-sm">
                        <span class="truncate"><span class="inline-block w-6"><?= $medals[$i] ?? '<span class="text-gray-400">' . ($i + 1) . '.</span>' ?></span><a class="text-gray-800 hover:underline" href="<?= e($memberUrl($row['member_id'])) ?>"><?= e((string) $row['who']) ?></a></span>
                        <span class="text-gray-500 tabular-nums ml-2"><?= e($num($row['n'])) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <?php /* ── Newcomers + since launch ──────────────────────────────── */ ?>
      <section class="grid gap-6 lg:grid-cols-2">
        <?php $nc = $report['newcomers']; ?>
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Newcomers</h2>
              <p class="text-sm text-slate-500">Active members who joined in the last <?= (int) $nc['days'] ?> days.</p>
            </div>
            <?php if ($nc['never']): ?>
              <a class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50" href="/admin/reports/?range=<?= e($rangeKey) ?>&amp;export=newcomers"><span class="material-icons-outlined text-sm">download</span>CSV</a>
            <?php endif; ?>
          </div>
          <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl bg-gray-50 p-3"><dt class="text-xs text-gray-500">Joined</dt><dd class="text-2xl font-bold text-gray-900"><?= e($num($nc['joined'])) ?></dd></div>
            <div class="rounded-xl bg-emerald-50 p-3"><dt class="text-xs text-emerald-700">Logged in</dt><dd class="text-2xl font-bold text-emerald-700"><?= e($num($nc['logged_in'])) ?></dd></div>
            <div class="rounded-xl <?= $nc['never'] ? 'bg-rose-50' : 'bg-gray-50' ?> p-3"><dt class="text-xs <?= $nc['never'] ? 'text-rose-700' : 'text-gray-500' ?>">Never logged in</dt><dd class="text-2xl font-bold <?= $nc['never'] ? 'text-rose-700' : 'text-gray-900' ?>"><?= e($num(count($nc['never']))) ?></dd></div>
          </dl>
          <p class="mt-3 text-sm text-gray-600">
            <?php if ($nc['median_days_to_first_login'] !== null): ?>Typical wait from approval to first login: <strong><?= e($nc['median_days_to_first_login']) ?> days</strong>.<?php else: ?>No first logins in this group yet.<?php endif; ?>
            <?php if ($nc['first_stops']): ?> First stop after the dashboard: <?= e(implode(', ', array_map(fn($s) => $s['label'] . ' (' . $s['count'] . ')', array_slice($nc['first_stops'], 0, 3)))) ?>.<?php endif; ?>
          </p>
          <?php if ($nc['never']): ?>
            <p class="mt-4 text-xs uppercase tracking-widest text-gray-400 font-semibold">Worth a nudge</p>
            <ul class="mt-2 divide-y divide-gray-100">
              <?php foreach (array_slice($nc['never'], 0, 10) as $row): ?>
                <li class="flex items-center justify-between py-2 text-sm">
                  <span><a class="text-gray-800 hover:underline" href="<?= e($memberUrl($row['member_id'])) ?>"><?= e((string) $row['who']) ?></a> <span class="text-xs text-gray-400">joined <?= e($ago($row['joined'])) ?><?= $row['has_account'] ? '' : ' · no login account yet' ?></span></span>
                  <?php if ($row['email']): ?><a class="text-xs font-semibold text-secondary hover:underline" href="mailto:<?= e($row['email']) ?>">Email</a><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if (count($nc['never']) > 10): ?><p class="mt-2 text-xs text-gray-400">Showing 10 of <?= count($nc['never']) ?> · download the CSV for the full list.</p><?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-lg font-semibold text-gray-900">Since launch</h2>
          <p class="text-sm text-slate-500"><?= $launch['launched'] ? 'First member login ' . e(date('j M Y', strtotime($launch['launched']))) . '.' : 'No member logins recorded yet.' ?> Totals do not change with the range above.</p>
          <dl class="mt-4 divide-y divide-gray-100 text-sm">
            <?php
            $adoption = $pct((int) $launch['active_ever_logged_in'], (int) $launch['active_members']);
            $rowsOut = [
              ['Active members who have ever logged in', $num($launch['active_ever_logged_in']) . ' of ' . $num($launch['active_members']) . ' · ' . $adoption],
              ['Member logins', $num($launch['total_logins'])],
              ['Memberships paid online', $launch['renewals_online'] === null ? $dash : $num($launch['renewals_online']) . ' · ' . $money($launch['renewals_online_total'])],
              ['Store orders paid', $launch['store_orders'] === null ? $dash : $num($launch['store_orders']) . ' · ' . $money($launch['store_total'])],
              ['Ride RSVPs', $num($launch['rsvps'])],
              ['Wings reads and downloads', $num($launch['wings_reads'])],
              ['Profile details members updated themselves', $num($launch['profile_self_updates'])],
              ['Applications submitted online', $num($launch['applications_online'])],
              ['Help tours completed', $num($launch['tours_completed'])],
            ];
            ?>
            <?php foreach ($rowsOut as [$label, $value]): ?>
              <div class="flex items-center justify-between py-2"><dt class="text-gray-600"><?= e($label) ?></dt><dd class="font-semibold text-gray-900 tabular-nums text-right"><?= e($value) ?></dd></div>
            <?php endforeach; ?>
          </dl>
        </div>
      </section>

      <?php /* ── Chapters + dormant ────────────────────────────────────── */ ?>
      <section class="grid gap-6 lg:grid-cols-2">
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-lg font-semibold text-gray-900">Chapter league</h2>
          <p class="text-sm text-slate-500">Share of each chapter's active members who logged in during the range.</p>
          <?php if (!$report['chapters']): ?>
            <p class="mt-6 text-sm text-gray-500">No chapters with active members.</p>
          <?php else: ?>
            <div class="mt-4 space-y-2">
              <?php foreach ($report['chapters'] as $ch): ?>
                <div class="grid grid-cols-[9rem_minmax(0,1fr)_6rem] items-center gap-3 text-sm">
                  <span class="truncate text-gray-700"><?= e((string) $ch['name']) ?></span>
                  <div class="h-2.5 rounded-full bg-gray-100"><div class="h-2.5 rounded-full bg-primary-strong" style="width: <?= (int) $ch['pct'] ?>%"></div></div>
                  <span class="text-right text-gray-500 tabular-nums"><?= (int) $ch['pct'] ?>% <span class="text-xs">· <?= (int) $ch['active'] ?>/<?= (int) $ch['members'] ?></span></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php $dormant = $report['dormant']; ?>
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Dormant members to chase</h2>
              <p class="text-sm text-slate-500"><?= e($num($dormant['total'])) ?> active members have not logged in for <?= (int) $dormant['days'] ?>+ days. These have a renewal due within 120 days.</p>
            </div>
            <?php if ($dormant['chase']): ?>
              <a class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50" href="/admin/reports/?range=<?= e($rangeKey) ?>&amp;export=dormant"><span class="material-icons-outlined text-sm">download</span>CSV</a>
            <?php endif; ?>
          </div>
          <?php if (!$dormant['chase']): ?>
            <p class="mt-6 text-sm text-gray-500">Nobody dormant has a renewal coming up. Nice.</p>
          <?php else: ?>
            <table class="mt-4 w-full text-sm">
              <thead class="text-xs uppercase tracking-widest text-gray-400"><tr><th class="text-left font-semibold pb-2">Member</th><th class="text-left font-semibold pb-2">Chapter</th><th class="text-left font-semibold pb-2">Last login</th><th class="text-left font-semibold pb-2">Renewal due</th></tr></thead>
              <tbody class="divide-y divide-gray-100">
                <?php foreach (array_slice($dormant['chase'], 0, 15) as $row): ?>
                  <tr>
                    <td class="py-2"><a class="text-gray-800 hover:underline" href="<?= e($memberUrl($row['member_id'])) ?>"><?= e((string) $row['who']) ?></a></td>
                    <td class="py-2 text-gray-500"><?= e((string) ($row['chapter'] ?? $dash)) ?></td>
                    <td class="py-2 text-gray-500"><?= e($ago($row['last_login'])) ?></td>
                    <td class="py-2 text-gray-500"><?= e($row['renewal_due'] ? date('j M Y', strtotime($row['renewal_due'])) : $dash) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (count($dormant['chase']) > 15): ?><p class="mt-2 text-xs text-gray-400">Showing 15 of <?= count($dormant['chase']) ?> · download the CSV for the full list.</p><?php endif; ?>
          <?php endif; ?>
        </div>
      </section>

      <p class="text-xs text-gray-400">Page views record the path and portal section only, never the IP address or search terms, and are deleted after <?= EngagementReportService::RETENTION_MONTHS ?> months. A visit is a run of page views less than <?= EngagementReportService::VISIT_GAP_SECONDS / 60 ?> minutes apart; its length runs from the first page to the last, so one-page visits count as zero and are left out of the median.</p>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  (() => {
    if (typeof Chart === 'undefined') return;
    const series = <?= json_encode($report['logins_series']['rows'], JSON_UNESCAPED_SLASHES) ?>;
    const daily = <?= $report['logins_series']['daily'] ? 'true' : 'false' ?>;
    const hours = <?= json_encode($report['hours']['counts']) ?>;
    const fmt = (iso) => {
      const d = new Date(iso + 'T00:00:00');
      return d.toLocaleDateString('en-AU', { day: 'numeric', month: 'short' }) + (daily ? '' : ' wk');
    };
    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.color = '#6b7280';

    new Chart(document.getElementById('logins-chart'), {
      type: 'line',
      data: {
        labels: series.map(r => fmt(r.bucket)),
        datasets: [
          { label: 'Logins', data: series.map(r => r.logins), borderColor: '#2F7D32', backgroundColor: 'rgba(47,125,50,0.12)', fill: true, tension: 0.3, pointRadius: series.length > 60 ? 0 : 3 },
          { label: 'Different members', data: series.map(r => r.members), borderColor: '#CFA032', backgroundColor: 'transparent', tension: 0.3, pointRadius: series.length > 60 ? 0 : 3 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { maxTicksLimit: 12 } } }, plugins: { legend: { position: 'bottom' } } }
    });

    new Chart(document.getElementById('hours-chart'), {
      type: 'bar',
      data: {
        labels: hours.map((_, h) => (h % 12 || 12) + (h < 12 ? 'am' : 'pm')),
        datasets: [{ label: 'Activity', data: hours, backgroundColor: '#F2C94C', borderRadius: 4 }]
      },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { maxTicksLimit: 8 } } }, plugins: { legend: { display: false } } }
    });
  })();
</script>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
