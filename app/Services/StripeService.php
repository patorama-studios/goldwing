<?php
namespace App\Services;

require_once __DIR__ . '/../ThirdParty/stripe-php/init.php';

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use App\Services\PaymentSettingsService;

class StripeService
{
    private static function client(string $secretKey): StripeClient
    {
        return new StripeClient($secretKey);
    }

    public static function createCheckoutSession(string $priceId, string $customerEmail, array $metadata): ?array
    {
        $settings = PaymentSettingsService::getSettingsByChannelCode('primary');
        $secret = $settings['secret_key'] ?? '';
        if ($secret === '') {
            $secret = SettingsService::getGlobal('payments.stripe.secret_key', '');
        }
        if ($secret === '') {
            return null;
        }

        $successUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&success=1');
        $cancelUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&cancel=1');

        try {
            $session = self::client($secret)->checkout->sessions->create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $customerEmail,
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'metadata' => $metadata,
            ]);
        } catch (ApiErrorException $e) {
            return null;
        }

        return $session->toArray();
    }

    public static function createCheckoutSessionForPrice(string $priceId, string $customerEmail, string $successUrl, string $cancelUrl, array $metadata): ?array
    {
        $settings = PaymentSettingsService::getSettingsByChannelCode('primary');
        $secret = $settings['secret_key'] ?? '';
        if ($secret === '') {
            $secret = SettingsService::getGlobal('payments.stripe.secret_key', '');
        }
        if ($secret === '') {
            return null;
        }

        try {
            $session = self::client($secret)->checkout->sessions->create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $customerEmail,
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'metadata' => $metadata,
            ]);
        } catch (ApiErrorException $e) {
            return null;
        }

        return $session->toArray();
    }

    public static function createCheckoutSessionWithLineItems(array $lineItems, string $customerEmail, string $successUrl, string $cancelUrl, array $metadata = []): ?array
    {
        $settings = PaymentSettingsService::getSettingsByChannelCode('primary');
        $secret = $settings['secret_key'] ?? '';
        if ($secret === '') {
            $secret = SettingsService::getGlobal('payments.stripe.secret_key', '');
        }
        if ($secret === '') {
            return null;
        }

        $normalized = [];
        foreach ($lineItems as $item) {
            $normalized[] = [
                'price_data' => [
                    'currency' => $item['currency'] ?? 'aud',
                    'product_data' => [
                        'name' => $item['name'],
                    ],
                    'unit_amount' => $item['unit_amount'],
                ],
                'quantity' => $item['quantity'],
            ];
        }

        try {
            $session = self::client($secret)->checkout->sessions->create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $customerEmail,
                'payment_method_types' => ['card'],
                'line_items' => $normalized,
                'metadata' => $metadata,
            ]);
        } catch (ApiErrorException $e) {
            return null;
        }

        return $session->toArray();
    }

    public static function createCheckoutSessionForOrder(string $secretKey, array $payload): array
    {
        $session = self::client($secretKey)->checkout->sessions->create($payload);
        return $session->toArray();
    }

    public static function createCustomerPortalSession(string $secretKey, array $payload): array
    {
        $session = self::client($secretKey)->billingPortal->sessions->create($payload);
        return $session->toArray();
    }

    public static function createCustomer(string $secretKey, array $payload): array
    {
        $customer = self::client($secretKey)->customers->create($payload);
        return $customer->toArray();
    }

    public static function updateCustomer(string $secretKey, string $customerId, array $payload): array
    {
        $customer = self::client($secretKey)->customers->update($customerId, $payload);
        return $customer->toArray();
    }

    public static function createRefund(string $paymentIntentId, int $amountCents = 0): ?array
    {
        $settings = PaymentSettingsService::getSettingsByChannelCode('primary');
        $secret = $settings['secret_key'] ?? '';
        if ($secret === '') {
            $secret = SettingsService::getGlobal('payments.stripe.secret_key', '');
        }
        if ($secret === '') {
            return null;
        }

        $payload = ['payment_intent' => $paymentIntentId];
        if ($amountCents > 0) {
            $payload['amount'] = $amountCents;
        }

        try {
            $refund = self::client($secret)->refunds->create($payload);
        } catch (ApiErrorException $e) {
            return null;
        }

        return $refund->toArray();
    }

    public static function retrievePaymentIntent(string $paymentIntentId): ?array
    {
        $settings = PaymentSettingsService::getSettingsByChannelCode('primary');
        $secret = $settings['secret_key'] ?? '';
        if ($secret === '') {
            $secret = SettingsService::getGlobal('payments.stripe.secret_key', '');
        }
        if ($secret === '') {
            return null;
        }

        try {
            $intent = self::client($secret)->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            return null;
        }

        return $intent->toArray();
    }

    public static function createRefundWithParams(string $secretKey, array $payload): array
    {
        $refund = self::client($secretKey)->refunds->create($payload);
        return $refund->toArray();
    }

    public static function verifyWebhook(string $payload, string $signature): bool
    {
        $settings = PaymentSettingsService::getSettingsByChannelCode('primary');
        $secret = $settings['webhook_secret'] ?? '';
        if ($secret === '') {
            $secret = SettingsService::getGlobal('payments.stripe.webhook_secret', '');
        }
        if ($secret === '') {
            return false;
        }
        return self::constructEvent($payload, $signature, $secret) !== null;
    }

    public static function constructEvent(string $payload, string $signature, string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            return null;
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        return $event->toArray();
    }
}
