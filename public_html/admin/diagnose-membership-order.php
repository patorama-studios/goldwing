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

$orderNumber  = trim((string) ($_GET['order'] ?? ''));
$memberIdArg  = (int) ($_GET['member_id'] ?? 0);
$memberSearch = trim((string) ($_GET['member_search'] ?? ''));
$forceDelete  = !empty($_GET['force_delete']);
$purgePeriod  = !empty($_GET['purge_period']);
$purgeMemberPeriods = !empty($_GET['purge_member_periods']);

echo "=== Membership Order Diagnostic ===\n";
echo "Version: v2 — order/member lookup + orphan period purge\n";
echo "Source mtime: " . @date('c', filemtime(__FILE__)) . "\n";
echo "Time:    " . date('c') . "\n";
echo "Order:         " . ($orderNumber !== '' ? $orderNumber : '(none)') . "\n";
echo "member_id arg: " . ($memberIdArg > 0 ? $memberIdArg : '(none)') . "\n";
echo "member_search: " . ($memberSearch !== '' ? $memberSearch : '(none)') . "\n";
echo "Force delete:  " . ($forceDelete ? 'YES (will DELETE order)' : 'no') . "\n";
echo "Purge period:  " . ($purgePeriod ? 'YES (will clean order\'s period)' : 'no') . "\n";
echo "Purge member orphan periods: " . ($purgeMemberPeriods ? 'YES' : 'no') . "\n\n";

if ($orderNumber === '' && $memberIdArg <= 0 && $memberSearch === '') {
    echo "Pass at least one of:\n";
    echo "  ?order=M-2026-000023\n";
    echo "  ?member_id=42\n";
    echo "  ?member_search=Aleksandrov\n";
    exit;
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo "FATAL: DB connect: " . $e->getMessage() . "\n";
    exit;
}

$orders = [];

// ── 1. Look up by order_number (if given) ────────────────────────────────────
if ($orderNumber !== '') {
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
}

// ── 1b. Resolve member via search if needed ─────────────────────────────────
if ($memberIdArg <= 0 && $memberSearch !== '') {
    echo "--- 1b. member_search lookup ---\n";
    try {
        // Native PDO prepares forbid binding :q three times — split into
        // three placeholders bound to the same value.
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, status, member_type, user_id
               FROM members
              WHERE first_name LIKE :q1 OR last_name LIKE :q2 OR CONCAT(first_name, ' ', last_name) LIKE :q3
              ORDER BY id DESC LIMIT 20"
        );
        $like = '%' . $memberSearch . '%';
        $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$matches) {
            echo "  (no members match '{$memberSearch}')\n\n";
        } else {
            foreach ($matches as $m) {
                echo "  id={$m['id']}  " . trim($m['first_name'] . ' ' . $m['last_name']) . "  status={$m['status']}  type={$m['member_type']}  user_id=" . ($m['user_id'] ?? 'NULL') . "\n";
            }
            echo "  (re-run with &member_id=N to inspect a specific match)\n\n";
        }
        if (count($matches) === 1) {
            $memberIdArg = (int) $matches[0]['id'];
            echo "  → auto-selected member_id={$memberIdArg}\n\n";
        }
    } catch (Throwable $e) { echo "  ERROR: " . $e->getMessage() . "\n\n"; }
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
    // table_name lives on both tables joined here, so qualify with k.* aliases
    $stmt = $pdo->prepare(
        "SELECT k.table_name AS table_name,
                k.column_name AS column_name,
                k.constraint_name AS constraint_name,
                r.delete_rule AS delete_rule
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
$memberId = $primaryOrder['member_id'] ?? ($memberIdArg > 0 ? $memberIdArg : null);

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

    echo "--- 4b2. all membership ORDERS for this member ---\n";
    try {
        $stmt = $pdo->prepare("SELECT id, order_number, status, payment_status, fulfillment_status, membership_period_id, total, voided_at, created_at FROM orders WHERE member_id = :id AND order_type = 'membership' ORDER BY id DESC LIMIT 25");
        $stmt->execute(['id' => $memberId]);
        $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$allOrders) {
            echo "  (no membership orders)\n\n";
        } else {
            foreach ($allOrders as $o) {
                echo "  id={$o['id']}  " . str_pad((string) ($o['order_number'] ?? '—'), 18) . "  status={$o['status']}  pay={$o['payment_status']}  period_id=" . ($o['membership_period_id'] ?? 'NULL') . "  total={$o['total']}  voided=" . ($o['voided_at'] ? 'Y' : 'N') . "  created={$o['created_at']}\n";
            }
            echo "\n";
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

// ── 4d. Optional: purge orphan PENDING_PAYMENT periods for this member ──────
if ($memberId && $purgeMemberPeriods) {
    echo "--- 4d. PURGE orphan PENDING_PAYMENT periods for member {$memberId} ---\n";
    try {
        $stmt = $pdo->prepare("SELECT id FROM membership_periods WHERE member_id = :id AND status = 'PENDING_PAYMENT' ORDER BY id DESC");
        $stmt->execute(['id' => $memberId]);
        $pendingPeriodIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$pendingPeriodIds) {
            echo "  (no PENDING_PAYMENT periods to consider)\n\n";
        } else {
            $pdo->beginTransaction();
            foreach ($pendingPeriodIds as $pid) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE membership_period_id = :pid');
                $stmt->execute(['pid' => $pid]);
                $refs = (int) $stmt->fetchColumn();
                if ($refs > 0) {
                    echo "  period {$pid}: {$refs} order(s) still reference it — skipped\n";
                    continue;
                }
                $stmt = $pdo->prepare('DELETE FROM renewal_reminders WHERE period_id = :pid');
                $stmt->execute(['pid' => $pid]);
                $rem = $stmt->rowCount();
                $stmt = $pdo->prepare('DELETE FROM membership_periods WHERE id = :pid');
                $stmt->execute(['pid' => $pid]);
                $per = $stmt->rowCount();
                echo "  period {$pid}: renewal_reminders deleted={$rem}, period rowCount={$per}\n";
            }
            $pdo->commit();
            echo "  COMMIT ok\n\n";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "  EXCEPTION: " . $e->getMessage() . "\n\n";
    }
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
echo "Done.\n";
echo "  ?order=M-2026-000023&force_delete=1&purge_period=1  → wipe a specific order + its pending period\n";
echo "  ?member_id=42&purge_member_periods=1                → wipe ALL orphan pending periods for member 42\n";
echo "  ?member_search=Aleksandrov                          → fuzzy search for a member\n";
