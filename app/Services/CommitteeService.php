<?php

namespace App\Services;

use PDO;
use Throwable;

/**
 * Committee & Leadership role assignment service.
 *
 * Two tables drive everything (added in migration 015):
 *   committee_roles               — catalog of roles
 *   member_committee_assignments  — who currently holds which role
 *
 * A role has its own email + phone (the persistent aga.* / ar.* addresses)
 * so the public listings keep working when people rotate through positions.
 *
 * Reads are cached per-request because every public page render hits them.
 */
final class CommitteeService
{
    /** @var array<string, mixed> */
    private static array $cache = [];

    /**
     * National roles in display order, each with the assigned member (or null
     * if vacant). Returned shape per row:
     *   [
     *     'role_id' => int, 'slug' => string, 'name' => string,
     *     'email' => ?string, 'phone' => ?string, 'sort_order' => int,
     *     'member_id' => ?int, 'first_name' => ?string, 'last_name' => ?string,
     *     'avatar_url' => ?string, 'chapter_name' => ?string,
     *   ]
     */
    public static function nationalRoles(): array
    {
        if (isset(self::$cache['national'])) {
            return self::$cache['national'];
        }
        return self::$cache['national'] = self::queryRoles("r.category = 'national'");
    }

    /**
     * Chapter rep roles grouped by chapter state then chapter name. Pass a
     * state name (matching chapters.state) to filter to one state. Returned
     * shape:
     *   ['ACT & New South Wales' => [ [role row + member row], ... ], ...]
     */
    public static function chapterRolesByState(?string $stateFilter = null): array
    {
        $cacheKey = 'chapter_' . ($stateFilter ?? '_all');
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $where = "r.category = 'chapter'";
        $params = [];
        if ($stateFilter !== null && $stateFilter !== '') {
            $where .= ' AND c.state = :state';
            $params[':state'] = $stateFilter;
        }
        $rows = self::queryRoles($where, $params);

        $grouped = [];
        foreach ($rows as $row) {
            $state = $row['chapter_state'] ?? 'Other';
            $grouped[$state][] = $row;
        }
        return self::$cache[$cacheKey] = $grouped;
    }

