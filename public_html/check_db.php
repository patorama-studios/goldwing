<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../calendar/lib/db.php';
$pdo = calendar_db();
$stmt = $pdo->query("DESCRIBE calendar_events");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($cols, JSON_PRETTY_PRINT);
