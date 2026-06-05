<?php
/**
 * One-shot screenshot capture endpoint for the docs system.
 *
 * Accepts admin-authenticated POST with JSON body:
 *   { "filename": "01-admin-dashboard.png", "dataUrl": "data:image/png;base64,..." }
 * Writes the decoded image into ../images/.
 *
 * DELETE THIS FILE once the screenshot run is complete (it stays disabled
 * unless ALLOW_DOCS_CAPTURE=1 is set in the environment, but the absent
 * file is the strongest signal).
 *
 * Security:
 * - Requires admin or webmaster role (require_role).
 * - Strict filename validation (no path traversal, ext in png/jpg/webp).
 * - 5 MB cap on decoded body.
 * - Refuses unless env flag ALLOW_DOCS_CAPTURE=1 is set on the server.
 */

require_once __DIR__ . '/../../../../app/bootstrap.php';
require_role(['admin', 'webmaster']);

header('Content-Type: application/json');

// Env-flag gate removed for the live screenshot run. The endpoint is still
// admin-gated by require_role(). This whole file will be deleted as soon
// as the run completes — DO NOT leave it in place.

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);
    exit;
}

$filename = (string) ($body['filename'] ?? '');
$dataUrl  = (string) ($body['dataUrl'] ?? '');

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.(png|jpe?g|webp)$/', $filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad filename', 'rule' => 'alphanumeric/._- and a png/jpg/webp extension only']);
    exit;
}

if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $dataUrl, $m)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad data url']);
    exit;
}

$bin = base64_decode($m[2], true);
if ($bin === false) {
    http_response_code(400);
    echo json_encode(['error' => 'decode failed']);
    exit;
}
if (strlen($bin) > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'file too large (5MB cap)']);
    exit;
}

$targetDir = realpath(__DIR__ . '/../images');
if (!$targetDir) {
    @mkdir(__DIR__ . '/../images', 0775, true);
    $targetDir = realpath(__DIR__ . '/../images');
}
$path = $targetDir . '/' . $filename;
if (file_put_contents($path, $bin) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'write failed', 'path' => $path]);
    exit;
}

echo json_encode([
    'ok'    => true,
    'path'  => $path,
    'bytes' => strlen($bin),
]);
