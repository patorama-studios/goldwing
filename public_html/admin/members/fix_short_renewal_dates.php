<?php
/**
 * One-shot corrective tool for membership renewal dates that were stored too
 * short before the duration fix landed. Two historical bugs left members with a
 * renewal date that didn't match the term they paid for:
 *
 *   1. Lapsed/expired renewals anchored the term to the *current* (nearly over)
 *      membership year, so a 3-year renewal advanced the date only ~2 years.
 *   2. New-member joins stored the raw pricing key (e.g. JOIN_P_3Y) as the
 *      term, which the old normaliser didn't understand, collapsing a 3-year
 *      join into a 1-year period.
 *
 * This tool replays the *fixed* expiry rules against each member's current
 * (latest) membership period and, where the stored end date is SHORT, corrects
 * it. It is strictly extend-only — it never shortens anyone's renewal date.
 * Where the corrected date is in the future, the member is (re)activated through
 * the same status funnel the admin UI uses, so members.status and the period
 * status stay in sync. The period's term is also re-saved in canonical form.
 *
 * Dry-run by default. POST with csrf_token + apply=1 to commit.
 *
 * DELETE THIS FILE once the correction is done.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MembershipService;
use App\Services\MembershipStatusService;

require_permission('admin.members.import_export');

$user = current_user();
$actorId = $user['id'] ?? null;
$pdo = Database::connection();

$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && Csrf::verify($_POST['csrf_token'] ?? '')
    && ($_POST['apply'] ?? '') === '1';

$today = new DateTimeImmutable(date('Y-m-d'));

/**
 * Replay the fixed activation rules to work out what this period's end date
 * SHOULD be, given its term, start date and the member's history.
 */
$expectedEnd = function (array $period, ?string $prevEnd, bool $hasPrior) use ($pdo): ?array {
    $term = (string) ($period['term'] ?? '');
    $months = MembershipService::termToMonths($term);
    if ($months === null) {
        return null; // LIFE — no expiry
    }
    $start = (string) ($period['start_date'] ?? '');
    if ($start === '') {
        return null;
    }
    $startD = new DateTimeImmutable($start);
    $prevEndD = $prevEnd ? new DateTimeImmutable((string) $prevEnd) : null;
    $isActiveStack = $prevEndD && $prevEndD->modify('+1 day')->format('Y-m-d') === $start;

    if ($isActiveStack) {
        // Renewal stacked on still-active coverage: term whole months on top.
        $end = $prevEndD->modify("+{$months} months");
    } elseif ($hasPrior) {
        // Lapsed / expired renewal: full term from the membership-year end that
        // contained the payment date. (This is the bug-1 path.)
        $cye = new DateTimeImmutable(MembershipService::calculateExpiry($start, 1));
        $end = $cye->modify("+{$months} months");
    } else {
        // Brand-new pro-rata join: rest of current year + whole years beyond
        // the first. (Correct joins already match; bug-2 joins are short here.)
        $cye = new DateTimeImmutable(MembershipService::calculateExpiry($start, 1));
        $extra = max(0, $months - 12);
        $end = $cye->modify("+{$extra} months");
    }
    return ['months' => $months, 'end' => $end->format('Y-m-d')];
};

$rows = [];
$report = ['scanned' => 0, 'short' => 0, 'fixed' => 0, 'reactivated' => 0, 'errors' => []];

try {
    if ($apply) {
        $pdo->beginTransaction();
    }

    $memberIds = $pdo->query('SELECT id FROM members ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    $latestStmt = $pdo->prepare(
        'SELECT id, member_id, term, start_date, end_date, status, paid_at
           FROM membership_periods
          WHERE member_id = :mid AND status <> "PENDING_PAYMENT"
       ORDER BY start_date DESC, id DESC LIMIT 1'
    );
    $prevStmt = $pdo->prepare(
        'SELECT end_date FROM membership_periods
          WHERE member_id = :mid AND id <> :pid AND end_date IS NOT NULL
            AND (start_date < :start OR (start_date = :start AND id < :pid))
       ORDER BY end_date DESC LIMIT 1'
    );
    $priorCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM membership_periods
          WHERE member_id = :mid AND id <> :pid AND status <> "PENDING_PAYMENT"'
    );
    $termStmt = $pdo->prepare('UPDATE membership_periods SET term = :term WHERE id = :id');

    foreach ($memberIds as $mid) {
        $mid = (int) $mid;
        $latestStmt->execute(['mid' => $mid]);
        $period = $latestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$period || $period['end_date'] === null) {
            continue;
        }
        $report['scanned']++;

        $prevStmt->execute(['mid' => $mid, 'pid' => (int) $period['id'], 'start' => $period['start_date']]);
        $prevEnd = $prevStmt->fetchColumn() ?: null;

        $priorCountStmt->execute(['mid' => $mid, 'pid' => (int) $period['id']]);
        $hasPrior = ((int) $priorCountStmt->fetchColumn()) > 0;

        $expected = $expectedEnd($period, $prevEnd, $hasPrior);
        if ($expected === null) {
            continue;
        }

        $storedEnd = (string) $period['end_date'];
        // Extend-only: act only when the correct date is LATER than stored.
        if (strtotime($expected['end']) <= strtotime($storedEnd)) {
            continue;
        }
        $report['short']++;

        $canonicalTerm = MembershipService::canonicalTerm((string) $period['term']);
        $willReactivate = strtotime($expected['end']) >= strtotime($today->format('Y-m-d'))
            && strtoupper((string) $period['status']) !== 'ACTIVE';

        // Member display details for the preview.
        $info = $pdo->prepare('SELECT first_name, last_name, email, status FROM members WHERE id = :id');
        $info->execute(['id' => $mid]);
        $m = $info->fetch(PDO::FETCH_ASSOC) ?: [];

        $rows[] = [
            'member_id'   => $mid,
            'name'        => trim((string) ($m['first_name'] ?? '') . ' ' . (string) ($m['last_name'] ?? '')),
            'email'       => (string) ($m['email'] ?? ''),
            'period_id'   => (int) $period['id'],
            'term_raw'    => (string) $period['term'],
            'term_canon'  => $canonicalTerm,
            'months'      => $expected['months'],
            'start'       => (string) $period['start_date'],
            'end_old'     => $storedEnd,
            'end_new'     => $expected['end'],
            'reactivate'  => $willReactivate,
        ];

        if ($apply) {
            try {
                $update = ['end_date' => $expected['end']];
                if (strtotime($expected['end']) >= strtotime($today->format('Y-m-d'))) {
                    // Future coverage => member is active. Funnel keeps
                    // members.status and the period status in sync.
                    $update['status'] = 'active';
                }
                MembershipStatusService::applyAdminUpdate($mid, $update);
                if ($canonicalTerm !== (string) $period['term']) {
                    $termStmt->execute(['term' => $canonicalTerm, 'id' => (int) $period['id']]);
                }
                ActivityLogger::log('admin', $actorId, $mid, 'membership.renewal_date_corrected', [
                    'period_id' => (int) $period['id'],
                    'term'      => $canonicalTerm,
                    'end_from'  => $storedEnd,
                    'end_to'    => $expected['end'],
                ]);
                $report['fixed']++;
                if ($willReactivate) {
                    $report['reactivated']++;
                }
            } catch (Throwable $e) {
                $report['errors'][] = "Member #$mid: " . $e->getMessage();
            }
        }
    }

    if ($apply) {
        $pdo->commit();
        ActivityLogger::log('admin', $actorId, null, 'members.renewal_dates_corrected', [
            'fixed'       => $report['fixed'],
            'reactivated' => $report['reactivated'],
        ]);
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $report['errors'][] = 'Aborted: ' . $e->getMessage();
}

