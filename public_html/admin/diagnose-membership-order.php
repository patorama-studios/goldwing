<?php
/**
 * Read-only inspection (plus optional supervised force-delete) for a single
 * membership order. Built to track down why /admin/members/actions.php with
 * action=membership_order_delete reports "Order permanently deleted." yet the
 * row keeps showing up after a reload.
 *
 * Usage:
 *   /admin/diagnose-membership-order.php?order=M-2026-000023
 *   /admin/diagnose-membership-order.php?order=M-2026-000023&force_delete=1
 *
 * Force-delete uses the same DELETE statement OrderAdminService runs, but
 * reports rowCount and catches any exception so we can see what actually
 * happens. It also (optionally, with &purge_period=1) cleans up the linked
 * membership_periods row when it's still in PENDING_PAYMENT and no other
 * orders point at it, so the /member/index.php?page=billing auto-recreate
 * stops firing.
 */
if (function_exists('opcache_reset')) { @opcache_reset(); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;

require_permission('admin.members.view');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$orderNumber = trim((string) ($_GET['order'] ?? ''));
$forceDelete = !empty($_GET['force_delete']);
$purgePeriod = !empty($_GET['purge_period']);

echo "=== Membership Order Diagnostic ===\n";
echo "Version: v1 — orders + period + activity log\n";
echo "Source mtime: " . @date('c', filemtime(__FILE__)) . "\n";
echo "Time:    " . date('c') . "\n";
echo "Order:   " . ($orderNumber !== '' ? $orderNumber : '(missing — pass ?order=M-...)') . "\n";
echo "Force:   " . ($forceDelete ? 'YES (will DELETE)' : 'no (read-only)') . "\n";
echo "Purge:   " . ($purgePeriod ? 'YES (will clean membership_periods)' : 'no') . "\n\n";

if ($orderNumber === '') {
    echo "Pass ?order=M-2026-000023 in the URL.\n";
    exit;
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo "FATAL: DB connect: " . $e->getMessage() . "\n";
    exit;
}

// ── 1. Look up all rows matching this order_number ──────────────────────────
echo "--- 1. orders rows with this order_number ---\n";
try {
    $stmt = $pdo->prepare('SELECT id, order_number, order_type, status, payment_status, fulfillment_status, member_id, user_id, membership_period_id, total, voided_at, created_at, updated_at FROM orders WHERE order_number = :n');
    $stmt->execute(['n' => $orderNumber]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit;
}
if (!$orders) {
    echo "(no rows in `orders` with order_number = {$orderNumber})\n\n";
} else {
    foreach ($orders as $o) {
        echo "  id={$o['id']}  type={$o['order_type']}  status={$o['status']}  pay={$o['payment_status']}  fulfil={$o['fulfillment_status']}\n";
        echo "    member_id=" . ($o['member_id'] ?? 'NULL') . "  user_id=" . ($o['user_id'] ?? 'NULL') . "  period_id=" . ($o['membership_period_id'] ?? 'NULL') . "  total={$o['total']}\n";
        echo "    voided_at=" . ($o['voided_at'] ?? 'NULL') . "  created_at={$o['created_at']}  updated_at=" . ($o['updated_at'] ?? 'NULL') . "\n";
    }
    echo "\n";
}

// ── 2. UNIQUE-key sanity check ──────────────────────────────────────────────
echo "--- 2. uniq_orders_order_number index present? ---\n";
try {
    $stmt = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'uniq_orders_order_number'");
    $idx = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo $idx ? "YES (UNIQUE on order_number)\n\n" : "NO — order_number is NOT unique, duplicates are possible!\n\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

// ── 3. All FK constraints referencing orders.id (information_schema) ────────
echo "--- 3. live FKs pointing AT orders(id) ---\n";
try {
    $stmt = $pdo->prepare(
        "SELECT table_name, column_name, constraint_name, delete_rule
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r
             ON r.constraint_name = k.constraint_name
            AND r.constraint_schema = k.constraint_schema
          WHERE k.referenced_table_name = 'orders'
            AND k.referenced_column_name = 'id'
            AND k.constraint_schema = DATABASE()"
    );
    $stmt->execute();
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$fks) {
        echo "(none found — that's strange)\n\n";
    } else {
        foreach ($fks as $fk) {
            echo "  {$fk['table_name']}.{$fk['column_name']}  on delete: {$fk['delete_rule']}  ({$fk['constraint_name']})\n";
        }
        echo "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

// ── 4. Linked membership_period + member ────────────────────────────────────
$primaryOrder = $orders[0] ?? null;
$periodId = $primaryOrder['membership_period_id'] ?? null;
$memberId = $primaryOrder['member_id'] ?? null;

if ($memberId) {
    echo "--- 4a. member row ---\n";
    try {
        $stmt = $pdo->prepare('SELECT id, first_name, last_name, status, member_type, user_id FROM members WHERE id = :id');
        $stmt->execute(['id' => $memberId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            echo "  id={$m['id']}  name=" . trim($m['first_name'] . ' ' . $m['last_name']) . "  status={$m['status']}  type={$m['member_type']}  user_id=" . ($m['user_id'] ?? 'NULL') . "\n\n";
        } else {
            echo "  (member id={$memberId} not found)\n\n";
        }
    } catch (Throwable $e) { echo "  ERROR: " . $e->getMessage() . "\n\n"; }

    echo "--- 4b. all membership_periods for this member ---\n";
    try {
        $stmt = $pdo->prepare('SELECT id, term, status, start_date, end_date, payment_id, paid_at, created_at FROM membership_periods WHERE member_id = :id ORDER BY id DESC');
        $stmt->execute(['id' => $memberId]);
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$periods) {
            echo "  (no periods)\n\n";
        } else {
            foreach ($periods as $p) {
                $marker = ($p['id'] == $periodId) ? '*' : ' ';
                echo "  {$marker} id={$p['id']}  status={$p['status']}  term={$p['term']}  start={$p['start_date']}  end=" . ($p['end_date'] ?? 'NULL') . "  paid_at=" . ($p['paid_at'] ?? 'NULL') . "  created={$p['created_at']}\n";
            }
            echo "  (* = linked to this order)\n\n";
        }
    } catch (Throwable $e) { echo "  ERROR: " . $e->getMessage() . "\n\n"; }

    echo "--- 4c. recent activity_log for this member (last 50) ---\n";
    try {
        $stmt = $pdo->prepare('SELECT id, action, actor_id, target_type, target_id, metadata, created_at FROM activity_log WHERE member_id = :id ORDER BY id DESC LIMIT 50');
        $stmt->execute(['id' => $memberId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$logs) {
            echo "  (no activity_log rows)\n\n";
        } else {
            foreach ($logs as $log) {
                $meta = (string) ($log['metadata'] ?? '');
                if (strlen($meta) > 200) {
                    $meta = substr($meta, 0, 200) . '...';
                }
                echo "  {$log['created_at']}  {$log['action']}  target={$log['target_type']}#{$log['target_id']}  meta={$meta}\n";
            }
            echo "\n";
        }
    } catch (Throwable $e) { echo "  ERROR: " . $e->getMessage() . "\n\n"; }
}

// ── 5. Optional force-delete ────────────────────────────────────────────────
if ($forceDelete) {
    if (!$primaryOrder) {
        echo "--- 5. force_delete requested but no order row to delete ---\n\n";
    } else {
        echo "--- 5. FORCE DELETE ---\n";
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
            $stmt->execute(['id' => $primaryOrder['id']]);
            $deleted = $stmt->rowCount();
            echo "  DELETE FROM orders WHERE id = {$primaryOrder['id']}  →  rowCount={$deleted}\n";

            if ($purgePeriod && $periodId) {
                // Only purge the period if it's still pending payment AND no
                // other orders reference it (after the delete above).
                $stmt = $pdo->prepare('SELECT id, status FROM membership_periods WHERE id = :id');
                $stmt->execute(['id' => $periodId]);
                $period = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$period) {
                    echo "  (period {$periodId} already gone)\n";
                } elseif (strtoupper($period['status']) !== 'PENDING_PAYMENT') {
                    echo "  (period {$periodId} status={$period['status']} — not purging)\n";
                } else {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE membership_period_id = :id');
                    $stmt->execute(['id' => $periodId]);
                    $remaining = (int) $stmt->fetchColumn();
                    if ($remaining > 0) {
                        echo "  ({$remaining} other order(s) still reference period {$periodId} — not purging)\n";
                    } else {
                        // renewal_reminders has a RESTRICT FK to membership_periods.id, so kill those first.
                        $stmt = $pdo->prepare('DELETE FROM renewal_reminders WHERE period_id = :id');
                        $stmt->execute(['id' => $periodId]);
                        $remDeleted = $stmt->rowCount();
                        $stmt = $pdo->prepare('DELETE FROM membership_periods WHERE id = :id');
                        $stmt->execute(['id' => $periodId]);
                        $periodDeleted = $stmt->rowCount();
                        echo "  Purged period {$periodId}: renewal_reminders deleted={$remDeleted}, period rowCount={$periodDeleted}\n";
                    }
                }
            }

            $pdo->commit();
            echo "  COMMIT ok\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo "  EXCEPTION: " . $e->getMessage() . "\n";
        }

        // Re-check
        try {
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = :n');
            $stmt->execute(['n' => $orderNumber]);
            $still = $stmt->fetchColumn();
            echo "  Post-delete check: " . ($still ? "STILL THERE as id={$still}" : "gone ✓") . "\n\n";
        } catch (Throwable $e) {
            echo "  Post-check ERROR: " . $e->getMessage() . "\n\n";
        }
    }
}

echo str_repeat('═', 78) . "\n";
echo "Done. ?force_delete=1&purge_period=1 to actually wipe (idempotent).\n";
