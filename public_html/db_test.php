<?php
require_once __DIR__ . '/../app/Services/Env.php';

use App\Services\Env;

Env::load(__DIR__ . '/../.env');
Env::load(__DIR__ . '/../.env.local');

$config = require __DIR__ . '/../config/database.php';
$port = $config['port'] ?? 3306;
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $port,
    $config['database'],
    $config['charset']
);

echo "DB_HOST=" . $config['host'] . PHP_EOL;
echo "DB_PORT=" . $port . PHP_EOL;
echo "DB_NAME=" . $config['database'] . PHP_EOL;
echo "DB_USER=" . $config['username'] . PHP_EOL;

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo "DB OK";
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage();
}
