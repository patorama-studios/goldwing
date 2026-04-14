<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
$stmt = $pdo->query("SELECT id, slug, title FROM pages");
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pages as $page) {
    echo $page['id'] . " - " . $page['slug'] . " - " . $page['title'] . "\n";
}
