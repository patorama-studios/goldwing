<?php
namespace App\Services;

use PDO;

class NavigationService
{
    private const DEFAULT_LOCATIONS = ['primary', 'secondary', 'footer'];
    private const CACHE_TTL = 60;

    public static function ensureLocations(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT location_key FROM menu_locations');
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $existing = $existing ?: [];
        foreach (self::DEFAULT_LOCATIONS as $location) {
            if (!in_array($location, $existing, true)) {
                $insert = $pdo->prepare('INSERT INTO menu_locations (location_key, menu_id, updated_at) VALUES (:location_key, NULL, NOW())');
                $insert->execute(['location_key' => $location]);
            }
        }
    }

    public static function listMenus(): array
    {
        self::ensureLocations();
        self::ensureDefaultMenu();
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT id, name, created_at, updated_at FROM menus ORDER BY name ASC');
        return $stmt->fetchAll() ?: [];
    }

    public static function listLocations(): array
    {
        self::ensureLocations();
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT location_key, menu_id, updated_at FROM menu_locations ORDER BY location_key ASC');
        return $stmt->fetchAll() ?: [];
    }

    public static function createMenu(string $name): array
    {
        $name = self::sanitizeLabel($name);
        if ($name === '') {
            return ['error' => 'Menu name is required.'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO menus (name, created_at, updated_at) VALUES (:name, NOW(), NOW())');
        $stmt->execute(['name' => $name]);
        return ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
    }

    public static function renameMenu(int $menuId, string $name): array
    {
        $name = self::sanitizeLabel($name);
        if ($name === '') {
            return ['error' => 'Menu name is required.'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE menus SET name = :name, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $menuId]);
        self::clearNavigationCache();
        return ['id' => $menuId, 'name' => $name];
    }

    public static function deleteMenu(int $menuId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE menu_locations SET menu_id = NULL, updated_at = NOW() WHERE menu_id = :menu_id');
        $stmt->execute(['menu_id' => $menuId]);
        $stmt = $pdo->prepare('DELETE FROM menus WHERE id = :id');
        $stmt->execute(['id' => $menuId]);
        self::clearNavigationCache();
    }

    public static function assignMenuToLocation(string $locationKey, ?int $menuId): array
    {
        $locationKey = self::sanitizeKey($locationKey);
        if ($locationKey === '') {
            return ['error' => 'Invalid location.'];
        }
        self::ensureLocations();
        $pdo = Database::connection();
        if ($menuId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM menus WHERE id = :id');
            $stmt->execute(['id' => $menuId]);
            if (!$stmt->fetch()) {
                return ['error' => 'Menu not found.'];
            }
        }
        $stmt = $pdo->prepare('UPDATE menu_locations SET menu_id = :menu_id, updated_at = NOW() WHERE location_key = :location_key');
        $stmt->execute([
            'menu_id' => $menuId,
            'location_key' => $locationKey,
        ]);
        self::clearNavigationCache($locationKey);
        return ['location_key' => $locationKey, 'menu_id' => $menuId];
    }

    public static function getMenuItemsTree(int $menuId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT mi.*, p.title AS page_title, p.slug AS page_slug, p.visibility AS page_visibility
            FROM menu_items mi
            LEFT JOIN pages p ON mi.page_id = p.id
            WHERE mi.menu_id = :menu_id
            ORDER BY mi.parent_id ASC, mi.sort_order ASC, mi.id ASC');
        $stmt->execute(['menu_id' => $menuId]);
        $rows = $stmt->fetchAll() ?: [];

        $itemsByParent = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] ? (int) $row['parent_id'] : 0;
            $itemsByParent[$parentId][] = self::formatAdminItem($row);
        }

        $visited = [];
        return self::buildTree($itemsByParent, 0, $visited);
    }

