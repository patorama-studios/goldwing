<?php
require_once __DIR__ . '/../../app/bootstrap.php';
use App\Services\PendingRequestsService;

// Admin only
$user = current_user();
if (!$user || empty($user['id'])) { http_response_code(403); die('Not authorised.'); }

header('Content-Type: text/plain');

$items = PendingRequestsService::all(null, 'pending');
echo "Total items returned: " . count($items) . "\n\n";
foreach ($items as $i => $item) {
    echo "--- Item $i ---\n";
    foreach ($item as $k => $v) {
        if ($k === 'raw') continue;
        echo "  $k: " . var_export($v, true) . "\n";
    }
    echo "\n";
}

echo "\n=== COUNTS ===\n";
$counts = PendingRequestsService::counts();
print_r($counts);
