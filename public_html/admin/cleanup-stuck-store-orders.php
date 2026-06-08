<?php
/**
 * One-off admin tool: clean up store_orders rows that were created when a user
 * clicked "Pay now" but never made it to Stripe (the JS bug that was fixed in
 * the June 2026 checkout repair). These rows have status='pending', the user's
 * cart was marked 'converted' (locking them out), and either no
 * stripe_session_id at all or a session that never reached paid status.
 *
 * Usage:
 *   /admin/cleanup-stuck-store-orders.php             — dry run, lists candidates
 *   /admin/cleanup-stuck-store-orders.php?confirm=1   — execute cleanup
 *
 * For each candidate it will:
 *   1. Verify with Stripe that the session is NOT paid (safety check)
 *   2. Mark the orders/store_orders row 'canceled'
 *   3. Reopen the user's store_carts row (status='active')
 *
 * The order rows are kept (marked canceled) so refund / audit history is
 * preserved. Cart is reopened so the user can retry checkout cleanly.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ThirdParty/stripe-php/init.php';

use App\Services\Database;
use App\Services\StripeSettingsService;
use Stripe\StripeClient;

require_permission('admin.settings.general.manage');

header('Content-Type: text/plain; charset=utf-8');

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$daysBack = isset($_GET['days']) ? max(1, min(60, (int) $_GET['days'])) : 14;

echo "=== Stuck Store Orders Cleanup ===\n";
echo "Time:        " . date('c') . "\n";
echo "Mode:        " . ($confirm ? '*** EXECUTING ***' : 'dry run (add ?confirm=1 to execute)') . "\n";
echo "Window:      last {$daysBack} day(s)\n\n";

$pdo = Database::connection();

$active = StripeSettingsService::getActiveKeys(StripeSettingsService::ACCOUNT_PRIMARY);
$secret = $active['secret_key'] ?? '';
if ($secret === '') {
    echo "Stripe secret key is empty — aborting.\n";
    exit(1);
}
$stripe = new StripeClient($secret);

// Pull candidate store_orders: pending, recent, with a session id (or none)
$stmt = $pdo->prepare("
    SELECT so.id, so.order_number, so.user_id, so.customer_name, so.customer_email,
           so.total, so.status, so.stripe_session_id, so.created_at,
           o.id AS orders_id, o.status AS orders_status, o.payment_status,
           o.stripe_session_id AS orders_session_id, o.stripe_payment_intent_id,
           sc.id AS cart_id, sc.status AS cart_status
    FROM store_orders so
    LEFT JOIN orders o ON o.order_number = so.order_number
    LEFT JOIN store_carts sc ON sc.user_id = so.user_id AND sc.status = 'converted'
    WHERE so.status = 'pending'
      AND so.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
    ORDER BY so.created_at DESC
");
$stmt->execute([':days' => $daysBack]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No pending store orders in the window. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($rows) . " pending store_order(s). Verifying against Stripe...\n\n";
echo str_pad('Order#', 18) . str_pad('Customer', 28) . str_pad('Total', 10) . str_pad('Cart', 10) . str_pad('Session', 16) . "Stripe status\n";
echo str_repeat('-', 100) . "\n";

$toClean = [];
$skipped = [];

foreach ($rows as $row) {
    $sessionId = $row['stripe_session_id'] ?: $row['orders_session_id'];
    $stripeStatus = '(no session)';
    $sessionPaymentStatus = null;

    if ($sessionId) {
        try {
            $session = $stripe->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent'],
            ]);
            $sessionPaymentStatus = $session->payment_status; // 'unpaid' | 'paid' | 'no_payment_required'
            $stripeStatus = $session->status . '/' . $session->payment_status;
            if ($session->payment_intent && is_object($session->payment_intent)) {
                $stripeStatus .= ' (pi=' . $session->payment_intent->status . ')';
            }
        } catch (\Throwable $e) {
            $stripeStatus = 'lookup error: ' . substr($e->getMessage(), 0, 40);
        }
    }

    $isPaid = ($sessionPaymentStatus === 'paid');

    echo str_pad($row['order_number'], 18)
       . str_pad(substr((string) $row['customer_name'], 0, 26), 28)
       . str_pad('$' . number_format((float) $row['total'], 2), 10)
       . str_pad((string) ($row['cart_id'] ? '#' . $row['cart_id'] : '-'), 10)
       . str_pad(substr((string) $sessionId, 0, 14), 16)
       . $stripeStatus . "\n";

    if ($isPaid) {
        // Paid sessions should not be cleaned up — webhook may have just been delayed
        $skipped[] = [$row, 'session is PAID — webhook should finalize; investigate manually'];
        continue;
    }

    $toClean[] = $row;
}

echo "\n";
echo "Will clean:  " . count($toClean) . "\n";
echo "Will skip:   " . count($skipped) . "\n\n";

if ($skipped) {
    echo "Skipped (manual review):\n";
    foreach ($skipped as [$row, $reason]) {
        echo "  - {$row['order_number']}: {$reason}\n";
    }
    echo "\n";
}

if (!$confirm) {
    echo "Dry run complete. Add ?confirm=1 to the URL to execute the cleanup.\n";
    exit(0);
}

if (!$toClean) {
    echo "Nothing to clean.\n";
    exit(0);
}

echo "Executing cleanup...\n";
$pdo->beginTransaction();
try {
    $cancelSo = $pdo->prepare("UPDATE store_orders SET status = 'canceled', updated_at = NOW() WHERE id = :id AND status = 'pending'");
    $cancelOrd = $pdo->prepare("UPDATE orders SET status = 'canceled', payment_status = 'canceled', updated_at = NOW() WHERE id = :id AND status = 'pending'");
    $reopenCart = $pdo->prepare("UPDATE store_carts SET status = 'active', updated_at = NOW() WHERE id = :id AND status = 'converted'");

    $cleanedOrders = 0;
    $reopenedCarts = 0;
    $touchedCartIds = [];

    foreach ($toClean as $row) {
        $cancelSo->execute([':id' => $row['id']]);
        $cleanedOrders += $cancelSo->rowCount();

        if (!empty($row['orders_id'])) {
            $cancelOrd->execute([':id' => $row['orders_id']]);
        }

        if (!empty($row['cart_id']) && !isset($touchedCartIds[$row['cart_id']])) {
            $reopenCart->execute([':id' => $row['cart_id']]);
            $reopenedCarts += $reopenCart->rowCount();
            $touchedCartIds[$row['cart_id']] = true;
        }
    }

    $pdo->commit();
    echo "OK. Canceled {$cleanedOrders} store_order(s), reopened {$reopenedCarts} cart(s).\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "ERROR — rolled back: " . $e->getMessage() . "\n";
    exit(1);
}
