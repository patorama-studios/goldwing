<?php

function admin_permission_registry(): array
{
    return [
        'Core Admin' => [
            ['key' => 'admin.dashboard.view', 'label' => 'View dashboard'],
            ['key' => 'admin.users.view', 'label' => 'View users'],
            ['key' => 'admin.users.create', 'label' => 'Create users'],
            ['key' => 'admin.users.edit', 'label' => 'Edit users'],
            ['key' => 'admin.users.disable', 'label' => 'Disable users'],
            ['key' => 'admin.roles.view', 'label' => 'View roles'],
            ['key' => 'admin.roles.manage', 'label' => 'Manage roles'],
        ],
        'Membership' => [
            ['key' => 'admin.members.view', 'label' => 'View members'],
            ['key' => 'admin.members.edit', 'label' => 'Edit members'],
            ['key' => 'admin.members.renew', 'label' => 'Renew memberships'],
            ['key' => 'admin.members.manual_payment', 'label' => 'Manual payments'],
            ['key' => 'admin.members.import_export', 'label' => 'Import/export members'],
            ['key' => 'admin.membership_types.manage', 'label' => 'Manage membership types'],
            ['key' => 'admin.payments.view', 'label' => 'View payments'],
            ['key' => 'admin.payments.refund', 'label' => 'Refund payments'],
        ],
        'Store / Orders' => [
            ['key' => 'admin.store.view', 'label' => 'Access store admin'],
            ['key' => 'admin.products.manage', 'label' => 'Manage products'],
            ['key' => 'admin.orders.view', 'label' => 'View orders'],
            ['key' => 'admin.orders.fulfil', 'label' => 'Fulfil orders'],
            ['key' => 'admin.orders.refund_cancel', 'label' => 'Refund/cancel orders'],
            ['key' => 'admin.order_fulfilment.manage', 'label' => 'Manage fulfilment workflow'],
        ],
        'Content / Pages' => [
            ['key' => 'admin.pages.view', 'label' => 'View pages'],
            ['key' => 'admin.pages.edit', 'label' => 'Edit pages'],
            ['key' => 'admin.pages.publish', 'label' => 'Publish pages'],
            ['key' => 'admin.media_library.manage', 'label' => 'Manage media library'],
            ['key' => 'admin.wings_magazine.manage', 'label' => 'Manage Wings magazine'],
        ],
        'Events / Calendar' => [
            ['key' => 'admin.calendar.view', 'label' => 'View calendar'],
            ['key' => 'admin.calendar.manage', 'label' => 'Manage calendar'],
            ['key' => 'admin.events.manage', 'label' => 'Manage events'],
        ],
        'Forms / Submissions' => [
            ['key' => 'admin.member_of_year.view', 'label' => 'View Member of the Year submissions'],
            ['key' => 'admin.member_of_year.manage', 'label' => 'Manage Member of the Year submissions'],
            ['key' => 'admin.forms.export', 'label' => 'Export form submissions'],
        ],
        'Builders / Tools' => [
            ['key' => 'admin.ai_page_builder.access', 'label' => 'Access AI page builder'],
            ['key' => 'admin.ai_page_builder.edit', 'label' => 'Edit AI page builder content'],
            ['key' => 'admin.ai_page_builder.publish', 'label' => 'Publish AI page builder content'],
            ['key' => 'admin.header_footer_builder.access', 'label' => 'Access header/footer builder'],
            ['key' => 'admin.settings.general.manage', 'label' => 'Manage general settings'],
            ['key' => 'admin.logs.view', 'label' => 'View logs'],
            ['key' => 'admin.integrations.manage', 'label' => 'Manage integrations'],
        ],
    ];
}

function admin_permission_keys(): array
{
    $keys = [];
    foreach (admin_permission_registry() as $section) {
        foreach ($section as $permission) {
            $key = $permission['key'] ?? '';
            if ($key !== '') {
                $keys[] = $key;
            }
        }
    }
    return array_values(array_unique($keys));
}

function admin_permissions_tables_ready(): bool
{
    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW TABLES LIKE 'role_permissions'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function admin_role_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : bin2hex(random_bytes(3));
}

