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

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

$user = current_user();
$userName = $user ? ($user['name'] ?? 'Unknown Member') : 'Guest';
$userEmail = $user ? ($user['email'] ?? 'No email') : 'No email';
$userId = $user ? ($user['id'] ?? 'N/A') : 'N/A';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

$subject = 'Goldwing Feedback request';
$body = "
<div style='font-family: sans-serif; line-height: 1.5; color: #333;'>
  <h2 style='color: #CFA032;'>Goldwing Beta Feedback</h2>
  <p><strong>Member Name:</strong> " . e($userName) . "</p>
  <p><strong>Member Email:</strong> " . e($userEmail) . "</p>
  <p><strong>User ID:</strong> " . e($userId) . "</p>
  <p><strong>Device/Browser:</strong> " . e($userAgent) . "</p>
  <hr style='border: 0; border-bottom: 1px solid #eee; margin: 20px 0;'>
  <p><strong>Message:</strong></p>
  <div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #F2C94C;'>
    " . nl2br(e($message)) . "
  </div>
</div>
";

$sent = EmailService::send('dev@patorama.com.au', $subject, $body);

if ($sent) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send email']);
}
