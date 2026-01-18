<?php
namespace App\Services;

class AiProviderKeyService
{
    public static function getKey(string $provider): ?string
    {
        $provider = strtolower($provider);
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT api_key_encrypted FROM ai_provider_keys WHERE provider = :provider LIMIT 1');
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return EncryptionService::decrypt($row['api_key_encrypted']);
    }

    public static function getMeta(string $provider): array
    {
        $provider = strtolower($provider);
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT api_key_encrypted FROM ai_provider_keys WHERE provider = :provider LIMIT 1');
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            return ['configured' => false, 'last4' => null];
        }
        if (!$row) {
            return ['configured' => false, 'last4' => null];
        }
        $plaintext = EncryptionService::decrypt($row['api_key_encrypted']);
        if ($plaintext === null) {
            return ['configured' => false, 'last4' => null];
        }
        $last4 = strlen($plaintext) >= 4 ? substr($plaintext, -4) : $plaintext;
        return ['configured' => true, 'last4' => $last4];
    }

    public static function upsertKey(string $provider, string $plaintext, int $userId): void
    {
        $provider = strtolower($provider);
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT id FROM ai_provider_keys WHERE provider = :provider LIMIT 1');
            $stmt->execute(['provider' => $provider]);
            $existing = $stmt->fetch();
        } catch (\PDOException $e) {
            return;
        }

        if ($plaintext === '') {
            if ($existing) {
                $stmt = $pdo->prepare('DELETE FROM ai_provider_keys WHERE provider = :provider');
                $stmt->execute(['provider' => $provider]);
            }
            return;
        }

        try {
            $encrypted = EncryptionService::encrypt($plaintext);
        } catch (\RuntimeException $e) {
            return;
        }
        if ($existing) {
            $stmt = $pdo->prepare('UPDATE ai_provider_keys SET api_key_encrypted = :api_key_encrypted, updated_by = :user_id, updated_at = NOW() WHERE provider = :provider');
            $stmt->execute([
                'api_key_encrypted' => $encrypted,
                'user_id' => $userId,
                'provider' => $provider,
            ]);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO ai_provider_keys (provider, api_key_encrypted, created_by, created_at) VALUES (:provider, :api_key_encrypted, :user_id, NOW())');
        $stmt->execute([
            'provider' => $provider,
            'api_key_encrypted' => $encrypted,
            'user_id' => $userId,
        ]);
    }

    public static function hasKey(string $provider): bool
    {
        $provider = strtolower($provider);
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT 1 FROM ai_provider_keys WHERE provider = :provider LIMIT 1');
            $stmt->execute(['provider' => $provider]);
            return (bool) $stmt->fetch();
        } catch (\PDOException $e) {
            return false;
        }
    }
}
