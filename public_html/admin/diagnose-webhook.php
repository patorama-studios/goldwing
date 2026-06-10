<?php
// TEMPORARY DIAGNOSTIC — DELETE AFTER USE
// Shows recent Stripe webhook events received by /api/stripe_webhook.php and
// the last error reported for each payment channel. Helps work out why store
// orders stay in PENDING (i.e. webhook never reached us, or signature failed,
// or our handler throws).
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
$user = current_user();
if (!$user || (int) ($user['id'] ?? 0) !== 1) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== payment_channels + settings_payments ===\n";
try {
    $rows = $pdo->query(
        'SELECT pc.id, pc.code, pc.label, pc.is_active, sp.mode, sp.last_webhook_received_at, sp.last_webhook_error
         FROM payment_channels pc
         LEFT JOIN settings_payments sp ON sp.channel_id = pc.id
         ORDER BY pc.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("#%d code=%s mode=%s active=%s last_wh=%s\n  last_err=%s\n",
            $r['id'], $r['code'], $r['mode'] ?? '(none)',
            $r['is_active'] ? 'yes' : 'no',
            $r['last_webhook_received_at'] ?? '(never)',
            $r['last_webhook_error'] ?? '(none)');
    }
    if (!$rows) echo "(no channels)\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== webhook_events (last 20) ===\n";
try {
    $rows = $pdo->query('SELECT id, stripe_event_id, type, processed_status, error, received_at FROM webhook_events ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("#%d %s type=%s status=%s received=%s\n  err=%s\n",
            $r['id'], $r['stripe_event_id'], $r['type'], $r['processed_status'], $r['received_at'],
            substr((string)($r['error'] ?? ''), 0, 300));
    }
    if (!$rows) echo "(no webhook events received — Stripe webhook has never reached this endpoint)\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== store_orders summary ===\n";
try {
    $rows = $pdo->query('SELECT order_status, payment_status, COUNT(*) c FROM store_orders GROUP BY order_status, payment_status ORDER BY c DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("  order_status=%s  payment_status=%s  count=%d\n", $r['order_status'] ?? '-', $r['payment_status'] ?? '-', $r['c']);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== last 10 store_orders ===\n";
try {
    $rows = $pdo->query('SELECT id, order_number, order_status, payment_status, fulfillment_status, total_amount, stripe_session_id, stripe_payment_intent_id, created_at FROM store_orders ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("#%d %s order=%s pay=%s fulfill=%s total=$%s\n  session=%s pi=%s created=%s\n",
            $r['id'], $r['order_number'],
            $r['order_status'], $r['payment_status'] ?? '-', $r['fulfillment_status'] ?? '-',
            $r['total_amount'],
            substr((string)($r['stripe_session_id'] ?? '(none)'), 0, 40),
            substr((string)($r['stripe_payment_intent_id'] ?? '(none)'), 0, 40),
            $r['created_at']);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== orders that have a Stripe session id but are still unpaid ===\n";
try {
    $rows = $pdo->query("SELECT id, order_number, payment_status, stripe_session_id, created_at FROM store_orders WHERE stripe_session_id IS NOT NULL AND stripe_session_id <> '' AND payment_status = 'unpaid' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "(none — every order with a Stripe session has been paid or was abandoned with no session)\n";
    foreach ($rows as $r) {
        echo sprintf("#%d %s pay=%s session=%s created=%s\n",
            $r['id'], $r['order_number'], $r['payment_status'],
            substr((string)$r['stripe_session_id'], 0, 40), $r['created_at']);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== webhook config sanity ===\n";
try {
    $stripeSettings = \App\Services\StripeSettingsService::getSettings();
    $pk = (string) ($stripeSettings['publishable_key'] ?? '');
    $sk = (string) ($stripeSettings['secret_key'] ?? '');
    $ws = \App\Services\StripeSettingsService::getWebhookSecret();
    echo "publishable_key prefix: " . substr($pk, 0, 10) . " (len " . strlen($pk) . ")\n";
    echo "secret_key prefix     : " . substr($sk, 0, 10) . " (len " . strlen($sk) . ")\n";
    echo "webhook_secret prefix : " . substr($ws, 0, 8) . " (len " . strlen($ws) . ")\n";
    if (strlen($ws) === 0) echo "  ⚠  webhook_secret IS BLANK — Stripe events will fail signature check.\n";
} catch (Throwable $e) {
    echo "ERROR reading Stripe settings: " . $e->getMessage() . "\n";
}
