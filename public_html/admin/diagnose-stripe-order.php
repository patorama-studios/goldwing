<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ThirdParty/stripe-php/init.php';

use App\Services\Database;
use App\Services\StripeSettingsService;
use Stripe\StripeClient;

require_permission('admin.members.view');

header('Content-Type: text/plain; charset=utf-8');

$orderNumber = isset($_GET['order']) ? trim((string) $_GET['order']) : 'M-2026-000012';

echo "=== Stripe Order Diagnostic ===\n";
echo "Order: {$orderNumber}\n";
echo "Time:  " . date('c') . "\n\n";

$pdo = Database::connection();

/* --------------------------------------------------------------------------
 * 1) Active Stripe account / mode
 * ------------------------------------------------------------------------ */
echo "--- 1. Active Stripe Account (primary) ---\n";
$active = StripeSettingsService::getActiveKeys(StripeSettingsService::ACCOUNT_PRIMARY);
$settings = StripeSettingsService::getSettings(StripeSettingsService::ACCOUNT_PRIMARY);
$secret = $active['secret_key'] ?? '';
$secretPrefix = $secret !== '' ? substr($secret, 0, 8) . '...(' . strlen($secret) . ' chars)' : '(empty)';
echo "Mode (resolved):    " . ($active['mode'] ?? '?') . "\n";
echo "use_test_mode flag: " . (!empty($settings['use_test_mode']) ? 'true' : 'false') . "\n";
echo "Active secret key:  {$secretPrefix}\n";
echo "Account key:        " . ($active['account_key'] ?? '?') . "\n";
echo "Webhook secret set: " . (StripeSettingsService::getWebhookSecret() !== '' ? 'yes' : 'NO') . "\n\n";

/* --------------------------------------------------------------------------
 * 2) Order row — check BOTH the unified `orders` table (membership + payment
 *    tracking) and `store_orders` (store fulfillment). A store purchase often
 *    has a row in both with the SAME order_number.
 * ------------------------------------------------------------------------ */
$order = null;     // orders table row
$storeOrder = null; // store_orders table row

