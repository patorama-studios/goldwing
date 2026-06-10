<?php
// TEMPORARY — delete after use
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
$user = current_user();
if ((int)($user['id'] ?? 0) !== 1) { http_response_code(403); echo 'Forbidden'; exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== Recent paid membership orders (with stripe_payment_intent_id, so refundable) ===\n";
$rows = $pdo->query("
    SELECT id, order_number, member_id, status, payment_status, total,
           membership_period_id, stripe_payment_intent_id, created_at
    FROM orders
    WHERE order_type = 'membership'
      AND payment_status = 'accepted'
      AND stripe_payment_intent_id IS NOT NULL
      AND stripe_payment_intent_id <> ''
    ORDER BY id DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "#{$r['id']} {$r['order_number']} member={$r['member_id']} status={$r['status']}/{$r['payment_status']} total={$r['total']} period={$r['membership_period_id']} pi=" . substr((string)$r['stripe_payment_intent_id'], 0, 20) . " {$r['created_at']}\n";
}
if (!$rows) echo "(none — no paid membership orders found with a Stripe PI)\n";

echo "\n=== Any recent membership orders (any status) ===\n";
$rows2 = $pdo->query("
    SELECT id, order_number, member_id, status, payment_status, total, stripe_payment_intent_id, created_at
    FROM orders WHERE order_type = 'membership'
    ORDER BY id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) {
    echo "#{$r['id']} {$r['order_number']} status={$r['status']}/{$r['payment_status']} total={$r['total']} pi=" . (empty($r['stripe_payment_intent_id']) ? '(none)' : substr((string)$r['stripe_payment_intent_id'], 0, 20)) . " {$r['created_at']}\n";
}
