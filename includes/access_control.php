<?php
use App\Services\ActivityLogger;

function access_control_role_aliases(): array
{
    return [
        'chapter_rep' => 'chapter_leader',
        'committee_member' => 'committee',
        'web_admin' => 'admin',
        'web admin' => 'admin',
        'web-admin' => 'admin',
        'super admin' => 'admin',
        'super-admin' => 'admin',
        'superadmin' => 'admin',
    ];
}

function access_control_roles(): array
{
    return [
        'public',
        'member',
        'chapter_leader',
        'committee',
        'treasurer',
        'store_manager',
        'admin',
    ];
}

function access_control_role_labels(): array
{
    return [
        'public' => 'Public',
        'member' => 'Member',
        'chapter_leader' => 'Chapter Rep',
        'committee' => 'Committee Member',
        'treasurer' => 'Treasurer',
        'store_manager' => 'Store Manager',
        'admin' => 'Admin',
    ];
}

function normalize_access_role(string $role): string
{
    $role = trim(strtolower($role));
    $aliases = access_control_role_aliases();
    if (isset($aliases[$role])) {
        return $aliases[$role];
    }
    return $role;
}

function normalize_access_roles(array $roles): array
{
    $normalized = [];
    foreach ($roles as $role) {
        $normalized[] = normalize_access_role((string) $role);
    }
    $normalized = array_values(array_unique(array_filter($normalized)));
    return $normalized;
}

function get_current_roles(): array
{
    $user = current_user();
    if (!$user) {
        return ['public'];
    }
    $roles = $user['roles'] ?? [];
    $roles = normalize_access_roles($roles);
    if (!$roles) {
        $roles = ['member'];
    }
    return $roles;
}

function get_current_role(): string
{
    $roles = get_current_roles();
    $priority = [
        'admin',
        'store_manager',
        'treasurer',
        'committee',
        'chapter_leader',
        'member',
        'public',
    ];
    foreach ($priority as $role) {
        if (in_array($role, $roles, true)) {
            return $role;
        }
    }
    return 'public';
}

function access_control_always_allowed_paths(): array
{
    return [
        '/login.php',
        '/logout.php',
        '/member/reset_password.php',
        '/member/reset_password_confirm.php',
        '/member/2fa_verify.php',
        '/member/2fa_enroll.php',
        '/locked-out',
        '/locked-out/',
        '/locked-out/index.php',
        '/locked-out.php',
    ];
}

