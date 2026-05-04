<?php
require_once __DIR__ . '/../../app/bootstrap.php';
use App\Services\PendingRequestsService;

// Admin only
$user = current_user();
if (!$user || empty($user['id'])) { http_response_code(403); die('Not authorised.'); }

header('Content-Type: text/plain');

// ── Notification Hub debug ─────────────────────────────────────────────────
echo "=== NOTIFICATION HUB ===\n";
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

// ── Member Directory debug ─────────────────────────────────────────────────
echo "\n\n=== MEMBER DIRECTORY ===\n";
$pdo = db();

// Raw member count and status values
$statusRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM members GROUP BY status ORDER BY cnt DESC")->fetchAll();
echo "Member status distribution:\n";
foreach ($statusRows as $r) {
    echo "  status=" . var_export($r['status'], true) . " => " . $r['cnt'] . " members\n";
}

// Check if 'active' members exist
$activeCount = $pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
echo "\nMembers with status='active': $activeCount\n";

// Check all columns on members table
$cols = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN, 0);
echo "\nMembers table columns:\n  " . implode(', ', $cols) . "\n";

// Try the actual directory query
try {
    $stmt = $pdo->query("SELECT m.id, m.first_name, m.last_name, m.status FROM members m WHERE m.status = 'active' LIMIT 5");
    $rows = $stmt->fetchAll();
    echo "\nSample active members: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "  " . $r['first_name'] . ' ' . $r['last_name'] . " (status=" . $r['status'] . ")\n";
    }
} catch (\Throwable $e) {
    echo "\nDirectory query error: " . $e->getMessage() . "\n";
}

// Check if beta_feedback table exists and has data
echo "\n\n=== BETA FEEDBACK TABLE ===\n";
try {
    $fbCount = $pdo->query("SELECT COUNT(*) FROM beta_feedback")->fetchColumn();
    $fbCols  = $pdo->query("SHOW COLUMNS FROM beta_feedback")->fetchAll(PDO::FETCH_COLUMN, 0);
    echo "Row count: $fbCount\n";
    echo "Columns: " . implode(', ', $fbCols) . "\n";
    $sample = $pdo->query("SELECT id, status, message, created_at FROM beta_feedback ORDER BY id DESC LIMIT 3")->fetchAll();
    foreach ($sample as $r) {
        echo "  id=" . $r['id'] . " status=" . $r['status'] . " created=" . $r['created_at'] . " msg=" . substr($r['message'], 0, 40) . "\n";
    }
} catch (\Throwable $e) {
    echo "beta_feedback error: " . $e->getMessage() . "\n";
}
