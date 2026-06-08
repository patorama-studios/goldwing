<?php
/**
 * One-off page-access reset for the admin role.
 *
 * Why: grant-pat-admin.php fixed the role_permissions table, but the
 * separate page_role_access table can also block admin pages. The default
 * sync (sync_access_registry) uses `ON DUPLICATE KEY UPDATE can_access =
 * can_access` so any manual toggle to deny admin sticks forever.
 *
 * This script forces can_access=1 for the 'admin' role on every page in
 * pages_registry. It does NOT touch other roles' rows.
 *
 * Gate: must be logged in as hi@patorama.com.au.
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
    echo "Forbidden. Run while logged in as {$TARGET_EMAIL}.\n";
    echo "Current session: " . ($sessionEmail !== '' ? $sessionEmail : '(none)') . "\n";
    exit;
}

echo "=== Reset page_role_access for admin role ===\n";
echo "Time: " . date('c') . "\n\n";

$pdo = Database::connection();

if (!access_control_tables_ready()) {
    echo "pages_registry table missing — nothing to do.\n";
    exit;
}

/* ----------------------------------------------------------------------
 * 1. Sync the registry first so any new default pages exist
 * -------------------------------------------------------------------- */
echo "--- 1. Sync registry ---\n";
sync_access_registry();
echo "Done.\n\n";

/* ----------------------------------------------------------------------
 * 2. BEFORE snapshot — pages currently denying admin
 * -------------------------------------------------------------------- */
echo "--- 2. Pages currently DENYING admin ---\n";
$stmt = $pdo->query(
    "SELECT p.id, p.label, p.path_pattern, COALESCE(pra.can_access, -1) AS can_access
       FROM pages_registry p
       LEFT JOIN page_role_access pra ON pra.page_id = p.id AND pra.role = 'admin'
      WHERE p.is_enabled = 1
        AND (pra.can_access IS NULL OR pra.can_access = 0)
      ORDER BY p.nav_group, p.label"
);
$denied = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$denied) {
    echo "(none)\n\n";
} else {
    foreach ($denied as $row) {
        $state = (int) $row['can_access'] === -1 ? 'no-row' : 'can_access=0';
        echo "  [{$state}] {$row['label']}  ({$row['path_pattern']})\n";
    }
    echo "\n";
}

/* ----------------------------------------------------------------------
 * 3. Force can_access=1 for admin on every page
 * -------------------------------------------------------------------- */
echo "--- 3. Forcing admin = allow on every page ---\n";
$pages = $pdo->query('SELECT id FROM pages_registry WHERE is_enabled = 1')->fetchAll(PDO::FETCH_ASSOC);
$upsert = $pdo->prepare(
    "INSERT INTO page_role_access (page_id, role, can_access, created_at, updated_at)
     VALUES (:page_id, 'admin', 1, NOW(), NOW())
     ON DUPLICATE KEY UPDATE can_access = 1, updated_at = NOW()"
);
$count = 0;
foreach ($pages as $p) {
    $upsert->execute([':page_id' => (int) $p['id']]);
    $count++;
}
echo "Upserted {$count} rows.\n\n";

/* ----------------------------------------------------------------------
 * 4. AFTER snapshot — anything still denied (should be empty)
 * -------------------------------------------------------------------- */
echo "--- 4. After: pages still denying admin ---\n";
$stmt = $pdo->query(
    "SELECT p.label, p.path_pattern, COALESCE(pra.can_access, -1) AS can_access
       FROM pages_registry p
       LEFT JOIN page_role_access pra ON pra.page_id = p.id AND pra.role = 'admin'
      WHERE p.is_enabled = 1
        AND (pra.can_access IS NULL OR pra.can_access = 0)
      ORDER BY p.label"
);
$stillDenied = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$stillDenied) {
    echo "(none — admin can now reach every registered page)\n\n";
} else {
    foreach ($stillDenied as $row) {
        echo "  {$row['label']}  ({$row['path_pattern']})\n";
    }
    echo "\n";
}

/* ----------------------------------------------------------------------
 * 5. Verify the specific page that triggered this
 * -------------------------------------------------------------------- */
echo "--- 5. Verify /admin/settings/access-control.php ---\n";
$canAccess = can_access_path(current_user(), '/admin/settings/access-control.php');
echo "can_access_path('admin', '/admin/settings/access-control.php') = " . ($canAccess ? 'TRUE' : 'FALSE') . "\n\n";

echo "=== DONE ===\n";
echo "Now visit /admin/settings/access-control.php — should load.\n";
echo "DELETE both /admin/grant-pat-admin.php and /admin/fix-pat-page-access.php after.\n";
