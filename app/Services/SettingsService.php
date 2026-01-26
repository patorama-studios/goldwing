<?php
namespace App\Services;

use PDO;

class SettingsService
{
    private static array $globalCache = [];
    private static bool $globalLoaded = false;

    public static function getGlobal(string $key, $default = null)
    {
        [$category, $name] = self::splitKey($key);
        $row = self::getGlobalRow($category, $name);
        if (!$row) {
            return $default;
        }
        $decoded = self::decodeValue($row['value_json'] ?? '');
        return $decoded !== null ? $decoded : $default;
    }

    public static function getGlobalMeta(string $key): array
    {
        [$category, $name] = self::splitKey($key);
        $row = self::getGlobalRow($category, $name);
        if (!$row) {
            return [
                'updated_by_user_id' => null,
                'updated_at' => null,
            ];
        }
        return [
            'updated_by_user_id' => $row['updated_by_user_id'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function getGlobalCategory(string $category): array
    {
        self::loadGlobals();
        return self::$globalCache[$category] ?? [];
    }

    public static function setGlobal(int $actorUserId, string $key, $value, array $options = []): void
    {
        [$category, $name] = self::splitKey($key);
        $pdo = Database::connection();
        $currentRow = self::getGlobalRow($category, $name);
        $encoded = self::encodeValue($value, $options);

        if ($currentRow) {
            if ($currentRow['value_json'] === $encoded) {
                return;
            }
            $stmt = $pdo->prepare('UPDATE settings_global SET value_json = :value_json, updated_by_user_id = :user_id, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'value_json' => $encoded,
                'user_id' => $actorUserId,
                'id' => (int) $currentRow['id'],
            ]);
            self::writeAudit($actorUserId, 'settings.update', 'settings_global', (int) $currentRow['id'], $currentRow['value_json'], $encoded);
        } else {
            $stmt = $pdo->prepare('INSERT INTO settings_global (category, key_name, value_json, updated_by_user_id, updated_at) VALUES (:category, :key_name, :value_json, :user_id, NOW())');
            $stmt->execute([
                'category' => $category,
                'key_name' => $name,
                'value_json' => $encoded,
                'user_id' => $actorUserId,
            ]);
            $newId = (int) $pdo->lastInsertId();
            self::writeAudit($actorUserId, 'settings.create', 'settings_global', $newId, null, $encoded);
        }
        self::$globalLoaded = false;
    }

    public static function getUser(int $userId, string $key, $default = null)
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT value_json FROM settings_user WHERE user_id = :user_id AND key_name = :key LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'key' => $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $default;
        }
        $decoded = self::decodeValue($row['value_json'] ?? '');
        return $decoded !== null ? $decoded : $default;
    }

