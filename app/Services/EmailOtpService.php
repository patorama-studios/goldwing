<?php
namespace App\Services;

class EmailOtpService
{
    private const CODE_LENGTH = 6;
    private const EXPIRY_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_DELAY_SECONDS = 60;
    private const MAX_RESENDS_PER_HOUR = 5;
    private const TRUST_DAYS = 30;

    public static function issueCode(int $userId, string $email, string $memberName = 'Member'): array
    {
        if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Missing recipient email.'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM email_otp_codes WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        $now = time();
        if ($row && !empty($row['last_sent_at'])) {
            $lastSent = strtotime((string) $row['last_sent_at']);
            if ($lastSent && ($now - $lastSent) < self::RESEND_DELAY_SECONDS) {
                return ['success' => false, 'error' => 'Please wait before requesting another code.'];
            }
        }

        $windowStart = $row && !empty($row['resend_window_started_at']) ? strtotime((string) $row['resend_window_started_at']) : null;
        $resendCount = (int) ($row['resend_count'] ?? 0);
        if (!$windowStart || ($now - $windowStart) >= 3600) {
            $windowStart = $now;
            $resendCount = 0;
        }
        if ($resendCount >= self::MAX_RESENDS_PER_HOUR) {
            return ['success' => false, 'error' => 'Too many resend attempts. Try again later.'];
        }

        $code = str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', $now + (self::EXPIRY_MINUTES * 60));
        $resendCount++;

        $stmt = $pdo->prepare('INSERT INTO email_otp_codes (user_id, code_hash, attempts, last_sent_at, expires_at, resend_count, resend_window_started_at, created_at, updated_at) VALUES (:user_id, :hash, 0, NOW(), :expires_at, :resend_count, FROM_UNIXTIME(:window_start), NOW(), NOW()) ON DUPLICATE KEY UPDATE code_hash = :hash, attempts = 0, last_sent_at = NOW(), expires_at = :expires_at, resend_count = :resend_count, resend_window_started_at = FROM_UNIXTIME(:window_start), updated_at = NOW()');
        $stmt->execute([
            'user_id' => $userId,
            'hash' => $hash,
            'expires_at' => $expiresAt,
            'resend_count' => $resendCount,
            'window_start' => $windowStart,
        ]);

        $memberId = self::memberIdForUser($userId);
        ActivityLogger::log('system', null, $memberId, 'security.otp_sent', [
            'user_id' => $userId,
            'visibility' => 'member',
        ]);

        $sent = NotificationService::dispatch('security_email_otp', [
            'primary_email' => $email,
            'member_name' => $memberName,
            'otp_code' => $code,
            'expires_minutes' => (string) self::EXPIRY_MINUTES,
            'user_id' => $userId,
            'member_id' => $memberId,
        ], [
            'force' => true,
        ]);

        if (!$sent) {
            ActivityLogger::log('system', null, $memberId, 'security.otp_send_failed', [
                'user_id' => $userId,
                'visibility' => 'admin',
            ]);
            return ['success' => false, 'error' => 'Unable to send verification email.'];
        }

        return ['success' => true];
    }

    public static function verifyCode(int $userId, string $code): bool
    {
        if ($userId <= 0 || $code === '') {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM email_otp_codes WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $memberId = self::memberIdForUser($userId);
        $expiresAt = !empty($row['expires_at']) ? strtotime((string) $row['expires_at']) : null;
        if (!$expiresAt || $expiresAt < time()) {
            self::consumeAttempt($userId, (int) $row['attempts']);
            ActivityLogger::log('system', null, $memberId, 'security.otp_expired', [
                'user_id' => $userId,
                'visibility' => 'member',
            ]);
            return false;
        }
        $attempts = (int) ($row['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            ActivityLogger::log('system', null, $memberId, 'security.otp_locked', [
                'user_id' => $userId,
                'visibility' => 'admin',
            ]);
            return false;
        }
        if (!password_verify($code, (string) $row['code_hash'])) {
            self::consumeAttempt($userId, $attempts);
            ActivityLogger::log('system', null, $memberId, 'security.otp_failed', [
                'user_id' => $userId,
                'visibility' => 'member',
            ]);
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM email_otp_codes WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        ActivityLogger::log('system', null, $memberId, 'security.otp_verified', [
            'user_id' => $userId,
            'visibility' => 'member',
        ]);
        return true;
    }

    public static function isTrusted(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $token = $_COOKIE['email_otp_trust'] ?? '';
        if ($token === '') {
            return false;
        }
        $hash = hash('sha256', $token);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM email_otp_trust WHERE user_id = :user_id AND token_hash = :hash AND expires_at > NOW() LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'hash' => $hash,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    public static function trustDevice(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + (self::TRUST_DAYS * 86400));
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO email_otp_trust (user_id, token_hash, expires_at, ip_address, user_agent, created_at) VALUES (:user_id, :hash, :expires_at, :ip, :ua, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'hash' => $hash,
            'expires_at' => $expiresAt,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('email_otp_trust', $token, [
            'expires' => time() + (self::TRUST_DAYS * 86400),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function isEnabled(): bool
    {
        return true;
    }

    private static function consumeAttempt(int $userId, int $attempts): void
    {
        $nextAttempts = $attempts + 1;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE email_otp_codes SET attempts = :attempts, updated_at = NOW() WHERE user_id = :user_id');
        $stmt->execute([
            'attempts' => $nextAttempts,
            'user_id' => $userId,
        ]);
    }

    private static function memberIdForUser(int $userId): ?int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT member_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return !empty($row['member_id']) ? (int) $row['member_id'] : null;
    }
}
