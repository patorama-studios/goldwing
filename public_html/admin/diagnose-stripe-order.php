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
$settings = StripeSettingsService::getSettings(StripeSettingsService::ACCOUNT_PRIMARY);
$mode = $settings['mode'] ?? 'unknown';
$secret = StripeSettingsService::getActiveSecretKey(StripeSettingsService::ACCOUNT_PRIMARY);
$secretPrefix = $secret !== '' ? substr($secret, 0, 8) . '...(' . strlen($secret) . ' chars)' : '(empty)';
echo "Mode:               {$mode}\n";
echo "Active secret key:  {$secretPrefix}\n";
echo "Account key:        " . ($settings['account_key'] ?? '?') . "\n";
echo "Webhook secret set: " . (StripeSettingsService::getWebhookSecret() !== '' ? 'yes' : 'NO') . "\n\n";

/* --------------------------------------------------------------------------
 * 2) Order row
 * ------------------------------------------------------------------------ */
echo "--- 2. orders row ---\n";
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
    echo "(no order found with that number)\n";
    exit;
}

foreach ($order as $k => $v) {
    if (in_array($k, ['shipping_address_json', 'admin_notes', 'internal_notes'], true)) continue;
    echo str_pad($k, 30) . ': ' . (is_null($v) ? '(null)' : (string) $v) . "\n";
}
echo "\n";

$sessionId     = $order['stripe_session_id'] ?? null;
$piId          = $order['stripe_payment_intent_id'] ?? null;
$chargeId      = $order['stripe_charge_id'] ?? null;
$customerEmail = $order['member_email'] ?? $order['user_email'] ?? null;
$stripeCustId  = $order['member_stripe_customer_id'] ?? null;

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

/* Search Stripe sessions by metadata.order_number */
echo "[search Stripe checkout sessions by metadata.order_number='{$orderNumber}']\n";
try {
    $found = $stripe->checkout->sessions->search([
        'query' => "metadata['order_number']:'{$orderNumber}'",
        'limit' => 10,
    ]);
    if (count($found->data) === 0) {
        echo "  (no checkout sessions found with that metadata)\n";
    } else {
        foreach ($found->data as $s) {
            echo "  session={$s->id} status={$s->status} payment_status={$s->payment_status} created=" . date('c', $s->created) . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

/* Search Stripe payment intents by metadata.order_number */
echo "[search Stripe payment intents by metadata.order_number='{$orderNumber}']\n";
try {
    $found = $stripe->paymentIntents->search([
        'query' => "metadata['order_number']:'{$orderNumber}'",
        'limit' => 10,
    ]);
    if (count($found->data) === 0) {
        echo "  (no payment intents found with that metadata)\n";
    } else {
        foreach ($found->data as $pi) {
            echo "  pi={$pi->id} status={$pi->status} amount={$pi->amount} created=" . date('c', $pi->created) . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

/* If we have a customer email, list recent sessions by customer */
if ($customerEmail) {
    echo "[recent checkout sessions on this Stripe account for {$customerEmail}]\n";
    try {
        $found = $stripe->checkout->sessions->search([
            'query' => "customer_details.email:'{$customerEmail}'",
            'limit' => 10,
        ]);
        if (count($found->data) === 0) {
            echo "  (no sessions found for this email on this Stripe account)\n";
        } else {
            foreach ($found->data as $s) {
                echo "  session={$s->id} status={$s->status} payment_status={$s->payment_status} amount_total={$s->amount_total} created=" . date('c', $s->created) . " metadata=" . json_encode($s->metadata) . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== End ===\n";
