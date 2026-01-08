<?php
require_once __DIR__ . '/Services/Env.php';
require_once __DIR__ . '/Services/Database.php';

use App\Services\Env;
use App\Services\Database;
use App\Services\DbSessionHandler;
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

function require_login(): void
{
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
    $user = current_user();
    $path = $_SERVER['REQUEST_URI'] ?? '';
    if ($user && !str_contains($path, '/member/2fa_enroll.php')) {
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
    if (in_array('super_admin', $userRoles, true)) {
        return;
    }
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
        $allow = array_intersect($roles, ['super_admin', 'admin']);
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
    return preg_replace_callback('/\\[media:(\\d+)\\]/', function ($matches) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
        $stmt->execute(['id' => (int) $matches[1]]);
        $media = $stmt->fetch();
        if (!$media) {
            return '';
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
}

require_once __DIR__ . '/../includes/store_helpers.php';
require_once __DIR__ . '/../includes/access_control.php';

enforce_page_access();
