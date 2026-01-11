<?php
namespace App\Services;

class StripeSettingsService
{
    public const DEFAULT_CURRENCY = 'AUD';

    public static function getSettings(): array
    {
        $channel = PaymentSettingsService::getChannelByCode('primary');
        $legacy = PaymentSettingsService::getSettingsByChannelId((int) ($channel['id'] ?? 0));
        $legacyMode = (string) SettingsService::getGlobal('payments.stripe.mode', $legacy['mode'] ?? 'test');
        $legacyPublishable = (string) SettingsService::getGlobal('payments.stripe.publishable_key', $legacy['publishable_key'] ?? '');
        $legacySecret = (string) SettingsService::getGlobal('payments.stripe.secret_key', $legacy['secret_key'] ?? '');
        $legacyWebhook = (string) SettingsService::getGlobal('payments.stripe.webhook_secret', $legacy['webhook_secret'] ?? '');

        $useTestModeMeta = SettingsService::getGlobalMeta('payments.stripe.use_test_mode');
        $useTestMode = SettingsService::getGlobal('payments.stripe.use_test_mode', null);
        if ($useTestModeMeta['updated_at'] === null) {
            $useTestMode = $legacyMode !== 'live';
        }
        $useTestMode = (bool) $useTestMode;

        $testPublishable = (string) SettingsService::getGlobal('payments.stripe.test_publishable_key', '');
        $testSecret = (string) SettingsService::getGlobal('payments.stripe.test_secret_key', '');
        $livePublishable = (string) SettingsService::getGlobal('payments.stripe.live_publishable_key', '');
        $liveSecret = (string) SettingsService::getGlobal('payments.stripe.live_secret_key', '');

        if ($testPublishable === '') {
            $testPublishable = (string) Env::get('STRIPE_TEST_PUBLISHABLE_KEY', '');
        }
        if ($testSecret === '') {
            $testSecret = (string) Env::get('STRIPE_TEST_SECRET_KEY', '');
        }
        if ($livePublishable === '') {
            $livePublishable = (string) Env::get('STRIPE_LIVE_PUBLISHABLE_KEY', '');
        }
        if ($liveSecret === '') {
            $liveSecret = (string) Env::get('STRIPE_LIVE_SECRET_KEY', '');
        }

        if ($testPublishable === '' && $livePublishable === '' && $legacyPublishable !== '') {
            if ($legacyMode === 'live') {
                $livePublishable = $legacyPublishable;
            } else {
                $testPublishable = $legacyPublishable;
            }
        }
        if ($testSecret === '' && $liveSecret === '' && $legacySecret !== '') {
            if ($legacyMode === 'live') {
                $liveSecret = $legacySecret;
            } else {
                $testSecret = $legacySecret;
            }
        }

        $webhookSecret = (string) SettingsService::getGlobal('payments.stripe.webhook_secret', '');
        if ($webhookSecret === '') {
            $webhookSecret = (string) Env::get('STRIPE_WEBHOOK_SECRET', '');
        }
        if ($webhookSecret === '' && $legacyWebhook !== '') {
            $webhookSecret = $legacyWebhook;
        }

        $membershipPrices = SettingsService::getGlobal('payments.membership_prices', []);
        if (!is_array($membershipPrices)) {
            $membershipPrices = [];
        }

        return [
            'use_test_mode' => $useTestMode,
            'test_publishable_key' => $testPublishable,
            'test_secret_key' => $testSecret,
            'live_publishable_key' => $livePublishable,
            'live_secret_key' => $liveSecret,
            'webhook_secret' => $webhookSecret,
            'allow_guest_checkout' => (bool) SettingsService::getGlobal('payments.stripe.allow_guest_checkout', true),
            'require_shipping_for_physical' => (bool) SettingsService::getGlobal('payments.stripe.require_shipping_for_physical', true),
            'digital_only_minimal' => (bool) SettingsService::getGlobal('payments.stripe.digital_only_minimal', true),
            'enable_apple_pay' => (bool) SettingsService::getGlobal('payments.stripe.enable_apple_pay', true),
            'enable_google_pay' => (bool) SettingsService::getGlobal('payments.stripe.enable_google_pay', true),
            'enable_bnpl' => (bool) SettingsService::getGlobal('payments.stripe.enable_bnpl', false),
            'send_receipts' => (bool) SettingsService::getGlobal('payments.stripe.send_receipts', true),
            'save_invoice_refs' => (bool) SettingsService::getGlobal('payments.stripe.save_invoice_refs', true),
            'customer_portal_enabled' => (bool) SettingsService::getGlobal('payments.stripe.customer_portal_enabled', false),
            'checkout_enabled' => (bool) SettingsService::getGlobal('payments.stripe.checkout_enabled', true),
            'membership_prices' => $membershipPrices,
            'membership_default_term' => (string) SettingsService::getGlobal('payments.membership_default_term', '12M'),
            'membership_allow_both_types' => (bool) SettingsService::getGlobal('payments.membership_allow_both_types', true),
        ];
    }

