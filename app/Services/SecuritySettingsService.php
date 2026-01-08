<?php
namespace App\Services;

class SecuritySettingsService
{
    private static ?array $cache = null;

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM security_settings WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            self::seedDefaults();
            $stmt->execute();
            $row = $stmt->fetch();
        }
        $settings = self::defaults();
        if ($row) {
            $settings['enable_2fa'] = (int) $row['enable_2fa'] === 1;
            $settings['twofa_mode'] = $row['twofa_mode'] ?? $settings['twofa_mode'];
            $settings['twofa_required_roles'] = self::decodeJsonList($row['twofa_required_roles_json'] ?? '[]');
            $settings['twofa_grace_days'] = (int) ($row['twofa_grace_days'] ?? $settings['twofa_grace_days']);
            $settings['stepup_enabled'] = (int) $row['stepup_enabled'] === 1;
            $settings['stepup_window_minutes'] = (int) ($row['stepup_window_minutes'] ?? $settings['stepup_window_minutes']);
            $settings['login_ip_max_attempts'] = (int) ($row['login_ip_max_attempts'] ?? $settings['login_ip_max_attempts']);
            $settings['login_ip_window_minutes'] = (int) ($row['login_ip_window_minutes'] ?? $settings['login_ip_window_minutes']);
            $settings['login_account_max_attempts'] = (int) ($row['login_account_max_attempts'] ?? $settings['login_account_max_attempts']);
            $settings['login_account_window_minutes'] = (int) ($row['login_account_window_minutes'] ?? $settings['login_account_window_minutes']);
            $settings['login_lockout_minutes'] = (int) ($row['login_lockout_minutes'] ?? $settings['login_lockout_minutes']);
            $settings['login_progressive_delay'] = (int) $row['login_progressive_delay'] === 1;
            $settings['alert_email'] = $row['alert_email'] ?? $settings['alert_email'];
            $settings['alerts'] = self::decodeJsonMap($row['alerts_json'] ?? '{}');
            $settings['fim_enabled'] = (int) $row['fim_enabled'] === 1;
            $settings['fim_paths'] = self::decodeJsonList($row['fim_paths_json'] ?? '[]');
            $settings['fim_exclude_paths'] = self::decodeJsonList($row['fim_exclude_paths_json'] ?? '[]');
            $settings['webhook_alerts_enabled'] = (int) $row['webhook_alerts_enabled'] === 1;
            $settings['webhook_alert_threshold'] = (int) ($row['webhook_alert_threshold'] ?? $settings['webhook_alert_threshold']);
            $settings['webhook_alert_window_minutes'] = (int) ($row['webhook_alert_window_minutes'] ?? $settings['webhook_alert_window_minutes']);
            $settings['updated_at'] = $row['updated_at'] ?? null;
        }
        self::$cache = $settings;
        return $settings;
    }

    public static function update(int $actorUserId, array $payload): void
    {
        $current = self::get();
        $data = array_merge($current, $payload);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE security_settings SET enable_2fa = :enable_2fa, twofa_mode = :twofa_mode, twofa_required_roles_json = :twofa_roles, twofa_grace_days = :twofa_grace_days, stepup_enabled = :stepup_enabled, stepup_window_minutes = :stepup_window_minutes, login_ip_max_attempts = :login_ip_max_attempts, login_ip_window_minutes = :login_ip_window_minutes, login_account_max_attempts = :login_account_max_attempts, login_account_window_minutes = :login_account_window_minutes, login_lockout_minutes = :login_lockout_minutes, login_progressive_delay = :login_progressive_delay, alert_email = :alert_email, alerts_json = :alerts_json, fim_enabled = :fim_enabled, fim_paths_json = :fim_paths_json, fim_exclude_paths_json = :fim_exclude_paths_json, webhook_alerts_enabled = :webhook_alerts_enabled, webhook_alert_threshold = :webhook_alert_threshold, webhook_alert_window_minutes = :webhook_alert_window_minutes, updated_at = NOW() WHERE id = 1');
        $stmt->execute([
            'enable_2fa' => $data['enable_2fa'] ? 1 : 0,
            'twofa_mode' => $data['twofa_mode'],
            'twofa_roles' => json_encode(array_values($data['twofa_required_roles']), JSON_UNESCAPED_SLASHES),
            'twofa_grace_days' => (int) $data['twofa_grace_days'],
            'stepup_enabled' => $data['stepup_enabled'] ? 1 : 0,
            'stepup_window_minutes' => (int) $data['stepup_window_minutes'],
            'login_ip_max_attempts' => (int) $data['login_ip_max_attempts'],
            'login_ip_window_minutes' => (int) $data['login_ip_window_minutes'],
            'login_account_max_attempts' => (int) $data['login_account_max_attempts'],
            'login_account_window_minutes' => (int) $data['login_account_window_minutes'],
            'login_lockout_minutes' => (int) $data['login_lockout_minutes'],
            'login_progressive_delay' => $data['login_progressive_delay'] ? 1 : 0,
            'alert_email' => $data['alert_email'],
            'alerts_json' => json_encode($data['alerts'], JSON_UNESCAPED_SLASHES),
            'fim_enabled' => $data['fim_enabled'] ? 1 : 0,
            'fim_paths_json' => json_encode(array_values($data['fim_paths']), JSON_UNESCAPED_SLASHES),
            'fim_exclude_paths_json' => json_encode(array_values($data['fim_exclude_paths']), JSON_UNESCAPED_SLASHES),
            'webhook_alerts_enabled' => $data['webhook_alerts_enabled'] ? 1 : 0,
            'webhook_alert_threshold' => (int) $data['webhook_alert_threshold'],
            'webhook_alert_window_minutes' => (int) $data['webhook_alert_window_minutes'],
        ]);

        ActivityLogger::log('admin', $actorUserId, null, 'security.settings_updated', [
            'section' => 'security_auth',
        ]);
        self::$cache = null;
    }

    public static function defaults(): array
    {
        return [
            'enable_2fa' => true,
            'twofa_mode' => 'REQUIRED_FOR_ALL',
            'twofa_required_roles' => [],
            'twofa_grace_days' => 0,
            'stepup_enabled' => true,
            'stepup_window_minutes' => 10,
            'login_ip_max_attempts' => 10,
            'login_ip_window_minutes' => 10,
            'login_account_max_attempts' => 5,
            'login_account_window_minutes' => 15,
            'login_lockout_minutes' => 30,
            'login_progressive_delay' => true,
            'alert_email' => '',
            'alerts' => [
                'failed_login' => true,
                'new_admin_device' => true,
                'refund_created' => true,
                'role_escalation' => true,
                'member_export' => true,
                'fim_changes' => true,
                'webhook_failure' => true,
            ],
            'fim_enabled' => true,
            'fim_paths' => ['/app', '/admin', '/config'],
            'fim_exclude_paths' => ['/uploads', '/cache'],
            'webhook_alerts_enabled' => true,
            'webhook_alert_threshold' => 3,
            'webhook_alert_window_minutes' => 10,
            'updated_at' => null,
        ];
    }

    private static function seedDefaults(): void
    {
        $pdo = Database::connection();
        $defaults = self::defaults();
        $stmt = $pdo->prepare('INSERT INTO security_settings (id, enable_2fa, twofa_mode, twofa_required_roles_json, twofa_grace_days, stepup_enabled, stepup_window_minutes, login_ip_max_attempts, login_ip_window_minutes, login_account_max_attempts, login_account_window_minutes, login_lockout_minutes, login_progressive_delay, alert_email, alerts_json, fim_enabled, fim_paths_json, fim_exclude_paths_json, webhook_alerts_enabled, webhook_alert_threshold, webhook_alert_window_minutes, updated_at) VALUES (1, :enable_2fa, :twofa_mode, :twofa_roles, :twofa_grace_days, :stepup_enabled, :stepup_window_minutes, :login_ip_max_attempts, :login_ip_window_minutes, :login_account_max_attempts, :login_account_window_minutes, :login_lockout_minutes, :login_progressive_delay, :alert_email, :alerts_json, :fim_enabled, :fim_paths_json, :fim_exclude_paths_json, :webhook_alerts_enabled, :webhook_alert_threshold, :webhook_alert_window_minutes, NOW())');
        $stmt->execute([
            'enable_2fa' => $defaults['enable_2fa'] ? 1 : 0,
            'twofa_mode' => $defaults['twofa_mode'],
            'twofa_roles' => json_encode($defaults['twofa_required_roles'], JSON_UNESCAPED_SLASHES),
            'twofa_grace_days' => (int) $defaults['twofa_grace_days'],
            'stepup_enabled' => $defaults['stepup_enabled'] ? 1 : 0,
            'stepup_window_minutes' => (int) $defaults['stepup_window_minutes'],
            'login_ip_max_attempts' => (int) $defaults['login_ip_max_attempts'],
            'login_ip_window_minutes' => (int) $defaults['login_ip_window_minutes'],
            'login_account_max_attempts' => (int) $defaults['login_account_max_attempts'],
            'login_account_window_minutes' => (int) $defaults['login_account_window_minutes'],
            'login_lockout_minutes' => (int) $defaults['login_lockout_minutes'],
            'login_progressive_delay' => $defaults['login_progressive_delay'] ? 1 : 0,
            'alert_email' => $defaults['alert_email'],
            'alerts_json' => json_encode($defaults['alerts'], JSON_UNESCAPED_SLASHES),
            'fim_enabled' => $defaults['fim_enabled'] ? 1 : 0,
            'fim_paths_json' => json_encode($defaults['fim_paths'], JSON_UNESCAPED_SLASHES),
            'fim_exclude_paths_json' => json_encode($defaults['fim_exclude_paths'], JSON_UNESCAPED_SLASHES),
            'webhook_alerts_enabled' => $defaults['webhook_alerts_enabled'] ? 1 : 0,
            'webhook_alert_threshold' => (int) $defaults['webhook_alert_threshold'],
            'webhook_alert_window_minutes' => (int) $defaults['webhook_alert_window_minutes'],
        ]);
    }

    private static function decodeJsonList(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeJsonMap(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