$csrf = Csrf::token();
$mode = $apply ? 'APPLIED' : 'DRY-RUN';
$modeClass = $apply ? 'applied' : 'dry';
function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fix short renewal dates — <?= $mode ?></title>
<style>
  body { font-family: system-ui, sans-serif; padding: 24px; max-width: 1000px; margin: 0 auto; color: #111; }
  h1 { margin: 0 0 4px; }
  .tag { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: .04em; }
  .dry { background: #fef3c7; color: #92400e; }
  .applied { background: #dcfce7; color: #166534; }
  table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 13px; }
  td, th { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .old { color: #991b1b; text-decoration: line-through; }
  .new { color: #166534; font-weight: 700; }
  .pill { font-size: 11px; background: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 999px; }
  .err { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 4px; margin: 8px 0; font-family: ui-monospace, monospace; font-size: 13px; }
  form { margin: 16px 0; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
  button { background: #111; color: #fff; padding: 10px 18px; border: 0; border-radius: 6px; font-weight: 700; cursor: pointer; }
  .note { font-size: 12px; color: #666; margin-top: 24px; }
  code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }
  .summary td.num { font-weight: 700; }
</style>
</head>
<body>
  <h1>Fix short renewal dates</h1>
  <p><span class="tag <?= $modeClass ?>"><?= $mode ?></span></p>

  <table class="summary" style="max-width:520px">
    <tr><th>Members with a current membership period scanned</th><td class="num"><?= number_format($report['scanned']) ?></td></tr>
    <tr><th>Found with a renewal date that's too short</th><td class="num"><?= number_format($report['short']) ?></td></tr>
    <?php if ($apply): ?>
    <tr><th>Renewal dates corrected</th><td class="num"><?= number_format($report['fixed']) ?></td></tr>
    <tr><th>Members reactivated (corrected date is in the future)</th><td class="num"><?= number_format($report['reactivated']) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php foreach ($report['errors'] as $err): ?>
    <div class="err"><?= h($err) ?></div>
  <?php endforeach; ?>

  <?php if ($rows): ?>
    <table>
      <tr>
        <th>Member</th><th>Term</th><th>Start</th>
        <th>Renewal date</th><th></th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>#<?= (int) $r['member_id'] ?> <?= h($r['name']) ?><br><span style="color:#666"><?= h($r['email']) ?></span></td>
          <td><?= h($r['term_canon']) ?><?php if ($r['term_raw'] !== $r['term_canon']): ?> <span style="color:#999">(was <?= h($r['term_raw']) ?>)</span><?php endif; ?></td>
          <td><?= h($r['start']) ?></td>
          <td><span class="old"><?= h($r['end_old']) ?></span> &rarr; <span class="new"><?= h($r['end_new']) ?></span></td>
          <td><?php if ($r['reactivate']): ?><span class="pill">reactivate</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p>No members with a too-short renewal date were found. Nothing to do. 🎉</p>
  <?php endif; ?>

  <?php if (!$apply && $rows): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="apply" value="1">
      <p>This is a dry-run — no changes have been made. Review the list above, then apply.</p>
      <button type="submit">Apply <?= number_format($report['short']) ?> correction<?= $report['short'] === 1 ? '' : 's' ?></button>
    </form>
  <?php elseif ($apply): ?>
    <p>Changes applied. <a href="?">Re-run dry-run</a> to confirm everything is clean.</p>
  <?php endif; ?>

  <p class="note">Extend-only — this never shortens a renewal date. DELETE this file from <code>/public_html/admin/members/</code> once the correction is finished.</p>
</body>
</html>