    public static function setUser(int $userId, string $key, $value, array $options = []): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, value_json FROM settings_user WHERE user_id = :user_id AND key_name = :key LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'key' => $key]);
        $row = $stmt->fetch();
        $encoded = self::encodeValue($value, $options);
        if ($row) {
            if ($row['value_json'] === $encoded) {
                return;
            }
            $stmt = $pdo->prepare('UPDATE settings_user SET value_json = :value_json, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['value_json' => $encoded, 'id' => (int) $row['id']]);
            self::writeAudit($userId, 'settings.update', 'settings_user', (int) $row['id'], $row['value_json'], $encoded);
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO settings_user (user_id, key_name, value_json, updated_at) VALUES (:user_id, :key, :value_json, NOW())');
        $stmt->execute(['user_id' => $userId, 'key' => $key, 'value_json' => $encoded]);
        $newId = (int) $pdo->lastInsertId();
        self::writeAudit($userId, 'settings.create', 'settings_user', $newId, null, $encoded);
    }

    public static function ensureDefaults(int $actorUserId = 0): void
    {
        $defaults = self::defaults();
        foreach ($defaults as $category => $pairs) {
            foreach ($pairs as $name => $value) {
                $key = $category . '.' . $name;
                if (self::getGlobalRow($category, $name) !== null) {
                    continue;
                }
                self::setGlobal($actorUserId, $key, $value);
            }
        }
    }

    public static function migrateLegacy(int $actorUserId = 0): void
    {
        $pdo = Database::connection();
        $store = $pdo->query('SELECT * FROM store_settings ORDER BY id ASC LIMIT 1')->fetch();
        if ($store) {
            $map = [
                'store.name' => $store['store_name'] ?? null,
                'store.slug' => $store['store_slug'] ?? null,
                'store.members_only' => (int) ($store['members_only'] ?? 0) === 1,
                'store.notification_emails' => $store['notification_emails'] ?? '',
                'store.pass_stripe_fees' => (int) ($store['stripe_fee_enabled'] ?? 0) === 1,
                'store.stripe_fee_percent' => (float) ($store['stripe_fee_percent'] ?? 0),
                'store.stripe_fee_fixed' => (float) ($store['stripe_fee_fixed'] ?? 0),
                'store.shipping_flat_enabled' => (int) ($store['shipping_flat_enabled'] ?? 0) === 1,
                'store.shipping_flat_rate' => $store['shipping_flat_rate'] ?? null,
                'store.shipping_free_enabled' => (int) ($store['shipping_free_enabled'] ?? 0) === 1,
                'store.shipping_free_threshold' => $store['shipping_free_threshold'] ?? null,
                'store.pickup_enabled' => (int) ($store['pickup_enabled'] ?? 0) === 1,
                'store.pickup_instructions' => $store['pickup_instructions'] ?? '',
                'store.email_logo_url' => $store['email_logo_url'] ?? '',
                'store.email_footer_text' => $store['email_footer_text'] ?? '',
                'store.support_email' => $store['support_email'] ?? '',
            ];
            foreach ($map as $key => $value) {
                [$category, $name] = self::splitKey($key);
                if (self::getGlobalRow($category, $name) === null) {
                    self::setGlobal($actorUserId, $key, $value);
                }
            }
        }

        $channelRow = $pdo->query("SELECT id FROM payment_channels WHERE code = 'primary' LIMIT 1")->fetch();
        $channelId = $channelRow['id'] ?? null;
        $paymentRow = null;
        if ($channelId) {
            $payment = $pdo->prepare('SELECT * FROM settings_payments WHERE channel_id = :channel_id LIMIT 1');
            $payment->execute(['channel_id' => $channelId]);
            $paymentRow = $payment->fetch();
        }
        if ($paymentRow) {
            $map = [
                'payments.stripe.mode' => $paymentRow['mode'] ?? 'test',
                'payments.stripe.publishable_key' => $paymentRow['publishable_key'] ?? '',
                'payments.stripe.secret_key' => CryptoService::decrypt($paymentRow['secret_key_encrypted'] ?? ''),
                'payments.stripe.webhook_secret' => CryptoService::decrypt($paymentRow['webhook_secret_encrypted'] ?? ''),
                'payments.stripe.invoice_prefix' => $paymentRow['invoice_prefix'] ?? 'INV',
                'payments.stripe.invoice_email_template' => $paymentRow['invoice_email_template'] ?? '',
                'payments.stripe.generate_pdf' => (int) ($paymentRow['generate_pdf'] ?? 0) === 1,
                'payments.stripe.webhook_last_received_at' => $paymentRow['last_webhook_received_at'] ?? null,
                'payments.stripe.webhook_last_error' => $paymentRow['last_webhook_error'] ?? null,
            ];
            foreach ($map as $key => $value) {
                [$category, $name] = self::splitKey($key);
                if (self::getGlobalRow($category, $name) === null) {
                    $options = [];
                    if (in_array($key, ['payments.stripe.secret_key', 'payments.stripe.webhook_secret'], true)) {
                        $options['encrypt'] = true;
                    }
                    self::setGlobal($actorUserId, $key, $value, $options);
                }
            }
        }

        $legacyPublishable = (string) self::getGlobal('payments.stripe.publishable_key', '');
        $legacySecret = (string) self::getGlobal('payments.stripe.secret_key', '');
        $legacyWebhook = (string) self::getGlobal('payments.stripe.webhook_secret', '');
        $legacyInvoicePrefix = (string) self::getGlobal('payments.stripe.invoice_prefix', 'INV');
        $legacyInvoiceTemplate = (string) self::getGlobal('payments.stripe.invoice_email_template', '');
        $legacyGeneratePdf = self::getGlobal('payments.stripe.generate_pdf', true) ? 1 : 0;
        $hasLegacy = $legacyPublishable !== '' || $legacySecret !== '' || $legacyWebhook !== '';

        if ($channelId && $hasLegacy) {
            $needsSync = !$paymentRow
                || (($paymentRow['publishable_key'] ?? '') === ''
                    && ($paymentRow['secret_key_encrypted'] ?? '') === ''
                    && ($paymentRow['webhook_secret_encrypted'] ?? '') === '');
            if ($needsSync) {
                PaymentSettingsService::updateSettings((int) $channelId, [
                    'publishable_key' => $legacyPublishable,
                    'secret_key' => $legacySecret,
                    'webhook_secret' => $legacyWebhook,
                    'invoice_prefix' => $legacyInvoicePrefix,
                    'invoice_email_template' => $legacyInvoiceTemplate,
                    'generate_pdf' => $legacyGeneratePdf,
                ]);
            }
        }

        $appConfig = require __DIR__ . '/../../config/app.php';
        $siteDefaults = [
            'site.name' => $appConfig['app_name'] ?? 'Australian Goldwing Association',
            'notifications.from_email' => $appConfig['email']['from'] ?? '',
            'notifications.from_name' => $appConfig['email']['from_name'] ?? '',
        ];
        foreach ($siteDefaults as $key => $value) {
            [$category, $name] = self::splitKey($key);
            if (self::getGlobalRow($category, $name) === null) {
                self::setGlobal($actorUserId, $key, $value);
            }
        }

        $membershipPrices = $appConfig['stripe']['membership_prices'] ?? [];
        if ($membershipPrices) {
            [$category, $name] = self::splitKey('payments.membership_prices');
            if (self::getGlobalRow($category, $name) === null) {
                self::setGlobal($actorUserId, 'payments.membership_prices', $membershipPrices);
            }
        }
    }

    public static function getMaskedSecret(string $key): array
    {
        $value = (string) self::getGlobal($key, '');
        $last4 = $value !== '' ? substr($value, -4) : '';
        return [
            'configured' => $value !== '',
            'last4' => $last4,
        ];
    }

    public static function isFeatureEnabled(string $flag): bool
    {
        $flags = self::getGlobal('advanced.feature_flags', []);
        if (is_array($flags) && array_key_exists($flag, $flags)) {
            return (bool) $flags[$flag];
        }
        return false;
    }

    private static function loadGlobals(): void
    {
        if (self::$globalLoaded) {
            return;
        }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->query('SELECT * FROM settings_global');
        } catch (\PDOException $e) {
            self::$globalCache = [];
            self::$globalLoaded = true;
            return;
        }
        self::$globalCache = [];
        foreach ($stmt->fetchAll() as $row) {
            $category = $row['category'];
            $name = $row['key_name'];
            if (!isset(self::$globalCache[$category])) {
                self::$globalCache[$category] = [];
            }
            self::$globalCache[$category][$name] = $row;
        }
        self::$globalLoaded = true;
    }

    private static function getGlobalRow(string $category, string $name): ?array
    {
        self::loadGlobals();
        return self::$globalCache[$category][$name] ?? null;
    }

    private static function splitKey(string $key): array
    {
        $parts = explode('.', $key, 2);
        if (count($parts) === 1) {
            return ['general', $parts[0]];
        }
        return [$parts[0], $parts[1]];
    }

    private static function encodeValue($value, array $options = []): string
    {
        if (!empty($options['encrypt'])) {
            $encrypted = CryptoService::encrypt((string) $value);
            if ($encrypted === null) {
                return json_encode($value, JSON_UNESCAPED_SLASHES);
            }
            $payload = [
                'encrypted' => true,
                'value' => $encrypted ?? '',
            ];
            return json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private static function decodeValue(string $valueJson)
    {
        if ($valueJson === '') {
            return null;
        }
        $decoded = json_decode($valueJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        if (is_array($decoded) && !empty($decoded['encrypted'])) {
            return CryptoService::decrypt($decoded['value'] ?? '');
        }
        return $decoded;
    }

    private static function writeAudit(?int $actorUserId, string $action, string $entityType, ?int $entityId, ?string $before, ?string $after): void
    {
        $pdo = Database::connection();
        $diff = [
            'before' => $before ? json_decode($before, true) : null,
            'after' => $after ? json_decode($after, true) : null,
        ];
        $stmt = $pdo->prepare('INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, diff_json, ip_address, user_agent, created_at) VALUES (:actor_user_id, :action, :entity_type, :entity_id, :diff_json, :ip_address, :user_agent, NOW())');
        $stmt->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'diff_json' => json_encode($diff, JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    private static function defaults(): array
    {
        return [
            'site' => [
                'name' => 'Australian Goldwing Association',
                'tagline' => 'Touring riders and community',
                'logo_url' => '',
                'favicon_url' => '',
                'timezone' => 'Australia/Sydney',
                'contact_email' => '',
                'contact_phone' => '',
                'social_links' => [
                    'facebook' => '',
                    'instagram' => '',
                    'youtube' => '',
                    'tiktok' => '',
                ],
                'legal_urls' => [
                    'privacy' => '',
                    'terms' => '',
                ],
                'show_nav' => true,
                'show_footer' => true,
                'base_url' => Env::get('APP_BASE_URL', ''),
            ],
            'store' => [
                'name' => 'Australian Goldwing Association Store',
                'slug' => 'store',
                'members_only' => true,
                'shipping_region' => 'AU',
                'gst_enabled' => true,
                'pass_stripe_fees' => true,
                'stripe_fee_percent' => 0.0,
                'stripe_fee_fixed' => 0.0,
                'shipping_flat_enabled' => false,
                'shipping_flat_rate' => null,
                'shipping_free_enabled' => false,
                'shipping_free_threshold' => null,
                'pickup_enabled' => false,
                'pickup_instructions' => 'Pickup from Canberra -- we will email instructions.',
                'notification_emails' => '',
                'email_logo_url' => '',
                'email_footer_text' => 'Thanks for supporting the Australian Goldwing Association.',
                'support_email' => '',
                'order_paid_status' => 'paid',
            ],
            'payments' => [
                'stripe.mode' => 'test',
                'stripe.publishable_key' => '',
                'stripe.secret_key' => '',
                'stripe.webhook_secret' => '',
                'stripe.checkout_enabled' => true,
                'stripe.customer_portal_enabled' => false,
                'stripe.generate_pdf' => false,
                'stripe.invoice_prefix' => 'INV',
                'stripe.invoice_email_template' => '',
                'bank_transfer_instructions' => '',
                'membership_prices' => [
                    'FULL_1Y' => '',
                    'FULL_3Y' => '',
                    'ASSOCIATE_1Y' => '',
                    'ASSOCIATE_3Y' => '',
                    'LIFE' => '',
                ],
            ],
            'notifications' => [
                'enabled' => true,
                'from_name' => 'Australian Goldwing Association',
                'from_email' => 'no-reply@goldwing.org.au',
                'reply_to' => 'webmaster@goldwing.org.au',
                'admin_emails' => '',
                'weekly_digest_enabled' => false,
                'event_reminders_enabled' => true,
                'in_app_categories' => ['events', 'payments', 'store', 'community'],
                'template_basic' => '<p>{{body}}</p>',
                'catalog' => NotificationService::defaultCatalog(),
            ],
            'accounts' => [
                'user_approval_required' => true,
                'membership_status_visibility' => 'member',
                'audit_role_changes' => true,
            ],
            'security' => [
                'force_https' => false,
                'password_min_length' => 12,
                'rate_limit_attempts' => 5,
                'rate_limit_window_minutes' => 15,
                'two_factor_enabled' => false,
            ],
            'integrations' => [
                'email_provider' => 'php_mail',
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_user' => '',
                'smtp_password' => '',
                'smtp_encryption' => 'tls',
                'youtube_embeds_enabled' => true,
                'vimeo_embeds_enabled' => true,
                'zoom_default_url' => '',
                'myob_enabled' => false,
            ],
            'media' => [
                'allowed_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                'max_upload_mb' => 10,
                'storage_limit_mb' => 5120,
                'image_optimization_enabled' => false,
                'folder_taxonomy' => [],
                'privacy_default' => 'member',
            ],
            'events' => [
                'rsvp_default_enabled' => true,
                'visibility_default' => 'member',
                'public_ticketing_enabled' => false,
                'timezone' => 'Australia/Sydney',
                'include_map_link' => true,
                'include_zoom_link' => true,
            ],
            'membership' => [
                'pricing_matrix' => [
                    'currency' => 'AUD',
                    'rows' => MembershipPricingService::defaultPricingRows(),
                ],
                'member_number_start' => 1000,
                'associate_suffix_start' => 1,
                'member_number_format_full' => '{base}',
                'member_number_format_associate' => '{base}.{suffix}',
                'member_number_base_padding' => 0,
                'member_number_suffix_padding' => 0,
                'manual_migration_enabled' => true,
                'manual_migration_expiry_days' => 14,
                'order_prefix' => 'M',
            ],
            'advanced' => [
                'maintenance_mode' => false,
                'disable_password_reset_rate_limit' => false,
                'feature_flags' => [
                    'security.two_factor' => false,
                    'payments.secondary_stripe' => false,
                    'integrations.myob' => false,
                    'accounts.roles' => false,
                    'media.folder_taxonomy' => false,
                ],
            ],
        ];
    }
}
