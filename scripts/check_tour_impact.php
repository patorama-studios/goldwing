<?php
/**
 * Tour impact check.
 *
 * Given a list of changed file paths (relative to the repo root), reports
 * which tours in the manifest may be affected and need re-verification.
 *
 * Usage:
 *   # all files changed in the current diff against origin/main:
 *   git diff --name-only origin/main...HEAD | php scripts/check_tour_impact.php
 *
 *   # staged files only:
 *   git diff --name-only --cached | php scripts/check_tour_impact.php
 *
 *   # explicit list:
 *   php scripts/check_tour_impact.php public_html/member/index.php
 *
 * Exit codes:
 *   0  no tours affected
 *   2  tours affected (intentionally non-zero so it surfaces in CI / git hooks
 *      without blocking the push — wrap in `|| true` to ignore)
 */

$repoRoot = realpath(__DIR__ . '/..');
$manifestPath = $repoRoot . '/config/tour-manifest.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "No manifest at $manifestPath\n");
    exit(0);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$tours    = $manifest['tours'] ?? [];

// Collect changed files from STDIN (one per line) and/or argv.
$changed = [];
if (!posix_isatty(STDIN)) {
    while (($line = fgets(STDIN)) !== false) {
        $line = trim($line);
        if ($line !== '') $changed[] = $line;
    }
}
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '' && $a[0] !== '-') $changed[] = $a;
}
$changed = array_values(array_unique($changed));

if (!$changed) {
    fwrite(STDOUT, "No changed files provided. Pipe `git diff --name-only` into this script.\n");
    exit(0);
}

$affected = [];
foreach ($tours as $slug => $tour) {
    $watched = $tour['watched_files'] ?? [];
    foreach ($watched as $w) {
        foreach ($changed as $c) {
            if ($w === $c || str_starts_with($c, rtrim($w, '/') . '/')) {
                $affected[$slug] = $affected[$slug] ?? ['tour' => $tour, 'hits' => []];
                $affected[$slug]['hits'][] = $c;
            }
        }
    }
}

if (!$affected) {
    fwrite(STDOUT, "✓ No tours affected by the " . count($changed) . " changed file(s).\n");
    exit(0);
}

fwrite(STDOUT, "⚠  " . count($affected) . " tour(s) may be affected by your changes — please re-verify:\n\n");
foreach ($affected as $slug => $info) {
    fwrite(STDOUT, "  • {$info['tour']['name']}\n");
    fwrite(STDOUT, "    slug: $slug\n");
    fwrite(STDOUT, "    test: /admin/help/validator.php  → click \"Test now\"\n");
    fwrite(STDOUT, "    or:   php scripts/lint_tours.php $slug\n");
    fwrite(STDOUT, "    changed files that affect this tour:\n");
    foreach (array_unique($info['hits']) as $h) {
        fwrite(STDOUT, "      - $h\n");
    }
    fwrite(STDOUT, "\n");
}

exit(2);
