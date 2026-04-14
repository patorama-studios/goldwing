<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = db();
$stmt2 = $pdo->query("SELECT live_html FROM pages WHERE slug = 'chapters-representatives'");
$row = $stmt2->fetch(PDO::FETCH_ASSOC);
file_put_contents('tmp_chapters_html.txt', $row['live_html']);
echo "Saved to tmp_chapters_html.txt";
