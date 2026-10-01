<?php
/**
 * One-shot repair for members wrongly flipped to LAPSED by
 * cron/expire_memberships.php on 1 Oct 2026.
 *
 * A renewal adds a NEW membership period and leaves the old one ACTIVE. When
 * the 2-month grace on the 31 Jul 2026 year end ran out, the cron lapsed every
 * stale period — and set members.status = LAPSED on its owner even when a
 * newer paid period still covered them. The cron now checks for that; this
 * tool puts the already-affected members back to ACTIVE.
 *
 * A member is restored only when they are LAPSED but still hold an ACTIVE
 * period the cron itself would not lapse. Members who genuinely didn't renew
 * have no such period and are left alone.
 *
 * Dry-run by default. POST with csrf_token + apply=1 to commit.
 *
 * DELETE THIS FILE once the repair is done.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MembershipAccessService;

require_permission('admin.members.import_export');

$user = current_user();
$actorId = $user['id'] ?? null;
$pdo = Database::connection();

$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && Csrf::verify($_POST['csrf_token'] ?? '')
    && ($_POST['apply'] ?? '') === '1';

$graceMonths = (int) MembershipAccessService::GRACE_MONTHS;
$errors = [];
$rows = [];
$restored = 0;
$stillLapsed = 0;

try {
    $rows = $pdo->query(
        "SELECT m.id, m.first_name, m.last_name, m.email, MAX(mp.end_date) AS covered_to
           FROM members m
           JOIN membership_periods mp ON mp.member_id = m.id
          WHERE UPPER(m.status) IN ('LAPSED', 'EXPIRED')
            AND mp.status = 'ACTIVE'
            AND (mp.end_date IS NULL OR mp.end_date >= (CURDATE() - INTERVAL $graceMonths MONTH))
       GROUP BY m.id, m.first_name, m.last_name, m.email
       ORDER BY m.last_name, m.first_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($apply) {
        // Direct UPDATE, not MemberRepository::update(), so nothing but the
        // status column is touched.
        $update = $pdo->prepare("UPDATE members SET status = 'ACTIVE', updated_at = NOW() WHERE id = :id AND UPPER(status) IN ('LAPSED', 'EXPIRED')");
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $update->execute(['id' => (int) $r['id']]);
            if ($update->rowCount() > 0) {
                $restored++;
                ActivityLogger::log('admin', $actorId, (int) $r['id'], 'membership.wrongly_lapsed_restored', [
                    'covered_to' => $r['covered_to'],
                ]);
            }
        }
        $pdo->commit();
    }

    $stillLapsed = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE UPPER(status) IN ('LAPSED', 'EXPIRED')")->fetchColumn();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $restored = 0;
    $errors[] = 'Aborted: ' . $e->getMessage();
}

$csrf = Csrf::token();
$mode = $apply ? 'APPLIED' : 'DRY-RUN';
function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fix wrongly lapsed members — <?= $mode ?></title>
<style>
  body { font-family: system-ui, sans-serif; padding: 24px; max-width: 900px; margin: 0 auto; color: #111; }
  .tag { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: <?= $apply ? '#dcfce7' : '#fef3c7' ?>; color: <?= $apply ? '#166534' : '#92400e' ?>; }
  table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 13px; }
  td, th { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; }
  .err { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 4px; margin: 8px 0; }
  form { margin: 16px 0; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
  button { background: #111; color: #fff; padding: 10px 18px; border: 0; border-radius: 6px; font-weight: 700; cursor: pointer; }
  .note { font-size: 12px; color: #666; margin-top: 24px; }
</style>
</head>
<body>
  <h1>Fix wrongly lapsed members</h1>
  <p><span class="tag"><?= $mode ?></span></p>

  <?php foreach ($errors as $err): ?>
    <div class="err"><?= h($err) ?></div>
  <?php endforeach; ?>

  <p>
    <strong><?= count($rows) ?></strong> lapsed member<?= count($rows) === 1 ? '' : 's' ?> still covered by a paid, active membership period.
    <?php if ($apply): ?><strong><?= $restored ?></strong> restored to ACTIVE.<?php endif; ?>
    <?= $stillLapsed ?> member<?= $stillLapsed === 1 ? ' is' : 's are' ?> lapsed in total<?= $apply ? ' now' : '' ?>.
  </p>

  <?php if ($rows): ?>
    <table>
      <tr><th>Member</th><th>Email</th><th>Paid up to</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>#<?= (int) $r['id'] ?> <?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
          <td><?= h($r['email']) ?></td>
          <td><?= h($r['covered_to'] ?? 'no end date') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p>No wrongly lapsed members found. Nothing to do.</p>
  <?php endif; ?>

  <?php if (!$apply && $rows): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="apply" value="1">
      <p>This is a dry-run — no changes have been made. Review the list above, then apply.</p>
      <button type="submit">Restore <?= count($rows) ?> member<?= count($rows) === 1 ? '' : 's' ?> to ACTIVE</button>
    </form>
  <?php elseif ($apply): ?>
    <p>Changes applied. <a href="?">Re-run the dry-run</a> to confirm the list is empty.</p>
  <?php endif; ?>

  <p class="note">Only changes <code>members.status</code>. DELETE this file from <code>/public_html/admin/members/</code> once the repair is finished.</p>
</body>
</html>
