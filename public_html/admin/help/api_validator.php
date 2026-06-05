<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\TourService;
use App\Services\EmailService;
use App\Services\SettingsService;

header('Content-Type: application/json');

require_login();
require_role(['admin', 'webmaster']);
$user = current_user();

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['slug']) || empty($payload['csrf']) || empty($payload['status'])) {
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
$tour = TourService::tour($slug);
if (!$tour) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown tour']);
    return;
}

$status = in_array($payload['status'], ['pass', 'fail', 'partial'], true) ? $payload['status'] : 'partial';
$runAsRole = isset($payload['run_as_role']) && is_string($payload['run_as_role'])
    ? preg_replace('/[^a-z_]/', '', $payload['run_as_role'])
    : null;
$results = isset($payload['results']) && is_array($payload['results']) ? $payload['results'] : [];

TourService::recordRun(
    $slug,
    'validator',
    $status,
    (int) ($user['id'] ?? 0),
    $runAsRole ?: null,
    ['steps' => $results]
);

// If the run failed, email the admin support address.
if ($status === 'fail') {
    $supportEmail = SettingsService::getGlobal('site.support_email', '');
    if ($supportEmail) {
        $body = '<p>The Tour Validator reported a <strong>FAIL</strong> for tour <code>' . htmlspecialchars($slug) . '</code>.</p>';
        $body .= '<p>Tested by: ' . htmlspecialchars((string) ($user['name'] ?? $user['email'] ?? 'unknown')) . '</p>';
        $failed = array_filter($results, function ($r) { return ($r['verdict'] ?? '') === 'fail'; });
        if ($failed) {
            $body .= '<h3>Failed steps</h3><ul>';
            foreach ($failed as $r) {
                $body .= '<li><strong>' . htmlspecialchars((string) ($r['title'] ?? '')) . '</strong>';
                if (!empty($r['element'])) {
                    $body .= ' — <code>' . htmlspecialchars((string) $r['element']) . '</code>';
                }
                if (!empty($r['note'])) {
                    $body .= '<br><em>' . htmlspecialchars((string) $r['note']) . '</em>';
                }
                $body .= '</li>';
            }
            $body .= '</ul>';
        }
        $body .= '<p><a href="/admin/help/validator.php">Open the Tour Validator</a></p>';
        EmailService::send($supportEmail, '[Goldwing tours] Validator FAIL: ' . $slug, $body);
    }
}

echo json_encode(['ok' => true, 'status' => $status]);
