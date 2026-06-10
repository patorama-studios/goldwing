<?php
// TEMPORARY DIAGNOSTIC — DELETE AFTER USE
require_once __DIR__ . '/../../../app/bootstrap.php';
require_login();
$user = current_user();
if (!$user || empty($user['id']) || (int)$user['id'] !== 1) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
echo "=== AUDIT HUB DIAGNOSE ===\n\n";

use App\Services\AuditHubService;

function run(string $name, callable $cb): void
{
    echo "[$name]\n";
    try {
        $result = $cb();
        echo "  OK — " . (is_array($result) ? 'rows: ' . (isset($result['rows']) ? count($result['rows']) : count($result)) : (string) $result) . "\n";
    } catch (Throwable $e) {
        echo "  EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
        echo "  FILE: " . $e->getFile() . ':' . $e->getLine() . "\n";
        echo "  TRACE:\n  " . str_replace("\n", "\n  ", $e->getTraceAsString()) . "\n";
    }
    echo "\n";
}

run('AuditHubService::stats()', fn() => AuditHubService::stats());
run('AuditHubService::distinctActions()', fn() => AuditHubService::distinctActions());
run('AuditHubService::query([], 10, 0)', fn() => AuditHubService::query([], 10, 0));

// Table sanity
echo "[table existence + row counts]\n";
foreach (['audit_log', 'audit_logs', 'activity_log'] as $t) {
    try {
        $n = (int) db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  $t: rows=$n\n";
    } catch (Throwable $e) {
        echo "  $t: ERROR — " . $e->getMessage() . "\n";
    }
}
