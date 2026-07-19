<?php
namespace App\Services;

/**
 * Timed access window for the developer's admin account ("handover lockout").
 *
 * Once the lockout is enabled (done as part of the committee handover), the
 * developer account can only sign in — or keep an existing session — while a
 * webmaster-granted access window is open. Granting, revoking and expiry are
 * all recorded in activity_log under security.dev_access_* actions.
 *
 * Storage is three settings_global keys (no schema change):
 *   security.dev_access_lockout_enabled  bool   master switch, off until handover
 *   security.dev_access_email            string the gated developer account email
 *   security.dev_access_expires_at       string ISO datetime; empty = locked
 *
 * Break-glass (webmaster locked out too): via cPanel phpMyAdmin set the
 * settings_global row category='security', key_name='dev_access_lockout_enabled'
 * to value_json 'false' — documented in Appendix D of the system docs.
 */
class DeveloperAccessService
{
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 30;
    public const DEFAULT_EMAIL = 'hi@patorama.com.au';

    public static function config(): array
    {
        return [
            'enabled' => (bool) SettingsService::getGlobal('security.dev_access_lockout_enabled', false),
            'email' => strtolower(trim((string) SettingsService::getGlobal('security.dev_access_email', self::DEFAULT_EMAIL))),
            'expires_at' => (string) SettingsService::getGlobal('security.dev_access_expires_at', ''),
        ];
    }

    /** Is this email the gated developer account (with lockout switched on)? */
    public static function isGated(string $email): bool
    {
        $cfg = self::config();
        return $cfg['enabled'] && $cfg['email'] !== '' && strtolower(trim($email)) === $cfg['email'];
    }

    /** True when the account must be refused login / have its session ended. */
    public static function isLocked(string $email): bool
    {
        return self::isGated($email) && !self::windowActive();
    }

    public static function windowActive(): bool
    {
        $expiresAt = self::config()['expires_at'];
        $ts = $expiresAt !== '' ? strtotime($expiresAt) : false;
        return $ts !== false && $ts > time();
    }

    /** Grants (or extends) the access window. Returns the new expiry (ISO). */
    public static function grant(int $actorUserId, int $days = self::DEFAULT_DAYS): string
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $expires = date('c', time() + $days * 86400);
        SettingsService::setGlobal($actorUserId, 'security.dev_access_expires_at', $expires);
        ActivityLogger::log('admin', $actorUserId, null, 'security.dev_access_granted', [
            'days' => $days,
            'expires_at' => $expires,
        ]);
        $devEmail = self::config()['email'];
        if ($devEmail !== '') {
            try {
                EmailService::send(
                    $devEmail,
                    'Goldwing developer access granted',
                    '<p>A webmaster has granted the developer account (' . htmlspecialchars($devEmail, ENT_QUOTES, 'UTF-8') . ') a '
                        . (int) $days . '-day access window on goldwing.org.au.</p>'
                        . '<p>Access ends <strong>' . htmlspecialchars(date('l j F Y, g:ia T', strtotime($expires)), ENT_QUOTES, 'UTF-8') . '</strong>, '
                        . 'after which login is locked again until a new window is granted.</p>'
                );
            } catch (\Throwable $e) {
                error_log('[DeveloperAccessService] grant notification email failed: ' . $e->getMessage());
            }
        }
        return $expires;
    }

    public static function revoke(int $actorUserId): void
    {
        SettingsService::setGlobal($actorUserId, 'security.dev_access_expires_at', '');
        ActivityLogger::log('admin', $actorUserId, null, 'security.dev_access_revoked', []);
    }

    public static function setLockoutEnabled(int $actorUserId, bool $on): void
    {
        SettingsService::setGlobal($actorUserId, 'security.dev_access_lockout_enabled', $on);
        ActivityLogger::log('admin', $actorUserId, null, $on ? 'security.dev_access_lockout_enabled' : 'security.dev_access_lockout_disabled', []);
    }

    public static function setEmail(int $actorUserId, string $email): void
    {
        SettingsService::setGlobal($actorUserId, 'security.dev_access_email', strtolower(trim($email)));
        ActivityLogger::log('admin', $actorUserId, null, 'security.dev_access_email_changed', ['email' => $email]);
    }

    /** Last N dev-access events for the settings page history panel. */
    public static function history(int $limit = 15): array
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                "SELECT a.action, a.metadata, a.created_at, u.name AS actor_name
                   FROM activity_log a
                   LEFT JOIN users u ON u.id = a.actor_id
                  WHERE a.action LIKE 'security.dev_access%'
               ORDER BY a.created_at DESC
                  LIMIT " . max(1, min(50, $limit))
            );
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
