<?php
namespace App\Services;

class SecurityPolicyService
{
    public static function computeTwoFaRequirement(array $user): string
    {
        $settings = SecuritySettingsService::get();
        if (!$settings['enable_2fa'] || $settings['twofa_mode'] === 'DISABLED') {
            return 'DISABLED';
        }

        $required = false;
        if ($settings['twofa_mode'] === 'REQUIRED_FOR_ALL') {
            $required = true;
        } elseif ($settings['twofa_mode'] === 'REQUIRED_FOR_ROLES') {
            $roles = $user['roles'] ?? [];
            $required = count(array_intersect($roles, $settings['twofa_required_roles'])) > 0;
        }

        $override = self::getTwoFaOverride((int) ($user['id'] ?? 0));
        if ($override === 'REQUIRED') {
            $required = true;
        } elseif ($override === 'EXEMPT') {
            $required = false;
        }

        return $required ? 'REQUIRED' : 'OPTIONAL';
    }

    public static function getTwoFaOverride(int $userId): string
    {
        if ($userId <= 0) {
            return 'DEFAULT';
        }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('SELECT twofa_override FROM user_security_overrides WHERE user_id = :user_id LIMIT 1');
            $stmt->execute(['user_id' => $userId]);
        } catch (\PDOException $e) {
            if (self::isTableMissing($e)) {
                return 'DEFAULT';
            }
            throw $e;
        }
        $row = $stmt->fetch();
        return $row['twofa_override'] ?? 'DEFAULT';
    }

    public static function setTwoFaOverride(int $userId, string $override): void
    {
        $allowed = ['DEFAULT', 'REQUIRED', 'EXEMPT'];
        if (!in_array($override, $allowed, true)) {
            $override = 'DEFAULT';
        }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('INSERT INTO user_security_overrides (user_id, twofa_override, updated_at) VALUES (:user_id, :override, NOW()) ON DUPLICATE KEY UPDATE twofa_override = :override, updated_at = NOW()');
            $stmt->execute([
                'user_id' => $userId,
                'override' => $override,
            ]);
        } catch (\PDOException $e) {
            if (self::isTableMissing($e)) {
                return;
            }
            throw $e;
        }
    }

    private static function isTableMissing(\PDOException $exception): bool
    {
        return $exception->getCode() === '42S02';
    }
}
