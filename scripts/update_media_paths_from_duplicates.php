<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function usage(): void
{
    echo "Usage: php scripts/update_media_paths_from_duplicates.php [report_csv] [--apply]\n";
    echo "Defaults to public_html/uploads/_duplicates_report.csv\n";
}

$args = $argv;
array_shift($args);
$apply = in_array('--apply', $args, true);
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
$header = fgetcsv($fh, 0, ',', '"', '\\');
while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    if (count($row) < 2) {
        continue;
    }
    $pairs[] = [
        'duplicate' => $row[0],
        'kept' => $row[1],
    ];
}
fclose($fh);

if (empty($pairs)) {
    echo "No entries found in {$reportPath}.\n";
    exit(0);
}

function candidate_paths(string $relative, string $baseUrl): array
{
    $relative = ltrim($relative, '/');
    $paths = [
        '/uploads/' . $relative,
        'uploads/' . $relative,
    ];
    if ($baseUrl !== '') {
        $paths[] = $baseUrl . '/uploads/' . $relative;
    }
    return array_values(array_unique($paths));
}

$pdo = db();
$totalMatches = 0;
$totalUpdates = 0;

$mapping = [];
foreach ($pairs as $pair) {
    $dupPaths = candidate_paths($pair['duplicate'], $baseUrl);
    $keptPaths = candidate_paths($pair['kept'], $baseUrl);
    foreach ($dupPaths as $index => $dupPath) {
        $keptPath = $keptPaths[$index] ?? $keptPaths[0];
        $mapping[$dupPath] = $keptPath;
    }
}

if (empty($mapping)) {
    echo "No duplicate mappings found.\n";
    exit(0);
}

try {
    if ($apply) {
        $pdo->beginTransaction();
    }

    $chunks = array_chunk($mapping, 400, true);
    foreach ($chunks as $chunk) {
        $caseParts = [];
        $params = [];
        $inParts = [];
        foreach ($chunk as $dupPath => $keptPath) {
            $caseParts[] = 'WHEN ? THEN ?';
            $params[] = $dupPath;
            $params[] = $keptPath;
            $inParts[] = '?';
            $params[] = $dupPath;
        }
        $caseSql = implode(' ', $caseParts);
        $inSql = implode(',', $inParts);

        if ($apply) {
            $pathSql = "UPDATE media SET path = CASE path {$caseSql} ELSE path END WHERE path IN ({$inSql})";
            $stmt = $pdo->prepare($pathSql);
            $stmt->execute($params);
            $totalUpdates += $stmt->rowCount();

            $thumbSql = "UPDATE media SET thumbnail_url = CASE thumbnail_url {$caseSql} ELSE thumbnail_url END WHERE thumbnail_url IN ({$inSql})";
            $stmt = $pdo->prepare($thumbSql);
            $stmt->execute($params);
            $totalUpdates += $stmt->rowCount();
        } else {
            $countSql = "SELECT COUNT(*) as c FROM media WHERE path IN ({$inSql}) OR thumbnail_url IN ({$inSql})";
            $countParams = array_merge(array_values(array_keys($chunk)), array_values(array_keys($chunk)));
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($countParams);
            $totalMatches += (int) ($stmt->fetch()['c'] ?? 0);
        }
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

if ($apply) {
    echo "Updated {$totalUpdates} media records.\n";
} else {
    echo "Dry-run: {$totalMatches} matching media records found.\n";
    echo "Re-run with --apply to update media paths.\n";
}
