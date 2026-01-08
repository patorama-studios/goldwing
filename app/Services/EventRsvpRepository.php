<?php
namespace App\Services;

use PDO;

class EventRsvpRepository
{
    private static ?bool $rsvpsTableAvailable = null;
    private static ?bool $eventsTableAvailable = null;
    private static array $eventColumnCache = [];

    private static function hasRsvpsTable(PDO $pdo): bool
    {
        if (self::$rsvpsTableAvailable !== null) {
            return self::$rsvpsTableAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'event_rsvps'");
            self::$rsvpsTableAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$rsvpsTableAvailable = false;
        }

        return self::$rsvpsTableAvailable;
    }

    private static function hasEventsTable(PDO $pdo): bool
    {
        if (self::$eventsTableAvailable !== null) {
            return self::$eventsTableAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'events'");
            self::$eventsTableAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$eventsTableAvailable = false;
        }

        return self::$eventsTableAvailable;
    }

    private static function hasEventColumn(PDO $pdo, string $column): bool
    {
        if (array_key_exists($column, self::$eventColumnCache)) {
            return self::$eventColumnCache[$column];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE " . $pdo->quote($column));
            self::$eventColumnCache[$column] = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$eventColumnCache[$column] = false;
        }

        return self::$eventColumnCache[$column];
    }

    public static function listByMember(int $memberId, int $limit = 25): array
    {
        $pdo = Database::connection();
        if (!self::hasRsvpsTable($pdo) || !self::hasEventsTable($pdo)) {
            return [];
        }
        $dateColumn = self::hasEventColumn($pdo, 'event_date')
            ? 'e.event_date'
            : (self::hasEventColumn($pdo, 'start_at') ? 'e.start_at' : 'NULL');
        $stmt = $pdo->prepare('SELECT r.*, e.title AS event_title, ' . $dateColumn . ' AS event_date FROM event_rsvps r JOIN events e ON e.id = r.event_id WHERE r.member_id = :member_id ORDER BY r.created_at DESC LIMIT :limit');
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
