<?php
namespace App\Services;

class StepUpService
{
    public static function isEnabled(): bool
    {
        $settings = SecuritySettingsService::get();
        return (bool) $settings['stepup_enabled'];
    }

    public static function issue(int $userId): int
    {
        $settings = SecuritySettingsService::get();
        $window = max(1, (int) $settings['stepup_window_minutes']);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO stepup_tokens (user_id, issued_at, expires_at, ip_address, user_agent) VALUES (:user_id, NOW(), DATE_ADD(NOW(), INTERVAL :window MINUTE), :ip, :user_agent)');
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':window', $window, \PDO::PARAM_INT);
        $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null);
        $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
        $stmt->execute();
        $tokenId = (int) $pdo->lastInsertId();
        $_SESSION['stepup_token_id'] = $tokenId;
        return $tokenId;
    }

    public static function isValid(int $userId): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        $tokenId = (int) ($_SESSION['stepup_token_id'] ?? 0);
        if ($tokenId <= 0) {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM stepup_tokens WHERE id = :id AND user_id = :user_id AND expires_at > NOW() LIMIT 1');
        $stmt->execute([
            'id' => $tokenId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (($row['ip_address'] ?? '') !== '' && ($row['ip_address'] ?? '') !== $ip) {
            return false;
        }
        if (($row['user_agent'] ?? '') !== '' && ($row['user_agent'] ?? '') !== $ua) {
            return false;
        }
        return true;
    }

    public static function clear(): void
    {
        unset($_SESSION['stepup_token_id']);
    }
}
