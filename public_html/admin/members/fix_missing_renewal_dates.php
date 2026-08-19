<?php
/**
 * One-shot corrective tool for members who have NO renewal date at all.
 *
 * Cause: a membership order can exist with no `membership_period_id` — the
 * admin "Request payment" top-up creates one deliberately, and a signup order
 * carries none until approval. When such an order was paid by card, the Stripe
 * webhook called markMembershipPaid(), which found no period, did nothing, and
 * logged nothing. The order shows "paid" in admin while the member has no
 * membership period and therefore no renewal date.
 *
 * The activation path now mints the missing period itself (see
 * MembershipOrderService::createPeriodForOrphanOrder), so NEW payments are
 * fine — this tool cleans up members already stuck in that state.
 *
 * What it does: lists every non-life member with no renewal date, and for each
 * one that has a PAID membership order, replays that order through the exact
 * same activateMembershipForOrder() a live webhook would use. The renewal date
 * is calculated from the term on the order, so it lands on the same 31 July the
 * member would have got had the webhook worked. Members with no paid order to
 * replay are listed too, untouched, so they can be dated by hand on their
 * profile.
 *
 * Dry-run by default — the work runs inside a transaction that is rolled back
 * unless you apply, so the preview is the real result, not an estimate.
 * POST with csrf_token + apply=1 to commit.
 *
 * DELETE THIS FILE once the correction is done.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\MembershipOrderService;
use App\Services\MembershipService;

require_permission('admin.members.import_export');

$user = current_user();
$actorId = $user['id'] ?? null;
$pdo = Database::connection();

$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && Csrf::verify($_POST['csrf_token'] ?? '')
    && ($_POST['apply'] ?? '') === '1';

$rows = [];
$report = ['candidates' => 0, 'fixable' => 0, 'fixed' => 0, 'no_order' => 0, 'errors' => []];

try {
    $pdo->beginTransaction();

    // Non-life members who are past the application stage but have no renewal
    // date: either no membership period at all, or a latest period with a NULL
    // end date. Pending applicants are excluded — they have no date yet by
    // design, and their period is created at approval.
    $sql = 'SELECT m.id, m.first_name, m.last_name, m.email, m.status, m.member_type,
                   m.member_number_base, m.member_number_suffix,
                   p.id AS period_id, p.term AS period_term, p.status AS period_status
              FROM members m
         LEFT JOIN membership_periods p
                ON p.id = (SELECT p2.id FROM membership_periods p2
                            WHERE p2.member_id = m.id
                         ORDER BY (p2.status = "ACTIVE") DESC, p2.start_date DESC, p2.id DESC
                            LIMIT 1)
             WHERE ' . MemberRepository::notLifeSql($pdo) . '
               AND UPPER(COALESCE(m.status, "")) NOT IN ("PENDING", "INACTIVE")
               AND (p.id IS NULL OR p.end_date IS NULL)
          ORDER BY m.id';

    $candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $report['candidates'] = count($candidates);

    $paidOrderStmt = $pdo->prepare(
        'SELECT * FROM orders
          WHERE member_id = :mid AND order_type = "membership" AND status = "paid"
       ORDER BY id DESC LIMIT 1'
    );
    $endStmt = $pdo->prepare('SELECT end_date, term FROM membership_periods WHERE member_id = :mid ORDER BY id DESC LIMIT 1');

    foreach ($candidates as $c) {
        $mid = (int) $c['id'];
        $row = [
            'member_id' => $mid,
            'number'    => MembershipService::displayMembershipNumber(
                (int) $c['member_number_base'],
                (int) $c['member_number_suffix']
            ),
            'name'      => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'email'     => $c['email'] ?? '',
            'status'    => $c['status'] ?? '',
            'period'    => $c['period_id'] ? ('#' . $c['period_id'] . ' ' . $c['period_status']) : 'none',
            'order'     => '',
            'term'      => '',
            'end_new'   => null,
        ];

        $paidOrderStmt->execute(['mid' => $mid]);
        $order = $paidOrderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $report['no_order']++;
            $rows[] = $row;
            continue;
        }
        $row['order'] = (string) ($order['order_number'] ?? ('#' . $order['id']));

        try {
            $activated = MembershipOrderService::activateMembershipForOrder($order, [
                'payment_reference' => $order['stripe_payment_intent_id'] ?? null,
                'period_id' => $order['membership_period_id'] ?? null,
            ]);
            if (!$activated) {
                $report['errors'][] = "Member #$mid: activation declined for order {$row['order']}.";
                $rows[] = $row;
                continue;
            }
            $endStmt->execute(['mid' => $mid]);
            $after = $endStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $row['end_new'] = $after['end_date'] ?? null;
            $row['term'] = (string) ($after['term'] ?? '');
            if ($row['end_new'] === null) {
                $report['errors'][] = "Member #$mid: activated but still no renewal date — review manually.";
                $rows[] = $row;
                continue;
            }
            $report['fixable']++;
            if ($apply) {
                $report['fixed']++;
                ActivityLogger::log('admin', $actorId, $mid, 'membership.renewal_date_backfilled', [
                    'order_id'     => $order['id'] ?? null,
                    'order_number' => $order['order_number'] ?? null,
                    'term'         => $row['term'],
                    'end_to'       => $row['end_new'],
                ]);
            }
        } catch (Throwable $e) {
            $report['errors'][] = "Member #$mid: " . $e->getMessage();
        }
        $rows[] = $row;
    }

    if ($apply) {
        $pdo->commit();
        ActivityLogger::log('admin', $actorId, null, 'members.renewal_dates_backfilled', [
            'fixed' => $report['fixed'],
        ]);
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
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
<title>Fix missing renewal dates — <?= $mode ?></title>
<style>
  body { font-family: system-ui, sans-serif; padding: 24px; max-width: 1100px; margin: 0 auto; color: #111; }
  h1 { margin: 0 0 4px; }
  .tag { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: .04em; }
  .dry { background: #fef3c7; color: #92400e; }
  .applied { background: #dcfce7; color: #166534; }
  table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 13px; }
  td, th { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .new { color: #166534; font-weight: 700; }
  .none { color: #991b1b; }
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
  <h1>Fix missing renewal dates</h1>
  <p><span class="tag <?= $modeClass ?>"><?= $mode ?></span></p>

  <table class="summary" style="max-width:560px">
    <tr><th>Members with no renewal date</th><td class="num"><?= number_format($report['candidates']) ?></td></tr>
    <tr><th>Fixable from a paid membership order</th><td class="num"><?= number_format($report['fixable']) ?></td></tr>
    <tr><th>No paid order to replay — set by hand</th><td class="num"><?= number_format($report['no_order']) ?></td></tr>
    <?php if ($apply): ?>
    <tr><th>Renewal dates set</th><td class="num"><?= number_format($report['fixed']) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php foreach ($report['errors'] as $err): ?>
    <div class="err"><?= h($err) ?></div>
  <?php endforeach; ?>

  <?php if ($rows): ?>
    <table>
      <tr>
        <th>Member</th><th>Status</th><th>Current period</th>
        <th>Paid order</th><th>Term</th><th>Renewal date</th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <a href="view.php?id=<?= (int) $r['member_id'] ?>">#<?= (int) $r['member_id'] ?></a>
            <?= h($r['number']) ?> <?= h($r['name']) ?><br>
            <span style="color:#666"><?= h($r['email']) ?></span>
          </td>
          <td><?= h($r['status']) ?></td>
          <td><?= h($r['period']) ?></td>
          <td><?= $r['order'] !== '' ? h($r['order']) : '<span class="none">none</span>' ?></td>
          <td><?= h($r['term']) ?></td>
          <td>
            <?php if ($r['end_new']): ?>
              <span class="new"><?= h($r['end_new']) ?></span>
            <?php else: ?>
              <span class="none">set by hand</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p>Every non-life member has a renewal date. Nothing to do. 🎉</p>
  <?php endif; ?>

  <?php if (!$apply && $report['fixable'] > 0): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="apply" value="1">
      <p>This is a dry-run — the dates above were calculated for real and then rolled back. Applying writes exactly those dates.</p>
      <button type="submit">Apply <?= number_format($report['fixable']) ?> renewal date<?= $report['fixable'] === 1 ? '' : 's' ?></button>
    </form>
  <?php elseif ($apply): ?>
    <p>Changes applied. <a href="?">Re-run dry-run</a> to confirm everything is clean.</p>
  <?php endif; ?>

  <p class="note">Only members with a <strong>paid</strong> membership order are touched — the date comes from the term they actually paid for. DELETE this file from <code>/public_html/admin/members/</code> once the correction is finished.</p>
</body>
</html>
