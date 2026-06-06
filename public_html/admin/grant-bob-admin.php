<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;

require_permission('admin.roles.manage');

header('Content-Type: text/plain; charset=utf-8');

// Override via ?user_id=N if needed, default to Bob Watson (15).
$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 15;
if ($userId <= 0) {
    echo "Invalid user_id\n";
    exit;
}

echo "=== Grant admin role ===\n";
echo "Target user_id: {$userId}\n";
echo "Time:           " . date('c') . "\n\n";

$pdo = Database::connection();

/* --------------------------------------------------------------------------
 * 1) Target user sanity check
 * ------------------------------------------------------------------------ */
echo "--- 1. Target user ---\n";
$stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "(no user found with id={$userId})\n";
    exit;
}
echo "id:    {$user['id']}\n";
echo "name:  " . ($user['name'] ?? '(null)') . "\n";
echo "email: " . ($user['email'] ?? '(null)') . "\n\n";

/* --------------------------------------------------------------------------
 * 2) Look up the admin role
 * ------------------------------------------------------------------------ */
echo "--- 2. Admin role lookup ---\n";
$stmt = $pdo->prepare("SELECT id, name FROM roles WHERE name = 'admin' LIMIT 1");
$stmt->execute();
$adminRole = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$adminRole) {
    echo "(no role row found where name = 'admin' — aborting; nothing changed)\n";
    exit;
}
$adminRoleId = (int) $adminRole['id'];
echo "admin role_id: {$adminRoleId}\n\n";

/* --------------------------------------------------------------------------
 * 3) Current user_roles BEFORE
 * ------------------------------------------------------------------------ */
echo "--- 3. user_roles BEFORE ---\n";
$stmt = $pdo->prepare('SELECT ur.user_id, ur.role_id, r.name FROM user_roles ur LEFT JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.name');
$stmt->execute([':id' => $userId]);
$before = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$before) {
    echo "(no rows)\n";
} else {
    foreach ($before as $row) {
        echo "  user_id={$row['user_id']}  role_id={$row['role_id']}  name=" . ($row['name'] ?? '(null)') . "\n";
    }
}
echo "\n";

$alreadyHas = false;
foreach ($before as $row) {
    if ((int) $row['role_id'] === $adminRoleId) {
        $alreadyHas = true;
        break;
    }
}

/* --------------------------------------------------------------------------
 * 4) Grant admin role (idempotent)
 * ------------------------------------------------------------------------ */
echo "--- 4. Grant ---\n";
if ($alreadyHas) {
    echo "User already has admin role — no insert needed.\n\n";
} else {
    $ins = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:u, :r)');
    $ins->execute([':u' => $userId, ':r' => $adminRoleId]);
    $affected = $ins->rowCount();
    echo "INSERT IGNORE INTO user_roles (user_id={$userId}, role_id={$adminRoleId}) — affected rows: {$affected}\n\n";
}

/* --------------------------------------------------------------------------
 * 5) Current user_roles AFTER
 * ------------------------------------------------------------------------ */
echo "--- 5. user_roles AFTER ---\n";
$stmt = $pdo->prepare('SELECT ur.user_id, ur.role_id, r.name FROM user_roles ur LEFT JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.name');
$stmt->execute([':id' => $userId]);
$after = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$after) {
    echo "(no rows)\n";
} else {
    foreach ($after as $row) {
        echo "  user_id={$row['user_id']}  role_id={$row['role_id']}  name=" . ($row['name'] ?? '(null)') . "\n";
    }
}
echo "\n";

echo "=== DONE — delete this file from cPanel now. ===\n";
