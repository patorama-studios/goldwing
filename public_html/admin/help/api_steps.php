<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\TourService;

header('Content-Type: application/json');

require_login();
$user = current_user();

$slug = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($_GET['slug'] ?? ''));
if ($slug === '' || !TourService::tour($slug)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown tour']);
    return;
}

// Admin-only "draft preview" mode: ?preview=1 overlays draft_* values so the
// wording editor can show "live preview" before publish.
$preview = !empty($_GET['preview']) &&
    (in_array('admin', $user['roles'] ?? [], true) || in_array('webmaster', $user['roles'] ?? [], true));

$steps = TourService::stepsFor($slug, $preview);
if (!$steps) {
    http_response_code(404);
    echo json_encode(['error' => 'No published steps for this tour yet']);
    return;
}

echo json_encode(['slug' => $slug, 'steps' => $steps, 'preview' => $preview]);
