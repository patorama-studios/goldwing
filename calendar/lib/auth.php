<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

function calendar_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $session = calendar_config('session');
    $secure = (bool) ($session['secure'] ?? true);
    if (calendar_is_https()) {
        $secure = true;
    }
    session_name($session['name'] ?? 'goldwing_calendar');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => (bool) ($session['httponly'] ?? true),
        'samesite' => $session['samesite'] ?? 'Lax',
    ]);
    session_start();
}

function calendar_current_user(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    calendar_start_session();
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $sessionUser = $_SESSION['user'];
        $roles = $sessionUser['roles'] ?? [];
        if (empty($roles) && !empty($sessionUser['role'])) {
            $roles = [$sessionUser['role']];
        }
        $sessionUser['roles'] = $roles;
        $cached = $sessionUser;
        return $cached;
    }
    if (empty($_SESSION['user_id'])) {
        $cached = null;
        return null;
    }
    $pdo = calendar_db();
    $stmt = $pdo->prepare('SELECT id, email, role, chapter_id FROM users WHERE id = :id');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $cached = null;
        return null;
    }
    $roles = [];
    if (!empty($user['role'])) {
        $roles[] = $user['role'];
    }
    $user['roles'] = $roles;
    $cached = $user;
    return $cached;
}

function calendar_require_login(): void
{
    if (!calendar_current_user()) {
        calendar_redirect(calendar_config('login_url', '/login.php'));
    }
}

function calendar_require_role(array $roles): void
{
    calendar_require_login();
    $user = calendar_current_user();
    if (!$user) {
        calendar_redirect(calendar_config('login_url', '/login.php'));
    }
    $userRoles = array_map('strtolower', $user['roles'] ?? []);
    foreach ($roles as $role) {
        if (in_array(strtolower($role), $userRoles, true)) {
            return;
        }
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function calendar_is_member(): bool
{
    return (bool) calendar_current_user();
}