function access_control_default_registry(): array
{
    $memberRoles = [
        'member',
        'chapter_leader',
        'committee',
        'treasurer',
        'store_manager',
        'admin',
    ];

    return [
        [
            'page_key' => 'home',
            'label' => 'Home',
            'path_pattern' => '/',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'index',
            'label' => 'Home (Index)',
            'path_pattern' => '/index.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'login',
            'label' => 'Login',
            'path_pattern' => '/login.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'logout',
            'label' => 'Logout',
            'path_pattern' => '/logout.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'apply',
            'label' => 'Membership Application',
            'path_pattern' => '/apply.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'checkout',
            'label' => 'Checkout',
            'path_pattern' => '/checkout',
            'match_type' => 'prefix',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'become_member',
            'label' => 'Become a Member',
            'path_pattern' => '/become-a-member',
            'match_type' => 'prefix',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'auth_callbacks',
            'label' => 'Auth Callbacks',
            'path_pattern' => '/auth/*',
            'match_type' => 'prefix',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'locked_out',
            'label' => 'Locked Out',
            'path_pattern' => '/locked-out',
            'match_type' => 'prefix',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'member_password_reset',
            'label' => 'Password Reset',
            'path_pattern' => '/member/reset_password.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'member_password_reset_confirm',
            'label' => 'Password Reset Confirm',
            'path_pattern' => '/member/reset_password_confirm.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'email_preferences',
            'label' => 'Email Preferences',
            'path_pattern' => '/email_preferences.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'member_2fa_verify',
            'label' => 'Two-Factor Verify',
            'path_pattern' => '/member/2fa_verify.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'member_2fa_enroll',
            'label' => 'Two-Factor Enroll',
            'path_pattern' => '/member/2fa_enroll.php',
            'match_type' => 'exact',
            'nav_group' => 'Public',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'member_portal',
            'label' => 'Member Portal',
            'path_pattern' => '/member/*',
            'match_type' => 'prefix',
            'nav_group' => 'Members',
            'roles' => $memberRoles,
        ],
        [
            'page_key' => 'member_of_year',
            'label' => 'Member of the Year',
            'path_pattern' => '/members/member-of-the-year',
            'match_type' => 'prefix',
            'nav_group' => 'Members',
            'roles' => $memberRoles,
        ],
        [
            'page_key' => 'store_front',
            'label' => 'Store',
            'path_pattern' => '/store/*',
            'match_type' => 'prefix',
            'nav_group' => 'Store',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'calendar_admin',
            'label' => 'Calendar Admin',
            'path_pattern' => '/calendar/admin',
            'match_type' => 'prefix',
            'nav_group' => 'Events',
            'roles' => ['admin', 'committee', 'treasurer'],
        ],
        [
            'page_key' => 'calendar_public',
            'label' => 'Calendar',
            'path_pattern' => '/calendar/*',
            'match_type' => 'prefix',
            'nav_group' => 'Events',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'calendar_webhook',
            'label' => 'Calendar Webhook',
            'path_pattern' => '/calendar/webhook_stripe.php',
            'match_type' => 'exact',
            'nav_group' => 'Events',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'admin_dashboard',
            'label' => 'Admin Dashboard',
            'path_pattern' => '/admin/index.php',
            'match_type' => 'exact',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'committee', 'treasurer', 'chapter_leader'],
        ],
        [
            'page_key' => 'admin_members',
            'label' => 'Members',
            'path_pattern' => '/admin/members/*',
            'match_type' => 'prefix',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'committee', 'treasurer', 'chapter_leader'],
        ],
        [
            'page_key' => 'admin_member_of_year',
            'label' => 'Member of the Year Submissions',
            'path_pattern' => '/admin/member-of-the-year',
            'match_type' => 'prefix',
            'nav_group' => 'Admin',
            'roles' => ['admin'],
        ],
        [
            'page_key' => 'admin_store',
            'label' => 'Store Admin',
            'path_pattern' => '/admin/store/*',
            'match_type' => 'prefix',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'store_manager'],
        ],
        [
            'page_key' => 'admin_navigation',
            'label' => 'Pages and Nav',
            'path_pattern' => '/admin/navigation.php',
            'match_type' => 'exact',
            'nav_group' => 'Admin',
            'roles' => ['admin'],
        ],
        [
            'page_key' => 'admin_security',
            'label' => 'Security Log',
            'path_pattern' => '/admin/security/*',
            'match_type' => 'prefix',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'committee', 'treasurer'],
        ],
        [
            'page_key' => 'admin_ai_editor',
            'label' => 'AI Page Builder',
            'path_pattern' => '/admin/page-builder',
            'match_type' => 'prefix',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'committee'],
        ],
        [
            'page_key' => 'settings_hub',
            'label' => 'Settings Hub',
            'path_pattern' => '/admin/settings/index.php',
            'match_type' => 'exact',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'store_manager', 'committee', 'treasurer'],
        ],
        [
            'page_key' => 'settings_access_control',
            'label' => 'Access Control',
            'path_pattern' => '/admin/settings/access-control.php',
            'match_type' => 'exact',
            'nav_group' => 'Admin',
            'roles' => ['admin'],
        ],
        [
            'page_key' => 'settings_ai',
            'label' => 'AI Settings',
            'path_pattern' => '/admin/settings/ai.php',
            'match_type' => 'exact',
            'nav_group' => 'Admin',
            'roles' => ['admin', 'committee'],
        ],
        [
            'page_key' => 'settings_access_control_save',
            'label' => 'Access Control Save',
            'path_pattern' => '/admin/settings/access-control-save.php',
            'match_type' => 'exact',
            'nav_group' => 'Admin',
            'roles' => ['admin'],
        ],
        [
            'page_key' => 'admin_root',
            'label' => 'Admin Area',
            'path_pattern' => '/admin/*',
            'match_type' => 'prefix',
            'nav_group' => 'Admin',
            'roles' => ['admin'],
        ],
        [
            'page_key' => 'api_webhook',
            'label' => 'Stripe Webhook',
            'path_pattern' => '/api/stripe_webhook.php',
            'match_type' => 'exact',
            'nav_group' => 'API',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'api_stripe_config',
            'label' => 'Stripe Public Config',
            'path_pattern' => '/api/stripe/config',
            'match_type' => 'exact',
            'nav_group' => 'API',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'api_stripe_payment_intent',
            'label' => 'Stripe Payment Intent',
            'path_pattern' => '/api/stripe/create-payment-intent',
            'match_type' => 'exact',
            'nav_group' => 'API',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'api_stripe_membership_intent',
            'label' => 'Stripe Membership Intent',
            'path_pattern' => '/api/stripe/create-application-payment-intent',
            'match_type' => 'exact',
            'nav_group' => 'API',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'api_stripe_subscription',
            'label' => 'Stripe Membership Subscription',
            'path_pattern' => '/api/stripe/create-subscription',
            'match_type' => 'exact',
            'nav_group' => 'API',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'api_stripe_webhook',
            'label' => 'Stripe Webhook (Router)',
            'path_pattern' => '/api/stripe/webhook',
            'match_type' => 'exact',
            'nav_group' => 'API',
            'roles' => array_merge(['public'], $memberRoles),
        ],
        [
            'page_key' => 'api_admin',
            'label' => 'API',
            'path_pattern' => '/api/*',
            'match_type' => 'prefix',
            'nav_group' => 'API',
            'roles' => ['admin'],
        ],
    ];
}

