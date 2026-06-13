<?php
/**
 * One-off admin tool: VOID the abandoned, never-paid store orders left behind by
 * the pre-Migration-036 duplicate-checkout bug, so they drop out of the default
 * orders list. Pairs with /admin/cleanup-stuck-store-orders.php (which cancels
 * them); this one hides them.
 *
 * Why a script instead of the list's "Void selected" bulk action: that bulk
 * action calls OrderAdminService::sendStoreOrderVoidedNotification() for every
 * order, which emails the CUSTOMER an "order voided" notice. Voiding ~56 test
 * orders that way would spam real members (Lewis, Vanessa, etc). This script
 * calls the SILENT OrderAdminService::voidStoreOrder() — DB + event-log only,
 * no notifications.
 *
 * Scope (safety): only voids store_orders that are
 *   - not already voided
 *   - never paid (paid_at IS NULL and payment_status not paid/refunded)
 *   - older than 30 minutes (never touches an in-progress checkout)
 *
 * Usage:
 *   /admin/void-abandoned-store-orders.php             — dry run, lists candidates
 *   /admin/void-abandoned-store-orders.php?confirm=1   — execute
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;
use App\Services\OrderAdminService;

require_permission('admin.settings.general.manage');

header('Content-Type: text/plain; charset=utf-8');

$user    = current_user();
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

echo "=== Void Abandoned Store Orders ===\n";
echo "Time:   " . date('c') . "\n";
echo "Mode:   " . ($confirm ? '*** EXECUTING ***' : 'dry run (add ?confirm=1 to execute)') . "\n\n";

$pdo = Database::connection();

// Never-paid, not-voided, settled (created > 30 min ago so a live checkout the
// member is mid-way through is never swept up).
$stmt = $pdo->query("
    SELECT id, order_number, customer_name, customer_email, total, status, payment_status, created_at
    FROM store_orders
    WHERE voided_at IS NULL
      AND paid_at IS NULL
      AND (payment_status IS NULL OR payment_status NOT IN ('paid', 'partial_refund', 'refunded'))
      AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ORDER BY created_at DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No abandoned (unpaid, un-voided) store orders found. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($rows) . " candidate(s):\n\n";
echo str_pad('Order#', 18) . str_pad('Customer', 28) . str_pad('Total', 10) . "Created\n";
echo str_repeat('-', 80) . "\n";
foreach ($rows as $row) {
    echo str_pad((string) $row['order_number'], 18)
       . str_pad(substr((string) $row['customer_name'], 0, 26), 28)
       . str_pad('$' . number_format((float) $row['total'], 2), 10)
       . (string) $row['created_at'] . "\n";
}
echo "\n";

if (!$confirm) {
    echo "Dry run complete. Add ?confirm=1 to the URL to void these orders.\n";
    exit(0);
}

$actorId = (int) ($user['id'] ?? 0);
$voided  = 0;
foreach ($rows as $row) {
    // Silent void — no customer notification (see header note).
    OrderAdminService::voidStoreOrder((int) $row['id'], $actorId, 'Abandoned pre-fix duplicate checkout (bulk void).');
    $voided++;
}

echo "OK. Voided {$voided} store order(s). They are now hidden from the default orders list.\n";
