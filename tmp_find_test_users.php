<?php
require_once __DIR__ . '/app/bootstrap.php';
try {
    $pdo = db();
    $stmt = $pdo->query("SELECT id, name, email FROM users WHERE LOWER(email) LIKE '%test%' OR LOWER(name) LIKE '%test%' OR LOWER(email) LIKE '%qa%' ORDER BY id DESC LIMIT 20");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found Test Users in `users` table:\n";
    foreach ($users as $user) {
        echo "ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
    }

    $stmt2 = $pdo->query("SELECT id, first_name, last_name, email, member_type FROM members WHERE LOWER(email) LIKE '%test%' OR LOWER(first_name) LIKE '%test%' OR LOWER(last_name) LIKE '%test%' ORDER BY id DESC LIMIT 20");
    $members = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "\nFound Test Members in `members` table:\n";
    foreach ($members as $member) {
        echo "ID: {$member['id']}, Name: {$member['first_name']} {$member['last_name']}, Email: {$member['email']}, Type: {$member['member_type']}\n";
    }

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
