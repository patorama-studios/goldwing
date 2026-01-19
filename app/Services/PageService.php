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

    public static function listEditablePages(): array
    {
        $pdo = Database::connection();
        self::ensureHomePage();
        return $pdo->query('SELECT id, slug, title, draft_html, live_html, access_level, updated_at FROM pages ORDER BY title ASC')->fetchAll() ?: [];
    }

    public static function ensureHomePage(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => 'home']);
        if ($stmt->fetch()) {
            return;
        }

        $title = 'Welcome to the Australian Goldwing Association';
        $html = '<p>Australian Goldwing Association is the national home for riders, chapters, and the open road.</p>';
        $stmt = $pdo->prepare('INSERT INTO pages (slug, title, html_content, draft_html, live_html, access_level, visibility, created_at) VALUES (:slug, :title, :html_content, :draft_html, :live_html, :access_level, :visibility, NOW())');
        $stmt->execute([
            'slug' => 'home',
            'title' => $title,
            'html_content' => $html,
            'draft_html' => $html,
            'live_html' => $html,
            'access_level' => 'public',
            'visibility' => 'public',
        ]);
    }

    public static function draftHtml(array $page): string
    {
        if (!empty($page['draft_html'])) {
            return (string) $page['draft_html'];
        }
        if (!empty($page['html_content'])) {
            return (string) $page['html_content'];
        }
        if (!empty($page['live_html'])) {
            return (string) $page['live_html'];
        }
        return '';
    }

    public static function liveHtml(array $page): string
    {
        if (!empty($page['live_html'])) {
            return (string) $page['live_html'];
        }
        if (!empty($page['html_content'])) {
            return (string) $page['html_content'];
        }
        return '';
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

    public static function updateContentWithSchema(int $pageId, string $html, string $schemaJson, int $userId, string $summary): void
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
        $stmt = $pdo->prepare('UPDATE pages SET html_content = :html_content, schema_json = :schema_json, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'html_content' => $html,
            'schema_json' => $schemaJson,
            'id' => $pageId,
        ]);
    }

    public static function updateDraft(int $pageId, string $draftHtml, string $accessLevel): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE pages SET draft_html = :draft_html, access_level = :access_level, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'draft_html' => $draftHtml,
            'access_level' => $accessLevel,
            'id' => $pageId,
        ]);
    }

    public static function publishDraft(int $pageId, string $liveHtml): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE pages SET live_html = :live_html, html_content = :html_content, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'live_html' => $liveHtml,
            'html_content' => $liveHtml,
            'id' => $pageId,
        ]);
    }
}
