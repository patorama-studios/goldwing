<?php
require_once __DIR__ . '/Services/Env.php';
require_once __DIR__ . '/Services/Database.php';

use App\Services\Env;
use App\Services\Database;
use App\Services\DbSessionHandler;
use App\Services\LogViewerService;
use App\Services\SecurityHeadersService;
use App\Services\StepUpService;

Env::load(__DIR__ . '/../.env');
Env::load(__DIR__ . '/../.env.local');

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

$appConfig = require __DIR__ . '/../config/app.php';
LogViewerService::configurePhpLogging();

$sessionConfig = $appConfig['session'];
$secure = $sessionConfig['secure'];
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $secure = true;
}

session_name($sessionConfig['name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => $sessionConfig['httponly'],
    'samesite' => 'Strict',
]);
session_set_save_handler(new DbSessionHandler(), true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
SecurityHeadersService::apply();

function db(): PDO
{
    return Database::connection();
}

function config(string $key, $default = null)
{
    $appConfig = require __DIR__ . '/../config/app.php';
    $parts = explode('.', $key);
    $value = $appConfig;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        return null;
    }
    if (isset($user['roles']) && function_exists('normalize_access_roles')) {
        $normalized = normalize_access_roles((array) $user['roles']);
        if ($normalized !== ($user['roles'] ?? [])) {
            $user['roles'] = $normalized;
            $_SESSION['user']['roles'] = $normalized;
        }
    }
    return $user;
}

function impersonation_context(): ?array
{
    $context = $_SESSION['impersonation'] ?? null;
    if (!$context || empty($context['admin_user']) || empty($context['member_id'])) {
        return null;
    }
    return $context;
}

function is_impersonating(): bool
{
    return impersonation_context() !== null;
}

