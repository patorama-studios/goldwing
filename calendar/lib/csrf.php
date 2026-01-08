<?php
require_once __DIR__ . '/auth.php';

function calendar_csrf_token(): string
{
    calendar_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function calendar_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . calendar_e(calendar_csrf_token()) . '">';
}

function calendar_csrf_verify(): void
{
    calendar_start_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        echo 'Invalid CSRF token';
        exit;
    }
}
