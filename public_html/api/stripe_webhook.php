<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\StripeService;
use App\Services\StripeSettingsService;
use App\Services\PaymentSettingsService;
use App\Services\PaymentWebhookService;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$channel = PaymentSettingsService::getChannelByCode('primary');
$channelId = (int) ($channel['id'] ?? 0);
$secret = StripeSettingsService::getWebhookSecret();

$event = StripeService::constructEvent($payload, $signature, $secret);
if (!$event) {
    if ($channelId > 0) {
        PaymentSettingsService::updateWebhookStatus($channelId, 'Invalid signature');
    }
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$isNew = PaymentWebhookService::recordEvent($event);
if (!$isNew) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$eventId = $event['id'] ?? '';
try {
    $type = $event['type'] ?? '';
    if ($type === 'checkout.session.completed') {
        PaymentWebhookService::handleCheckoutCompleted($event, $channelId);
    }
    if ($type === 'payment_intent.succeeded') {
        PaymentWebhookService::handlePaymentIntentSucceeded($event);
    }
    if (in_array($type, ['payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
        PaymentWebhookService::handlePaymentFailed($event);
    }
    if ($type === 'charge.refunded') {
        PaymentWebhookService::handleChargeRefunded($event);
    }
    if ($type === 'invoice.paid') {
        PaymentWebhookService::handleInvoicePaid($event);
    }
    if ($type === 'invoice.payment_failed') {
        PaymentWebhookService::handleInvoicePaymentFailed($event);
    }
    if (in_array($type, ['customer.subscription.updated', 'customer.subscription.deleted'], true)) {
        PaymentWebhookService::handleSubscriptionUpdated($event);
    }
    PaymentWebhookService::markProcessed($eventId, 'processed', null);
    if ($channelId > 0) {
        PaymentSettingsService::updateWebhookStatus($channelId, null);
    }
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    PaymentWebhookService::markProcessed($eventId, 'failed', $e->getMessage());
    if ($channelId > 0) {
        PaymentSettingsService::updateWebhookStatus($channelId, $e->getMessage());
    }
    http_response_code(500);
    echo 'Webhook error';
}
