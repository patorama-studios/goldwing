<?php
/**
 * Inspect a store_order and the data we sent to Stripe so we can compare
 * against what shows up on the linked Stripe Invoice. Built for tracking down
 * "the invoice on Stripe is only showing the processing fee, not the product
 * line items" reports.
 *
 *   ?store_order_id=N        focus on one store_order
 *   ?pi=pi_3Tfzve...         walk back from a PaymentIntent id
 *   ?invoice=in_1Tfzvc...    walk back from a Stripe Invoice id
 *   ?recent=1                show the 10 most recent store_orders
 */
if (function_exists('opcache_reset')) { @opcache_reset(); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;

require_permission('admin.members.view');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$storeOrderId = (int) ($_GET['store_order_id'] ?? 0);
$piRef        = trim((string) ($_GET['pi'] ?? ''));
$invoiceRef   = trim((string) ($_GET['invoice'] ?? ''));
$showRecent   = !empty($_GET['recent']);

echo "=== Store Invoice / Line-Item Diagnostic ===\n";
echo "Version: v1\n";
echo "Source mtime: " . @date('c', filemtime(__FILE__)) . "\n";
echo "Time: " . date('c') . "\n";
echo "store_order_id: " . ($storeOrderId > 0 ? $storeOrderId : '(none)') . "\n";
echo "pi:             " . ($piRef !== '' ? $piRef : '(none)') . "\n";
echo "invoice:        " . ($invoiceRef !== '' ? $invoiceRef : '(none)') . "\n";
echo "recent flag:    " . ($showRecent ? 'YES' : 'no') . "\n\n";

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo "FATAL: DB connect: " . $e->getMessage() . "\n";
    exit;
}

// ── If pi or invoice given, resolve store_order_id ──────────────────────────
if ($storeOrderId <= 0 && ($piRef !== '' || $invoiceRef !== '')) {
    echo "--- Resolving store_order_id from pi/invoice ---\n";
    try {
        if ($piRef !== '') {
            $stmt = $pdo->prepare('SELECT id FROM store_orders WHERE stripe_payment_intent_id = :pi LIMIT 1');
            $stmt->execute(['pi' => $piRef]);
            $storeOrderId = (int) ($stmt->fetchColumn() ?: 0);
            echo "  by pi: store_order_id=" . ($storeOrderId ?: '(none)') . "\n";
        }
        if ($storeOrderId <= 0 && $invoiceRef !== '') {
            $stmt = $pdo->prepare('SELECT id FROM store_orders WHERE stripe_invoice_id = :inv LIMIT 1');
            $stmt->execute(['inv' => $invoiceRef]);
            $storeOrderId = (int) ($stmt->fetchColumn() ?: 0);
            echo "  by invoice: store_order_id=" . ($storeOrderId ?: '(none)') . "\n";
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n\n";
    }
}

// ── Recent listing ──────────────────────────────────────────────────────────
if ($showRecent) {
    echo "--- 10 most recent store_orders ---\n";
    try {
        $stmt = $pdo->query("SELECT id, order_number, status, total, processing_fee_total, stripe_invoice_id, stripe_payment_intent_id, stripe_session_id, member_id, voided_at, created_at FROM store_orders ORDER BY id DESC LIMIT 10");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "  id={$row['id']}  {$row['order_number']}  status={$row['status']}  total={$row['total']}  fee={$row['processing_fee_total']}\n";
            echo "    invoice={$row['stripe_invoice_id']}  pi={$row['stripe_payment_intent_id']}  session={$row['stripe_session_id']}\n";
            echo "    member_id=" . ($row['member_id'] ?? 'NULL') . "  voided=" . ($row['voided_at'] ? 'Y' : 'N') . "  created={$row['created_at']}\n";
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n\n";
    }
}

if ($storeOrderId <= 0) {
    if (!$showRecent) {
        echo "Pass ?store_order_id=N, ?pi=pi_..., ?invoice=in_..., or ?recent=1 to focus the report.\n";
    }
    exit;
}

// ── 1. store_orders row ─────────────────────────────────────────────────────
echo "--- 1. store_orders row #{$storeOrderId} ---\n";
try {
    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $storeOrderId]);
    $storeOrder = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit;
}
if (!$storeOrder) {
    echo "  (not found)\n";
    exit;
}
$fields = [
    'order_number', 'status', 'fulfillment_method',
    'subtotal', 'discount_total', 'shipping_total', 'processing_fee_total', 'total',
    'discount_code', 'customer_name', 'customer_email', 'member_id', 'user_id',
    'stripe_session_id', 'stripe_payment_intent_id', 'stripe_invoice_id',
    'voided_at', 'created_at', 'updated_at',
];
foreach ($fields as $f) {
    if (array_key_exists($f, $storeOrder)) {
        $v = $storeOrder[$f];
        echo "  " . str_pad($f, 28) . " " . ($v ?? 'NULL') . "\n";
    }
}
echo "\n";