    /**
     * Roles currently held by a single member, ordered by sort_order. Useful
     * for the dashboard pill and the member's own profile badge.
     *
     * @return list<array{role_id:int, slug:string, name:string, category:string}>
     */
    public static function rolesForMember(int $memberId): array
    {
        if ($memberId <= 0) { return []; }
        $cacheKey = 'member_' . $memberId;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        try {
            $stmt = db()->prepare("
                SELECT r.id AS role_id, r.slug, r.name, r.category, r.sort_order,
                       c.name AS chapter_name, c.state AS chapter_state
                FROM member_committee_assignments a
                JOIN committee_roles r ON r.id = a.role_id
                LEFT JOIN chapters c ON c.id = r.chapter_id
                WHERE a.member_id = :mid AND r.is_active = 1
                ORDER BY r.sort_order ASC, r.name ASC
            ");
            $stmt->execute([':mid' => $memberId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        return self::$cache[$cacheKey] = $rows;
    }

    /**
     * Full role catalog for the admin assignment dropdown. Grouped into
     * "national" and "chapter" so the UI can render two optgroups.
     *
     * @return array{national: list<array<string,mixed>>, chapter: list<array<string,mixed>>}
     */
    public static function catalogForAssignment(): array
    {
        if (isset(self::$cache['catalog'])) {
            return self::$cache['catalog'];
        }
        try {
            $rows = db()->query("
                SELECT r.id, r.slug, r.name, r.category, r.sort_order,
                       r.chapter_id, c.name AS chapter_name, c.state AS chapter_state,
                       r.email, r.phone
                FROM committee_roles r
                LEFT JOIN chapters c ON c.id = r.chapter_id
                WHERE r.is_active = 1
                ORDER BY r.category ASC, r.sort_order ASC, r.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        $out = ['national' => [], 'chapter' => []];
        foreach ($rows as $row) {
            $bucket = $row['category'] === 'national' ? 'national' : 'chapter';
            $out[$bucket][] = $row;
        }
        return self::$cache['catalog'] = $out;
    }

    /**
     * Replace the set of role assignments for a member with the given role IDs.
     * Idempotent — calling with the same set twice is a no-op.
     */
    public static function syncAssignments(int $memberId, array $roleIds): void
    {
        if ($memberId <= 0) { return; }
        $pdo = db();
        $clean = [];
        foreach ($roleIds as $rid) {
            $i = (int) $rid;
            if ($i > 0) { $clean[$i] = true; }
        }
        $clean = array_keys($clean);

        $existing = [];
        $sel = $pdo->prepare("SELECT role_id FROM member_committee_assignments WHERE member_id = :m");
        $sel->execute([':m' => $memberId]);
        foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $existing[(int) $rid] = true;
        }
        $existing = array_keys($existing);

        $toAdd = array_diff($clean, $existing);
        $toRemove = array_diff($existing, $clean);

        if ($toAdd) {
            $ins = $pdo->prepare("
                INSERT INTO member_committee_assignments (member_id, role_id, since)
                VALUES (:m, :r, CURDATE())
                ON DUPLICATE KEY UPDATE since = COALESCE(since, VALUES(since))
            ");
            foreach ($toAdd as $rid) {
                $ins->execute([':m' => $memberId, ':r' => $rid]);
            }
        }
        if ($toRemove) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $del = $pdo->prepare("
                DELETE FROM member_committee_assignments
                WHERE member_id = ? AND role_id IN ($placeholders)
            ");
            $del->execute(array_merge([$memberId], array_values($toRemove)));
        }
        self::$cache = []; // any change blows the request-local cache
    }

    /** @return list<int> */
    public static function roleIdsForMember(int $memberId): array
    {
        if ($memberId <= 0) { return []; }
        try {
            $stmt = db()->prepare("SELECT role_id FROM member_committee_assignments WHERE member_id = :m");
            $stmt->execute([':m' => $memberId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─── internal ────────────────────────────────────────────────────────────

    /**
     * Single source of truth for the role+member+chapter join. The avatar URL
     * prefers members.avatar_url and falls back to settings_user (matching
     * the convention used elsewhere in view.php / member/index.php).
     */
    private static function queryRoles(string $where, array $params = []): array
    {
        try {
            $pdo = db();
            $hasAvatarCol = false;
            try {
                $hasAvatarCol = (bool) $pdo->query("SHOW COLUMNS FROM members LIKE 'avatar_url'")->fetch();
            } catch (Throwable $e) {}

            $avatarSelect = $hasAvatarCol
                ? "COALESCE(NULLIF(m.avatar_url, ''), JSON_UNQUOTE(su.value_json))"
                : "JSON_UNQUOTE(su.value_json)";

            $sql = "
                SELECT
                    r.id AS role_id, r.slug, r.name, r.category,
                    r.email, r.phone, r.sort_order, r.chapter_id,
                    c.name AS chapter_name, c.state AS chapter_state,
                    a.member_id,
                    m.first_name, m.last_name, m.email AS member_email, m.phone AS member_phone,
                    $avatarSelect AS avatar_url
                FROM committee_roles r
                LEFT JOIN chapters c ON c.id = r.chapter_id
                LEFT JOIN member_committee_assignments a ON a.role_id = r.id
                LEFT JOIN members m ON m.id = a.member_id
                LEFT JOIN users   u ON u.id = m.user_id
                LEFT JOIN settings_user su ON su.user_id = u.id AND su.key_name = 'avatar_url'
                WHERE r.is_active = 1 AND $where
                ORDER BY r.sort_order ASC, c.name ASC, r.name ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
