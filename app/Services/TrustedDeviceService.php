<?php
namespace App\Services;

class TrustedDeviceService
{
    public static function fingerprint(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        return hash('sha256', $ip . '|' . $ua . '|' . $accept);
    }

    public static function record(int $userId, string $fingerprint): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM trusted_devices WHERE user_id = :user_id AND device_fingerprint_hash = :hash LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'hash' => $fingerprint]);
        $row = $stmt->fetch();
        if ($row) {
            $stmt = $pdo->prepare('UPDATE trusted_devices SET last_seen_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $row['id']]);
            return false;
        }
        $stmt = $pdo->prepare('INSERT INTO trusted_devices (user_id, device_fingerprint_hash, first_seen_at, last_seen_at) VALUES (:user_id, :hash, NOW(), NOW())');
        $stmt->execute(['user_id' => $userId, 'hash' => $fingerprint]);
        return true;
    }
}
