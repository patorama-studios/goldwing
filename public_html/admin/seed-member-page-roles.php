<?php
/**
 * One-time seeder for member portal page visibility (Role Builder → "Member
 * Portal Pages"). Grants every gateable member page to every EXISTING role, so
 * the base "Member" role (and the others) show every page ticked and current
 * members keep seeing everything until an admin deliberately un-ticks a page.
 *
 * Insert-only: never overwrites a later admin customisation, and roles created
 * after this runs start blank on purpose. Idempotent — safe to run repeatedly.
 * Visit this URL once, while logged in as admin, after deploying the feature.
 *
 * (Kept separate from run-migration.php only because that file currently holds
 * unrelated uncommitted work; fold this in as a normal migration once that
 * lands.)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

if (function_exists('opcache_reset')) { opcache_reset(); }

$user = current_user();
if (!$user || empty($user['id'])) {
    http_response_code(403);
    die('Not authorised. Please log in as admin first.');
}
if (!function_exists('current_admin_can') || !current_admin_can('admin.settings.general.manage', $user)) {
    http_response_code(403);
    die('Not authorised. Admin settings permission required.');
}

$status = 'applied';
$note   = '';
try {
    $pdo  = db();
    $keys = function_exists('member_page_permission_keys') ? member_page_permission_keys() : [];
    $inserted = 0;
    if ($keys) {
        $seed = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :permission_key, 1, NOW(), NOW() FROM roles r
             ON DUPLICATE KEY UPDATE updated_at = role_permissions.updated_at'
        );
        foreach ($keys as $permKey) {
            $seed->execute([':permission_key' => $permKey]);
            $inserted += $seed->rowCount();
        }
    }
    $note = count($keys) . ' page(s) granted to all existing roles (' . $inserted . ' new rows).';
} catch (Throwable $e) {
    $status = 'error';
    $note   = $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seed Member Page Roles — Admin</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f8fafc; padding: 2rem; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; max-width: 640px; margin: 0 auto; }
    h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 1rem; }
    .badge { padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
    .applied { background: #dcfce7; color: #166534; }
    .error   { background: #fee2e2; color: #991b1b; }
    .note { font-size: 0.85rem; color: #475569; margin-top: 0.75rem; }
    .back { display: inline-block; margin-top: 1.25rem; font-size: 0.875rem; color: #3b82f6; text-decoration: none; }
  </style>
</head>
<body>
  <div class="card">
    <h1>🔧 Seed Member Portal Page Roles</h1>
    <span class="badge <?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
    <p class="note"><?= htmlspecialchars($note) ?></p>
    <p class="note">You only need to run this once. It's safe to run again — it won't overwrite any pages you've since un-ticked in the Role Builder.</p>
    <a class="back" href="/admin/settings/roles.php">← Go to the Admin Role Builder</a>
  </div>
</body>
</html>
