<?php
namespace App\Services;

use PDO;
use Throwable;

/**
 * Unified read layer over the three legacy log tables:
 *   - audit_log     (settings diffs, written by SettingsService::writeAudit)
 *   - audit_logs    (admin actions, written by AuditService::log)
 *   - activity_log  (auth/security/payments, written by ActivityLogger::log)
 *
 * The writers stay where they are. This service projects all three into a
 * single normalized row shape so the new Audit Hub UI can show one list.
 */
class AuditHubService
{
    public const SOURCES = [
        'settings' => [
            'table' => 'audit_log',
            'label' => 'Settings',
        ],
        'admin' => [
            'table' => 'audit_logs',
            'label' => 'Admin',
        ],
        'activity' => [
            'table' => 'activity_log',
            'label' => 'Activity',
        ],
    ];

    private static ?array $tableCache = null;

    public static function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $built = self::buildUnion($filters);
        if ($built === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$unionSql, $params, $available] = $built;

        $pdo = self::pdo();

        $countSql = 'SELECT COUNT(*) FROM (' . $unionSql . ') AS audit_union';
        $countStmt = $pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $rowsSql = $unionSql . ' ORDER BY created_at DESC LIMIT :__limit OFFSET :__offset';
        $rowsStmt = $pdo->prepare($rowsSql);
        foreach ($params as $key => $value) {
            $rowsStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $rowsStmt->bindValue(':__limit', max(1, $limit), PDO::PARAM_INT);
        $rowsStmt->bindValue(':__offset', max(0, $offset), PDO::PARAM_INT);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['actor_label'] = self::actorLabel($row);
            $row['target_label'] = self::targetLabel($row);
            $row['action_label'] = self::humaniseAction($row['action'] ?? '');
            $row['summary'] = self::summarise($row);
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
            'available_sources' => $available,
        ];
    }

    public static function stats(): array
    {
        $pdo = self::pdo();
        $out = ['today' => 0, 'week' => 0, 'total' => 0, 'by_source' => []];
        foreach (self::SOURCES as $key => $meta) {
            $table = $meta['table'];
            if (!self::tableExists($table)) {
                $out['by_source'][$key] = 0;
                continue;
            }
            try {
                $sourceTotal = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                $today = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE created_at >= CURDATE()")->fetchColumn();
                $week = (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
                $out['by_source'][$key] = $sourceTotal;
                $out['total'] += $sourceTotal;
                $out['today'] += $today;
                $out['week'] += $week;
            } catch (Throwable $e) {
                $out['by_source'][$key] = 0;
            }
        }
        return $out;
    }

    public static function distinctActions(int $limit = 200): array
    {
        $pdo = self::pdo();
        $actions = [];
        foreach (self::SOURCES as $meta) {
            $table = $meta['table'];
            if (!self::tableExists($table)) continue;
            try {
                $rows = $pdo->query("SELECT DISTINCT action FROM `$table` WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $a) { $actions[$a] = true; }
            } catch (Throwable $e) {
                // ignore
            }
        }
        $list = array_keys($actions);
        sort($list);
        return $list;
    }

    public static function sourceLabel(string $source): string
    {
        return self::SOURCES[$source]['label'] ?? ucfirst($source);
    }

    public static function sourceBadgeClasses(string $source): string
    {
        return match ($source) {
            'settings' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
            'admin' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',
            'activity' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
            default => 'bg-slate-50 text-slate-600 ring-1 ring-slate-200',
        };
    }

    // ---------------------------------------------------------------------
    // Friendly metadata formatting
    // ---------------------------------------------------------------------

    /**
     * Returns a list of "friendly" key/value pairs for the metadata column.
     * Falls back to the raw payload (which the UI shows behind a "Show raw"
     * toggle) when there is no payload to project.
     */
    public static function friendlyMetadata(array $row): array
    {
        $raw = self::decodeRawPayload($row);
        $pairs = [];

        if (!empty($row['details_text'])) {
            $pairs[] = ['label' => 'Details', 'value' => (string) $row['details_text']];
        }

        if (is_array($raw)) {
            // Settings diff: before/after
            if (array_key_exists('before', $raw) || array_key_exists('after', $raw)) {
                $diffs = self::diffPairs($raw['before'] ?? null, $raw['after'] ?? null);
                foreach ($diffs as $d) {
                    $pairs[] = ['label' => $d['key'], 'value' => $d['old'] . ' → ' . $d['new']];
                }
                if (!$diffs) {
                    $pairs[] = ['label' => 'Change', 'value' => 'No field-level diff recorded'];
                }
            } else {
                // Activity log metadata: flatten 1-deep
                foreach ($raw as $k => $v) {
                    if ($k === 'target_type' || $k === 'target_id') continue; // already shown
                    $pairs[] = ['label' => self::prettifyKey((string) $k), 'value' => self::scalarToString($v)];
                }
            }
        }

        if (!empty($row['ip_address'])) {
            $pairs[] = ['label' => 'IP', 'value' => (string) $row['ip_address']];
        }

        return $pairs;
    }

    public static function rawPayload(array $row): ?string
    {
        $raw = self::decodeRawPayload($row);
        if ($raw === null) {
            return null;
        }
        return json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    private static function tableExists(string $name): bool
    {
        if (self::$tableCache === null) {
            self::$tableCache = [];
            try {
                $rows = self::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $r) { self::$tableCache[strtolower((string) $r)] = true; }
            } catch (Throwable $e) {
                // leave cache empty; downstream will skip tables
            }
        }
        return isset(self::$tableCache[strtolower($name)]);
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = self::pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
            $stmt->execute(['t' => $table, 'c' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Builds the UNION SQL across whichever of the three tables exist.
     * Returns null when no tables are available.
     */
    private static function buildUnion(array $filters): ?array
    {
        $sourceFilter = (string) ($filters['source'] ?? '');
        $allowedSources = $sourceFilter !== '' && isset(self::SOURCES[$sourceFilter])
            ? [$sourceFilter]
            : array_keys(self::SOURCES);

        $parts = [];
        $params = [];
        $available = [];

        // ---- settings: audit_log -----------------------------------------
        if (in_array('settings', $allowedSources, true) && self::tableExists('audit_log')) {
            $available[] = 'settings';
            $parts[] = "SELECT 'settings' AS source,
                              al.id AS source_id,
                              al.created_at AS created_at,
                              al.actor_user_id AS actor_user_id,
                              u.name AS actor_name,
                              u.email AS actor_email,
                              al.action AS action,
                              al.entity_type AS target_type,
                              al.entity_id AS target_id,
                              CAST(NULL AS CHAR) AS details_text,
                              al.diff_json AS metadata_json,
                              al.ip_address AS ip_address
                       FROM audit_log al
                       LEFT JOIN users u ON u.id = al.actor_user_id";
        }

        // ---- admin: audit_logs -------------------------------------------
        if (in_array('admin', $allowedSources, true) && self::tableExists('audit_logs')) {
            $available[] = 'admin';
            $parts[] = "SELECT 'admin' AS source,
                              alg.id AS source_id,
                              alg.created_at AS created_at,
                              alg.user_id AS actor_user_id,
                              u.name AS actor_name,
                              u.email AS actor_email,
                              alg.action AS action,
                              CAST(NULL AS CHAR) AS target_type,
                              CAST(NULL AS UNSIGNED) AS target_id,
                              alg.details AS details_text,
                              CAST(NULL AS CHAR) AS metadata_json,
                              alg.ip_address AS ip_address
                       FROM audit_logs alg
                       LEFT JOIN users u ON u.id = alg.user_id";
        }

        // ---- activity: activity_log --------------------------------------
        if (in_array('activity', $allowedSources, true) && self::tableExists('activity_log')) {
            $available[] = 'activity';
            $hasTarget = self::columnExists('activity_log', 'target_type');
            $hasTargetId = self::columnExists('activity_log', 'target_id');
            $targetTypeExpr = $hasTarget ? 'act.target_type' : "CAST(NULL AS CHAR)";
            $targetIdExpr = $hasTargetId ? 'act.target_id' : "CAST(NULL AS UNSIGNED)";
            $parts[] = "SELECT 'activity' AS source,
                              act.id AS source_id,
                              act.created_at AS created_at,
                              act.actor_id AS actor_user_id,
                              u.name AS actor_name,
                              u.email AS actor_email,
                              act.action AS action,
                              $targetTypeExpr AS target_type,
                              $targetIdExpr AS target_id,
                              CAST(NULL AS CHAR) AS details_text,
                              act.metadata AS metadata_json,
                              act.ip_address AS ip_address
                       FROM activity_log act
                       LEFT JOIN users u ON u.id = act.actor_id";
        }

        if (!$parts) {
            return null;
        }

        // Each leg is parenthesised so the UNION parser keeps the SELECTs
        // independent; the outer wrapper exists so we can apply WHERE / ORDER
        // BY / LIMIT once across the combined result.
        $union = '(' . implode(') UNION ALL (', $parts) . ')';

        // Outer filter wrapper (so filters apply uniformly across sources).
        $where = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(actor_name LIKE :__search OR actor_email LIKE :__search OR action LIKE :__search OR details_text LIKE :__search OR metadata_json LIKE :__search)';
            $params['__search'] = '%' . $search . '%';
        }
        $actor = trim((string) ($filters['actor'] ?? ''));
        if ($actor !== '') {
            $where[] = '(actor_name LIKE :__actor OR actor_email LIKE :__actor)';
            $params['__actor'] = '%' . $actor . '%';
        }
        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'action = :__action';
            $params['__action'] = $action;
        }
        $start = trim((string) ($filters['start'] ?? ''));
        if ($start !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $where[] = 'created_at >= :__start';
            $params['__start'] = $start . ' 00:00:00';
        }
        $end = trim((string) ($filters['end'] ?? ''));
        if ($end !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $where[] = 'created_at <= :__end';
            $params['__end'] = $end . ' 23:59:59';
        }

        $sql = 'SELECT * FROM (' . $union . ') AS audit_union';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return [$sql, $params, $available];
    }

    private static function actorLabel(array $row): string
    {
        $name = trim((string) ($row['actor_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $email = trim((string) ($row['actor_email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
        if (!empty($row['actor_user_id'])) {
            return 'User #' . (int) $row['actor_user_id'];
        }
        return 'System';
    }

    private static function targetLabel(array $row): string
    {
        $type = trim((string) ($row['target_type'] ?? ''));
        $id = $row['target_id'] ?? null;
        if ($type === '' && ($id === null || $id === '')) {
            return '—';
        }
        $label = self::prettifyKey($type ?: 'item');
        if ($id !== null && $id !== '') {
            $label .= ' #' . (string) $id;
        }
        return $label;
    }

    public static function humaniseAction(string $action): string
    {
        if ($action === '') return '—';
        $clean = str_replace(['_', '.'], ' ', $action);
        return ucwords(strtolower($clean));
    }

    private static function summarise(array $row): string
    {
        $pairs = self::friendlyMetadata($row);
        if (!$pairs) return '';
        $first = $pairs[0];
        $text = $first['label'] . ': ' . $first['value'];
        if (mb_strlen($text) > 90) {
            $text = mb_substr($text, 0, 87) . '…';
        }
        return $text;
    }

    private static function decodeRawPayload(array $row)
    {
        $json = $row['metadata_json'] ?? null;
        if ($json === null || $json === '') return null;
        $decoded = json_decode((string) $json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Field-level diff from a {before, after} payload.
     */
    private static function diffPairs($before, $after): array
    {
        $beforeArr = is_array($before) ? $before : [];
        $afterArr = is_array($after) ? $after : [];
        $keys = array_unique(array_merge(array_keys($beforeArr), array_keys($afterArr)));
        $out = [];
        foreach ($keys as $k) {
            $oldVal = $beforeArr[$k] ?? null;
            $newVal = $afterArr[$k] ?? null;
            if (self::scalarToString($oldVal) === self::scalarToString($newVal)) continue;
            $out[] = [
                'key' => self::prettifyKey((string) $k),
                'old' => self::scalarToString($oldVal),
                'new' => self::scalarToString($newVal),
            ];
        }
        return $out;
    }

    private static function scalarToString($value): string
    {
        if ($value === null) return '—';
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if (is_scalar($value)) return (string) $value;
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) return '[unprintable]';
        if (mb_strlen($encoded) > 120) return mb_substr($encoded, 0, 117) . '…';
        return $encoded;
    }

    private static function prettifyKey(string $key): string
    {
        if ($key === '') return '';
        $clean = str_replace(['_', '.'], ' ', $key);
        return ucwords(strtolower($clean));
    }
}