echo "--- 2a. orders row ---\n";
$stmt = $pdo->prepare("
    SELECT o.*,
           u.email   AS user_email,
           u.name    AS user_name,
           m.email   AS member_email,
           m.first_name AS member_first_name,
           m.last_name  AS member_last_name,
           m.stripe_customer_id AS member_stripe_customer_id
    FROM orders o
    LEFT JOIN users   u ON u.id = o.user_id
    LEFT JOIN members m ON m.id = o.member_id
    WHERE o.order_number = :n
    LIMIT 1
");
$stmt->execute([':n' => $orderNumber]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    echo "(not in `orders` table)\n\n";
} else {
    foreach ($order as $k => $v) {
        if (in_array($k, ['shipping_address_json', 'admin_notes', 'internal_notes'], true)) continue;
        echo str_pad($k, 30) . ': ' . (is_null($v) ? '(null)' : (string) $v) . "\n";
    }
    echo "\n";
}

echo "--- 2b. store_orders row ---\n";
$stmt = $pdo->prepare("
    SELECT so.*,
           u.email   AS user_email,
           u.name    AS user_name,
           m.email   AS member_email,
           m.first_name AS member_first_name,
           m.last_name  AS member_last_name,
           m.stripe_customer_id AS member_stripe_customer_id
    FROM store_orders so
    LEFT JOIN users   u ON u.id = so.user_id
    LEFT JOIN members m ON m.id = so.member_id
    WHERE so.order_number = :n
    LIMIT 1
");
$stmt->execute([':n' => $orderNumber]);
$storeOrder = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$storeOrder) {
    echo "(not in `store_orders` table)\n\n";
} else {
    foreach ($storeOrder as $k => $v) {
        if (in_array($k, ['admin_notes'], true)) continue;
        echo str_pad($k, 30) . ': ' . (is_null($v) ? '(null)' : (string) $v) . "\n";
    }
    echo "\n";

    /* Also dump store line items */
    echo "--- 2c. store_order_items ---\n";
    try {
        $stmt = $pdo->prepare("SELECT id, product_id, product_title, variant_label, quantity, unit_price, line_total FROM store_order_items WHERE order_id = :id");
        $stmt->execute([':id' => $storeOrder['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            echo "(no items)\n";
        } else {
            foreach ($items as $it) echo "  " . json_encode($it, JSON_UNESCAPED_SLASHES) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

if (!$order && !$storeOrder) {
    echo "(no order found in either table with that number)\n";
    exit;
}

/* Prefer orders-table identifiers; fall back to store_orders if it's a
 * store order that wasn't dual-written. */
$src           = $order ?: $storeOrder;
$sessionId     = $src['stripe_session_id'] ?? null;
$piId          = $src['stripe_payment_intent_id'] ?? null;
$chargeId      = $src['stripe_charge_id'] ?? null;
$customerEmail = $src['member_email'] ?? $src['user_email'] ?? ($src['customer_email'] ?? null);
$stripeCustId  = $src['member_stripe_customer_id'] ?? null;

/* Cross-check: if both tables exist, do their Stripe IDs agree? */
if ($order && $storeOrder) {
    echo "--- 2d. orders ↔ store_orders cross-check ---\n";
    $mismatch = false;
    foreach (['stripe_session_id', 'stripe_payment_intent_id'] as $f) {
        $a = $order[$f] ?? null;
        $b = $storeOrder[$f] ?? null;
        if ((string)$a !== (string)$b) {
            echo "  MISMATCH {$f}: orders=" . ($a ?: '(null)') . "  store_orders=" . ($b ?: '(null)') . "\n";
            $mismatch = true;
        }
    }
    if (!$mismatch) echo "  Stripe IDs agree across both tables.\n";
    echo "  orders.status={$order['status']}  payment_status={$order['payment_status']}  paid_at=" . ($order['paid_at'] ?: '(null)') . "\n";
    echo "  store_orders.status={$storeOrder['status']}  paid_at=" . ($storeOrder['paid_at'] ?: '(null)') . "\n\n";
}

/* --------------------------------------------------------------------------
 * 3) payments rows linked by order_reference
 * ------------------------------------------------------------------------ */
echo "--- 3. payments rows ---\n";
$stmt = $pdo->prepare("SELECT id, type, description, amount, status, payment_method, order_source, order_reference, stripe_payment_id, created_at FROM payments WHERE order_reference = :n ORDER BY id DESC");
$stmt->execute([':n' => $orderNumber]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "(no payments rows reference this order)\n";
} else {
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
echo "\n";

/* --------------------------------------------------------------------------
 * 4) webhook_events that mention this order
 * ------------------------------------------------------------------------ */
echo "--- 4. webhook_events mentioning order/session/PI ---\n";
$needles = array_filter([$orderNumber, $sessionId, $piId, $chargeId]);
if (!$needles) {
    echo "(no Stripe identifiers on the order yet, so nothing to search for)\n";
} else {
    foreach ($needles as $needle) {
        $stmt = $pdo->prepare("SELECT id, stripe_event_id, type, processed_status, error, received_at FROM webhook_events WHERE payload_json LIKE :p ORDER BY id DESC LIMIT 20");
        $stmt->execute([':p' => '%' . $needle . '%']);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "search for: {$needle}\n";
        if (!$events) {
            echo "  (none)\n";
        } else {
            foreach ($events as $e) {
                echo "  [{$e['received_at']}] {$e['type']} status={$e['processed_status']} event={$e['stripe_event_id']}";
                if ($e['error']) echo "  ERROR=" . substr($e['error'], 0, 200);
                echo "\n";
            }
        }
    }
}
echo "\n";

/* --------------------------------------------------------------------------
 * 5) Talk to Stripe directly
 * ------------------------------------------------------------------------ */
echo "--- 5. Live Stripe API lookup ---\n";
if ($secret === '') {
    echo "(no Stripe secret configured, skipping)\n";
    exit;
}

$stripe = new StripeClient($secret);

if ($sessionId) {
    echo "[checkout.session.retrieve {$sessionId}]\n";
    try {
        $s = $stripe->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent', 'payment_intent.latest_charge']]);
        echo "  status:          {$s->status}\n";
        echo "  payment_status:  {$s->payment_status}\n";
        echo "  amount_total:    {$s->amount_total} {$s->currency}\n";
        echo "  customer_email:  " . ($s->customer_email ?? ($s->customer_details->email ?? '(n/a)')) . "\n";
        echo "  payment_intent:  " . (is_object($s->payment_intent) ? $s->payment_intent->id : (string) $s->payment_intent) . "\n";
        echo "  metadata:        " . json_encode($s->metadata) . "\n";
        echo "  created:         " . date('c', $s->created) . "\n";
        if (is_object($s->payment_intent)) {
            $pi = $s->payment_intent;
            echo "  PI status:       {$pi->status}\n";
            echo "  PI amount_received: {$pi->amount_received}\n";
            echo "  PI latest_charge: " . (is_object($pi->latest_charge) ? $pi->latest_charge->id : (string) $pi->latest_charge) . "\n";
            if (is_object($pi->latest_charge)) {
                echo "  charge paid:     " . ($pi->latest_charge->paid ? 'yes' : 'no') . "\n";
                echo "  charge status:   {$pi->latest_charge->status}\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
} else {
    echo "(order has no stripe_session_id)\n\n";
}

if ($piId) {
    echo "[payment_intent.retrieve {$piId}]\n";
    try {
        $pi = $stripe->paymentIntents->retrieve($piId, ['expand' => ['latest_charge']]);
        echo "  status:          {$pi->status}\n";
        echo "  amount:          {$pi->amount} ({$pi->currency})\n";
        echo "  amount_received: {$pi->amount_received}\n";
        echo "  metadata:        " . json_encode($pi->metadata) . "\n";
        echo "  created:         " . date('c', $pi->created) . "\n";
        echo "  latest_charge:   " . (is_object($pi->latest_charge) ? $pi->latest_charge->id : (string) $pi->latest_charge) . "\n";
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

/* List recent checkout sessions and filter locally by metadata.order_number
 * (older Stripe SDK in this repo doesn't support ->search()). */
echo "[recent checkout sessions on this Stripe account — filtering for metadata.order_number='{$orderNumber}' and email='{$customerEmail}']\n";
try {
    $sessions = $stripe->checkout->sessions->all(['limit' => 100]);
    $matches = 0;
    foreach ($sessions->data as $s) {
        $meta = is_object($s->metadata) ? $s->metadata->toArray() : (array) $s->metadata;
        $email = $s->customer_email ?? ($s->customer_details->email ?? '');
        $hitMeta  = isset($meta['order_number']) && $meta['order_number'] === $orderNumber;
        $hitOrder = isset($meta['order_id']) && (string) $meta['order_id'] === (string) ($src['id'] ?? '');
        $hitStoreOrder = isset($meta['store_order_id']) && $storeOrder && (string) $meta['store_order_id'] === (string) ($storeOrder['id'] ?? '');
        $hitEmail = $customerEmail && strcasecmp((string) $email, (string) $customerEmail) === 0;
        if ($hitMeta || $hitOrder || $hitStoreOrder || $hitEmail) {
            $matches++;
            echo "  session={$s->id} status={$s->status} payment_status={$s->payment_status} amount_total={$s->amount_total} email={$email} created=" . date('c', $s->created) . " metadata=" . json_encode($meta) . "\n";
        }
    }
    echo "  (scanned " . count($sessions->data) . " most-recent sessions, {$matches} matched)\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== End ===\n";
