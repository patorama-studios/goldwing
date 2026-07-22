<?php

/**
 * Member portal page visibility — role-gated view access to the logged-in
 * members' area (Wings, Calendar, Members Directory, …).
 *
 * Complements the admin Role Builder: each gateable page has a
 * `member.page.<key>` permission stored in `role_permissions`. A member may
 * view a page if ANY of their roles grants it. The seed migration
 * (2026_07_22_member_page_roles.sql) grants every page to every existing
 * role, so nothing changes until an admin un-ticks a page — to hide a page
 * from ordinary members you un-tick it on the base "Member" role, then grant
 * it back on a purpose-built role and assign that role to the chosen members.
 *
 * These keys are deliberately kept OUT of admin_permission_keys() so that a
 * member holding member.page.* grants is never mistaken for an admin (see
 * admin_render_forbidden / the sidebar "View as Admin" check).
 */

/**
 * Gateable member portal pages, keyed by the sidebar item key.
 *   label – shown in the Role Builder and (implicitly) the sidebar
 *   pages – the /member/index.php?page= values this key covers, for
 *           direct-URL enforcement. Empty for pages that live at their own
 *           URL (awards, member-of-the-year) or are also public (store).
 */
function member_page_registry(): array
{
    return [
        'wings'              => ['label' => 'Wings Magazine',     'pages' => ['wings']],
        'calendar'           => ['label' => 'Ride Calendar',      'pages' => ['calendar']],
        'notices'            => ['label' => 'Notice Board',       'pages' => ['notices-view', 'notices-create']],
        'fallen-wings'       => ['label' => 'Fallen Wings',       'pages' => ['fallen-wings']],
        'member-of-the-year' => ['label' => 'Member of the Year', 'pages' => []],
        'awards'             => ['label' => 'AGM Awards',         'pages' => []],
        'directory'          => ['label' => 'Members Directory',  'pages' => ['directory']],
        'committee'          => ['label' => 'Committee',          'pages' => ['committee']],
        'dealers'            => ['label' => 'Honda Dealers',      'pages' => ['dealers']],
        'store'              => ['label' => 'Store',              'pages' => []],
    ];
}

function member_page_permission_key(string $key): string
{
    return 'member.page.' . $key;
}

/** All member-page permission keys, for the Role Builder save whitelist. */
function member_page_permission_keys(): array
{
    return array_map('member_page_permission_key', array_keys(member_page_registry()));
}

/** Map a /member/index.php ?page= value to its registry key, or null if that page isn't gateable. */
function member_page_key_for_param(string $page): ?string
{
    foreach (member_page_registry() as $key => $meta) {
        if (in_array($page, $meta['pages'], true)) {
            return $key;
        }
    }
    return null;
}

/**
 * The member-page keys the user's roles grant, or null when gating is not in
 * effect — tables missing, or no role the user holds carries any member.page.*
 * rule at all (treated as "allow everything" for backward-compatibility).
 */
function member_allowed_page_keys(array $user): ?array
{
    static $cache = [];
    $userId = (int) ($user['id'] ?? 0);
    if ($userId > 0 && array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    if (!function_exists('admin_permissions_tables_ready') || !admin_permissions_tables_ready()) {
        return $cache[$userId] = null;
    }

    $roleIds = admin_role_ids_for_user($user);
    if (!$roleIds) {
        return $cache[$userId] = null;
    }

    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $sql = "SELECT permission_key, MAX(allowed) AS allowed
            FROM role_permissions
            WHERE permission_key LIKE 'member.page.%'
              AND role_id IN ($placeholders)
            GROUP BY permission_key";
    $stmt = db()->prepare($sql);
    $stmt->execute($roleIds);
    $rows = $stmt->fetchAll() ?: [];
    if (!$rows) {
        return $cache[$userId] = null; // unmanaged → allow all
    }

    $allowed = [];
    $prefixLen = strlen('member.page.');
    foreach ($rows as $row) {
        if ((int) $row['allowed'] === 1) {
            $allowed[] = substr((string) $row['permission_key'], $prefixLen);
        }
    }
    return $cache[$userId] = $allowed;
}

/**
 * Can this user view the given member page (registry key)? Webmasters and any
 * unconfigured account fail open so an admin can never lock themselves out of
 * the portal preview.
 */
function member_can_view_page(?array $user, string $pageKey): bool
{
    if (!$user) {
        return false;
    }
    $roles = normalize_access_roles($user['roles'] ?? []);
    if (in_array('admin', $roles, true)) {
        return true;
    }
    $allowed = member_allowed_page_keys($user);
    if ($allowed === null) {
        return true;
    }
    return in_array($pageKey, $allowed, true);
}
