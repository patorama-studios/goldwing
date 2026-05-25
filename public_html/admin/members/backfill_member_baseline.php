<?php
/**
 * One-shot backfill to give every member a working baseline:
 *   1. members.status PENDING  -> ACTIVE
 *   2. members without a linked user_id -> link to existing user by email
 *      (else create a new users row with a random password)
 *   3. users with no row in user_roles -> assign the 'member' role
 *
 * Members in LAPSED / INACTIVE / ACTIVE keep their existing status.
 * Users that already have any role are left alone.
 *
 * Dry-run by default. POST with csrf_token + apply=1 to commit.
 *
 * DELETE THIS FILE once the backfill is done.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\Database;

require_permission('admin.members.import_export');

$user = current_user();
$actorId = $user['id'] ?? null;

$pdo = Database::connection();

$memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'member' LIMIT 1")->fetchColumn();
if (!$memberRoleId) {
    http_response_code(500);
    echo "The 'member' role is missing from the roles table — aborting.";
    exit;
}

$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && Csrf::verify($_POST['csrf_token'] ?? '')
    && ($_POST['apply'] ?? '') === '1';

$report = [
    'pending_promoted'        => 0,
    'members_linked_existing' => 0,
    'users_created'           => 0,
    'roles_assigned'          => 0,
    'skipped_no_email'        => 0,
    'errors'                  => [],
];

try {
    if ($apply) {
        $pdo->beginTransaction();
    }

    // 1) PENDING -> ACTIVE
    $pendingIds = $pdo->query("SELECT id FROM members WHERE status = 'PENDING'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $report['pending_promoted'] = count($pendingIds);
    if ($apply && $pendingIds) {
        $placeholders = implode(',', array_fill(0, count($pendingIds), '?'));
        $upd = $pdo->prepare(
            "UPDATE members SET status = 'ACTIVE', updated_at = NOW() WHERE id IN ($placeholders)"
        );
        $upd->execute($pendingIds);
    }

    // 2) Members without a linked user
    $noUserRows = $pdo->query(
        "SELECT id, email, first_name, last_name
           FROM members
          WHERE user_id IS NULL OR user_id = 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    $findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $linkUser = $pdo->prepare(
        'UPDATE members SET user_id = :uid WHERE id = :mid AND (user_id IS NULL OR user_id = 0)'
    );
    $createUser = $pdo->prepare(
        'INSERT INTO users (member_id, name, email, password_hash, is_active, created_at)
         VALUES (:member_id, :name, :email, :hash, 1, NOW())'
    );

    foreach ($noUserRows as $row) {
        $memberId = (int) $row['id'];
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            $report['skipped_no_email']++;
            continue;
        }
        $findUser->execute(['email' => $email]);
        $existing = $findUser->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($apply) {
                $linkUser->execute(['uid' => (int) $existing['id'], 'mid' => $memberId]);
            }
            $report['members_linked_existing']++;
            continue;
        }

        if ($apply) {
            try {
                $createUser->execute([
                    'member_id' => $memberId,
                    'name'      => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                    'email'     => $email,
                    'hash'      => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                ]);
                $newUserId = (int) $pdo->lastInsertId();
                $linkUser->execute(['uid' => $newUserId, 'mid' => $memberId]);
                ActivityLogger::log('admin', $actorId, $memberId, 'member.user_account_backfilled', [
                    'user_id' => $newUserId,
                ]);
            } catch (Throwable $e) {
                $report['errors'][] = "Member #$memberId ($email): " . $e->getMessage();
                continue;
            }
        }
        $report['users_created']++;
    }

    // 3) Users with no role -> assign 'member'
    if ($apply) {
        $rolelessIds = $pdo->query(
            "SELECT u.id
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.user_id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        $assignRole = $pdo->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)'
        );
        foreach ($rolelessIds as $uid) {
            $assignRole->execute(['uid' => (int) $uid, 'rid' => $memberRoleId]);
        }
        $report['roles_assigned'] = count($rolelessIds);
    } else {
        // Dry-run estimate: existing roleless users + users we would create
        $existingRoleless = (int) $pdo->query(
            "SELECT COUNT(*)
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.user_id IS NULL"
        )->fetchColumn();
        $report['roles_assigned'] = $existingRoleless + $report['users_created'];
    }

    if ($apply) {
        $pdo->commit();
        ActivityLogger::log('admin', $actorId, null, 'members.baseline_backfill_applied', $report);
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
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Member baseline backfill — <?= $mode ?></title>
<style>
  body { font-family: system-ui, sans-serif; padding: 24px; max-width: 760px; margin: 0 auto; color: #111; }
  h1 { margin: 0 0 4px; }
  .tag { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: .04em; }
  .dry { background: #fef3c7; color: #92400e; }
  .applied { background: #dcfce7; color: #166534; }
  table { border-collapse: collapse; width: 100%; margin: 16px 0; }
  td, th { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
  .err { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 4px; margin: 8px 0; font-family: ui-monospace, monospace; font-size: 13px; }
  form { margin: 16px 0; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
  button { background: #111; color: #fff; padding: 10px 18px; border: 0; border-radius: 6px; font-weight: 700; cursor: pointer; }
  .note { font-size: 12px; color: #666; margin-top: 24px; }
  code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }
</style>
</head>
<body>
  <h1>Member baseline backfill</h1>
  <p><span class="tag <?= $modeClass ?>"><?= $mode ?></span></p>

  <table>
    <tr><th>Action</th><th style="text-align:right">Count</th></tr>
    <tr><td>Members with <code>status=PENDING</code> &rarr; <code>ACTIVE</code></td><td class="num"><?= number_format($report['pending_promoted']) ?></td></tr>
    <tr><td>Members linked to an existing user (by email)</td><td class="num"><?= number_format($report['members_linked_existing']) ?></td></tr>
    <tr><td>New user accounts created for unlinked members</td><td class="num"><?= number_format($report['users_created']) ?></td></tr>
    <tr><td>Users assigned the <code>member</code> role</td><td class="num"><?= number_format($report['roles_assigned']) ?></td></tr>
    <tr><td>Members skipped (no email on record)</td><td class="num"><?= number_format($report['skipped_no_email']) ?></td></tr>
  </table>

  <?php foreach ($report['errors'] as $err): ?>
    <div class="err"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$apply): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="apply" value="1">
      <p>This is a dry-run — no changes have been made. Click below to apply.</p>
      <button type="submit">Apply changes</button>
    </form>
  <?php else: ?>
    <p>Changes applied. <a href="?">Re-run dry-run</a> to confirm everything is clean.</p>
  <?php endif; ?>

  <p class="note">DELETE this file from <code>/public_html/admin/members/</code> once the backfill is finished.</p>
</body>
</html>
