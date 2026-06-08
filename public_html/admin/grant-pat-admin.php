<?php
/**
 * One-off elevation script for hi@patorama.com.au.
 *
 * Why this exists (and why it bypasses require_permission):
 *   The Access Control page is gated by `admin.roles.manage`. If the seed
 *   migration didn't populate role_permissions for the admin role, even a
 *   user who already holds the admin role can't reach Access Control to
 *   fix it — chicken-and-egg. This script breaks the loop by:
 *     1) checking the SESSION user's email matches hi@patorama.com.au
 *     2) ensuring that user is linked to the 'admin' role
 *     3) seeding role_permissions for the admin role with every known
 *        admin permission key (idempotent).
 *
 * Gate: must be logged in AS hi@patorama.com.au. No password, no token —
 * the session is the auth. Worst case, that user elevates themselves,
 * which is exactly what this script is for.
 *
 * Delete this file after running.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;

header('Content-Type: text/plain; charset=utf-8');

require_login();

$TARGET_EMAIL = 'hi@patorama.com.au';
$user = current_user();
$sessionEmail = strtolower((string) ($user['email'] ?? ''));
if ($sessionEmail !== $TARGET_EMAIL) {
    http_response_code(403);
    echo "Forbidden. This script can only be run while logged in as {$TARGET_EMAIL}.\n";
    echo "Current session email: " . ($sessionEmail !== '' ? $sessionEmail : '(none)') . "\n";
    exit;
}

echo "=== Grant full admin to {$TARGET_EMAIL} ===\n";
echo "Time: " . date('c') . "\n\n";

$pdo = Database::connection();

/* ----------------------------------------------------------------------
 * 1. Target user lookup
 * -------------------------------------------------------------------- */
echo "--- 1. Target user ---\n";
$stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE LOWER(email) = :email LIMIT 1');
$stmt->execute([':email' => $TARGET_EMAIL]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    echo "(no user row found for {$TARGET_EMAIL} — aborting)\n";
    exit;
}
$userId = (int) $target['id'];
echo "id:    {$target['id']}\n";
echo "name:  " . ($target['name'] ?? '(null)') . "\n";
echo "email: {$target['email']}\n\n";

/* ----------------------------------------------------------------------
 * 2. Ensure 'admin' role exists
 * -------------------------------------------------------------------- */
echo "--- 2. Admin role ---\n";
$stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
$stmt->execute();
$adminRole = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$adminRole) {
    echo "No 'admin' role found — creating it.\n";
    $pdo->prepare(
        "INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
         VALUES ('admin', 'admin', 'Admin (full access)', 1, 1, NOW(), NOW())"
    )->execute();
    $adminRoleId = (int) $pdo->lastInsertId();
} else {
    $adminRoleId = (int) $adminRole['id'];
}
echo "admin role_id: {$adminRoleId}\n\n";

/* ----------------------------------------------------------------------
 * 3. Link user to admin role (idempotent)
 * -------------------------------------------------------------------- */
echo "--- 3. user_roles link ---\n";
$stmt = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :u AND role_id = :r LIMIT 1');
$stmt->execute([':u' => $userId, ':r' => $adminRoleId]);
if ($stmt->fetchColumn()) {
    echo "Already linked.\n\n";
} else {
    $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:u, :r)')
        ->execute([':u' => $userId, ':r' => $adminRoleId]);
    echo "Linked user_id={$userId} to admin role_id={$adminRoleId}.\n\n";
}

/* ----------------------------------------------------------------------
 * 4. Seed role_permissions for admin role with every known key
 * -------------------------------------------------------------------- */
echo "--- 4. Seed role_permissions for admin role ---\n";
if (!admin_permissions_tables_ready()) {
    echo "role_permissions table missing — skipping (fallback in code will grant full access by role name).\n\n";
} else {
    $keys = admin_permission_keys();
    $upsert = $pdo->prepare(
        'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
         VALUES (:role_id, :key, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
    );
    $count = 0;
    foreach ($keys as $key) {
        $upsert->execute([':role_id' => $adminRoleId, ':key' => $key]);
        $count++;
    }
    echo "Upserted {$count} permission rows for admin role.\n\n";
}

/* ----------------------------------------------------------------------
 * 5. Refresh session roles so the change takes effect this request
 * -------------------------------------------------------------------- */
echo "--- 5. Refresh session ---\n";
$stmt = $pdo->prepare(
    'SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id'
);
$stmt->execute([':id' => $userId]);
$roleNames = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
$_SESSION['user']['roles'] = $roleNames;
echo "Session roles now: " . implode(', ', $roleNames) . "\n\n";

/* ----------------------------------------------------------------------
 * 6. Verify access
 * -------------------------------------------------------------------- */
echo "--- 6. Verify ---\n";
$canManageRoles = current_admin_can('admin.roles.manage', current_user());
echo "current_admin_can('admin.roles.manage') = " . ($canManageRoles ? 'TRUE' : 'FALSE') . "\n\n";

echo "=== DONE ===\n";
echo "Now visit: /admin/settings/access-control.php\n";
echo "Then DELETE this file (/admin/grant-pat-admin.php) from cPanel.\n";
