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

echo "=== payment_channels ===\n";
try {
    $rows = $pdo->query('SELECT id, code, mode, last_webhook_status, last_webhook_at FROM payment_channels ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("#%d code=%s mode=%s last_status=%s last_at=%s\n",
            $r['id'], $r['code'], $r['mode'] ?? '-', $r['last_webhook_status'] ?? '(none)', $r['last_webhook_at'] ?? '(never)');
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

echo "\n=== last 5 store_orders ===\n";
try {
    $rows = $pdo->query('SELECT id, order_number, order_status, payment_status, total_cents, stripe_session_id, stripe_payment_intent_id, created_at FROM store_orders ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("#%d %s status=%s/%s total=%d session=%s pi=%s created=%s\n",
            $r['id'], $r['order_number'], $r['order_status'], $r['payment_status'] ?? '-',
            $r['total_cents'], substr((string)($r['stripe_session_id'] ?? ''), 0, 30),
            substr((string)($r['stripe_payment_intent_id'] ?? ''), 0, 30), $r['created_at']);
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