function admin_default_role_permissions(): array
{
    $all = admin_permission_keys();

    $admin = $all;

    return [
        'admin' => $admin,
        'membership_admin' => [
            'admin.members.view',
            'admin.members.edit',
            'admin.members.renew',
            'admin.members.manual_payment',
            'admin.members.import_export',
            'admin.membership_types.manage',
            'admin.payments.view',
            'admin.payments.refund',
        ],
        'store_admin' => [
            'admin.store.view',
            'admin.products.manage',
            'admin.orders.view',
            'admin.orders.fulfil',
            'admin.orders.refund_cancel',
            'admin.order_fulfilment.manage',
        ],
        'content_admin' => [
            'admin.pages.view',
            'admin.pages.edit',
            'admin.pages.publish',
            'admin.media_library.manage',
            'admin.wings_magazine.manage',
            'admin.ai_page_builder.access',
            'admin.ai_page_builder.edit',
            'admin.ai_page_builder.publish',
        ],
        'committee' => [
            'admin.dashboard.view',
            'admin.members.view',
            'admin.members.edit',
            'admin.members.renew',
            'admin.members.manual_payment',
            'admin.members.import_export',
            'admin.membership_types.manage',
            'admin.payments.view',
            'admin.payments.refund',
            'admin.events.manage',
            'admin.calendar.view',
            'admin.pages.view',
        ],
        'treasurer' => [
            'admin.dashboard.view',
            'admin.members.view',
            'admin.members.edit',
            'admin.payments.view',
            'admin.payments.refund',
        ],
        'chapter_leader' => [
            'admin.dashboard.view',
            'admin.members.view',
            'admin.members.edit',
        ],
        'store_manager' => [
            'admin.store.view',
            'admin.products.manage',
            'admin.orders.view',
            'admin.orders.fulfil',
            'admin.order_fulfilment.manage',
        ],
    ];
}

function admin_permission_default_roles(): array
{
    $map = [];
    foreach (admin_default_role_permissions() as $role => $keys) {
        foreach ($keys as $key) {
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
            $map[$key][] = $role;
        }
    }
    return $map;
}

function admin_role_ids_for_user(array $user): array
{
    $roles = normalize_access_roles($user['roles'] ?? []);
    if (!$roles) {
        return [];
    }
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE name IN (' . $placeholders . ') AND is_active = 1');
    $stmt->execute($roles);
    $rows = $stmt->fetchAll() ?: [];
    return array_map('intval', array_column($rows, 'id'));
}

function current_admin_can(string $permissionKey, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    $roles = normalize_access_roles($user['roles'] ?? []);
    if (!admin_permissions_tables_ready()) {
        $fallback = admin_permission_default_roles();
        $allowed = $fallback[$permissionKey] ?? [];
        return (bool) array_intersect($roles, $allowed);
    }

    $roleIds = admin_role_ids_for_user($user);
    if (!$roleIds) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $sql = 'SELECT 1 FROM role_permissions WHERE permission_key = ? AND allowed = 1 AND role_id IN (' . $placeholders . ') LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$permissionKey], $roleIds));
    return (bool) $stmt->fetchColumn();
}

function admin_render_forbidden(string $message = 'You do not have access to this area.'): void
{
    http_response_code(403);
    $pageTitle = 'Access denied';
    $activePage = null;
    $topbarTitle = 'Access denied';
    require __DIR__ . '/../app/Views/partials/backend_head.php';
    echo '<div class="flex h-screen overflow-hidden">';
    require __DIR__ . '/../app/Views/partials/backend_admin_sidebar.php';
    echo '<main class="flex-1 overflow-y-auto bg-background-light relative">';
    require __DIR__ . '/../app/Views/partials/backend_mobile_topbar.php';
    echo '<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10">';
    echo '<div class="rounded-2xl border border-rose-100 bg-rose-50 px-6 py-5 text-rose-700">';
    echo '<h1 class="font-display text-2xl font-bold text-rose-700">Access denied</h1>';
    echo '<p class="mt-2 text-sm">' . e($message) . '</p>';
    echo '</div></div></main></div>';    
    echo '</body></html>';
    exit;
}

function require_permission(string $permissionKey): void
{
    require_login();
    $user = current_user();
    if ($user && current_admin_can($permissionKey, $user)) {
        return;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJson = str_contains($accept, 'application/json') || str_contains(strtolower($accept), 'json');
    if ($isJson || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    admin_render_forbidden();
}

function admin_role_builder_candidates(PDO $pdo): array
{
    if (!admin_permissions_tables_ready()) {
        $stmt = $pdo->query('SELECT r.*, 0 AS permission_count FROM roles r ORDER BY r.name');
        return $stmt->fetchAll() ?: [];
    }
    $stmt = $pdo->query('SELECT r.*, COUNT(rp.id) AS permission_count FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id GROUP BY r.id ORDER BY r.name');
    return $stmt->fetchAll() ?: [];
}

function admin_role_is_admin(array $role): bool
{
    $permissionCount = (int) ($role['permission_count'] ?? 0);
    if ($permissionCount > 0) {
        return true;
    }
    $defaultRoles = array_keys(admin_default_role_permissions());
    return in_array($role['name'] ?? '', $defaultRoles, true);
}
