<?php
namespace App\Services;

use PDO;

class AuthService
{
    public static function attemptLogin(string $identifier, string $password): array
    {
        $pdo = Database::connection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifier = trim($identifier);
        $rate = LoginRateLimiter::check($identifier, null, $ip);
        if ($rate['locked']) {
            ActivityLogger::log('system', null, null, 'security.login_locked', ['email' => $identifier]);
            return ['status' => 'locked'];
        }
        if ($rate['delay'] > 0) {
            usleep($rate['delay'] * 1000000);
        }
        $user = self::findUserByIdentifier($pdo, $identifier);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            LoginRateLimiter::recordFailure($identifier, null, $ip);
            ActivityLogger::log('system', null, null, 'security.login_failed', ['email' => $identifier]);
            $postRate = LoginRateLimiter::check($identifier, null, $ip);
            if ($postRate['locked']) {
                $safeEmail = htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8');
                $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
                SecurityAlertService::send('failed_login', 'Security alert: repeated failed logins', '<p>Repeated failed login attempts for ' . $safeEmail . ' from IP ' . $safeIp . '.</p>');
            }
            return ['status' => 'invalid'];
        }
        if ((int) $user['is_active'] !== 1) {
            return ['status' => 'inactive'];
        }
        LoginRateLimiter::recordSuccess($identifier, (int) $user['id'], $ip);

        $user['roles'] = self::getUserRoles((int) $user['id']);
        $twofaRequirement = SecurityPolicyService::computeTwoFaRequirement($user);
        $has2fa = TwoFactorService::isEnabled((int) $user['id']);
        if ($has2fa) {
            self::setPendingTwoFa((int) $user['id'], 'verify');
            return ['status' => '2fa_required'];
        }
        if ($twofaRequirement === 'REQUIRED') {
            if (EmailOtpService::isEnabled()) {
                if (EmailOtpService::isTrusted((int) $user['id'])) {
                    self::completeLogin($user);
                    return ['status' => 'ok'];
                }
                $issued = EmailOtpService::issueCode((int) $user['id'], (string) $user['email'], (string) ($user['name'] ?? 'Member'));
                if ($issued['success']) {
                    self::setPendingTwoFa((int) $user['id'], 'email_otp');
                    return ['status' => '2fa_email_required'];
                }
                return ['status' => '2fa_email_failed'];
            }
            if (self::withinGracePeriod()) {
                self::completeLogin($user);
                $_SESSION['twofa_enroll_required'] = true;
                return ['status' => 'ok'];
            }
            self::setPendingTwoFa((int) $user['id'], 'enroll');
            return ['status' => '2fa_enroll'];
        }
        self::completeLogin($user);
        return ['status' => 'ok'];
    }

    public static function logout(): void
    {
        StepUpService::clear();
        $_SESSION = [];
        session_destroy();
    }

    public static function getUserRoles(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return array_map(fn($row) => $row['name'], $stmt->fetchAll());
    }

    public static function completeTwoFactorLogin(): bool
    {
        $pending = $_SESSION['auth_pending'] ?? null;
        if (!$pending || empty($pending['user_id'])) {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $pending['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            return false;
        }
        $user['roles'] = self::getUserRoles((int) $user['id']);
        self::completeLogin($user);
        unset($_SESSION['auth_pending']);
        return true;
    }

    public static function verifyPassword(int $userId, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        return password_verify($password, $row['password_hash']);
    }

    public static function recordLogin(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO user_logins (user_id, ip_address, created_at) VALUES (:user_id, :ip, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }

    private static function completeLogin(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'member_id' => $user['member_id'],
            'roles' => $user['roles'] ?? [],
        ];
        self::recordLogin((int) $user['id']);
        $roleList = $user['roles'] ?? [];
        $actorType = array_intersect($roleList, ['admin', 'committee', 'treasurer', 'chapter_leader', 'store_manager']) ? 'admin' : 'member';
        ActivityLogger::log($actorType, (int) $user['id'], null, 'security.login_success', ['email' => $user['email']]);

        $fingerprint = TrustedDeviceService::fingerprint();
        $isNewDevice = TrustedDeviceService::record((int) $user['id'], $fingerprint);
        $adminRoles = ['admin', 'committee', 'treasurer', 'chapter_leader', 'store_manager'];
        if ($isNewDevice && count(array_intersect($roleList, $adminRoles)) > 0) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $safeEmail = htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8');
            $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
            SecurityAlertService::send('new_admin_device', 'Security alert: new admin device', '<p>Admin login from a new device for ' . $safeEmail . ' at IP ' . $safeIp . '.</p>');
            ActivityLogger::log('admin', (int) $user['id'], null, 'security.admin_new_device', ['ip' => $ip]);
        }
    }

    private static function setPendingTwoFa(int $userId, string $purpose): void
    {
        $_SESSION['auth_pending'] = [
            'user_id' => $userId,
            'purpose' => $purpose,
            'created_at' => time(),
        ];
    }

    public static function withinGracePeriod(): bool
    {
        $settings = SecuritySettingsService::get();
        $days = (int) $settings['twofa_grace_days'];
        if ($days <= 0 || empty($settings['updated_at'])) {
            return false;
        }
        $start = strtotime($settings['updated_at']);
        if (!$start) {
            return false;
        }
        $end = $start + ($days * 86400);
        return time() <= $end;
    }

    private static function findUserByIdentifier(PDO $pdo, string $identifier): ?array
    {
        if ($identifier === '') {
            return null;
        }
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $identifier]);
            $user = $stmt->fetch();
            return $user ?: null;
        }

        $memberNumber = self::parseMemberNumber($identifier);
        if (!$memberNumber) {
            return null;
        }

        $member = null;
        if (MemberRepository::hasMemberNumberColumn($pdo)) {
            $stmt = $pdo->prepare('SELECT id, user_id FROM members WHERE member_number = :member_number LIMIT 1');
            $stmt->execute(['member_number' => $memberNumber['display']]);
            $member = $stmt->fetch();
        }
        if (!$member) {
            $stmt = $pdo->prepare('SELECT id, user_id FROM members WHERE member_number_base = :base AND member_number_suffix = :suffix LIMIT 1');
            $stmt->execute([
                'base' => $memberNumber['base'],
                'suffix' => $memberNumber['suffix'],
            ]);
            $member = $stmt->fetch();
        }
        if (!$member) {
            return null;
        }
        if (!empty($member['user_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $member['user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                return $user;
            }
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE member_id = :member_id LIMIT 1');
        $stmt->execute(['member_id' => (int) $member['id']]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    private static function parseMemberNumber(string $identifier): ?array
    {
        return MembershipService::parseMemberNumberString($identifier);
    }
}
