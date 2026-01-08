<?php
namespace App\Services;

use PDO;

class DownloadLogRepository
{
    private static ?bool $downloadsTableAvailable = null;

    private static function hasDownloadsTable(PDO $pdo): bool
    {
        if (self::$downloadsTableAvailable !== null) {
            return self::$downloadsTableAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'downloads_log'");
            self::$downloadsTableAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$downloadsTableAvailable = false;
        }

        return self::$downloadsTableAvailable;
    }

    public static function listByMember(int $memberId, int $limit = 25): array
    {
        $pdo = Database::connection();
        if (!self::hasDownloadsTable($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM downloads_log WHERE member_id = :member_id ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
