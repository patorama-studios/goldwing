<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\WingsSendService;

// Same rule as read_wings.php: a Wings email link (?t=) needs no login.
$recipient = WingsSendService::recipientForToken((string) ($_GET['t'] ?? ''));
if (!$recipient) {
    require_login();
}

$id = $recipient ? (int) $recipient['issue_id'] : (int) ($_GET['id'] ?? 0);
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

// Only count as a download when not just viewing in the flipbook reader
$viewOnly = !empty($_GET['view']);
if (!$viewOnly) {
    $update = $pdo->prepare('UPDATE wings_issues SET downloads = downloads + 1 WHERE id = :id');
    $update->execute(['id' => $id]);

    // Per-member attribution for the daily summary's Wings leaderboard. The
    // counter above is per-issue only and can't say who read what.
    $u = current_user();
    $readerMemberId = $recipient ? (int) $recipient['member_id'] : (int) ($u['member_id'] ?? 0);
    if ($recipient) {
        WingsSendService::markOpened((int) $recipient['id']);
    }
    if ($readerMemberId > 0) {
        \App\Services\ActivityLogger::log('member', $u ? (int) $u['id'] : null, $readerMemberId, 'wings.download', [
            'issue_id' => $id,
            'title' => $issue['title'] ?? '',
            'via' => $recipient ? 'email_link' : 'portal',
        ]);
    }
}

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
