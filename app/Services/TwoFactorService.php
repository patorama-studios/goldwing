<?php
namespace App\Services;

class TwoFactorService
{
    public static function getUser(int $userId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isEnabled(int $userId): bool
    {
        $row = self::getUser($userId);
        return $row && !empty($row['enabled_at']);
    }

    public static function beginEnrollment(int $userId): array
    {
        $secret = TotpService::generateSecret();
        $encrypted = self::encryptSecret($secret);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO user_2fa (user_id, totp_secret_encrypted, enabled_at, last_verified_at, recovery_codes_json, updated_at) VALUES (:user_id, :secret_insert, NULL, NULL, NULL, NOW()) ON DUPLICATE KEY UPDATE totp_secret_encrypted = :secret_update, enabled_at = NULL, last_verified_at = NULL, recovery_codes_json = NULL, updated_at = NOW()');
        $stmt->execute([
            'user_id' => $userId,
            'secret_insert' => $encrypted,
            'secret_update' => $encrypted,
        ]);
        return ['secret' => $secret];
    }

    public static function verifyAndEnable(int $userId, string $code): array
    {
        $secret = self::getSecret($userId);
        if ($secret === '') {
            return ['success' => false, 'error' => 'Missing secret.'];
        }
        if (!TotpService::verifyCode($secret, $code)) {
            return ['success' => false, 'error' => 'Invalid code.'];
        }

        $codes = self::generateRecoveryCodes();
        $hashes = array_map(fn($value) => password_hash($value, PASSWORD_DEFAULT), $codes);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE user_2fa SET enabled_at = NOW(), last_verified_at = NOW(), recovery_codes_json = :codes, updated_at = NOW() WHERE user_id = :user_id');
        $stmt->execute([
            'codes' => json_encode($hashes, JSON_UNESCAPED_SLASHES),
            'user_id' => $userId,
        ]);
        return ['success' => true, 'recovery_codes' => $codes];
    }

    public static function verifyCode(int $userId, string $code): bool
    {
        $secret = self::getSecret($userId);
        if ($secret === '') {
            return false;
        }
        if (!TotpService::verifyCode($secret, $code)) {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE user_2fa SET last_verified_at = NOW(), updated_at = NOW() WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return true;
    }

    public static function verifyRecoveryCode(int $userId, string $code): bool
    {
        $row = self::getUser($userId);
        if (!$row || empty($row['recovery_codes_json'])) {
            return false;
        }
        $hashes = json_decode($row['recovery_codes_json'], true);
        if (!is_array($hashes)) {
            return false;
        }
        $remaining = [];
        $matched = false;
        foreach ($hashes as $hash) {
            if (!$matched && password_verify($code, $hash)) {
                $matched = true;
                continue;
            }
            $remaining[] = $hash;
        }
        if (!$matched) {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE user_2fa SET recovery_codes_json = :codes, updated_at = NOW() WHERE user_id = :user_id');
        $stmt->execute([
            'codes' => json_encode($remaining, JSON_UNESCAPED_SLASHES),
            'user_id' => $userId,
        ]);
        return true;
    }

    public static function reset(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE user_2fa SET totp_secret_encrypted = NULL, enabled_at = NULL, last_verified_at = NULL, recovery_codes_json = NULL, updated_at = NOW() WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    public static function getSecret(int $userId): string
    {
        $row = self::getUser($userId);
        if (!$row || empty($row['totp_secret_encrypted'])) {
            return '';
        }
        return self::decryptSecret((string) $row['totp_secret_encrypted']);
    }

    private static function encryptSecret(string $secret): string
    {
        if (EncryptionService::isReady()) {
            return EncryptionService::encrypt($secret);
        }
        $fallback = CryptoService::encrypt($secret);
        return $fallback ?? '';
    }

    private static function decryptSecret(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        if (EncryptionService::isReady()) {
            $value = EncryptionService::decrypt($payload);
            return $value ?? '';
        }
        return CryptoService::decrypt($payload);
    }

    private static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }
}
