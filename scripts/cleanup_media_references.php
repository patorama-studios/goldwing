<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function usage(): void
{
    echo "Usage: php scripts/cleanup_media_references.php [report_csv] [--apply] [--db] [--files] [--dedupe]\n";
    echo "Defaults to public_html/uploads/_duplicates_report.csv. Dry-run unless --apply is provided.\n";
}

$args = $argv;
array_shift($args);
$apply = in_array('--apply', $args, true);
$doDb = in_array('--db', $args, true);
$doFiles = in_array('--files', $args, true);
$doDedupe = in_array('--dedupe', $args, true);

if (!$doDb && !$doFiles && !$doDedupe) {
    $doDb = true;
    $doFiles = true;
    $doDedupe = true;
}

$reportArg = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    $reportArg = $arg;
    break;
}

$defaultReport = __DIR__ . '/../public_html/uploads/_duplicates_report.csv';
$reportPath = $reportArg ?: $defaultReport;

if (!is_file($reportPath)) {
    fwrite(STDERR, "Report not found: {$reportPath}\n");
    usage();
    exit(1);
}

$baseUrl = rtrim((string) config('base_url', ''), '/');

$pairs = [];
$fh = fopen($reportPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Failed to read {$reportPath}\n");
    exit(1);
}
fgetcsv($fh, 0, ',', '"', '\\');
while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    if (count($row) < 2) {
        continue;
    }
    $pairs[] = [
        'duplicate' => trim($row[0]),
        'kept' => trim($row[1]),
    ];
}
fclose($fh);

if (empty($pairs)) {
    echo "No entries found in {$reportPath}.\n";
    exit(0);
}

function build_path_mapping(array $pairs, string $baseUrl): array
{
    $mapping = [];
    foreach ($pairs as $pair) {
        $dup = ltrim($pair['duplicate'], '/');
        $keep = ltrim($pair['kept'], '/');
        $mapping["/uploads/{$dup}"] = "/uploads/{$keep}";
        $mapping["uploads/{$dup}"] = "uploads/{$keep}";
        if ($baseUrl !== '') {
            $mapping["{$baseUrl}/uploads/{$dup}"] = "{$baseUrl}/uploads/{$keep}";
        }
    }
    return $mapping;
}

function needs_replacement(string $value): bool
{
    return str_contains($value, 'uploads/') || str_contains($value, '[media:');
}

function update_table_columns(PDO $pdo, string $table, array $columns, array $replacements, bool $apply): int
{
    $updated = 0;
    $lastId = 0;
    $colsSql = implode(', ', array_map(fn($col) => "`{$col}`", $columns));
    $selectSql = "SELECT id, {$colsSql} FROM {$table} WHERE id > :lastId ORDER BY id ASC LIMIT 200";
    $selectStmt = $pdo->prepare($selectSql);

    $setSql = implode(', ', array_map(fn($col) => "`{$col}` = :{$col}", $columns));
    $updateSql = "UPDATE {$table} SET {$setSql} WHERE id = :id";
    $updateStmt = $pdo->prepare($updateSql);

    while (true) {
        $selectStmt->execute(['lastId' => $lastId]);
        $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            $lastId = (int) $row['id'];
            $newRow = [];
            $changed = false;
            foreach ($columns as $col) {
                $value = $row[$col];
                if ($value === null || $value === '') {
                    $newRow[$col] = $value;
                    continue;
                }
                $valueStr = (string) $value;
                if (!needs_replacement($valueStr)) {
                    $newRow[$col] = $value;
                    continue;
                }
                $newValue = strtr($valueStr, $replacements);
                $newRow[$col] = $newValue;
                if ($newValue !== $valueStr) {
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            if ($apply) {
                $params = ['id' => $row['id']] + $newRow;
                $updateStmt->execute($params);
                $updated += $updateStmt->rowCount();
            } else {
                $updated += 1;
            }
        }
    }

    return $updated;
}

function dedupe_media(PDO $pdo, bool $apply): array
{
    $idMap = [];
    $removed = 0;
    $dupStmt = $pdo->query('SELECT path, COUNT(*) as c FROM media GROUP BY path HAVING c > 1');
    $dupGroups = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$dupGroups) {
        return ['id_map' => $idMap, 'removed' => 0];
    }

    $fetchStmt = $pdo->prepare('SELECT id, created_at FROM media WHERE path = :path ORDER BY created_at DESC, id DESC');
    $deleteStmt = $pdo->prepare('DELETE FROM media WHERE id = :id');

    foreach ($dupGroups as $group) {
        $path = $group['path'];
        $fetchStmt->execute(['path' => $path]);
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 2) {
            continue;
        }
        $keepId = (int) $rows[0]['id'];
        foreach (array_slice($rows, 1) as $row) {
            $oldId = (int) $row['id'];
            $idMap[$oldId] = $keepId;
            if ($apply) {
                $deleteStmt->execute(['id' => $oldId]);
                $removed += $deleteStmt->rowCount();
            } else {
                $removed += 1;
            }
        }
    }

    return ['id_map' => $idMap, 'removed' => $removed];
}

