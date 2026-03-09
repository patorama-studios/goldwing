<?php
require_once __DIR__ . '/../app/bootstrap.php';
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

header('Location: ' . $issue['pdf_url']);
exit;
