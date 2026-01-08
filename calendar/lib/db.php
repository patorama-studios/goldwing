<?php
require_once __DIR__ . '/utils.php';

function calendar_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (class_exists('App\\Services\\Database')) {
        $pdo = App\Services\Database::connection();
        return $pdo;
    }
    $host = calendar_config('db.host');
    $name = calendar_config('db.name');
    $user = calendar_config('db.user');
    $pass = calendar_config('db.pass');
    $charset = calendar_config('db.charset', 'utf8mb4');
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function calendar_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function calendar_events_has_chapter_id(PDO $pdo): bool
{
    return calendar_table_has_column($pdo, 'calendar_events', 'chapter_id');
}
