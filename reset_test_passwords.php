<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=8889;dbname=goldwing;charset=utf8mb4", "root", "root");
    $hash = password_hash("password", PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email LIKE '%@goldwing.local'");
    $stmt->execute([$hash]);
    echo "Updated passwords to 'password' for " . $stmt->rowCount() . " test users.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
