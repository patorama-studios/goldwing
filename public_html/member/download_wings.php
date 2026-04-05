<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo "Issue ID required.";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM wings_issues WHERE id = :id');
$stmt->execute(['id' => $id]);
$issue = $stmt->fetch();

if (!$issue) {
    http_response_code(404);
    echo "Issue not found.";
    exit;
}

// Increment download counter
$update = $pdo->prepare('UPDATE wings_issues SET downloads = downloads + 1 WHERE id = :id');
$update->execute(['id' => $id]);

// Redirect to actual file
if (empty($issue['pdf_url'])) {
    http_response_code(404);
    echo "No PDF available for this issue.";
    exit;
}

$url = $issue['pdf_url'];

// If it's an external URL, redirect
if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
    header('Location: ' . $url);
    exit;
}

// Serve local files directly to bypass directory protection or path issues
$pdfPath = realpath(__DIR__ . '/..' . $url);
if (!$pdfPath || !file_exists($pdfPath)) {
    http_response_code(404);
    echo "PDF file not found on server.";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdfPath) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($pdfPath));
readfile($pdfPath);
exit;
