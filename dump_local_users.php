<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=8889;dbname=goldwing;charset=utf8mb4", "root", "root");
    $stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY id ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($users);
    
    $stmt2 = $pdo->query("SELECT id, first_name, last_name, email FROM members ORDER BY id ASC LIMIT 5");
    $members = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    print_r($members);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