function access_control_tables_ready(): bool
{
    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW TABLES LIKE 'pages_registry'");
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function sync_access_registry(): void
{
    static $synced = false;
    if ($synced) {
        return;
    }
    $synced = true;
    if (!access_control_tables_ready()) {
        return;
    }

    $pdo = db();
    $defaults = access_control_default_registry();
    $pageIds = [];

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare('INSERT INTO pages_registry (page_key, label, path_pattern, match_type, nav_group, is_enabled, created_at, updated_at)
            VALUES (:page_key, :label, :path_pattern, :match_type, :nav_group, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE label = VALUES(label), path_pattern = VALUES(path_pattern), match_type = VALUES(match_type), nav_group = VALUES(nav_group), is_enabled = 1, updated_at = NOW()');

        foreach ($defaults as $entry) {
            $upsert->execute([
                'page_key' => $entry['page_key'],
                'label' => $entry['label'],
                'path_pattern' => $entry['path_pattern'],
                'match_type' => $entry['match_type'],
                'nav_group' => $entry['nav_group'],
            ]);
            $pageIds[$entry['page_key']] = (int) $pdo->lastInsertId();
        }

        $lookupStmt = $pdo->query('SELECT id, page_key FROM pages_registry');
        $lookupRows = $lookupStmt->fetchAll() ?: [];
        foreach ($lookupRows as $row) {
            $pageIds[$row['page_key']] = (int) $row['id'];
        }

        $roles = access_control_roles();
        $insertAccess = $pdo->prepare('INSERT INTO page_role_access (page_id, role, can_access, created_at, updated_at)
            VALUES (:page_id, :role, :can_access, NOW(), NOW())
            ON DUPLICATE KEY UPDATE can_access = can_access');

        foreach ($defaults as $entry) {
            $pageId = $pageIds[$entry['page_key']] ?? 0;
            if (!$pageId) {
                continue;
            }
            foreach ($roles as $role) {
                $canAccess = in_array($role, $entry['roles'], true) ? 1 : 0;
                $insertAccess->execute([
                    'page_id' => $pageId,
                    'role' => $role,
                    'can_access' => $canAccess,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}

function access_control_registry(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!access_control_tables_ready()) {
        $cache = [];
        return $cache;
    }
    try {
        $pdo = db();
        $stmt = $pdo->query('SELECT * FROM pages_registry WHERE is_enabled = 1');
        $rows = $stmt->fetchAll() ?: [];
        $cache = $rows;
        return $rows;
    } catch (Throwable $e) {
        $cache = [];
        return $cache;
    }
}

function access_control_match_page(string $path): ?array
{
    $path = access_control_normalize_path($path);
    if ($path === '') {
        return null;
    }
    $registry = access_control_registry();
    $best = null;
    $bestScore = -1;

    foreach ($registry as $row) {
        $pattern = $row['path_pattern'] ?? '';
        $type = $row['match_type'] ?? 'exact';
        if ($pattern === '') {
            continue;
        }
        if ($type === 'exact') {
            if ($pattern === $path) {
                return $row;
            }
            continue;
        }
        $prefix = rtrim($pattern, '*');
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $score = strlen($prefix);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
    }

    return $best;
}

function access_control_page_allowed_roles(int $pageId): array
{
    static $cache = [];
    if (isset($cache[$pageId])) {
        return $cache[$pageId];
    }
    if (!access_control_tables_ready()) {
        $cache[$pageId] = [];
        return $cache[$pageId];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT role, can_access FROM page_role_access WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);
        $rows = $stmt->fetchAll() ?: [];
        $roles = [];
        foreach ($rows as $row) {
            if ((int) $row['can_access'] === 1) {
                $roles[] = $row['role'];
            }
        }
        $roles = normalize_access_roles($roles);
        $cache[$pageId] = $roles;
        return $roles;
    } catch (Throwable $e) {
        $cache[$pageId] = [];
        return $cache[$pageId];
    }
}

function access_control_normalize_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }
    $parts = parse_url($path);
    if (!empty($parts['path'])) {
        $path = $parts['path'];
    }
    if ($path === '') {
        $path = '/';
    }
    return $path;
}

function access_control_is_always_allowed(string $path): bool
{
    $path = access_control_normalize_path($path);
    if (str_starts_with($path, '/api/stripe/')) {
        return true;
    }
    foreach (access_control_always_allowed_paths() as $allowed) {
        if ($allowed === $path) {
            return true;
        }
    }
    return false;
}

function access_control_extract_internal_path(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || str_starts_with($url, '#')) {
        return null;
    }
    if (preg_match('/^https?:\/\//i', $url)) {
        return null;
    }
    if (str_starts_with($url, '//')) {
        return null;
    }
    if (!str_starts_with($url, '/')) {
        return null;
    }
    return access_control_normalize_path($url);
}

function is_page_allowed($role, string $requestPath): bool
{
    return can_access_path($role, $requestPath);
}

function can_access_path($rolesOrUser, string $path): bool
{
    if (!access_control_tables_ready()) {
        return true;
    }
    sync_access_registry();

    $roles = [];
    if (is_array($rolesOrUser) && array_key_exists('roles', $rolesOrUser)) {
        $roles = normalize_access_roles($rolesOrUser['roles'] ?? []);
    } elseif (is_array($rolesOrUser)) {
        $roles = normalize_access_roles($rolesOrUser);
    } elseif (is_string($rolesOrUser)) {
        $roles = [normalize_access_role($rolesOrUser)];
    }

    if (!$roles) {
        $roles = ['public'];
    }

    $path = access_control_normalize_path($path);
    if (access_control_is_always_allowed($path)) {
        return true;
    }

    $match = access_control_match_page($path);
    if (!$match) {
        return true;
    }
    $allowedRoles = access_control_page_allowed_roles((int) $match['id']);
    if (in_array('public', $allowedRoles, true)) {
        return true;
    }

    return (bool) array_intersect($roles, $allowedRoles);
}

function enforce_page_access(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $path = access_control_normalize_path($_SERVER['REQUEST_URI'] ?? '/');
    if (access_control_is_always_allowed($path)) {
        return;
    }
    if (!access_control_tables_ready()) {
        return;
    }

    sync_access_registry();

    $match = access_control_match_page($path);
    if (!$match) {
        return;
    }

    $roles = get_current_roles();
    $allowedRoles = access_control_page_allowed_roles((int) $match['id']);
    if (in_array('public', $allowedRoles, true)) {
        return;
    }

    $user = current_user();
    $wantsJson = str_starts_with($path, '/api/');
    if (!$user) {
        if ($wantsJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Location: /login.php');
        exit;
    }

    if (array_intersect($roles, $allowedRoles)) {
        return;
    }

    if (class_exists(ActivityLogger::class)) {
        $currentRole = get_current_role();
        $actorType = $currentRole === 'member' ? 'member' : ($currentRole === 'public' ? 'system' : 'admin');
        ActivityLogger::log($actorType, (int) ($user['id'] ?? 0), null, 'security.access_denied', [
            'role' => $currentRole,
            'attempted_path' => $path,
            'page_key' => $match['page_key'] ?? null,
            'page_label' => $match['label'] ?? null,
        ]);
    }

    $_SESSION['locked_out'] = [
        'role' => get_current_role(),
        'path' => $path,
        'page_label' => $match['label'] ?? null,
    ];

    if ($wantsJson) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /locked-out');
    exit;
}
