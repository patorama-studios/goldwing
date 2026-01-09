<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\StripeService;
use App\Services\MembershipService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\PaymentSettingsService;
use App\Services\PaymentWebhookService;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!StripeService::verifyWebhook($payload, $signature)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!$event) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$isNew = PaymentWebhookService::recordEvent($event);
if (!$isNew) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$pdo = db();

try {
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];
        $orderId = isset($metadata['order_id']) ? (int) $metadata['order_id'] : 0;
        if ($orderId > 0) {
            $channel = PaymentSettingsService::getChannelByCode('primary');
            PaymentWebhookService::handleCheckoutCompleted($event, (int) ($channel['id'] ?? 0));
        } else {
            $periodId = isset($metadata['period_id']) ? (int) $metadata['period_id'] : 0;
            $memberId = isset($metadata['member_id']) ? (int) $metadata['member_id'] : 0;
            $amount = isset($session['amount_total']) ? ((int) $session['amount_total'] / 100) : 0;
            $customerId = $session['customer'] ?? null;

            if ($periodId) {
                MembershipService::markPaid($periodId, $session['payment_intent'] ?? '');
            }

            if ($memberId && $customerId) {
                $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :customer_id WHERE id = :id');
                $stmt->execute(['customer_id' => $customerId, 'id' => $memberId]);
            }

            $stmt = $pdo->prepare('INSERT INTO payments (member_id, type, description, amount, status, payment_method, order_source, order_reference, stripe_payment_id, created_at) VALUES (:member_id, :type, :description, :amount, :status, :payment_method, :order_source, :order_reference, :stripe_payment_id, NOW())');
            $stmt->execute([
                'member_id' => $memberId,
                'type' => 'membership',
                'description' => 'Membership payment',
                'amount' => $amount,
                'status' => 'PAID',
                'payment_method' => 'Stripe',
                'order_source' => 'Stripe',
                'order_reference' => $session['payment_intent'] ?? null,
                'stripe_payment_id' => $session['payment_intent'] ?? '',
            ]);

            if ($memberId) {
                AuditService::log(null, 'payment_received', 'Stripe payment received for member #' . $memberId . '.');
            }
        }
    }
    if (in_array($event['type'] ?? '', ['payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
        PaymentWebhookService::handlePaymentFailed($event);
    }

    PaymentWebhookService::markProcessed($event['id'] ?? '', 'processed', null);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    PaymentWebhookService::markProcessed($event['id'] ?? '', 'failed', $e->getMessage());
    http_response_code(500);
    echo 'Webhook error';
}
