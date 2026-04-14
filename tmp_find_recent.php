<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
$stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id DESC LIMIT 5");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "5 Most Recent Users:\n";
foreach ($users as $user) {
    echo "ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
}

$stmt2 = $pdo->query("SELECT id, first_name, last_name, email, member_type FROM members ORDER BY id DESC LIMIT 5");
$members = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\n5 Most Recent Members:\n";
foreach ($members as $member) {
    echo "ID: {$member['id']}, Name: {$member['first_name']} {$member['last_name']}, Email: {$member['email']}, Type: {$member['member_type']}\n";
}
