<?php
namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class FileIntegrityService
{
    public static function computeBaseline(string $root, array $paths, array $excludes): array
    {
        $files = self::collectFiles($root, $paths, $excludes);
        $baseline = [];
        foreach ($files as $relative => $fullPath) {
            $hash = hash_file('sha256', $fullPath);
            $baseline[$relative] = $hash;
        }
        ksort($baseline);
        return $baseline;
    }

    public static function loadBaseline(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT baseline_json FROM file_integrity_baseline WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();
        $baseline = json_decode($row['baseline_json'] ?? '', true);
        return is_array($baseline) ? $baseline : [];
    }

    public static function saveBaseline(array $baseline, ?int $approvedByUserId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE file_integrity_baseline SET baseline_json = :baseline, approved_by_user_id = :user_id, approved_at = NOW(), last_scan_at = NOW(), last_scan_status = \"OK\", last_scan_report_json = NULL WHERE id = 1');
        $stmt->execute([
            'baseline' => json_encode($baseline, JSON_UNESCAPED_SLASHES),
            'user_id' => $approvedByUserId,
        ]);
    }

    public static function scan(string $root, array $paths, array $excludes): array
    {
        $baseline = self::loadBaseline();
        if ($baseline === []) {
            throw new RuntimeException('Baseline not set.');
        }
        $current = self::computeBaseline($root, $paths, $excludes);
        $changes = [
            'added' => [],
            'modified' => [],
            'deleted' => [],
        ];
        foreach ($current as $file => $hash) {
            if (!isset($baseline[$file])) {
                $changes['added'][] = $file;
            } elseif ($baseline[$file] !== $hash) {
                $changes['modified'][] = $file;
            }
        }
        foreach ($baseline as $file => $hash) {
            if (!isset($current[$file])) {
                $changes['deleted'][] = $file;
            }
        }
        return $changes;
    }

    public static function recordScanResult(string $status, array $report = []): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE file_integrity_baseline SET last_scan_at = NOW(), last_scan_status = :status, last_scan_report_json = :report WHERE id = 1');
        $stmt->execute([
            'status' => $status,
            'report' => $report ? json_encode($report, JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    private static function collectFiles(string $root, array $paths, array $excludes): array
    {
        $root = rtrim($root, '/');
        $normalizedExcludes = array_map(fn($path) => self::normalizePath($path), $excludes);
        $files = [];
        foreach ($paths as $path) {
            $relativePath = self::normalizePath($path);
            $fullPath = $root . $relativePath;
            if (!is_dir($fullPath) && !is_file($fullPath)) {
                continue;
            }
            $iterator = is_dir($fullPath)
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS))
                : [$fullPath];
            foreach ($iterator as $item) {
                $pathName = is_string($item) ? $item : $item->getPathname();
                if (is_dir($pathName)) {
                    continue;
                }
                $relative = str_replace($root, '', $pathName);
                $relative = self::normalizePath($relative);
                if (self::isExcluded($relative, $normalizedExcludes)) {
                    continue;
                }
                $files[$relative] = $pathName;
            }
        }
        return $files;
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '') {
            return '';
        }
        return $path[0] === '/' ? $path : '/' . $path;
    }

    private static function isExcluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if ($exclude === '/') {
                continue;
            }
            if (str_starts_with($path, $exclude)) {
                return true;
            }
        }
        return false;
    }
}
