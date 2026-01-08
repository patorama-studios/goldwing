<?php
declare(strict_types=1);

function usage(): void
{
    echo "Usage: php scripts/cleanup_upload_duplicates.php [path] [--apply]\n";
    echo "Scans for files with -WxH or _WxH suffixes and keeps the largest file per base name.\n";
    echo "Default is dry-run; add --apply to move duplicates into a _duplicates_ timestamp folder.\n";
}

$args = $argv;
array_shift($args);
$apply = in_array('--apply', $args, true);
$rootArg = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    $rootArg = $arg;
    break;
}

$root = $rootArg ?: (__DIR__ . '/../public_html/uploads');
$root = rtrim($root, '/');

if (!is_dir($root)) {
    fwrite(STDERR, "Directory not found: {$root}\n");
    usage();
    exit(1);
}

$skipPrefixes = ['_duplicates_'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($current, $key, $iterator) use ($root, $skipPrefixes): bool {
            if ($iterator->hasChildren()) {
                $path = $current->getPathname();
                $relative = ltrim(substr($path, strlen($root)), '/');
                foreach ($skipPrefixes as $prefix) {
                    if ($relative !== '' && str_starts_with($relative, $prefix)) {
                        return false;
                    }
                }
            }
            return true;
        }
    )
);

$groups = [];

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $path = $fileInfo->getPathname();
    $relative = ltrim(substr($path, strlen($root)), '/');
    foreach ($skipPrefixes as $prefix) {
        if ($relative !== '' && str_starts_with($relative, $prefix)) {
            continue 2;
        }
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $filename = pathinfo($path, PATHINFO_FILENAME);
    $hasSuffix = false;
    $baseName = $filename;
    if (preg_match('/^(.*?)([-_])(\d+)x(\d+)$/', $filename, $matches)) {
        $baseName = $matches[1];
        $hasSuffix = true;
    }

    $dir = dirname($relative);
    $normalized = ($dir === '.' ? '' : $dir . '/') . $baseName . '.' . $extension;
    $groups[$normalized][] = [
        'path' => $path,
        'relative' => $relative,
        'size' => $fileInfo->getSize(),
        'has_suffix' => $hasSuffix,
        'name' => basename($path),
    ];
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

$duplicates = [];
foreach ($groups as $key => $items) {
    if (count($items) <= 1) {
        continue;
    }
    usort($items, function (array $a, array $b): int {
        if ($a['size'] !== $b['size']) {
            return $b['size'] <=> $a['size'];
        }
        if ($a['has_suffix'] !== $b['has_suffix']) {
            return $a['has_suffix'] <=> $b['has_suffix'];
        }
        return strcmp($a['name'], $b['name']);
    });
    $keep = array_shift($items);
    foreach ($items as $dupe) {
        $duplicates[] = [
            'keep' => $keep,
            'dupe' => $dupe,
            'group' => $key,
        ];
    }
}

if (empty($duplicates)) {
    echo "No duplicates found in {$root}.\n";
    exit(0);
}

$totalBytes = array_sum(array_map(fn($row) => $row['dupe']['size'], $duplicates));
echo "Found " . count($duplicates) . " duplicate files across " . count($groups) . " groups.\n";
echo "Potential savings: " . format_bytes((int) $totalBytes) . "\n";

$report = [];
foreach ($duplicates as $row) {
    $report[] = [
        $row['dupe']['relative'],
        $row['keep']['relative'],
        (string) $row['dupe']['size'],
        (string) $row['keep']['size'],
    ];
}

$reportPath = $root . '/_duplicates_report.csv';
$fh = fopen($reportPath, 'w');
fputcsv($fh, ['duplicate', 'kept', 'duplicate_bytes', 'kept_bytes'], ',', '"', '\\');
foreach ($report as $line) {
    fputcsv($fh, $line, ',', '"', '\\');
}
fclose($fh);
echo "Report written to {$reportPath}\n";

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to move duplicates.\n";
    exit(0);
}

$timestamp = date('Ymd_His');
$dupeDir = $root . '/_duplicates_' . $timestamp;
if (!is_dir($dupeDir) && !mkdir($dupeDir, 0755, true) && !is_dir($dupeDir)) {
    fwrite(STDERR, "Failed to create {$dupeDir}\n");
    exit(1);
}

$moved = 0;
foreach ($duplicates as $row) {
    $source = $row['dupe']['path'];
    $relative = $row['dupe']['relative'];
    $target = $dupeDir . '/' . $relative;
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Failed to create {$targetDir}\n");
        continue;
    }
    if (rename($source, $target)) {
        $moved += 1;
    } else {
        fwrite(STDERR, "Failed to move {$source}\n");
    }
}

echo "Moved {$moved} duplicate files to {$dupeDir}\n";
