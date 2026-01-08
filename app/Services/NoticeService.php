<?php
namespace App\Services;

class NoticeService
{
    public static function updateContent(int $noticeId, string $content, int $userId, string $summary): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT content FROM notices WHERE id = :id');
        $stmt->execute(['id' => $noticeId]);
        $current = $stmt->fetch();
        if ($current) {
            $stmt = $pdo->prepare('INSERT INTO notice_versions (notice_id, content, created_by, change_summary, created_at) VALUES (:notice_id, :content, :created_by, :summary, NOW())');
            $stmt->execute([
                'notice_id' => $noticeId,
                'content' => $current['content'],
                'created_by' => $userId,
                'summary' => $summary,
            ]);
        }
        $stmt = $pdo->prepare('UPDATE notices SET content = :content, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'content' => $content,
            'id' => $noticeId,
        ]);
    }
}
