<?php
/**
 * Pure-function checks for the Member Engagement report. No database: the
 * area mapper, visit stitching, median, streaks and series fill are all
 * static helpers, so this loads the two service files directly.
 *
 *   /Applications/MAMP/bin/php/php8.4.17/bin/php tests/test_engagement_report.php
 */
require_once __DIR__ . '/../app/Services/PageViewLogger.php';
require_once __DIR__ . '/../app/Services/EngagementReportService.php';

use App\Services\EngagementReportService as R;
use App\Services\PageViewLogger as P;

$failures = 0;
$check = function (string $name, $expected, $actual) use (&$failures): void {
    if ($expected === $actual) {
        echo "PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$name}\n      expected " . json_encode($expected) . "\n      got      " . json_encode($actual) . "\n";
};

// ── Area mapping ─────────────────────────────────────────────────────────
$check('member dashboard (no page)',   'dashboard',          P::areaFor('/member/index.php'));
$check('member dashboard (explicit)',  'dashboard',          P::areaFor('/member/index.php', 'dashboard'));
$check('wings page',                   'wings',              P::areaFor('/member/index.php', 'wings'));
$check('notices create → notices',     'notices',            P::areaFor('/member/index.php', 'notices-create'));
$check('unknown page → other',         'other',              P::areaFor('/member/index.php', 'bogus'));
$check('flipbook reader → wings',      'wings',              P::areaFor('/member/read_wings.php'));
$check('notifications',                'notifications',      P::areaFor('/member/notifications.php'));
$check('2fa page → other',             'other',              P::areaFor('/member/2fa_verify.php'));
$check('member of the year',           'member-of-the-year', P::areaFor('/members/member-of-the-year'));
$check('awards',                       'awards',             P::areaFor('/members/awards/'));
$check('store product',                'store',              P::areaFor('/store/product.php'));
$check('calendar event',               'calendar',           P::areaFor('/calendar/event_view.php'));
$check('calendar admin → admin',       'admin',              P::areaFor('/calendar/admin_events.php'));
$check('admin page',                   'admin',              P::areaFor('/admin/members/'));
$check('memberships success → checkout', 'checkout',         P::areaFor('/memberships/success'));
$check('home → public',                'public',             P::areaFor('/'));
$check('cms page → public',            'public',             P::areaFor('/about-us'));
$check('label lookup',                 'Members Directory',  P::label('directory'));
$check('label fallback',               'Whatever',           P::label('whatever'));

// ── Visit stitching ──────────────────────────────────────────────────────
$t = 1_700_000_000;
$rows = [
    ['user_id' => 1, 'ts' => $t],                 // visit 1: 3 pages over 10 min
    ['user_id' => 1, 'ts' => $t + 300],
    ['user_id' => 1, 'ts' => $t + 600],
    ['user_id' => 1, 'ts' => $t + 600 + 1801],    // gap > 30 min → visit 2 (single page)
    ['user_id' => 2, 'ts' => $t + 100],           // visit 3: 2 pages over 20 min
    ['user_id' => 2, 'ts' => $t + 1300],
];
$v = R::stitchVisits($rows);
$check('visits counted',            3,            $v['visits']);
$check('pages counted',             6,            $v['pages']);
$check('durations (multi-page only)', [600, 1200], $v['durations']);
$check('empty input',               ['visits' => 0, 'pages' => 0, 'durations' => []], R::stitchVisits([]));
$check('exact gap stays one visit', 1,            R::stitchVisits([['user_id' => 9, 'ts' => 0], ['user_id' => 9, 'ts' => 1800]])['visits']);

// ── Median ───────────────────────────────────────────────────────────────
$check('median odd',   3.0,  R::median([5, 1, 3]));
$check('median even',  2.5,  R::median([4, 1, 2, 3]));
$check('median empty', null, R::median([]));

// ── Streaks ──────────────────────────────────────────────────────────────
// ISO weeks 2026-W36..W39 with "now" = W39. Member 1: 4 straight weeks.
// Member 2: W39 + W37 (broken, streak 1 → dropped). Member 3: W36..W38 —
// ended last week, which still counts as current. Member 4: W38 + W39 → 2.
// Member 5: 2026-W01 + 2025-W52 (year boundary) but stale → dropped.
$weeks = [
    ['member_id' => 1, 'who' => 'A', 'wk' => 202639], ['member_id' => 1, 'who' => 'A', 'wk' => 202638],
    ['member_id' => 1, 'who' => 'A', 'wk' => 202637], ['member_id' => 1, 'who' => 'A', 'wk' => 202636],
    ['member_id' => 2, 'who' => 'B', 'wk' => 202639], ['member_id' => 2, 'who' => 'B', 'wk' => 202637],
    ['member_id' => 3, 'who' => 'C', 'wk' => 202638], ['member_id' => 3, 'who' => 'C', 'wk' => 202637], ['member_id' => 3, 'who' => 'C', 'wk' => 202636],
    ['member_id' => 4, 'who' => 'D', 'wk' => 202638], ['member_id' => 4, 'who' => 'D', 'wk' => 202639],
    ['member_id' => 5, 'who' => 'E', 'wk' => 202601], ['member_id' => 5, 'who' => 'E', 'wk' => 202552],
];
$s = R::streaksFromWeeks($weeks, 202639);
$check('streak order + lengths', [['member_id' => 1, 'who' => 'A', 'n' => 4], ['member_id' => 3, 'who' => 'C', 'n' => 3], ['member_id' => 4, 'who' => 'D', 'n' => 2]], $s);
// Last week counts as current, and a run across the ISO year boundary is unbroken.
$s2 = R::streaksFromWeeks([['member_id' => 5, 'who' => 'E', 'wk' => 202601], ['member_id' => 5, 'who' => 'E', 'wk' => 202552]], 202602);
$check('year-boundary streak', [['member_id' => 5, 'who' => 'E', 'n' => 2]], $s2);

// ── Series fill ──────────────────────────────────────────────────────────
$now = strtotime('2026-09-23 12:00:00');
$daily = R::fillSeries([['bucket' => '2026-09-21', 'logins' => 4, 'members' => 3]], true, '2026-09-20 12:00:00', $now);
$check('daily fill length', 4, count($daily));
$check('daily fill values', ['2026-09-20' => 0, '2026-09-21' => 4, '2026-09-22' => 0, '2026-09-23' => 0], array_column($daily, 'logins', 'bucket'));
$weekly = R::fillSeries([['bucket' => '2026-09-14', 'logins' => 7, 'members' => 5]], false, '2026-09-10 00:00:00', $now);
$check('weekly fill starts Monday', ['2026-09-07', '2026-09-14', '2026-09-21'], array_column($weekly, 'bucket'));
$check('weekly fill value', 7, $weekly[1]['logins']);
$check('launch range with no rows', [], R::fillSeries([], false, null, $now));

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
