<?php
namespace App\Services;

class PageAiRevisionService
{
    public static function create(array $data): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO page_ai_revisions (page_id, user_id, provider, model, summary, diff_text, files_changed, before_content, after_content, reverted_from_revision_id, created_at) VALUES (:page_id, :user_id, :provider, :model, :summary, :diff_text, :files_changed, :before_content, :after_content, :reverted_from_revision_id, NOW())');
        $stmt->execute([
            'page_id' => $data['page_id'],
            'user_id' => $data['user_id'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'summary' => $data['summary'],
            'diff_text' => $data['diff_text'],
            'files_changed' => $data['files_changed'],
            'before_content' => $data['before_content'],
            'after_content' => $data['after_content'],
            'reverted_from_revision_id' => $data['reverted_from_revision_id'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function listByPage(int $pageId, int $limit = 50): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.*, u.name AS user_name FROM page_ai_revisions r LEFT JOIN users u ON r.user_id = u.id WHERE r.page_id = :page_id ORDER BY r.created_at DESC LIMIT :limit');
        $stmt->bindValue(':page_id', $pageId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function getById(int $pageId, int $revisionId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.*, u.name AS user_name FROM page_ai_revisions r LEFT JOIN users u ON r.user_id = u.id WHERE r.page_id = :page_id AND r.id = :id LIMIT 1');
        $stmt->execute([
            'page_id' => $pageId,
            'id' => $revisionId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
