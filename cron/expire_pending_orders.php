<?php
/**
 * Auto-expire stale pending Stripe orders.
 *
 * Stripe Checkout Sessions expire after ~24h on Stripe's side, but the DB
 * never learns. Without this job, abandoned orders accumulate forever in
 * `orders` and `store_orders` with status='pending' and no breadcrumb.
 *
 * Rules:
 *  - Only orders older than $thresholdHours
 *  - Only stripe payments — bank-transfer orders stay pending until a human
 *    confirms them
 *  - Defensive skip if stripe_charge_id, paid_at, or payment_status already
 *    reflects a paid outcome (a webhook may have set things while we ran)
 *  - Linked membership_periods in PENDING_PAYMENT get marked LAPSED, matching
 *    MembershipOrderService::markOrderRejected's behaviour
 *
 * Suggested cPanel cron: hourly  →  `0 * * * * php /home/.../cron/expire_pending_orders.php`
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ActivityLogger;

$thresholdHours = 24;
$pdo = db();

echo "[" . date('c') . "] expire_pending_orders: scanning for orders older than {$thresholdHours}h\n";

$expiredOrders = 0;
$expiredStoreOrders = 0;
$lapsedPeriods = 0;
$skipped = 0;

/* -------------------------------------------------------------------------
 * 1) Stale `orders` rows (membership + store dual-write).
 * ---------------------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, order_number, order_type, member_id, membership_period_id,
           status, payment_status, payment_method,
           stripe_charge_id, paid_at, created_at
    FROM orders
    WHERE status = 'pending'
      AND payment_status = 'pending'
      AND payment_method = 'stripe'
      AND created_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)
");
$stmt->execute([':hours' => $thresholdHours]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    /* Defensive: a webhook may have updated state between SELECT and our UPDATE */
    if (!empty($row['stripe_charge_id']) || !empty($row['paid_at'])) {
        $skipped++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        $note = "Auto-expired by expire_pending_orders cron: no payment received within {$thresholdHours}h.";
        $upd = $pdo->prepare("
            UPDATE orders
            SET status = 'cancelled',
                payment_status = 'failed',
                admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), '\n', :note)),
                updated_at = NOW()
            WHERE id = :id
              AND status = 'pending'
              AND payment_status = 'pending'
              AND stripe_charge_id IS NULL
              AND paid_at IS NULL
        ");
        $upd->execute([':id' => $row['id'], ':note' => $note]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            $skipped++;
            continue;
        }
        $expiredOrders++;

        /* Linked membership_periods in PENDING_PAYMENT → LAPSED */
        if (!empty($row['membership_period_id'])) {
            $periodStmt = $pdo->prepare("
                UPDATE membership_periods
                SET status = 'LAPSED'
                WHERE id = :id AND status = 'PENDING_PAYMENT'
            ");
            $periodStmt->execute([':id' => $row['membership_period_id']]);
            if ($periodStmt->rowCount() > 0) {
                $lapsedPeriods++;
            }
        }

        $pdo->commit();

        ActivityLogger::log('system', null, isset($row['member_id']) ? (int) $row['member_id'] : null,
            'order.auto_expired', [
                'order_id' => (int) $row['id'],
                'order_number' => $row['order_number'],
                'order_type' => $row['order_type'],
                'threshold_hours' => $thresholdHours,
            ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "[error] orders.id={$row['id']}: " . $e->getMessage() . "\n";
    }
}

/* -------------------------------------------------------------------------
 * 2) Stale `store_orders` rows.
 *
 * Store orders dual-write into `orders` too, so step 1 already cancelled the
 * paired payment record. Here we close out the fulfilment-side row. Identify
 * by order_number (the only field that links store_orders ↔ orders).
 * ---------------------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT id, order_number, status, paid_at, created_at
    FROM store_orders
    WHERE status = 'pending'
      AND payment_status = 'unpaid'
      AND created_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)
");
$stmt->execute([':hours' => $thresholdHours]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    if (!empty($row['paid_at'])) {
        $skipped++;
        continue;
    }

    try {
        /* NB: store_orders.payment_status enum is ('unpaid','paid','refunded','partial_refund')
         * — no 'failed' value, so we leave payment_status='unpaid' and signal expiry
         * via status='cancelled' + admin_notes. */
        $note = "Auto-expired by expire_pending_orders cron: no payment received within {$thresholdHours}h.";
        $upd = $pdo->prepare("
            UPDATE store_orders
            SET status = 'cancelled',
                admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), '\n', :note)),
                updated_at = NOW()
            WHERE id = :id
              AND status = 'pending'
              AND paid_at IS NULL
        ");
        $upd->execute([':id' => $row['id'], ':note' => $note]);
        if ($upd->rowCount() > 0) {
            $expiredStoreOrders++;
            ActivityLogger::log('system', null, null, 'store_order.auto_expired', [
                'store_order_id' => (int) $row['id'],
                'order_number' => $row['order_number'],
                'threshold_hours' => $thresholdHours,
            ]);
        } else {
            $skipped++;
        }
    } catch (\Throwable $e) {
        echo "[error] store_orders.id={$row['id']}: " . $e->getMessage() . "\n";
    }
}

/* -------------------------------------------------------------------------
 * 3) Record run timestamp (matches expire_memberships.php convention).
 * ---------------------------------------------------------------------- */
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_pending_expire_run', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()")->execute();
} catch (\Throwable $e) {
    echo "[warn] could not record run timestamp: " . $e->getMessage() . "\n";
}

echo "[" . date('c') . "] expire_pending_orders: done. expired_orders={$expiredOrders}, expired_store_orders={$expiredStoreOrders}, lapsed_periods={$lapsedPeriods}, skipped={$skipped}\n";
