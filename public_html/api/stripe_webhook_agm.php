<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AgmWebhookService;
use App\Services\PaymentSettingsService;
use App\Services\PaymentWebhookService;
use App\Services\StripeService;
use App\Services\StripeSettingsService;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$channel = PaymentSettingsService::getChannelByCode('agm');
$channelId = (int) ($channel['id'] ?? 0);
$secret = StripeSettingsService::getWebhookSecret(StripeSettingsService::ACCOUNT_AGM);

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
    AgmWebhookService::handleEvent($event);
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
