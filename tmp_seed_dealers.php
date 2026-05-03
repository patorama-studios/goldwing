<?php
require_once __DIR__ . '/app/Services/Env.php';
\App\Services\Env::load(__DIR__ . '/.env');
\App\Services\Env::load(__DIR__ . '/.env.local');

$config = require __DIR__ . '/config/database.php';
$port = $config['port'] ?? 3306;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $port, $config['database'], $config['charset']);

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("INSERT INTO honda_dealers (name, state, address, phone, website) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['Sydney City Motorcycles', 'NSW', '123 Parramatta Rd, Auburn NSW', '02 9123 4567', 'https://sydneycitymotorcycles.com.au']);
    $stmt->execute(['Brisbane Motorcycles', 'QLD', '456 Gympie Rd, Kedron QLD', '07 3123 4567', 'https://brisbanemotorcycles.com.au']);
    $stmt->execute(['Honda World Melbourne', 'VIC', '789 Dandenong Rd, Springvale VIC', '03 9123 4567', '']);
    $stmt->execute(['Perth Metro Honda', 'WA', '101 Albany Hwy, Victoria Park WA', '08 9123 4567', 'https://honda.com.au']);
    echo "Dealers seeded.\n";
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