function impersonation_admin_user(): ?array
{
    $context = impersonation_context();
    if (!$context) {
        return null;
    }
    return $context['admin_user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
    $user = current_user();
    $path = $_SERVER['REQUEST_URI'] ?? '';
    if ($user && !str_contains($path, '/member/2fa_enroll.php') && !is_impersonating()) {
        $requirement = App\Services\SecurityPolicyService::computeTwoFaRequirement($user);
        $has2fa = App\Services\TwoFactorService::isEnabled((int) $user['id']);
        if ($requirement === 'REQUIRED' && !$has2fa && !App\Services\AuthService::withinGracePeriod() && !App\Services\EmailOtpService::isEnabled()) {
            header('Location: /member/2fa_enroll.php');
            exit;
        }
    }
}

function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    $userRoles = $user['roles'] ?? [];
    foreach ($roles as $role) {
        if (in_array($role, $userRoles, true)) {
            return;
        }
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function require_stepup(string $redirectUrl = ''): void
{
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    if (!StepUpService::isValid((int) $user['id'])) {
        $_SESSION['stepup_redirect'] = $redirectUrl !== '' ? $redirectUrl : ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: /stepup.php');
        exit;
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

try {
    $timezone = App\Services\SettingsService::getGlobal('site.timezone', 'Australia/Sydney');
    if ($timezone) {
        date_default_timezone_set($timezone);
    } else {
        date_default_timezone_set('Australia/Sydney');
    }
} catch (Throwable $e) {
    date_default_timezone_set('UTC');
}

try {
    $forceHttps = App\Services\SettingsService::getGlobal('security.force_https', false);
    if ($forceHttps && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
    $maintenanceMode = App\Services\SettingsService::getGlobal('advanced.maintenance_mode', false);
    if ($maintenanceMode) {
        $user = current_user();
        $roles = $user['roles'] ?? [];
        $allow = array_intersect($roles, ['admin']);
        if (empty($allow)) {
            http_response_code(503);
            echo '<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>Maintenance</h1><p>The site is currently undergoing maintenance. Please check back soon.</p></body></html>';
            exit;
        }
    }
} catch (Throwable $e) {
}

function render_media_shortcodes(string $html): string
{
    $html = preg_replace_callback('/\\[media:(\\d+)\\]/', function ($matches) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
        $stmt->execute(['id' => (int) $matches[1]]);
        $media = $stmt->fetch();
        if (!$media) {
            return '<span class="text-xs text-slate-500">Missing media</span>';
        }
        if ($media['type'] === 'image') {
            return '<img src="' . e($media['path']) . '" alt="' . e($media['title']) . '">';
        }
        if ($media['type'] === 'pdf' || $media['type'] === 'file') {
            return '<a href="' . e($media['path']) . '">' . e($media['title']) . '</a>';
        }
        if ($media['type'] === 'video' && !empty($media['embed_html'])) {
            return $media['embed_html'];
        }
        return '<a href="' . e($media['path']) . '">' . e($media['title']) . '</a>';
    }, $html);

    // Shared card renderer for the [committee] / [chapter-reps] shortcodes.
    // Inlined here (rather than going through the committee_cards.php partial)
    // because the partial was returning empty output through the
    // preg_replace_callback closure in production, leaving the public pages
    // with no cards. Using a local closure removes require/scope as a variable.
    $renderCommitteeCard = function (array $role): string {
        $first   = trim((string) ($role['first_name'] ?? ''));
        $last    = trim((string) ($role['last_name']  ?? ''));
        $vacant  = $first === '' && $last === '';
        $private = !empty($role['committee_private']);
        // When the member has flagged this listing as private, show first
        // name only and suppress the role phone. The role email + title +
        // chapter still render — they identify the position, not the person.
        $displayName = $vacant ? '' : ($private ? $first : trim($first . ' ' . $last));
        $avatar  = $role['avatar_url']   ?? '';
        $email   = $role['email']        ?? '';
        $phone   = $private ? '' : ((string) ($role['phone'] ?? ''));
        $title   = $role['name']         ?? '';
        $chapter = $role['chapter_name'] ?? '';
        $h  = '<div class="card">';
        $imgSrc = (!$vacant && $avatar !== '') ? $avatar : '/uploads/about/committee-placeholder.png';
        $imgAlt = $vacant ? 'Position vacant' : $displayName;
        $h .= '<img src="' . e($imgSrc) . '" alt="' . e($imgAlt) . '" style="width:100%; border-radius:8px; margin-bottom:0.75rem;">';
        $h .= $vacant
            ? '<h3 style="font-style:italic; color:#9ca3af;">Position vacant</h3>'
            : '<h3>' . e($displayName) . '</h3>';
        $h .= '<p>' . e($title) . '</p>';
        if ($chapter !== '') {
            $h .= '<p style="color:#6b7280; font-size:0.875rem;">' . e($chapter) . '</p>';
        }
        if ($phone !== '') {
            $h .= '<p>Phone: <a href="tel:' . e(preg_replace('/\s+/', '', $phone)) . '">' . e($phone) . '</a></p>';
        } elseif (!$vacant && !$private) {
            $h .= '<p>Phone: TBC</p>';
        }
        if ($email !== '') {
            $h .= '<p><a href="mailto:' . e($email) . '">' . e($email) . '</a></p>';
        }
        $h .= '</div>';
        return $h;
    };

    // [committee] — National committee grid
    $html = preg_replace_callback('/\\[committee\\]/', function () use ($renderCommitteeCard) {
        try {
            $roles = \App\Services\CommitteeService::nationalRoles();
        } catch (\Throwable $e) {
            return '<div class="card"><p style="color:#b91c1c">Couldn\'t load committee roles: ' . e($e->getMessage()) . '</p></div>';
        }
        if (!$roles) {
            return '<div class="card"><p style="color:#6b7280">No committee roles configured yet. Visit <a href="/admin/run-migration.php">admin/run-migration.php</a> to seed the catalog.</p></div>';
        }
        $out = '<div class="grid grid-3 committee-grid">';
        foreach ($roles as $role) { $out .= $renderCommitteeCard($role); }
        $out .= '</div>';
        return $out;
    }, $html);

    // [chapter-reps]                  — all chapter reps, grouped by state
    // [chapter-reps state="Tasmania"] — single-state listing (no group header)
    $html = preg_replace_callback('/\\[chapter-reps(?:\\s+state="([^"]*)")?\\]/', function ($matches) use ($renderCommitteeCard) {
        $stateFilter = isset($matches[1]) && $matches[1] !== '' ? $matches[1] : null;
        try {
            $byState = \App\Services\CommitteeService::chapterRolesByState($stateFilter);
        } catch (\Throwable $e) {
            return '<div class="card"><p style="color:#b91c1c">Couldn\'t load chapter reps: ' . e($e->getMessage()) . '</p></div>';
        }
        if (!$byState) {
            return '<div class="card"><p style="color:#6b7280">No chapter representatives configured yet for this region.</p></div>';
        }
        $out = '';
        foreach ($byState as $state => $stateRoles) {
            if ($stateFilter === null) {
                $out .= '<h2 style="margin-top:2rem;">' . e((string) $state) . '</h2>';
            }
            $out .= '<div class="grid grid-3 chapter-grid">';
            foreach ($stateRoles as $role) { $out .= $renderCommitteeCard($role); }
            $out .= '</div>';
        }
        return $out;
    }, $html);

    return $html;
}

require_once __DIR__ . '/../includes/date_helpers.php';
require_once __DIR__ . '/../includes/store_helpers.php';
require_once __DIR__ . '/../includes/access_control.php';
require_once __DIR__ . '/../includes/admin_permissions.php';

enforce_page_access();
