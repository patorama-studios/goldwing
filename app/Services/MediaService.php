<?php
namespace App\Services;

use PDO;

class MediaService
{
    private const UPLOADS_PREFIX = '/uploads/';

    public static function registerUpload(array $data): ?int
    {
        $path = trim((string) ($data['path'] ?? ''));
        $filePath = self::normalizeUploadsPath($path);
        if ($filePath === null) {
            return null;
        }

        $fileName = $data['file_name'] ?? basename($filePath);
        $fullPath = self::uploadsBaseDir() . '/' . $filePath;
        $fileSize = $data['file_size'] ?? (is_file($fullPath) ? filesize($fullPath) : null);
        $fileType = $data['file_type'] ?? (is_file($fullPath) ? self::detectMime($fullPath) : null);

        $type = $data['type'] ?? self::typeFromMime($fileType);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $fileName;
        }
        $visibility = $data['visibility'] ?? 'member';
        $uploadedBy = isset($data['uploaded_by_user_id']) ? (int) $data['uploaded_by_user_id'] : null;

        $pdo = Database::connection();
        $existing = null;
        if ($filePath !== '') {
            $stmt = $pdo->prepare('SELECT id FROM media WHERE file_path = :file_path LIMIT 1');
            $stmt->execute(['file_path' => $filePath]);
            $existing = $stmt->fetch();
        }
        if (!$existing && $path !== '') {
            $stmt = $pdo->prepare('SELECT id FROM media WHERE path = :path LIMIT 1');
            $stmt->execute(['path' => $path]);
            $existing = $stmt->fetch();
        }
        if ($existing) {
            return (int) $existing['id'];
        }

