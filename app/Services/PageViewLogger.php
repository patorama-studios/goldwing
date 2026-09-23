<?php
namespace App\Services;

/**
 * Records one page_views row per HTML page load by a logged-in user so the
 * admin Member Engagement report (/admin/reports/) can say which areas members
 * use and stitch visits together for time on site. Called once from
 * bootstrap.php, after enforce_page_access().
 *
 * Deliberately narrow: GET + "Accept: text/html" only (fetch/XHR calls, feeds
 * and PDF fetches never count), no IP address, no query string beyond the
 * portal ?page= key, and nothing while an admin is impersonating a member.
 */
class PageViewLogger
{
    /** Area key => label, in the order the report lists them. */
    public const AREAS = [
        'dashboard'          => 'Dashboard',
        'wings'              => 'Wings',
        'calendar'           => 'Calendar',
        'notices'            => 'Notice Board',
        'directory'          => 'Members Directory',
        'committee'          => 'Committee',
        'dealers'            => 'Honda Dealers',
        'fallen-wings'       => 'Fallen Wings',
        'member-of-the-year' => 'Member of the Year',
        'awards'             => 'AGM Awards',
        'store'              => 'Store',
        'checkout'           => 'Checkout',
        'agm'                => 'AGM',
        'profile'            => 'Profile',
        'billing'            => 'Billing',
        'membership'         => 'Membership',
        'history'            => 'History',
        'activity'           => 'Activity',
        'settings'           => 'Settings',
        'notifications'      => 'Notifications',
        'help'               => 'Help & Guides',
        'public'             => 'Public site',
        'admin'              => 'Admin area',
        'other'              => 'Other',
    ];

    /** Requests that are never a page a member reads. */
    private const SKIP = '#^/(api|auth|uploads|assets)/|(feed|ics|download_wings|export|logout|login|stepup|debug-|diagnose-|run-migration|check_db|db_test|migrate)[^/]*\.php$#';

    public static function label(string $area): string
    {
        return self::AREAS[$area] ?? ucfirst($area);
    }

    /** Map a request path (no query string) plus the portal ?page= value to an area key. */
    public static function areaFor(string $path, ?string $page = null): string
    {
        $path = '/' . ltrim($path, '/');
        $page = strtolower(trim((string) $page));

        if (in_array($path, ['/member/index.php', '/member/', '/member'], true)) {
            if ($page === '' || $page === 'dashboard') {
                return 'dashboard';
            }
            if (str_starts_with($page, 'notices')) {
                return 'notices';
            }
            return isset(self::AREAS[$page]) ? $page : 'other';
        }

        $exact = [
            '/member/notifications.php' => 'notifications',
            '/member/help.php'          => 'help',
            '/member/read_wings.php'    => 'wings',
            '/fallen-wings.php'         => 'fallen-wings',
        ];
        if (isset($exact[$path])) {
            return $exact[$path];
        }

        // Order matters: longer prefixes first.
        $prefixes = [
            '/members/member-of-the-year' => 'member-of-the-year',
            '/members/awards'             => 'awards',
            '/calendar/admin'             => 'admin',
            '/admin'                      => 'admin',
            '/store'                      => 'store',
            '/calendar'                   => 'calendar',
            '/agm'                        => 'agm',
            '/checkout'                   => 'checkout',
            '/order'                      => 'checkout',
            '/memberships'                => 'checkout',
            '/member/'                    => 'other',
        ];
        foreach ($prefixes as $prefix => $area) {
            if (str_starts_with($path, $prefix)) {
                return $area;
            }
        }
        return 'public';
    }

    public static function record(): void
    {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        if (!str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
            return;
        }
        if (!function_exists('current_user')) {
            return;
        }
        $user = current_user();
        if (empty($user['id']) || (function_exists('is_impersonating') && is_impersonating())) {
            return;
        }

        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        if (preg_match(self::SKIP, $path)) {
            return;
        }

        $page = isset($_GET['page']) ? preg_replace('/[^a-z0-9-]/', '', strtolower((string) $_GET['page'])) : '';
        $roles = (array) ($user['roles'] ?? []);
        if (function_exists('normalize_access_roles')) {
            $roles = normalize_access_roles($roles);
        }

        try {
            $stmt = Database::connection()->prepare('INSERT INTO page_views (user_id, member_id, is_admin, area, path, created_at) VALUES (:user_id, :member_id, :is_admin, :area, :path, NOW())');
            $stmt->execute([
                'user_id'   => (int) $user['id'],
                'member_id' => !empty($user['member_id']) ? (int) $user['member_id'] : null,
                'is_admin'  => in_array('admin', $roles, true) ? 1 : 0,
                'area'      => self::areaFor($path, $page),
                'path'      => substr($page !== '' ? $path . '?page=' . $page : $path, 0, 191),
            ]);
        } catch (\Throwable $e) {
            // A missing table means Migration 051 hasn't run yet — stay quiet
            // rather than logging a line per page load. Anything else is news.
            if (!str_contains($e->getMessage(), "doesn't exist")) {
                error_log('[PageViewLogger] ' . $e->getMessage());
            }
        }
    }
}
