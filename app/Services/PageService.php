<?php
namespace App\Services;

class PageService
{
    public static function getBySlug(string $slug): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $page = $stmt->fetch();
        return $page ?: null;
    }

    public static function getById(int $pageId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $pageId]);
        $page = $stmt->fetch();
        return $page ?: null;
    }

    public static function updateContent(int $pageId, string $html, int $userId, string $summary): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT html_content FROM pages WHERE id = :id');
        $stmt->execute(['id' => $pageId]);
        $current = $stmt->fetch();
        if ($current) {
            $stmt = $pdo->prepare('INSERT INTO page_versions (page_id, html_content, created_by, change_summary, created_at) VALUES (:page_id, :html_content, :created_by, :summary, NOW())');
            $stmt->execute([
                'page_id' => $pageId,
                'html_content' => $current['html_content'],
                'created_by' => $userId,
                'summary' => $summary,
            ]);
        }
        $stmt = $pdo->prepare('UPDATE pages SET html_content = :html_content, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'html_content' => $html,
            'id' => $pageId,
        ]);
    }
}