        $stmt = $pdo->prepare('INSERT INTO media (type, title, path, embed_html, thumbnail_url, tags, visibility, uploaded_by, created_at, file_name, file_path, file_type, file_size, uploaded_by_user_id, source_context, source_table, source_record_id) VALUES (:type, :title, :path, :embed_html, :thumbnail_url, :tags, :visibility, :uploaded_by, NOW(), :file_name, :file_path, :file_type, :file_size, :uploaded_by_user_id, :source_context, :source_table, :source_record_id)');
        $stmt->execute([
            'type' => $type ?? 'file',
            'title' => $title,
            'path' => $path !== '' ? $path : self::buildUploadsUrl($filePath),
            'embed_html' => $data['embed_html'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'tags' => $data['tags'] ?? null,
            'visibility' => $visibility,
            'uploaded_by' => $uploadedBy,
            'file_name' => $fileName !== '' ? $fileName : null,
            'file_path' => $filePath !== '' ? $filePath : null,
            'file_type' => $fileType,
            'file_size' => $fileSize !== null ? (int) $fileSize : null,
            'uploaded_by_user_id' => $uploadedBy,
            'source_context' => $data['source_context'] ?? null,
            'source_table' => $data['source_table'] ?? null,
            'source_record_id' => isset($data['source_record_id']) ? (int) $data['source_record_id'] : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function syncIndex(int $actorUserId): array
    {
        $pdo = Database::connection();
        $added = 0;
        $scanned = 0;
        $paths = [];

        try {
            $stmt = $pdo->query("SELECT id, path FROM media WHERE (file_path IS NULL OR file_path = '') AND path LIKE '%/uploads/%'");
            $update = $pdo->prepare('UPDATE media SET file_path = :file_path, file_name = :file_name WHERE id = :id');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $normalized = self::normalizeUploadsPath((string) ($row['path'] ?? ''));
                if (!$normalized) {
                    continue;
                }
                $update->execute([
                    'file_path' => $normalized,
                    'file_name' => basename($normalized),
                    'id' => (int) $row['id'],
                ]);
            }
        } catch (\Throwable $e) {
        }

        $addCandidate = function (?string $value, string $context, ?string $table, ?int $recordId, ?string $mime = null) use (&$paths, &$scanned, &$added) {
            if ($value === null || $value === '') {
                return;
            }
            $normalized = MediaService::normalizeUploadsPath($value);
            if ($normalized === null) {
                return;
            }
            $key = $normalized . '|' . $context;
            if (isset($paths[$key])) {
                return;
            }
            $paths[$key] = [
                'path' => MediaService::buildUploadsUrl($normalized),
                'file_path' => $normalized,
                'source_context' => $context,
                'source_table' => $table,
                'source_record_id' => $recordId,
                'file_type' => $mime,
            ];
            $scanned += 1;
        };

        self::scanSimpleTable($pdo, 'store_product_images', ['image_url'], 'store', $addCandidate);
        self::scanSimpleTable($pdo, 'member_bikes', ['image_url'], 'member', $addCandidate);
        self::scanSimpleTable($pdo, 'notices', ['attachment_url'], 'notices', $addCandidate);
        self::scanSimpleTable($pdo, 'events', ['attachment_url'], 'events', $addCandidate);
        self::scanSimpleTable($pdo, 'wings_issues', ['pdf_url', 'cover_image_url'], 'wings', $addCandidate);

        try {
            $stmt = $pdo->query('SELECT id, file_path, mime FROM files');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $addCandidate($row['file_path'] ?? '', 'payments', 'files', (int) $row['id'], $row['mime'] ?? null);
            }
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $pdo->query("SELECT category, key_name, value_json FROM settings_global WHERE (category = 'site' AND key_name IN ('logo_url','favicon_url')) OR (category = 'store' AND key_name = 'email_logo_url')");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode($row['value_json'] ?? '', true);
                if (is_string($decoded)) {
                    $addCandidate($decoded, 'settings', 'settings_global', null);
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $pdo->prepare('SELECT user_id, value_json FROM settings_user WHERE key_name = :key');
            $stmt->execute(['key' => 'avatar_url']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode($row['value_json'] ?? '', true);
                if (is_string($decoded)) {
                    $addCandidate($decoded, 'member', 'settings_user', (int) $row['user_id']);
                }
            }
        } catch (\Throwable $e) {
        }

        $stmt = $pdo->query('SELECT id, html_content, draft_html, live_html FROM pages');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pageId = (int) $row['id'];
            foreach (['html_content', 'draft_html', 'live_html'] as $column) {
                $content = (string) ($row[$column] ?? '');
                if ($content === '') {
                    continue;
                }
                foreach (self::extractUploadUrls($content) as $url) {
                    $addCandidate($url, 'pages', 'pages', $pageId);
                }
            }
        }

        try {
            $stmt = $pdo->query("SELECT key_name, value_json FROM settings_global WHERE category = 'notifications' AND key_name = 'catalog'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $value = (string) ($row['value_json'] ?? '');
                foreach (self::extractUploadUrls($value) as $url) {
                    $addCandidate($url, 'notifications', 'settings_global', null);
                }
            }
        } catch (\Throwable $e) {
        }

        foreach ($paths as $candidate) {
            $id = self::registerUpload($candidate);
            if ($id) {
                $added += 1;
            }
        }

        return [
            'added' => $added,
            'scanned' => $scanned,
        ];
    }

    public static function deleteMedia(int $mediaId, int $actorUserId, ?string $bulkId = null): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
        $stmt->execute(['id' => $mediaId]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$media) {
            return ['ok' => false, 'error' => 'Media not found.'];
        }

        $filePath = $media['file_path'] ?? null;
        if ($filePath === null || $filePath === '') {
            $filePath = self::normalizeUploadsPath((string) ($media['path'] ?? ''));
        }
        if (!$filePath || !self::isSafeRelativePath($filePath)) {
            self::logDelete($actorUserId, $mediaId, (string) ($media['path'] ?? ''), $bulkId, 'blocked_unsafe_path');
            return ['ok' => false, 'error' => 'Delete blocked: unsafe file path.'];
        }

        $baseDir = self::uploadsBaseDir();
        $fullPath = rtrim($baseDir, '/') . '/' . $filePath;
        if (!self::isWithinUploads($fullPath, $baseDir)) {
            self::logDelete($actorUserId, $mediaId, $filePath, $bulkId, 'blocked_outside_uploads');
            return ['ok' => false, 'error' => 'Delete blocked: outside uploads directory.'];
        }

        $referenceSummary = self::clearReferences($pdo, $filePath, (string) ($media['path'] ?? ''));
        $referenceSummary['source_table'] = self::clearSourceReference($pdo, $media);

        $stmt = $pdo->prepare('DELETE FROM media WHERE id = :id');
        $stmt->execute(['id' => $mediaId]);

        $fileDeleted = false;
        if (is_file($fullPath)) {
            $fileDeleted = @unlink($fullPath);
        }

        self::logDelete($actorUserId, $mediaId, $filePath, $bulkId, $fileDeleted ? 'deleted' : 'missing_or_failed');

        return [
            'ok' => true,
            'file_deleted' => $fileDeleted,
            'references' => $referenceSummary,
        ];
    }

