<?php
/**
 * Read-only dump of recent `stripe_errors` rows so we can see what's making
 * /api/stripe/create-payment-intent return the generic "Unable to start
 * checkout" banner without exposing the underlying error to the customer.
 *
 * Filters to the last 24 hours by default; ?hours=N overrides.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;

require_permission('admin.members.view');

header('Content-Type: text/plain; charset=utf-8');

$hours = max(1, min(168, (int) ($_GET['hours'] ?? 24)));
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 20)));

$pdo = Database::connection();

echo "=== Recent stripe_errors ===\n";
echo "Window: last {$hours}h · limit {$limit}\n";
echo "Time:   " . date('c') . "\n\n";

// ── Schema sanity check ──────────────────────────────────────────────────────
$checkColumn = function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
    $stmt->execute(['c' => $column]);
    return (bool) $stmt->fetchColumn();
};
echo "store_products.stripe_product_id present: "
    . ($checkColumn('store_products', 'stripe_product_id') ? 'YES' : 'NO') . "\n";
echo "store_orders.stripe_invoice_id present:   "
    . ($checkColumn('store_orders', 'stripe_invoice_id') ? 'YES' : 'NO') . "\n";
echo "store_orders.stripe_payment_intent_id:    "
    . ($checkColumn('store_orders', 'stripe_payment_intent_id') ? 'YES' : 'NO') . "\n";
echo "members.stripe_customer_id present:       "
    . ($checkColumn('members', 'stripe_customer_id') ? 'YES' : 'NO') . "\n\n";

// ── Recent stripe_errors rows ────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT id, occurred_at, source, operation, error_code, error_message,
            related_order_id, related_store_order_id, related_stripe_pi_id,
            context_json
       FROM stripe_errors
      WHERE occurred_at >= NOW() - INTERVAL :hours HOUR
      ORDER BY id DESC
      LIMIT {$limit}"
);
$stmt->execute(['hours' => $hours]);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "(no stripe_errors rows in the last {$hours}h)\n";
} else {
    foreach ($rows as $r) {
        echo str_repeat('─', 78) . "\n";
        echo "#{$r['id']}  {$r['occurred_at']}\n";
        echo "source:    {$r['source']}\n";
        echo "operation: {$r['operation']}\n";
        if (!empty($r['error_code'])) {
            echo "code:      {$r['error_code']}\n";
        }
        if (!empty($r['related_order_id'])) {
            echo "order_id:        {$r['related_order_id']}\n";
        }
        if (!empty($r['related_store_order_id'])) {
            echo "store_order_id:  {$r['related_store_order_id']}\n";
        }
        if (!empty($r['related_stripe_pi_id'])) {
            echo "pi:              {$r['related_stripe_pi_id']}\n";
        }
        if (!empty($r['error_message'])) {
            echo "message:\n  " . str_replace("\n", "\n  ", (string) $r['error_message']) . "\n";
        }
        if (!empty($r['context_json'])) {
            $decoded = json_decode((string) $r['context_json'], true);
            if (is_array($decoded)) {
                echo "context:\n";
                foreach ($decoded as $k => $v) {
                    $vs = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES);
                    echo "  {$k}: {$vs}\n";
                }
            } else {
                echo "context: {$r['context_json']}\n";
            }
        }
        echo "\n";
    }
}

echo str_repeat('═', 78) . "\n";
echo "Done. ?hours=72&limit=50 to widen the window.\n";
