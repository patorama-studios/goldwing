<?php
namespace App\Services;

use DateTimeImmutable;

class PaymentSettingsService
{
    public static function getChannelByCode(string $code): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM payment_channels WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $channel = $stmt->fetch();
        if ($channel) {
            return $channel;
        }
        $label = strtoupper(substr($code, 0, 1)) . substr($code, 1);
        $stmt = $pdo->prepare('INSERT INTO payment_channels (code, label, is_active, created_at) VALUES (:code, :label, 0, NOW())');
        $stmt->execute(['code' => $code, 'label' => $label]);
        $id = (int) $pdo->lastInsertId();
        return [
            'id' => $id,
            'code' => $code,
            'label' => $label,
            'is_active' => 0,
        ];
    }

    public static function getSettingsByChannelId(int $channelId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM settings_payments WHERE channel_id = :channel_id LIMIT 1');
        $stmt->execute(['channel_id' => $channelId]);
        $settings = $stmt->fetch();
        if (!$settings) {
            $stmt = $pdo->prepare('INSERT INTO settings_payments (channel_id, mode, invoice_prefix, invoice_prefix_store, created_at) VALUES (:channel_id, "test", "INV", "STORE", NOW())');
            $stmt->execute(['channel_id' => $channelId]);
            $stmt = $pdo->prepare('SELECT * FROM settings_payments WHERE channel_id = :channel_id LIMIT 1');
            $stmt->execute(['channel_id' => $channelId]);
            $settings = $stmt->fetch();
        }
        if (!$settings) {
            return [];
        }
        $settings['secret_key'] = CryptoService::decrypt($settings['secret_key_encrypted'] ?? '');
        $settings['webhook_secret'] = CryptoService::decrypt($settings['webhook_secret_encrypted'] ?? '');
        return $settings;
    }

    public static function getSettingsByChannelCode(string $code): array
    {
        $channel = self::getChannelByCode($code);
        return self::getSettingsByChannelId((int) $channel['id']);
    }

    public static function updateSettings(int $channelId, array $payload): void
    {
        $pdo = Database::connection();
        $current = self::getSettingsByChannelId($channelId);
        $publishableKey = $payload['publishable_key'] ?? ($current['publishable_key'] ?? '');
        $secretKeyPlain = $payload['secret_key'] ?? '';
        $webhookSecretPlain = $payload['webhook_secret'] ?? '';
        $invoicePrefix = $payload['invoice_prefix'] ?? ($current['invoice_prefix'] ?? 'INV');
        $invoicePrefixStore = $payload['invoice_prefix_store'] ?? ($current['invoice_prefix_store'] ?? 'STORE');
        $generatePdf = isset($payload['generate_pdf']) ? (int) $payload['generate_pdf'] : (int) ($current['generate_pdf'] ?? 1);
        $emailTemplate = $payload['invoice_email_template'] ?? ($current['invoice_email_template'] ?? null);

        $secretKeyEncrypted = $current['secret_key_encrypted'] ?? null;
        if ($secretKeyPlain !== '') {
            $encrypted = CryptoService::encrypt($secretKeyPlain);
            if ($encrypted !== null) {
                $secretKeyEncrypted = $encrypted;
            }
        }
        $webhookSecretEncrypted = $current['webhook_secret_encrypted'] ?? null;
        if ($webhookSecretPlain !== '') {
            $encrypted = CryptoService::encrypt($webhookSecretPlain);
            if ($encrypted !== null) {
                $webhookSecretEncrypted = $encrypted;
            }
        }

        $mode = self::inferMode($secretKeyPlain !== '' ? $secretKeyPlain : ($current['secret_key'] ?? ''));

        $stmt = $pdo->prepare('UPDATE settings_payments SET publishable_key = :publishable_key, secret_key_encrypted = :secret_key_encrypted, webhook_secret_encrypted = :webhook_secret_encrypted, mode = :mode, invoice_prefix = :invoice_prefix, invoice_prefix_store = :invoice_prefix_store, invoice_email_template = :invoice_email_template, generate_pdf = :generate_pdf, updated_at = NOW() WHERE channel_id = :channel_id');
        $stmt->execute([
            'publishable_key' => $publishableKey,
            'secret_key_encrypted' => $secretKeyEncrypted,
            'webhook_secret_encrypted' => $webhookSecretEncrypted,
            'mode' => $mode,
            'invoice_prefix' => $invoicePrefix,
            'invoice_prefix_store' => $invoicePrefixStore,
            'invoice_email_template' => $emailTemplate,
            'generate_pdf' => $generatePdf,
            'channel_id' => $channelId,
        ]);
    }

    public static function updateWebhookStatus(int $channelId, ?string $error): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE settings_payments SET last_webhook_received_at = NOW(), last_webhook_error = :error, updated_at = NOW() WHERE channel_id = :channel_id');
        $stmt->execute([
            'error' => $error,
            'channel_id' => $channelId,
        ]);
    }

    public static function inferMode(string $secretKey): string
    {
        if (str_starts_with($secretKey, 'sk_live_')) {
            return 'live';
        }
        return 'test';
    }

    public static function nextInvoiceNumber(int $channelId, string $orderType = 'membership'): string
    {
        $pdo = Database::connection();
        $year = (int) (new DateTimeImmutable('now'))->format('Y');
        $manageTransaction = !$pdo->inTransaction();

        // Map order type to its column trio. Anything other than 'store'
        // (e.g. 'membership', '', null-ish) uses the original sequence so
        // existing INV-YYYY-NNNNN numbers keep flowing.
        $isStore = strtolower($orderType) === 'store';
        $prefixCol = $isStore ? 'invoice_prefix_store' : 'invoice_prefix';
        $yearCol = $isStore ? 'invoice_counter_year_store' : 'invoice_counter_year';
        $counterCol = $isStore ? 'invoice_counter_store' : 'invoice_counter';
        $defaultPrefix = $isStore ? 'STORE' : 'INV';

        if ($manageTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT ' . $prefixCol . ' AS prefix, ' . $yearCol . ' AS counter_year, ' . $counterCol . ' AS counter FROM settings_payments WHERE channel_id = :channel_id FOR UPDATE');
            $stmt->execute(['channel_id' => $channelId]);
            $settings = $stmt->fetch();
            if (!$settings) {
                if ($manageTransaction) {
                    $pdo->rollBack();
                }
                return '';
            }
            $counterYear = (int) ($settings['counter_year'] ?? 0);
            $counter = (int) ($settings['counter'] ?? 0);
            if ($counterYear !== $year) {
                $counterYear = $year;
                $counter = 0;
            }
            $counter++;
            $stmt = $pdo->prepare('UPDATE settings_payments SET ' . $yearCol . ' = :year, ' . $counterCol . ' = :counter, updated_at = NOW() WHERE channel_id = :channel_id');
            $stmt->execute([
                'year' => $counterYear,
                'counter' => $counter,
                'channel_id' => $channelId,
            ]);
            if ($manageTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($manageTransaction) {
                $pdo->rollBack();
            }
            return '';
        }

        $prefix = !empty($settings['prefix']) ? (string) $settings['prefix'] : $defaultPrefix;
        return sprintf('%s-%04d-%05d', $prefix, $counterYear, $counter);
    }
}
