<?php
namespace App\Services;

use DateTimeImmutable;

class LoginRateLimiter
{
    public static function check(string $email, ?int $userId, string $ip): array
    {
        $settings = SecuritySettingsService::get();
        if ((int) $settings['login_ip_max_attempts'] <= 0 && (int) $settings['login_account_max_attempts'] <= 0) {
            return [
                'locked' => false,
                'delay' => 0,
                'ip_row' => null,
                'account_row' => null,
            ];
        }
        $ipRow = self::getIpRow($ip);
        $accountRow = self::getAccountRow($email);
        $locked = self::isLocked($ipRow) || self::isLocked($accountRow);
        $delay = 0;
        if ($settings['login_progressive_delay']) {
            $attempts = max((int) ($ipRow['attempts_count'] ?? 0), (int) ($accountRow['attempts_count'] ?? 0));
            $delay = min(5, $attempts);
        }
        return [
            'locked' => $locked,
            'delay' => $delay,
            'ip_row' => $ipRow,
            'account_row' => $accountRow,
        ];
    }

    public static function recordFailure(string $email, ?int $userId, string $ip): void
    {
        $settings = SecuritySettingsService::get();
        if ((int) $settings['login_ip_max_attempts'] <= 0 && (int) $settings['login_account_max_attempts'] <= 0) {
            return;
        }
        self::applyAttempt($email, $userId, $ip, $settings['login_account_max_attempts'], $settings['login_account_window_minutes'], $settings['login_lockout_minutes'], true);
        self::applyAttempt(null, null, $ip, $settings['login_ip_max_attempts'], $settings['login_ip_window_minutes'], $settings['login_lockout_minutes'], false);
    }

    public static function recordSuccess(string $email, ?int $userId, string $ip): void
    {
        $settings = SecuritySettingsService::get();
        if ((int) $settings['login_ip_max_attempts'] <= 0 && (int) $settings['login_account_max_attempts'] <= 0) {
            return;
        }
        self::resetRow($email, $userId, $ip, true);
        self::resetRow(null, null, $ip, false);
    }

    private static function applyAttempt(?string $email, ?int $userId, string $ip, int $maxAttempts, int $windowMinutes, int $lockoutMinutes, bool $isAccount): void
    {
        $pdo = Database::connection();
        $row = $isAccount ? self::getAccountRow((string) $email) : self::getIpRow($ip);
        $now = new DateTimeImmutable('now');
        $firstAttemptAt = $now;
        $attempts = 1;
        if ($row && !empty($row['last_attempt_at'])) {
            $last = new DateTimeImmutable($row['last_attempt_at']);
            $windowStart = $now->modify('-' . $windowMinutes . ' minutes');
            if ($last >= $windowStart) {
                $firstAttemptAt = !empty($row['first_attempt_at']) ? new DateTimeImmutable($row['first_attempt_at']) : $now;
                $attempts = (int) $row['attempts_count'] + 1;
            }
        }
        $lockedUntil = null;
        if ($attempts >= max(1, $maxAttempts)) {
            $lockedUntil = $now->modify('+' . max(1, $lockoutMinutes) . ' minutes')->format('Y-m-d H:i:s');
        }
        if ($row) {
            $stmt = $pdo->prepare('UPDATE login_attempts SET attempts_count = :attempts, first_attempt_at = :first_attempt_at, last_attempt_at = NOW(), locked_until = :locked_until, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'attempts' => $attempts,
                'first_attempt_at' => $firstAttemptAt->format('Y-m-d H:i:s'),
                'locked_until' => $lockedUntil,
                'id' => $row['id'],
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO login_attempts (email, user_id, ip_address, attempts_count, first_attempt_at, last_attempt_at, locked_until, updated_at) VALUES (:email, :user_id, :ip, :attempts, :first_attempt_at, NOW(), :locked_until, NOW())');
            $stmt->execute([
                'email' => $email,
                'user_id' => $userId,
                'ip' => $ip,
                'attempts' => $attempts,
                'first_attempt_at' => $firstAttemptAt->format('Y-m-d H:i:s'),
                'locked_until' => $lockedUntil,
            ]);
        }
    }

    private static function resetRow(?string $email, ?int $userId, string $ip, bool $isAccount): void
    {
        $pdo = Database::connection();
        $row = $isAccount ? self::getAccountRow((string) $email) : self::getIpRow($ip);
        if (!$row) {
            return;
        }
        $stmt = $pdo->prepare('UPDATE login_attempts SET attempts_count = 0, first_attempt_at = NULL, last_attempt_at = NULL, locked_until = NULL, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $row['id']]);
    }

    private static function getIpRow(string $ip): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM login_attempts WHERE ip_address = :ip AND email IS NULL AND user_id IS NULL LIMIT 1');
        $stmt->execute(['ip' => $ip]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function getAccountRow(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM login_attempts WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function isLocked(?array $row): bool
    {
        if (!$row || empty($row['locked_until'])) {
            return false;
        }
        return strtotime($row['locked_until']) > time();
    }
}