// Dashboard helpers
if (!empty($storeOrder['stripe_invoice_id'])) {
    echo "Stripe dashboard:\n";
    echo "  invoice → https://dashboard.stripe.com/invoices/" . $storeOrder['stripe_invoice_id'] . "\n";
}
if (!empty($storeOrder['stripe_payment_intent_id'])) {
    echo "  pi      → https://dashboard.stripe.com/payments/" . $storeOrder['stripe_payment_intent_id'] . "\n";
}
echo "\n";

// ── 2. store_order_items ────────────────────────────────────────────────────
echo "--- 2. store_order_items for store_order_id={$storeOrderId} ---\n";
try {
    $stmt = $pdo->prepare(
        "SELECT oi.id, oi.product_id, oi.variant_id, oi.title_snapshot, oi.variant_snapshot,
                oi.sku_snapshot, oi.type, oi.quantity, oi.unit_price, oi.unit_price_final, oi.line_total,
                p.title AS product_title, p.stripe_product_id
           FROM store_order_items oi
           LEFT JOIN store_products p ON p.id = oi.product_id
          WHERE oi.order_id = :id
          ORDER BY oi.id"
    );
    $stmt->execute(['id' => $storeOrderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        echo "  (no items — this is why nothing made it into the invoice!)\n\n";
    } else {
        foreach ($items as $i => $it) {
            echo "  #" . ($i + 1) . "\n";
            echo "    id={$it['id']}  product_id=" . ($it['product_id'] ?? 'NULL') . "  variant_id=" . ($it['variant_id'] ?? 'NULL') . "  type={$it['type']}\n";
            echo "    title_snapshot='" . ($it['title_snapshot'] ?? '') . "'  variant_snapshot='" . ($it['variant_snapshot'] ?? '') . "'\n";
            echo "    sku_snapshot='" . ($it['sku_snapshot'] ?? '') . "'  product_title='" . ($it['product_title'] ?? '') . "'\n";
            echo "    stripe_product_id='" . ($it['stripe_product_id'] ?? '') . "'\n";
            echo "    quantity={$it['quantity']}  unit_price={$it['unit_price']}  unit_price_final={$it['unit_price_final']}  line_total={$it['line_total']}\n";
            $cents = (int) round((float) $it['unit_price_final'] * 100);
            echo "    → would send to Stripe: unit_amount={$cents}  quantity=" . max(1, (int) $it['quantity']) . "\n";
            $expectedDesc = (string) ($it['title_snapshot'] ?? '');
            if (!empty($it['variant_snapshot'])) { $expectedDesc .= ' (' . $it['variant_snapshot'] . ')'; }
            $expectedName = $expectedDesc !== '' ? $expectedDesc : 'Store item';
            echo "    → would send to Stripe: description='{$expectedDesc}'  product_data.name='{$expectedName}'\n";
        }
        echo "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n\n";
}

// ── 3. matching unified `orders` row ────────────────────────────────────────
echo "--- 3. matching `orders` row (by order_number) ---\n";
try {
    $stmt = $pdo->prepare("SELECT id, order_number, order_type, status, payment_status, total, stripe_invoice_id, stripe_payment_intent_id, created_at FROM orders WHERE order_number = :n LIMIT 1");
    $stmt->execute(['n' => $storeOrder['order_number']]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        echo "  (no `orders` row — the webhook would have nowhere to mark paid)\n\n";
    } else {
        foreach ($o as $k => $v) {
            echo "  " . str_pad($k, 28) . " " . ($v ?? 'NULL') . "\n";
        }
        echo "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n\n";
}

// ── 4. Recent stripe_errors for this store_order ────────────────────────────
echo "--- 4. stripe_errors rows touching this store_order ---\n";
try {
    $stmt = $pdo->prepare("SELECT id, occurred_at, source, operation, error_message FROM stripe_errors WHERE related_store_order_id = :id OR context_json LIKE :ctx ORDER BY id DESC LIMIT 15");
    $stmt->execute(['id' => $storeOrderId, 'ctx' => '%"store_order_id":' . $storeOrderId . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "  (none)\n\n";
    } else {
        foreach ($rows as $r) {
            $msg = (string) ($r['error_message'] ?? '');
            if (strlen($msg) > 200) { $msg = substr($msg, 0, 200) . '...'; }
            echo "  #{$r['id']}  {$r['occurred_at']}  {$r['operation']}\n    {$msg}\n";
        }
        echo "\n";
    }
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n\n";
}

echo str_repeat('═', 78) . "\n";
echo "Done.\n";
