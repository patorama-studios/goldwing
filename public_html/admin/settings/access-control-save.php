<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\NavigationService;

require_permission('admin.roles.manage');

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    $_SESSION['access_control_toast'] = 'Invalid CSRF token.';
    header('Location: /admin/settings/access-control.php');
    exit;
}

sync_access_registry();

$role = normalize_access_role($_POST['role'] ?? '');
$roleOptions = access_control_roles();
if (!in_array($role, $roleOptions, true)) {
    $_SESSION['access_control_toast'] = 'Invalid role.';
    header('Location: /admin/settings/access-control.php');
    exit;
}

$pageIds = array_map('intval', $_POST['page_ids'] ?? []);
$pageIds = array_values(array_filter($pageIds));
$access = $_POST['access'] ?? [];
$resetDefaults = ($_POST['reset_defaults'] ?? '0') === '1';

if (!$pageIds) {
    $_SESSION['access_control_toast'] = 'No pages selected.';
    header('Location: /admin/settings/access-control.php?role=' . rawurlencode($role));
    exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $defaults = access_control_default_registry();
    $defaultMap = [];
    foreach ($defaults as $entry) {
        $defaultMap[$entry['page_key']] = $entry['roles'];
    }

    $lookupStmt = $pdo->query('SELECT id, page_key FROM pages_registry');
    $lookupRows = $lookupStmt->fetchAll() ?: [];
    $pageKeyById = [];
    foreach ($lookupRows as $row) {
        $pageKeyById[(int) $row['id']] = $row['page_key'];
    }

    $upsert = $pdo->prepare('INSERT INTO page_role_access (page_id, role, can_access, created_at, updated_at)
        VALUES (:page_id, :role, :can_access, NOW(), NOW())
        ON DUPLICATE KEY UPDATE can_access = VALUES(can_access), updated_at = NOW()');

    foreach ($pageIds as $pageId) {
        $canAccess = 0;
        if ($resetDefaults) {
            $pageKey = $pageKeyById[$pageId] ?? null;
            if ($pageKey && isset($defaultMap[$pageKey])) {
                $canAccess = in_array($role, $defaultMap[$pageKey], true) ? 1 : 0;
            }
        } else {
            $canAccess = isset($access[$pageId]) ? 1 : 0;
        }
        $upsert->execute([
            'page_id' => $pageId,
            'role' => $role,
            'can_access' => $canAccess,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['access_control_toast'] = 'Failed to update permissions.';
    header('Location: /admin/settings/access-control.php?role=' . rawurlencode($role));
    exit;
}

ActivityLogger::log('admin', (int) current_user()['id'], null, 'access_control.updated', [
    'role' => $role,
    'reset' => $resetDefaults,
]);

NavigationService::clearCache();
$_SESSION['access_control_toast'] = 'Permissions updated.';
header('Location: /admin/settings/access-control.php?role=' . rawurlencode($role));
exit;