    public static function replaceMenuItemsTree(int $menuId, array $tree): array
    {
        if (!is_array($tree)) {
            return ['error' => 'Invalid menu items payload.'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM menus WHERE id = :id');
        $stmt->execute(['id' => $menuId]);
        if (!$stmt->fetch()) {
            return ['error' => 'Menu not found.'];
        }

        $pageIds = [];
        self::collectPageIds($tree, $pageIds);
        $pageLookup = self::fetchPageLookup($pageIds);

        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM menu_items WHERE menu_id = :menu_id');
            $delete->execute(['menu_id' => $menuId]);
            self::insertMenuItems($pdo, $menuId, $tree, null, $pageLookup);
            $pdo->commit();
        } catch (\RuntimeException $e) {
            $pdo->rollBack();
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['error' => 'Failed to save menu items.'];
        }
        self::clearNavigationCache();
        return ['success' => true];
    }

    public static function getNavigationTree(string $locationKey, ?array $user = null): array
    {
        $locationKey = self::sanitizeKey($locationKey);
        if ($locationKey === '') {
            return [];
        }

        $scope = self::navScope($user);
        $cached = self::readCache($locationKey, $scope);
        if ($cached !== null) {
            return $cached;
        }

        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('SELECT menu_id FROM menu_locations WHERE location_key = :location_key LIMIT 1');
            $stmt->execute(['location_key' => $locationKey]);
            $row = $stmt->fetch();
            $menuId = $row ? (int) $row['menu_id'] : 0;
        } catch (\PDOException $e) {
            $data = self::fallbackNavigation($user);
            self::writeCache($locationKey, $scope, $data);
            return $data;
        }

        if ($menuId === 0) {
            $data = self::fallbackNavigation($user);
            self::writeCache($locationKey, $scope, $data);
            return $data;
        }

        try {
            $items = self::getMenuItemsForPublic($menuId);
            $visited = [];
            $tree = self::buildTree($items, 0, $visited);
            $filtered = self::filterNavigation($tree, $user);
            self::writeCache($locationKey, $scope, $filtered);
            return $filtered;
        } catch (\PDOException $e) {
            $data = self::fallbackNavigation($user);
            self::writeCache($locationKey, $scope, $data);
            return $data;
        }
    }

