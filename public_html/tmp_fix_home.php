<?php
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// Fix 1: Update the home page live_html to remove the duplicated tagline and page-card wrapper
// The new index.php renders the hero + sponsors directly, so we just need clean body content
$homeHtml = '<p>Australian Goldwing Association is the national home for riders, chapters, and the open road.</p>';

$stmt = $pdo->prepare("UPDATE pages SET live_html = :html, html_content = :html, draft_html = :html WHERE slug = 'home'");
$stmt->execute(['html' => $homeHtml]);
echo "Updated home page content.\n";

// Fix 2: Verify the constitution PDF link points to the right place
$stmt = $pdo->prepare("SELECT live_html FROM pages WHERE slug = 'constitution'");
$stmt->execute();
$row = $stmt->fetch();
if ($row && strpos($row['live_html'], '/uploads/about/constitution.pdf') !== false) {
    echo "Constitution PDF link is correct.\n";
} else {
    echo "Constitution PDF link may need updating.\n";
}

echo "Done.\n";