    public static function getActiveKeys(): array
    {
        $settings = self::getSettings();
        $mode = $settings['use_test_mode'] ? 'test' : 'live';
        $publishable = $settings[$mode . '_publishable_key'] ?? '';
        $secret = $settings[$mode . '_secret_key'] ?? '';
        if ($mode === 'live' && $secret === '' && $settings['test_secret_key'] !== '') {
            $mode = 'test';
            $publishable = $settings['test_publishable_key'] ?? '';
            $secret = $settings['test_secret_key'] ?? '';
        }
        return [
            'mode' => $mode,
            'publishable_key' => $publishable,
            'secret_key' => $secret,
        ];
    }

    public static function getActiveMode(): string
    {
        return self::getActiveKeys()['mode'] ?? 'test';
    }

    public static function getActivePublishableKey(): string
    {
        return self::getActiveKeys()['publishable_key'] ?? '';
    }

    public static function getActiveSecretKey(): string
    {
        return self::getActiveKeys()['secret_key'] ?? '';
    }

    public static function getWebhookSecret(): string
    {
        $settings = self::getSettings();
        return $settings['webhook_secret'] ?? '';
    }

    public static function getPublicConfig(): array
    {
        $settings = self::getSettings();
        $active = self::getActiveKeys();

        return [
            'publishableKey' => $active['publishable_key'] ?? '',
            'mode' => $active['mode'] ?? 'test',
            'currency' => self::DEFAULT_CURRENCY,
            'paymentMethods' => [
                'card' => true,
                'applePay' => $settings['enable_apple_pay'],
                'googlePay' => $settings['enable_google_pay'],
                'bnpl' => $settings['enable_bnpl'],
                'link' => true,
            ],
            'allowGuestCheckout' => $settings['allow_guest_checkout'],
        ];
    }

    public static function webhookHealth(array $paymentSettings): array
    {
        $lastReceived = $paymentSettings['last_webhook_received_at'] ?? null;
        $lastError = $paymentSettings['last_webhook_error'] ?? null;
        $status = 'stale';
        if (!empty($lastError)) {
            $status = 'failing';
        } elseif ($lastReceived) {
            $receivedAt = strtotime((string) $lastReceived);
            if ($receivedAt && $receivedAt >= strtotime('-24 hours')) {
                $status = 'ok';
            }
        }
        return [
            'status' => $status,
            'last_received_at' => $lastReceived,
            'last_error' => $lastError,
        ];
    }

    public static function maskValue(string $value): array
    {
        $value = trim($value);
        $last4 = $value !== '' ? substr($value, -4) : '';
        return [
            'configured' => $value !== '',
            'last4' => $last4,
        ];
    }

