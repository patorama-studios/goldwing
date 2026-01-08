<?php
namespace App\Services;

class EventService
{
    public static function updateDescription(int $eventId, string $description, int $userId, string $summary): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT description FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $current = $stmt->fetch();
        if ($current) {
            $stmt = $pdo->prepare('INSERT INTO event_versions (event_id, description, created_by, change_summary, created_at) VALUES (:event_id, :description, :created_by, :summary, NOW())');
            $stmt->execute([
                'event_id' => $eventId,
                'description' => $current['description'],
                'created_by' => $userId,
                'summary' => $summary,
            ]);
        }
        $stmt = $pdo->prepare('UPDATE events SET description = :description, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'description' => $description,
            'id' => $eventId,
        ]);
    }
}
