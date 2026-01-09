<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'goldwing',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'base_url' => 'https://example.com/calendar/public',
    'login_url' => '/login.php',
    'session' => [
        'name' => 'goldwing_calendar',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'mail' => [
        'from_email' => 'noreply@example.com',
        'from_name' => 'Australian Goldwing Association',
    ],
    'stripe' => [
        'secret_key' => '',
        'webhook_secret' => '',
    ],
    'timezone_default' => 'Australia/Sydney',
];
