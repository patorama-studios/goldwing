<?php
namespace App\Services;

require_once __DIR__ . '/../ThirdParty/stripe-php/init.php';

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use App\Services\PaymentSettingsService;
use App\Services\StripeSettingsService;

class StripeService
{
    private static function client(string $secretKey): StripeClient
    {
        return new StripeClient($secretKey);
    }

    private static function activeSecretKey(?string $accountKey = null): string
    {
        return StripeSettingsService::getActiveSecretKey($accountKey);
    }

    public static function createCheckoutSession(string $priceId, string $customerEmail, array $metadata): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }

        // Land on the dashboard with ?renewed=1 so the thank-you lightbox
        // + confetti fires. The /member/index.php?page=billing&success=1
        // path showed only a flat banner.
        $successUrl = BaseUrlService::buildUrl('/member/?renewed=1');
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
            StripeErrorLogger::log(__METHOD__, 'checkout_session.create', $e, [
                'account_key' => null,
                'price_id' => $priceId,
                'customer_email' => $customerEmail,
                'metadata' => $metadata,
            ]);
            return null;
        }

        return $session->toArray();
    }

    public static function createCheckoutSessionForPrice(string $priceId, string $customerEmail, string $successUrl, string $cancelUrl, array $metadata): ?array
    {
        $secret = self::activeSecretKey();
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
            StripeErrorLogger::log(__METHOD__, 'checkout_session.create', $e, [
                'account_key' => null,
                'price_id' => $priceId,
                'customer_email' => $customerEmail,
                'metadata' => $metadata,
            ]);
            return null;
        }

        return $session->toArray();
    }

    public static function createCheckoutSessionWithLineItems(array $lineItems, string $customerEmail, string $successUrl, string $cancelUrl, array $metadata = [], ?string $accountKey = null): ?array
    {
        $secret = self::activeSecretKey($accountKey);
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
            StripeErrorLogger::log(__METHOD__, 'checkout_session.create', $e, [
                'account_key' => $accountKey,
                'customer_email' => $customerEmail,
                'line_item_count' => count($normalized),
                'metadata' => $metadata,
            ]);
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

    public static function retrieveAccount(string $secretKey): ?array
    {
        try {
            $account = self::client($secretKey)->accounts->retrieve();
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'account.retrieve', $e, []);
            return null;
        }
        return $account->toArray();
    }

    public static function updateCustomer(string $secretKey, string $customerId, array $payload): array
    {
        $customer = self::client($secretKey)->customers->update($customerId, $payload);
        return $customer->toArray();
    }

    public static function createRefund(string $paymentIntentId, int $amountCents = 0, ?string $accountKey = null): ?array
    {
        $secret = self::activeSecretKey($accountKey);
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
            StripeErrorLogger::log(__METHOD__, 'refund.create', $e, [
                'account_key' => $accountKey,
                'related_stripe_pi_id' => $paymentIntentId,
                'amount_cents' => $amountCents,
            ]);
            return null;
        }

        return $refund->toArray();
    }

    public static function retrievePaymentIntent(string $paymentIntentId, ?string $accountKey = null): ?array
    {
        $secret = self::activeSecretKey($accountKey);
        if ($secret === '') {
            return null;
        }

        try {
            $intent = self::client($secret)->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'payment_intent.retrieve', $e, [
                'account_key' => $accountKey,
                'related_stripe_pi_id' => $paymentIntentId,
            ]);
            return null;
        }

        return $intent->toArray();
    }

    public static function createRefundWithParams(string $secretKey, array $payload): array
    {
        $refund = self::client($secretKey)->refunds->create($payload);
        return $refund->toArray();
    }

    public static function verifyWebhook(string $payload, string $signature, ?string $accountKey = null): bool
    {
        $secret = StripeSettingsService::getWebhookSecret($accountKey);
        if ($secret === '') {
            return false;
        }
        return self::constructEvent($payload, $signature, $secret) !== null;
    }

    public static function createPaymentIntent(array $payload, ?string $idempotencyKey = null): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        $options = [];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options['idempotency_key'] = $idempotencyKey;
        }
        try {
            $intent = self::client($secret)->paymentIntents->create($payload, $options);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'payment_intent.create', $e, [
                'account_key' => null,
                'amount' => $payload['amount'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'customer' => $payload['customer'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
            return null;
        }
        return $intent->toArray();
    }

    public static function createSubscription(array $payload): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $subscription = self::client($secret)->subscriptions->create($payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'subscription.create', $e, [
                'account_key' => null,
                'customer' => $payload['customer'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);
            return null;
        }
        return $subscription->toArray();
    }

    public static function findCustomerByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $results = self::client($secret)->customers->search([
                'query' => "email:'" . addslashes($email) . "'",
                'limit' => 1,
            ]);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'customer.search', $e, [
                'account_key' => null,
                'customer_email' => $email,
            ]);
            return null;
        }
        $data = $results->data ?? [];
        if (!$data) {
            return null;
        }
        return $data[0]->toArray();
    }

    public static function retrieveCustomer(string $secretKey, string $customerId): ?array
    {
        try {
            $customer = self::client($secretKey)->customers->retrieve($customerId);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'customer.retrieve', $e, [
                'customer' => $customerId,
            ]);
            return null;
        }
        return $customer->toArray();
    }

    public static function createSetupIntent(string $secretKey, array $payload): ?array
    {
        try {
            $intent = self::client($secretKey)->setupIntents->create($payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'setup_intent.create', $e, [
                'customer' => $payload['customer'] ?? null,
            ]);
            return null;
        }
        return $intent->toArray();
    }

    public static function listPaymentMethods(string $secretKey, string $customerId, string $type = 'card'): array
    {
        try {
            $methods = self::client($secretKey)->customers->allPaymentMethods($customerId, [
                'type' => $type,
                'limit' => 20,
            ]);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'payment_method.list', $e, [
                'customer' => $customerId,
            ]);
            return [];
        }
        $out = [];
        foreach (($methods->data ?? []) as $pm) {
            $out[] = $pm->toArray();
        }
        return $out;
    }

    public static function detachPaymentMethod(string $secretKey, string $paymentMethodId): bool
    {
        try {
            self::client($secretKey)->paymentMethods->detach($paymentMethodId);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'payment_method.detach', $e, [
                'payment_method' => $paymentMethodId,
            ]);
            return false;
        }
        return true;
    }

    /* -----------------------------------------------------------------
     * Product / Invoice helpers — used by StoreInvoiceService to mirror
     * store products into Stripe's catalog and bill orders via Stripe
     * Invoices (which natively show itemized line items in the dashboard).
     * ----------------------------------------------------------------- */

    public static function createProduct(array $payload): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $product = self::client($secret)->products->create($payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'product.create', $e, [
                'name' => $payload['name'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);
            return null;
        }
        return $product->toArray();
    }

    public static function updateProduct(string $productId, array $payload): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $product = self::client($secret)->products->update($productId, $payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'product.update', $e, [
                'product_id' => $productId,
                'metadata' => $payload['metadata'] ?? null,
            ]);
            return null;
        }
        return $product->toArray();
    }

    public static function retrieveCustomerSimple(string $customerId): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '' || $customerId === '') {
            return null;
        }
        return self::retrieveCustomer($secret, $customerId);
    }

    public static function createCustomerSimple(array $payload): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $customer = self::client($secret)->customers->create($payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'customer.create', $e, [
                'email' => $payload['email'] ?? null,
            ]);
            return null;
        }
        return $customer->toArray();
    }

    public static function createInvoice(array $payload, ?string $idempotencyKey = null): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        $options = [];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $options['idempotency_key'] = $idempotencyKey;
        }
        try {
            $invoice = self::client($secret)->invoices->create($payload, $options);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'invoice.create', $e, [
                'customer' => $payload['customer'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
            return null;
        }
        return $invoice->toArray();
    }

    public static function createInvoiceItem(array $payload): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $item = self::client($secret)->invoiceItems->create($payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'invoice_item.create', $e, [
                'customer' => $payload['customer'] ?? null,
                'invoice' => $payload['invoice'] ?? null,
                'amount' => $payload['amount'] ?? null,
                'description' => $payload['description'] ?? null,
            ]);
            return null;
        }
        return $item->toArray();
    }

    public static function finalizeInvoice(string $invoiceId, array $payload = []): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $invoice = self::client($secret)->invoices->finalizeInvoice($invoiceId, $payload);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'invoice.finalize', $e, [
                'invoice_id' => $invoiceId,
            ]);
            return null;
        }
        return $invoice->toArray();
    }

    /**
     * Mark a finalized invoice as paid WITHOUT charging (the money was collected
     * elsewhere). Used by the historical-membership backfill so past orders show
     * as itemized paid invoices in Stripe without re-billing the member.
     */
    public static function payInvoiceOutOfBand(string $invoiceId): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $invoice = self::client($secret)->invoices->pay($invoiceId, ['paid_out_of_band' => true]);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'invoice.pay_out_of_band', $e, [
                'invoice_id' => $invoiceId,
            ]);
            return null;
        }
        return $invoice->toArray();
    }

    public static function retrieveCheckoutSession(string $sessionId, array $expand = []): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        $options = $expand ? ['expand' => $expand] : [];
        try {
            $session = self::client($secret)->checkout->sessions->retrieve($sessionId, $options);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'checkout.session.retrieve', $e, [
                'session_id' => $sessionId,
            ]);
            return null;
        }
        return $session->toArray();
    }

    public static function retrieveSubscription(string $subscriptionId, array $expand = []): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        $options = $expand ? ['expand' => $expand] : [];
        try {
            $subscription = self::client($secret)->subscriptions->retrieve($subscriptionId, $options);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'subscription.retrieve', $e, [
                'subscription_id' => $subscriptionId,
            ]);
            return null;
        }
        return $subscription->toArray();
    }

    public static function retrieveInvoice(string $invoiceId, array $expand = []): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        $options = [];
        if ($expand) {
            $options['expand'] = $expand;
        }
        try {
            $invoice = self::client($secret)->invoices->retrieve($invoiceId, $options);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'invoice.retrieve', $e, [
                'invoice_id' => $invoiceId,
            ]);
            return null;
        }
        return $invoice->toArray();
    }

    public static function voidInvoice(string $invoiceId): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $invoice = self::client($secret)->invoices->voidInvoice($invoiceId);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'invoice.void', $e, [
                'invoice_id' => $invoiceId,
            ]);
            return null;
        }
        return $invoice->toArray();
    }

    public static function retrievePaymentIntentSimple(string $paymentIntentId): ?array
    {
        $secret = self::activeSecretKey();
        if ($secret === '') {
            return null;
        }
        try {
            $intent = self::client($secret)->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            StripeErrorLogger::log(__METHOD__, 'payment_intent.retrieve.simple', $e, [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return null;
        }
        return $intent->toArray();
    }

    public static function constructEvent(string $payload, string $signature, string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            StripeErrorLogger::log(__METHOD__, 'webhook.signature_invalid', $e, [
                'payload_bytes' => strlen($payload),
                'signature_present' => $signature !== '',
            ]);
            return null;
        } catch (\UnexpectedValueException $e) {
            StripeErrorLogger::log(__METHOD__, 'webhook.payload_invalid', $e, [
                'payload_bytes' => strlen($payload),
            ]);
            return null;
        }
        return $event->toArray();
    }
}
