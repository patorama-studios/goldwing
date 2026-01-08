<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AiProviderKeyService;
use App\Services\AuditService;
use App\Services\Csrf;
use App\Services\EncryptionService;

require_role(['admin']);

header('Content-Type: application/json');

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = current_user();

if ($method === 'GET') {
    $providers = config('ai.providers', []);
    $result = [];
    foreach ($providers as $key => $provider) {
        $meta = AiProviderKeyService::getMeta($key);
        $result[$key] = [
            'configured' => $meta['configured'],
            'last4' => $meta['last4'],
        ];
    }
    json_response([
        'ok' => true,
        'providers' => $result,
        'encryption_ready' => EncryptionService::isReady(),
    ]);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
    if (!Csrf::verify($csrfToken)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }
    if (!EncryptionService::isReady()) {
        json_response(['ok' => false, 'error' => 'APP_KEY is not configured.'], 400);
    }

    $providers = config('ai.providers', []);
    $inputKeys = $data['keys'] ?? [];
    if (!is_array($inputKeys)) {
        $inputKeys = [];
    }

    foreach ($providers as $key => $provider) {
        if (!array_key_exists($key, $inputKeys)) {
            continue;
        }
        $value = trim((string) $inputKeys[$key]);
        AiProviderKeyService::upsertKey($key, $value, (int) ($user['id'] ?? 0));
    }

    AuditService::log($user['id'] ?? null, 'ai_provider_keys_updated', 'AI provider keys updated.');

    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Unsupported method.'], 405);