function update_files(array $roots, array $replacements, bool $apply): array
{
    $updatedFiles = [];
    $allowed = ['php', 'html', 'htm', 'css', 'js', 'json', 'md', 'txt', 'svg', 'xml'];
    $skipParts = [
        '/public_html/uploads',
        '/.git/',
        '/node_modules/',
        '/vendor/',
    ];

    foreach ($roots as $root) {
        $rootPath = realpath($root);
        if ($rootPath === false) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $path = $fileInfo->getPathname();
            $normalized = str_replace('\\', '/', $path);
            foreach ($skipParts as $skip) {
                if (str_contains($normalized, $skip)) {
                    continue 2;
                }
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            if (!needs_replacement($contents)) {
                continue;
            }
            $newContents = strtr($contents, $replacements);
            if ($newContents === $contents) {
                continue;
            }
            if ($apply) {
                file_put_contents($path, $newContents);
            }
            $updatedFiles[] = $path;
        }
    }

    return $updatedFiles;
}

$pathMapping = build_path_mapping($pairs, $baseUrl);
$shortcodeMapping = [];

$dbUpdated = 0;
$fileUpdates = [];
$removedRows = 0;

$pdo = db();

try {
    if ($apply && $doDb) {
        $pdo->beginTransaction();
    }

    if ($doDb) {
        $dbUpdated += update_table_columns($pdo, 'media', ['path', 'thumbnail_url', 'embed_html'], $pathMapping, $apply);
    }

    if ($doDedupe) {
        $dedupeResult = dedupe_media($pdo, $apply);
        $removedRows = $dedupeResult['removed'];
        foreach ($dedupeResult['id_map'] as $oldId => $keepId) {
            $shortcodeMapping["[media:{$oldId}]"] = "[media:{$keepId}]";
        }
    }

    $replacements = $pathMapping + $shortcodeMapping;

    if ($doDb) {
        $targets = [
            'pages' => ['html_content'],
            'page_versions' => ['html_content'],
            'notices' => ['content'],
            'notice_versions' => ['content'],
            'events' => ['description', 'attachment_url'],
            'event_versions' => ['description'],
            'wings_issues' => ['pdf_url', 'cover_image_url'],
            'ai_messages' => ['content'],
            'ai_drafts' => ['proposed_content'],
            'email_log' => ['body'],
        ];
        foreach ($targets as $table => $columns) {
            $dbUpdated += update_table_columns($pdo, $table, $columns, $replacements, $apply);
        }
    }

    if ($apply && $doDb) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $doDb && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

if ($doFiles) {
    $roots = [
        __DIR__ . '/../public_html',
        __DIR__ . '/../app',
    ];
    $fileUpdates = update_files($roots, $pathMapping + $shortcodeMapping, $apply);
}

echo $apply ? "Applied cleanup.\n" : "Dry-run complete.\n";
echo "DB rows updated: {$dbUpdated}\n";
echo "Media duplicates removed: {$removedRows}\n";
echo "Files updated: " . count($fileUpdates) . "\n";
if (!$apply) {
    echo "Re-run with --apply to write changes.\n";
}
