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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listForManagement(PDO $pdo): array
    {
        $columns = ['id', 'name'];
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function orderBy(PDO $pdo): string
    {
        if (self::hasColumn($pdo, 'sort_order')) {
            return 'sort_order ASC, name ASC';
        }
        return 'name ASC';
    }
}
