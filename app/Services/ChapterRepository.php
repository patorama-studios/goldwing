<?php
namespace App\Services;

use PDO;

class ChapterRepository
{
    private static array $columnCache = [];

    public static function hasColumn(PDO $pdo, string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM chapters LIKE " . $pdo->quote($column));
            self::$columnCache[$column] = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$columnCache[$column] = false;
        }

        return self::$columnCache[$column];
    }

    public static function listForSelection(PDO $pdo, bool $activeOnly = true): array
    {
        $columns = ['id', 'name'];
        if (self::hasColumn($pdo, 'abbreviation')) {
            $columns[] = 'abbreviation';
        }
        if (self::hasColumn($pdo, 'state')) {
            $columns[] = 'state';
        }
        if (self::hasColumn($pdo, 'is_active')) {
            $columns[] = 'is_active';
        }
        if (self::hasColumn($pdo, 'sort_order')) {
            $columns[] = 'sort_order';
        }

        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM chapters';
        if ($activeOnly && self::hasColumn($pdo, 'is_active')) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY ' . self::orderBy($pdo);

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return self::decorateRows($rows);
    }

    public static function listForManagement(PDO $pdo): array
    {
        $columns = ['id', 'name'];
        if (self::hasColumn($pdo, 'abbreviation')) {
            $columns[] = 'abbreviation';
        }
        if (self::hasColumn($pdo, 'state')) {
            $columns[] = 'state';
        }
        if (self::hasColumn($pdo, 'is_active')) {
            $columns[] = 'is_active';
        }
        if (self::hasColumn($pdo, 'sort_order')) {
            $columns[] = 'sort_order';
        }

        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM chapters ORDER BY ' . self::orderBy($pdo);
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return self::decorateRows($rows);
    }

    /**
     * SQL fragment that returns the chapter name prefixed with its abbreviation
     * in brackets when available, e.g. "(FCC) Fraser Coast Chapter".
     * Falls back to the raw name when the abbreviation column is missing.
     */
    public static function displayNameSql(PDO $pdo, string $alias = 'c'): string
    {
        if (self::hasColumn($pdo, 'abbreviation')) {
            return "CASE WHEN {$alias}.abbreviation IS NOT NULL AND {$alias}.abbreviation <> '' "
                . "THEN CONCAT('(', {$alias}.abbreviation, ') ', {$alias}.name) "
                . "ELSE {$alias}.name END";
        }
        return "{$alias}.name";
    }

    public static function formatLabel(?string $name, ?string $abbreviation = null): string
    {
        $name = trim((string) $name);
        $abbreviation = trim((string) $abbreviation);
        if ($name === '') {
            return '';
        }
        if ($abbreviation === '') {
            return $name;
        }
        return '(' . $abbreviation . ') ' . $name;
    }

    private static function decorateRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['display_label'] = self::formatLabel($row['name'] ?? '', $row['abbreviation'] ?? null);
        }
        return $rows;
    }

    private static function orderBy(PDO $pdo): string
    {
        if (self::hasColumn($pdo, 'sort_order')) {
            return 'sort_order ASC, name ASC';
        }
        return 'name ASC';
    }
}
