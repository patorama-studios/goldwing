<?php
// TEMPORARY DIAGNOSTIC — DELETE AFTER USE
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();
$user = current_user();
if ((int)($user['id'] ?? 0) !== 1) { http_response_code(403); echo 'Forbidden'; exit; }
header('Content-Type: text/plain; charset=utf-8');

echo "current_user():\n";
echo "  id           = " . ($user['id'] ?? '-') . "\n";
echo "  name         = " . ($user['name'] ?? '-') . "\n";
echo "  email        = " . ($user['email'] ?? '-') . "\n";
echo "  member_id    = " . ($user['member_id'] ?? '(null)') . "\n";
echo "\n";

$pdo = db();
$mid = (int) ($user['member_id'] ?? 0);
if ($mid > 0) {
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, member_number_base, member_number_suffix FROM members WHERE id = :id');
    $stmt->execute(['id' => $mid]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "members row for current user.member_id=$mid:\n";
    if ($m) {
        foreach ($m as $k => $v) echo "  $k = " . var_export($v, true) . "\n";
    } else {
        echo "  (no row found — broken FK)\n";
    }
}

echo "\n--- users.name on the users table (the source of the leak) ---\n";
$stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id');
$stmt->execute(['id' => (int)$user['id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
foreach ($u as $k => $v) echo "  $k = " . var_export($v, true) . "\n";

echo "\n--- any other Lindley members ---\n";
$stmt = $pdo->query("SELECT id, first_name, last_name, email FROM members WHERE last_name LIKE '%Lindley%' OR last_name LIKE '%MASTER%' LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  #{$r['id']} {$r['first_name']} / {$r['last_name']} ({$r['email']})\n";
if (!$rows) echo "  (none)\n";