    public static function saveAdminSettings(int $actorUserId, array $payload, array &$errors): void
    {
        $useTestMode = !empty($payload['stripe_use_test_mode']);
        $testPublishable = self::normalizeMaskedInput((string) ($payload['stripe_test_publishable_key'] ?? ''));
        $testSecret = self::normalizeMaskedInput((string) ($payload['stripe_test_secret_key'] ?? ''));
        $livePublishable = self::normalizeMaskedInput((string) ($payload['stripe_live_publishable_key'] ?? ''));
        $liveSecret = self::normalizeMaskedInput((string) ($payload['stripe_live_secret_key'] ?? ''));
        $webhookSecret = self::normalizeMaskedInput((string) ($payload['stripe_webhook_secret'] ?? ''));
        $invoicePrefix = trim((string) ($payload['stripe_invoice_prefix'] ?? 'INV'));
        $invoiceTemplate = trim((string) ($payload['stripe_invoice_email_template'] ?? ''));
        $generatePdf = !empty($payload['stripe_generate_pdf']) ? 1 : 0;
        $bankTransferInstructions = trim((string) ($payload['bank_transfer_instructions'] ?? ''));
        $defaultTerm = strtoupper(trim((string) ($payload['membership_default_term'] ?? '12M')));
        if (!in_array($defaultTerm, ['12M', '24M'], true)) {
            $defaultTerm = '12M';
        }

        self::validateKey($testPublishable, 'pk_test_', 'Test publishable key', $errors);
        self::validateKey($testSecret, 'sk_test_', 'Test secret key', $errors);
        self::validateKey($livePublishable, 'pk_live_', 'Live publishable key', $errors);
        self::validateKey($liveSecret, 'sk_live_', 'Live secret key', $errors);
        if ($webhookSecret !== '' && !str_starts_with($webhookSecret, 'whsec_')) {
            $errors[] = 'Webhook secret must start with whsec_.';
        }

        if ($errors) {
            return;
        }

        SettingsService::setGlobal($actorUserId, 'payments.stripe.use_test_mode', $useTestMode);
        if ($testPublishable !== '') {
            SettingsService::setGlobal($actorUserId, 'payments.stripe.test_publishable_key', $testPublishable);
        }
        if ($testSecret !== '') {
            SettingsService::setGlobal($actorUserId, 'payments.stripe.test_secret_key', $testSecret, ['encrypt' => true]);
        }
        if ($livePublishable !== '') {
            SettingsService::setGlobal($actorUserId, 'payments.stripe.live_publishable_key', $livePublishable);
        }
        if ($liveSecret !== '') {
            SettingsService::setGlobal($actorUserId, 'payments.stripe.live_secret_key', $liveSecret, ['encrypt' => true]);
        }
        if ($webhookSecret !== '') {
            SettingsService::setGlobal($actorUserId, 'payments.stripe.webhook_secret', $webhookSecret, ['encrypt' => true]);
        }

        SettingsService::setGlobal($actorUserId, 'payments.stripe.allow_guest_checkout', !empty($payload['stripe_allow_guest_checkout']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.require_shipping_for_physical', !empty($payload['stripe_require_shipping_for_physical']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.digital_only_minimal', !empty($payload['stripe_digital_only_minimal']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.enable_apple_pay', !empty($payload['stripe_enable_apple_pay']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.enable_google_pay', !empty($payload['stripe_enable_google_pay']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.enable_bnpl', !empty($payload['stripe_enable_bnpl']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.send_receipts', !empty($payload['stripe_send_receipts']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.save_invoice_refs', !empty($payload['stripe_save_invoice_refs']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.checkout_enabled', !empty($payload['stripe_checkout_enabled']));
        SettingsService::setGlobal($actorUserId, 'payments.stripe.customer_portal_enabled', !empty($payload['stripe_customer_portal_enabled']));
        SettingsService::setGlobal($actorUserId, 'payments.membership_default_term', $defaultTerm);
        SettingsService::setGlobal($actorUserId, 'payments.membership_allow_both_types', !empty($payload['membership_allow_both_types']));
        SettingsService::setGlobal($actorUserId, 'payments.bank_transfer_instructions', $bankTransferInstructions);

        $existingPrices = SettingsService::getGlobal('payments.membership_prices', []);
        if (!is_array($existingPrices)) {
            $existingPrices = [];
        }
        $prices = array_merge($existingPrices, [
            'FULL_12' => trim((string) ($payload['price_full_12'] ?? '')),
            'ASSOCIATE_12' => trim((string) ($payload['price_associate_12'] ?? '')),
            'FULL_24' => trim((string) ($payload['price_full_24'] ?? '')),
            'ASSOCIATE_24' => trim((string) ($payload['price_associate_24'] ?? '')),
            'FULL_1Y' => trim((string) ($payload['price_full_1y'] ?? '')),
            'FULL_3Y' => trim((string) ($payload['price_full_3y'] ?? '')),
            'ASSOCIATE_1Y' => trim((string) ($payload['price_associate_1y'] ?? '')),
            'ASSOCIATE_3Y' => trim((string) ($payload['price_associate_3y'] ?? '')),
            'LIFE' => trim((string) ($payload['price_life'] ?? '')),
        ]);
        SettingsService::setGlobal($actorUserId, 'payments.membership_prices', $prices);

        $channel = PaymentSettingsService::getChannelByCode('primary');
        PaymentSettingsService::updateSettings((int) $channel['id'], [
            'invoice_prefix' => $invoicePrefix !== '' ? $invoicePrefix : 'INV',
            'invoice_email_template' => $invoiceTemplate,
            'generate_pdf' => $generatePdf,
        ]);
        SettingsService::setGlobal($actorUserId, 'payments.stripe.generate_pdf', $generatePdf === 1);
        SettingsService::setGlobal($actorUserId, 'payments.stripe.invoice_prefix', $invoicePrefix !== '' ? $invoicePrefix : 'INV');
        SettingsService::setGlobal($actorUserId, 'payments.stripe.invoice_email_template', $invoiceTemplate);
    }

    private static function validateKey(string $value, string $prefix, string $label, array &$errors): void
    {
        if ($value !== '' && !str_starts_with($value, $prefix)) {
            $errors[] = $label . ' must start with ' . $prefix . '.';
        }
    }

    private static function normalizeMaskedInput(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '****')) {
            return '';
        }
        return $value;
    }
}
