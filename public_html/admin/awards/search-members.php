<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AwardsService;

require_permission('admin.awards.view');

header('Content-Type: application/json; charset=utf-8');

$term = trim((string) ($_GET['q'] ?? ''));
if (strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

$rows = AwardsService::searchMembers($term, 20);
$out = array_map(static function (array $r): array {
    return [
        'id'         => (int) $r['id'],
        'first_name' => $r['first_name'] ?? '',
        'last_name'  => $r['last_name'] ?? '',
        'email'      => $r['email'] ?? '',
    ];
}, $rows);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
