<?php
require_once __DIR__ . '/../../app/bootstrap.php';
use App\Services\EmailService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$pageUrl = trim($input['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));

if ($message === '') {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

$user = current_user();
$userName  = $user ? ($user['name']  ?? 'Unknown Member') : 'Guest';
$userEmail = $user ? ($user['email'] ?? null) : null;
$userId    = $user ? ($user['id']    ?? null) : null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

// Persist to DB so it appears in the notification hub as a ticket.
$ticketId = null;
try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO beta_feedback (user_id, submitter_name, submitter_email, message, page_url, user_agent, status, created_at)
         VALUES (:user_id, :name, :email, :message, :page_url, :user_agent, "open", NOW())'
    );
    $stmt->execute([
        'user_id'    => $userId ?: null,
        'name'       => $userName,
        'email'      => $userEmail,
        'message'    => $message,
        'page_url'   => $pageUrl !== '' ? substr($pageUrl, 0, 500) : null,
        'user_agent' => substr($userAgent, 0, 500),
    ]);
    $ticketId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    // Table might not exist yet on a server that hasn't run Migration 003.
    // Fall back to email-only behaviour silently.
    error_log('[BetaFeedback] DB insert failed: ' . $e->getMessage());
}

$subject = 'Goldwing Feedback request' . ($ticketId ? ' (#' . $ticketId . ')' : '');
$body = "
<div style='font-family: sans-serif; line-height: 1.5; color: #333;'>
  <h2 style='color: #CFA032;'>Goldwing Beta Feedback</h2>
  " . ($ticketId ? "<p><strong>Ticket #:</strong> " . (int) $ticketId . "</p>" : '') . "
  <p><strong>Member Name:</strong> " . e($userName) . "</p>
  <p><strong>Member Email:</strong> " . e($userEmail ?? 'No email') . "</p>
  <p><strong>User ID:</strong> " . e((string) ($userId ?? 'N/A')) . "</p>
  <p><strong>Page:</strong> " . e($pageUrl !== '' ? $pageUrl : 'N/A') . "</p>
  <p><strong>Device/Browser:</strong> " . e($userAgent) . "</p>
  <hr style='border: 0; border-bottom: 1px solid #eee; margin: 20px 0;'>
  <p><strong>Message:</strong></p>
  <div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #F2C94C;'>
    " . nl2br(e($message)) . "
  </div>
</div>
";

$sent = EmailService::send('dev@patorama.com.au', $subject, $body);

echo json_encode([
    'success'   => $sent || $ticketId !== null,
    'ticket_id' => $ticketId,
]);
