<?php
$envPath = __DIR__ . '/.env';
$env = parse_ini_file($envPath);
if (!$env) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $env[trim($k)] = trim($v);
        }
    }
}
try {
    $pdo = new PDO("mysql:host=".$env["DB_HOST"].";dbname=".$env["DB_NAME"].";charset=utf8mb4", $env["DB_USER"], $env["DB_PASS"]);
    $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id DESC LIMIT 20;");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