    public static function referenceCounts(array $mediaRows): array
    {
        $paths = [];
        foreach ($mediaRows as $row) {
            $path = $row['file_path'] ?? self::normalizeUploadsPath((string) ($row['path'] ?? ''));
            if ($path) {
                $paths[$path] = true;
            }
        }
        if (!$paths) {
            return [];
        }
        $paths = array_keys($paths);
        $counts = array_fill_keys($paths, 0);

        $pdo = Database::connection();
        foreach (self::referenceColumns() as $ref) {
            $results = self::countsForColumn($pdo, $ref['table'], $ref['column'], $paths);
            foreach ($results as $value => $count) {
                $normalized = self::normalizeUploadsPath((string) $value);
                if ($normalized && isset($counts[$normalized])) {
                    $counts[$normalized] += $count;
                }
            }
        }

        $encoded = [];
        foreach ($paths as $path) {
            $encoded[json_encode(self::buildUploadsUrl($path), JSON_UNESCAPED_SLASHES)] = $path;
            $encoded[json_encode('uploads/' . ltrim($path, '/'), JSON_UNESCAPED_SLASHES)] = $path;
        }
        try {
            $stmt = $pdo->prepare('SELECT value_json, COUNT(*) as c FROM settings_user WHERE key_name = ? AND value_json IN (' . self::placeholders(count($encoded)) . ') GROUP BY value_json');
            $stmt->execute(array_merge(['avatar_url'], array_keys($encoded)));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $path = $encoded[$row['value_json']] ?? null;
                if ($path) {
                    $counts[$path] += (int) $row['c'];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $pdo->prepare("SELECT value_json, COUNT(*) as c FROM settings_global WHERE ((category = 'site' AND key_name IN ('logo_url','favicon_url')) OR (category = 'store' AND key_name = 'email_logo_url')) AND value_json IN (" . self::placeholders(count($encoded)) . ") GROUP BY value_json");
            $stmt->execute(array_keys($encoded));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $path = $encoded[$row['value_json']] ?? null;
                if ($path) {
                    $counts[$path] += (int) $row['c'];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $fileResults = self::countsForColumn($pdo, 'files', 'file_path', $paths);
            foreach ($fileResults as $value => $count) {
                $normalized = self::normalizeUploadsPath((string) $value);
                if ($normalized && isset($counts[$normalized])) {
                    $counts[$normalized] += $count;
                }
            }
        } catch (\Throwable $e) {
        }

        return $counts;
    }

    public static function referenceColumnsReport(): array
    {
        $columns = [];
        foreach (self::referenceColumns() as $ref) {
            $columns[] = $ref['table'] . '.' . $ref['column'];
        }
        $columns[] = 'settings_user.avatar_url';
        $columns[] = 'settings_global.site.logo_url';
        $columns[] = 'settings_global.site.favicon_url';
        $columns[] = 'settings_global.store.email_logo_url';
        $columns[] = 'store_settings.email_logo_url';
        $columns[] = 'files.file_path';
        $columns[] = 'pages.html_content';
        $columns[] = 'pages.draft_html';
        $columns[] = 'pages.live_html';
        $columns[] = 'settings_global.notifications.catalog';
        return $columns;
    }

    private static function scanSimpleTable(PDO $pdo, string $table, array $columns, string $context, callable $addCandidate): void
    {
        $columnSql = implode(', ', array_merge(['id'], $columns));
        try {
            $stmt = $pdo->query("SELECT {$columnSql} FROM {$table}");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $recordId = (int) ($row['id'] ?? 0);
                foreach ($columns as $column) {
                    $addCandidate((string) ($row[$column] ?? ''), $context, $table, $recordId);
                }
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function clearReferences(PDO $pdo, string $filePath, string $fullPathUrl): array
    {
        $summary = [];
        $pathUrl = self::buildUploadsUrl($filePath);
        $targets = [$pathUrl, $fullPathUrl];

        $summary['store_product_images'] = self::deleteRows($pdo, 'store_product_images', 'image_url', $targets);
        $summary['member_bikes'] = self::nullField($pdo, 'member_bikes', 'image_url', $targets);
        $summary['notices'] = self::nullField($pdo, 'notices', 'attachment_url', $targets, ['attachment_type']);
        $summary['events'] = self::nullField($pdo, 'events', 'attachment_url', $targets);
        $summary['wings_cover'] = self::nullField($pdo, 'wings_issues', 'cover_image_url', $targets);
        $summary['wings_pdf'] = self::emptyField($pdo, 'wings_issues', 'pdf_url', $targets);

        $summary['store_settings'] = self::nullField($pdo, 'store_settings', 'email_logo_url', $targets);

        $encodedTargets = [];
        foreach ($targets as $target) {
            $encodedTargets[] = json_encode($target, JSON_UNESCAPED_SLASHES);
        }
        $summary['settings_user'] = self::updateSettings($pdo, 'settings_user', 'avatar_url', $encodedTargets);
        $summary['settings_global'] = self::updateSettingsGlobal($pdo, $encodedTargets);

        $summary['files'] = self::deleteFiles($pdo, $targets);
        $summary['pages'] = self::replaceInTextColumns($pdo, 'pages', ['html_content', 'draft_html', 'live_html'], $targets);
        $summary['notifications'] = self::replaceInTextColumns($pdo, 'settings_global', ['value_json'], $targets, "category = 'notifications' AND key_name = 'catalog'");

        return $summary;
    }

    private static function clearSourceReference(PDO $pdo, array $media): int
    {
        $table = $media['source_table'] ?? '';
        $recordId = (int) ($media['source_record_id'] ?? 0);
        if ($table === '' || $recordId <= 0) {
            return 0;
        }
        try {
            if ($table === 'store_product_images') {
                $stmt = $pdo->prepare('DELETE FROM store_product_images WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
            if ($table === 'member_bikes') {
                $stmt = $pdo->prepare('UPDATE member_bikes SET image_url = NULL WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
            if ($table === 'notices') {
                $stmt = $pdo->prepare('UPDATE notices SET attachment_url = NULL, attachment_type = NULL WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
            if ($table === 'events') {
                $stmt = $pdo->prepare('UPDATE events SET attachment_url = NULL WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
            if ($table === 'wings_issues') {
                if (($media['type'] ?? '') === 'pdf') {
                    $stmt = $pdo->prepare('UPDATE wings_issues SET pdf_url = "" WHERE id = :id');
                } else {
                    $stmt = $pdo->prepare('UPDATE wings_issues SET cover_image_url = NULL WHERE id = :id');
                }
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
            if ($table === 'calendar_events') {
                $stmt = $pdo->prepare('UPDATE calendar_events SET media_id = NULL WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
            if ($table === 'files') {
                $stmt = $pdo->prepare('UPDATE invoices SET pdf_file_id = NULL WHERE pdf_file_id = :id');
                $stmt->execute(['id' => $recordId]);
                $stmt = $pdo->prepare('DELETE FROM files WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                return $stmt->rowCount();
            }
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }

    private static function deleteFiles(PDO $pdo, array $targets): int
    {
        if (!$targets) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM files WHERE file_path IN (' . self::placeholders(count($targets)) . ')');
            $stmt->execute($targets);
            $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
            if (!$ids) {
                return 0;
            }
            $pdo->prepare('UPDATE invoices SET pdf_file_id = NULL WHERE pdf_file_id IN (' . self::placeholders(count($ids)) . ')')->execute($ids);
            $stmt = $pdo->prepare('DELETE FROM files WHERE id IN (' . self::placeholders(count($ids)) . ')');
            $stmt->execute($ids);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function updateSettings(PDO $pdo, string $table, string $keyName, array $encodedTargets): int
    {
        if (!$encodedTargets) {
            return 0;
        }
        $emptyJson = json_encode('', JSON_UNESCAPED_SLASHES);
        $params = array_merge([$emptyJson, $keyName], $encodedTargets);
        $stmt = $pdo->prepare("UPDATE {$table} SET value_json = ?, updated_at = NOW() WHERE key_name = ? AND value_json IN (" . self::placeholders(count($encodedTargets)) . ')');
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function updateSettingsGlobal(PDO $pdo, array $encodedTargets): int
    {
        if (!$encodedTargets) {
            return 0;
        }
        $emptyJson = json_encode('', JSON_UNESCAPED_SLASHES);
        $params = array_merge([$emptyJson], $encodedTargets);
        $stmt = $pdo->prepare("UPDATE settings_global SET value_json = ?, updated_at = NOW() WHERE ((category = 'site' AND key_name IN ('logo_url','favicon_url')) OR (category = 'store' AND key_name = 'email_logo_url')) AND value_json IN (" . self::placeholders(count($encodedTargets)) . ')');
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function nullField(PDO $pdo, string $table, string $column, array $targets, array $extraNulls = []): int
    {
        if (!$targets) {
            return 0;
        }
        try {
            $fields = array_merge([$column], $extraNulls);
            $set = implode(', ', array_map(fn($field) => "{$field} = NULL", $fields));
            $stmt = $pdo->prepare("UPDATE {$table} SET {$set} WHERE {$column} IN (" . self::placeholders(count($targets)) . ')');
            $stmt->execute($targets);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function emptyField(PDO $pdo, string $table, string $column, array $targets): int
    {
        if (!$targets) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("UPDATE {$table} SET {$column} = '' WHERE {$column} IN (" . self::placeholders(count($targets)) . ')');
            $stmt->execute($targets);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function deleteRows(PDO $pdo, string $table, string $column, array $targets): int
    {
        if (!$targets) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$column} IN (" . self::placeholders(count($targets)) . ')');
            $stmt->execute($targets);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function replaceInTextColumns(PDO $pdo, string $table, array $columns, array $targets, string $extraWhere = ''): int
    {
        if (!$targets || !$columns) {
            return 0;
        }
        $updated = 0;
        $where = $extraWhere !== '' ? ' AND ' . $extraWhere : '';
        foreach ($targets as $target) {
            foreach ($columns as $column) {
                try {
                    $stmt = $pdo->prepare("UPDATE {$table} SET {$column} = REPLACE({$column}, :target, '') WHERE {$column} LIKE :like{$where}");
                    $stmt->execute([
                        'target' => $target,
                        'like' => '%' . $target . '%',
                    ]);
                    $updated += $stmt->rowCount();
                } catch (\Throwable $e) {
                }
            }
        }
        return $updated;
    }

    private static function countsForColumn(PDO $pdo, string $table, string $column, array $paths): array
    {
        if (!$paths) {
            return [];
        }
        try {
            $placeholders = self::placeholders(count($paths));
            $stmt = $pdo->prepare("SELECT {$column} as value, COUNT(*) as c FROM {$table} WHERE {$column} IN ({$placeholders}) GROUP BY {$column}");
            $stmt->execute(array_map(fn($p) => self::buildUploadsUrl($p), $paths));
            $results = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[$row['value']] = (int) $row['c'];
            }
            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function referenceColumns(): array
    {
        return [
            ['table' => 'store_product_images', 'column' => 'image_url'],
            ['table' => 'member_bikes', 'column' => 'image_url'],
            ['table' => 'notices', 'column' => 'attachment_url'],
            ['table' => 'events', 'column' => 'attachment_url'],
            ['table' => 'wings_issues', 'column' => 'pdf_url'],
            ['table' => 'wings_issues', 'column' => 'cover_image_url'],
            ['table' => 'store_settings', 'column' => 'email_logo_url'],
        ];
    }

    private static function logDelete(int $actorUserId, int $mediaId, string $filePath, ?string $bulkId, string $status): void
    {
        $payload = [
            'media_id' => $mediaId,
            'file_path' => $filePath,
            'bulk_id' => $bulkId,
            'status' => $status,
        ];
        AuditService::log($actorUserId, 'media_delete', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private static function extractUploadUrls(string $content): array
    {
        $matches = [];
        preg_match_all('~\/uploads\/[a-zA-Z0-9_\-\/\.]+~', $content, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    private static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }

    private static function uploadsBaseDir(): string
    {
        $path = realpath(__DIR__ . '/../../public_html/uploads');
        return $path ?: __DIR__ . '/../../public_html/uploads';
    }

    public static function normalizeUploadsPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsed = parse_url($path, PHP_URL_PATH);
            if (is_string($parsed)) {
                $path = $parsed;
            }
        }
        if (str_contains($path, self::UPLOADS_PREFIX)) {
            $path = substr($path, strpos($path, self::UPLOADS_PREFIX) + strlen(self::UPLOADS_PREFIX));
        } elseif (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
        }
        if (str_contains($path, '?')) {
            $path = strstr($path, '?', true);
        }
        if (str_contains($path, '#')) {
            $path = strstr($path, '#', true);
        }
        $path = ltrim($path, '/');
        if ($path === '' || !self::isSafeRelativePath($path)) {
            return null;
        }
        return $path;
    }

    private static function buildUploadsUrl(string $filePath): string
    {
        return self::UPLOADS_PREFIX . ltrim($filePath, '/');
    }

    private static function detectMime(string $path): ?string
    {
        if (!class_exists('finfo')) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return $mime ?: null;
    }

    private static function typeFromMime(?string $mime): ?string
    {
        if ($mime === null || $mime === '') {
            return null;
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        return 'file';
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        if (preg_match('/^[a-zA-Z]:/', $path)) {
            return false;
        }
        return true;
    }

    private static function isWithinUploads(string $fullPath, string $baseDir): bool
    {
        $base = realpath($baseDir);
        if ($base === false) {
            return false;
        }
        $full = realpath($fullPath);
        if ($full === false) {
            return str_starts_with($fullPath, $base);
        }
        return str_starts_with($full, $base);
    }
}