    private static function getMenuItemsForPublic(int $menuId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT mi.*, p.title AS page_title, p.slug AS page_slug, p.visibility AS page_visibility
            FROM menu_items mi
            LEFT JOIN pages p ON mi.page_id = p.id
            WHERE mi.menu_id = :menu_id
            ORDER BY mi.parent_id ASC, mi.sort_order ASC, mi.id ASC');
        $stmt->execute(['menu_id' => $menuId]);
        $rows = $stmt->fetchAll() ?: [];

        $itemsByParent = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] ? (int) $row['parent_id'] : 0;
            $itemsByParent[$parentId][] = self::formatPublicItem($row);
        }
        return $itemsByParent;
    }

    private static function buildTree(array $itemsByParent, int $parentId, array &$visited): array
    {
        $branch = [];
        $children = $itemsByParent[$parentId] ?? [];
        foreach ($children as $child) {
            $id = (int) ($child['id'] ?? 0);
            if ($id && isset($visited[$id])) {
                continue;
            }
            if ($id) {
                $visited[$id] = true;
            }
            $child['children'] = self::buildTree($itemsByParent, $id, $visited);
            $branch[] = $child;
        }
        return $branch;
    }

    private static function formatAdminItem(array $row): array
    {
        $label = $row['label'];
        if (!empty($row['use_page_title']) && $row['page_title']) {
            $label = $row['page_title'];
        }
        $item = [
            'id' => (int) $row['id'],
            'menu_id' => (int) $row['menu_id'],
            'page_id' => $row['page_id'] ? (int) $row['page_id'] : null,
            'custom_url' => $row['custom_url'],
            'label' => $label,
            'parent_id' => $row['parent_id'] ? (int) $row['parent_id'] : null,
            'sort_order' => (int) $row['sort_order'],
            'open_in_new_tab' => (int) $row['open_in_new_tab'] === 1,
            'use_page_title' => (int) $row['use_page_title'] === 1,
            'page_title' => $row['page_title'],
            'page_slug' => $row['page_slug'],
            'page_visibility' => $row['page_visibility'],
            'status' => 'Custom',
        ];

        if ($item['page_id']) {
            if (!$row['page_title']) {
                $item['status'] = 'Missing';
            } elseif ($row['page_visibility'] !== 'public') {
                $item['status'] = 'Restricted';
            } else {
                $item['status'] = 'Published';
            }
        }

        return $item;
    }

    private static function formatPublicItem(array $row): array
    {
        $label = $row['label'] ?? '';
        if (!empty($row['use_page_title']) && $row['page_title']) {
            $label = $row['page_title'];
        }
        $label = self::sanitizeLabel($label);
        if ($label === '' && $row['page_title']) {
            $label = self::sanitizeLabel($row['page_title']);
        }

        $url = null;
        if (!empty($row['page_slug'])) {
            $slug = $row['page_slug'];
            $url = $slug === 'home' ? '/' : '/?page=' . rawurlencode($slug);
        } elseif (!empty($row['custom_url'])) {
            $url = self::sanitizeUrl($row['custom_url']);
        }

        return [
            'id' => (int) $row['id'],
            'label' => $label,
            'url' => $url,
            'page_id' => $row['page_id'] ? (int) $row['page_id'] : null,
            'page_visibility' => $row['page_visibility'],
            'page_slug' => $row['page_slug'],
            'open_in_new_tab' => (int) $row['open_in_new_tab'] === 1,
        ];
    }

    private static function filterNavigation(array $items, ?array $user): array
    {
        $filtered = [];
        foreach ($items as $item) {
            $children = self::filterNavigation($item['children'] ?? [], $user);
            $canView = true;
            if (!empty($item['page_id'])) {
                $canView = self::canViewPage($item['page_visibility'] ?? 'public', $user);
            }

            $canAccess = true;
            $url = $item['url'] ?? null;
            if ($url && function_exists('access_control_extract_internal_path') && function_exists('can_access_path')) {
                $path = access_control_extract_internal_path($url);
                if ($path !== null) {
                    $canAccess = can_access_path($user, $path);
                }
            }

            if (!$canView || !$canAccess) {
                $url = null;
            }

            if ((!$canView || !$canAccess) && empty($children)) {
                continue;
            }

            $item['url'] = $url;
            $item['children'] = $children;
            if (!$item['url'] && empty($children)) {
                continue;
            }
            $filtered[] = $item;
        }
        return $filtered;
    }

    private static function fallbackNavigation(?array $user): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT id, slug, title, visibility FROM pages WHERE visibility = "public" ORDER BY title ASC');
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        $home = null;
        foreach ($rows as $row) {
            $label = self::sanitizeLabel($row['title']);
            if ($row['slug'] === 'home') {
                $home = [
                    'id' => (int) $row['id'],
                    'label' => 'Home',
                    'url' => '/',
                    'page_id' => (int) $row['id'],
                    'page_slug' => 'home',
                    'page_visibility' => $row['visibility'],
                    'open_in_new_tab' => false,
                    'children' => [],
                ];
                continue;
            }
            $items[] = [
                'id' => (int) $row['id'],
                'label' => $label,
                'url' => '/?page=' . rawurlencode($row['slug']),
                'page_id' => (int) $row['id'],
                'page_slug' => $row['slug'],
                'page_visibility' => $row['visibility'],
                'open_in_new_tab' => false,
                'children' => [],
            ];
        }
        if ($home) {
            array_unshift($items, $home);
        }
        return $items;
    }

    private static function canViewPage(string $visibility, ?array $user): bool
    {
        if ($visibility === 'public') {
            return true;
        }
        if ($visibility === 'member') {
            return $user !== null;
        }
        if ($visibility === 'admin') {
            return $user !== null && in_array('admin', $user['roles'] ?? [], true);
        }
        return false;
    }

    private static function sanitizeLabel(string $label): string
    {
        $label = trim(strip_tags($label));
        if (strlen($label) > 150) {
            $label = substr($label, 0, 150);
        }
        return $label;
    }

    private static function sanitizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9-_]/', '', $value);
        return $value;
    }

    private static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (strpos($url, 'javascript:') === 0 || strpos($url, 'data:') === 0) {
            return null;
        }
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return $url;
        }
        if (strpos($url, '#') === 0) {
            return $url;
        }
        if (preg_match('/^https?:\\/\\//i', $url)) {
            return $url;
        }
        return null;
    }

    private static function collectPageIds(array $items, array &$pageIds): void
    {
        foreach ($items as $item) {
            if (!empty($item['page_id'])) {
                $pageIds[] = (int) $item['page_id'];
            }
            if (!empty($item['children']) && is_array($item['children'])) {
                self::collectPageIds($item['children'], $pageIds);
            }
        }
    }

    private static function fetchPageLookup(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter($pageIds)));
        if (!$pageIds) {
            return [];
        }
        $pdo = Database::connection();
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $pdo->prepare('SELECT id, title FROM pages WHERE id IN (' . $placeholders . ')');
        $stmt->execute($pageIds);
        $rows = $stmt->fetchAll() ?: [];
        $lookup = [];
        foreach ($rows as $row) {
            $lookup[(int) $row['id']] = $row;
        }
        return $lookup;
    }

    private static function insertMenuItems(PDO $pdo, int $menuId, array $items, ?int $parentId, array $pageLookup): void
    {
        $order = 1;
        foreach ($items as $item) {
            $pageId = !empty($item['page_id']) ? (int) $item['page_id'] : null;
            if ($pageId !== null && $pageId <= 0) {
                $pageId = null;
            }
            if ($pageId !== null && !isset($pageLookup[$pageId])) {
                $pageId = null;
            }
            $customUrl = isset($item['custom_url']) ? self::sanitizeUrl((string) $item['custom_url']) : null;
            if ($pageId && $customUrl) {
                $customUrl = null;
            }

            $usePageTitle = !empty($item['use_page_title']) ? 1 : 0;
            if (!$pageId) {
                $usePageTitle = 0;
            }

            $label = self::sanitizeLabel((string) ($item['label'] ?? ''));
            if ($usePageTitle && $pageId && isset($pageLookup[$pageId])) {
                $label = self::sanitizeLabel($pageLookup[$pageId]['title'] ?? $label);
            }
            if ($label === '' && $pageId && isset($pageLookup[$pageId])) {
                $label = self::sanitizeLabel($pageLookup[$pageId]['title']);
            }
            if ($label === '') {
                throw new \RuntimeException('Menu item label is required.');
            }
            if (!$pageId && !$customUrl) {
                throw new \RuntimeException('Menu item must have a page or URL.');
            }

            $openInNewTab = !empty($item['open_in_new_tab']) ? 1 : 0;

            $stmt = $pdo->prepare('INSERT INTO menu_items (menu_id, page_id, custom_url, label, parent_id, sort_order, open_in_new_tab, use_page_title, created_at, updated_at)
                VALUES (:menu_id, :page_id, :custom_url, :label, :parent_id, :sort_order, :open_in_new_tab, :use_page_title, NOW(), NOW())');
            $stmt->execute([
                'menu_id' => $menuId,
                'page_id' => $pageId,
                'custom_url' => $customUrl,
                'label' => $label,
                'parent_id' => $parentId,
                'sort_order' => $order,
                'open_in_new_tab' => $openInNewTab,
                'use_page_title' => $usePageTitle,
            ]);
            $newId = (int) $pdo->lastInsertId();

            if (!empty($item['children']) && is_array($item['children'])) {
                self::insertMenuItems($pdo, $menuId, $item['children'], $newId, $pageLookup);
            }
            $order += 1;
        }
    }

    private static function navScope(?array $user): string
    {
        if (!$user) {
            return 'guest';
        }
        $roles = $user['roles'] ?? [];
        $roles = array_values(array_unique($roles));
        sort($roles);
        return 'roles_' . substr(sha1(implode(',', $roles)), 0, 12);
    }

    public static function clearCache(): void
    {
        self::clearNavigationCache();
    }

    private static function cacheDir(): string
    {
        return __DIR__ . '/../cache';
    }

    private static function readCache(string $locationKey, string $scope): ?array
    {
        $cacheDir = self::cacheDir();
        $cacheFile = $cacheDir . '/nav_' . $locationKey . '_' . $scope . '.json';
        if (!is_file($cacheFile)) {
            return null;
        }
        $raw = file_get_contents($cacheFile);
        if (!$raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['cached_at']) || !isset($decoded['data'])) {
            return null;
        }
        if (time() - (int) $decoded['cached_at'] > self::CACHE_TTL) {
            return null;
        }
        return $decoded['data'];
    }

    private static function writeCache(string $locationKey, string $scope, array $data): void
    {
        $cacheDir = self::cacheDir();
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/nav_' . $locationKey . '_' . $scope . '.json';
        $payload = json_encode(['cached_at' => time(), 'data' => $data]);
        file_put_contents($cacheFile, $payload);
    }

    private static function clearNavigationCache(?string $locationKey = null): void
    {
        $cacheDir = self::cacheDir();
        if (!is_dir($cacheDir)) {
            return;
        }
        $pattern = $locationKey ? '/nav_' . $locationKey . '_*.json' : '/nav_*.json';
        foreach (glob($cacheDir . $pattern) as $file) {
            @unlink($file);
        }
    }

    private static function ensureDefaultMenu(): void
    {
        $pdo = Database::connection();
        $count = $pdo->query('SELECT COUNT(*) AS c FROM menus')->fetch();
        if (!empty($count['c'])) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO menus (name, created_at, updated_at) VALUES (:name, NOW(), NOW())');
            $stmt->execute(['name' => 'Primary Menu']);
            $menuId = (int) $pdo->lastInsertId();

            $location = $pdo->prepare('SELECT id FROM menu_locations WHERE location_key = :location_key LIMIT 1');
            $location->execute(['location_key' => 'primary']);
            if ($location->fetch()) {
                $assign = $pdo->prepare('UPDATE menu_locations SET menu_id = :menu_id, updated_at = NOW() WHERE location_key = :location_key');
                $assign->execute(['menu_id' => $menuId, 'location_key' => 'primary']);
            } else {
                $assign = $pdo->prepare('INSERT INTO menu_locations (location_key, menu_id, updated_at) VALUES (:location_key, :menu_id, NOW())');
                $assign->execute(['location_key' => 'primary', 'menu_id' => $menuId]);
            }

            $slugMap = [
                'home' => 'Home',
                'about' => 'About',
                'ride-calendar' => 'Ride Calendar',
                'membership' => 'Membership',
                'events' => 'Events',
            ];
            $order = 1;
            foreach ($slugMap as $slug => $label) {
                $pageStmt = $pdo->prepare('SELECT id FROM pages WHERE slug = :slug LIMIT 1');
                $pageStmt->execute(['slug' => $slug]);
                $page = $pageStmt->fetch();
                if (!$page) {
                    continue;
                }
                $insertItem = $pdo->prepare('INSERT INTO menu_items (menu_id, page_id, custom_url, label, parent_id, sort_order, open_in_new_tab, use_page_title, created_at, updated_at)
                    VALUES (:menu_id, :page_id, NULL, :label, NULL, :sort_order, 0, 0, NOW(), NOW())');
                $insertItem->execute([
                    'menu_id' => $menuId,
                    'page_id' => (int) $page['id'],
                    'label' => $label,
                    'sort_order' => $order,
                ]);
                $order += 1;
            }

            $insertCustom = $pdo->prepare('INSERT INTO menu_items (menu_id, page_id, custom_url, label, parent_id, sort_order, open_in_new_tab, use_page_title, created_at, updated_at)
                VALUES (:menu_id, NULL, :custom_url, :label, NULL, :sort_order, 0, 0, NOW(), NOW())');
            $insertCustom->execute([
                'menu_id' => $menuId,
                'custom_url' => '/login.php',
                'label' => 'Member Login',
                'sort_order' => $order,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
        }
    }
}
