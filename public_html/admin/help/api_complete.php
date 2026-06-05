<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\TourService;

header('Content-Type: application/json');

require_login();
$user = current_user();
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['slug']) || empty($payload['csrf'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
    return;
}
if (!Csrf::verify((string) $payload['csrf'])) {
    http_response_code(419);
    echo json_encode(['error' => 'CSRF token invalid']);
    return;
}

$slug = preg_replace('/[^a-z0-9_\-]/i', '', (string) $payload['slug']);
if ($slug === '' || !TourService::tour($slug)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown tour']);
    return;
}

TourService::markCompleted((int) $user['id'], $slug);
echo json_encode(['ok' => true]);
