<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;

require_permission('admin.roles.manage');

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    $_SESSION['admin_roles_toast'] = 'Invalid CSRF token.';
    header('Location: /admin/settings/roles.php');
    exit;
}

$action = $_POST['action'] ?? 'save';
$roleId = (int) ($_POST['role_id'] ?? 0);
$pdo = db();

if ($action === 'delete') {
    if ($roleId <= 0) {
        $_SESSION['admin_roles_toast'] = 'Invalid role.';
        header('Location: /admin/settings/roles.php');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, name, is_system FROM roles WHERE id = :id');
    $stmt->execute(['id' => $roleId]);
    $role = $stmt->fetch();
    if (!$role) {
        $_SESSION['admin_roles_toast'] = 'Role not found.';
        header('Location: /admin/settings/roles.php');
        exit;
    }
    if ((int) $role['is_system'] === 1) {
        $_SESSION['admin_roles_toast'] = 'System roles cannot be deleted.';
        header('Location: /admin/settings/roles.php?role_id=' . $roleId);
        exit;
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id');
    $countStmt->execute(['role_id' => $roleId]);
    if ((int) $countStmt->fetchColumn() > 0) {
        $_SESSION['admin_roles_toast'] = 'Cannot delete a role that is assigned to users.';
        header('Location: /admin/settings/roles.php?role_id=' . $roleId);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM roles WHERE id = :id');
    $stmt->execute(['id' => $roleId]);
    ActivityLogger::log('admin', (int) current_user()['id'], null, 'admin_role.deleted', [
        'role_id' => $roleId,
        'role_name' => $role['name'],
    ]);
    $_SESSION['admin_roles_toast'] = 'Role deleted.';
    header('Location: /admin/settings/roles.php');
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$slug = trim((string) ($_POST['slug'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($name === '' && $roleId <= 0) {
    $_SESSION['admin_roles_toast'] = 'Role name is required.';
    header('Location: /admin/settings/roles.php');
    exit;
}

if ($slug === '') {
    $slug = admin_role_slugify($name);
}
$slug = strtolower($slug);

if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
    $_SESSION['admin_roles_toast'] = 'Slug can only contain letters, numbers, underscores, and dashes.';
    header('Location: /admin/settings/roles.php' . ($roleId > 0 ? '?role_id=' . $roleId : ''));
    exit;
}

$existing = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug AND id != :id');
$existing->execute(['slug' => $slug, 'id' => $roleId]);
if ($existing->fetchColumn()) {
    $_SESSION['admin_roles_toast'] = 'Slug already exists. Choose another.';
    header('Location: /admin/settings/roles.php' . ($roleId > 0 ? '?role_id=' . $roleId : ''));
    exit;
}

$pdo->beginTransaction();
try {
    if ($roleId > 0) {
        $stmt = $pdo->prepare('SELECT id, is_system FROM roles WHERE id = :id');
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch();
        if (!$role) {
            throw new RuntimeException('Role not found.');
        }
        $isSystem = (int) $role['is_system'] === 1;
        if ($isSystem) {
            $stmt = $pdo->prepare('UPDATE roles SET description = :description, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'description' => $description !== '' ? $description : null,
                'id' => $roleId,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE roles SET name = :name, slug = :slug, description = :description, is_active = :is_active, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'is_active' => $isActive,
                'id' => $roleId,
            ]);
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at) VALUES (:name, :slug, :description, 0, :is_active, NOW(), NOW())');
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'is_active' => $isActive,
        ]);
        $roleId = (int) $pdo->lastInsertId();
    }

    $permissionKeys = admin_permission_keys();
    $allowed = array_map('strval', (array) ($_POST['permissions'] ?? []));
    $allowed = array_values(array_unique($allowed));

    $upsert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
        VALUES (:role_id, :permission_key, :allowed, NOW(), NOW())
        ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_at = NOW()');

    foreach ($permissionKeys as $permissionKey) {
        $upsert->execute([
            'role_id' => $roleId,
            'permission_key' => $permissionKey,
            'allowed' => in_array($permissionKey, $allowed, true) ? 1 : 0,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['admin_roles_toast'] = 'Failed to save role.';
    header('Location: /admin/settings/roles.php' . ($roleId > 0 ? '?role_id=' . $roleId : ''));
    exit;
}

ActivityLogger::log('admin', (int) current_user()['id'], null, 'admin_role.updated', [
    'role_id' => $roleId,
    'permissions_count' => count($allowed),
]);

$_SESSION['admin_roles_toast'] = 'Role saved.';
header('Location: /admin/settings/roles.php?role_id=' . $roleId);
exit;
