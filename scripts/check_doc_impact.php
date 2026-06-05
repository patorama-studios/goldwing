<?php
/**
 * Documentation impact check.
 *
 * Given a list of changed file paths (relative to the repo root), reports
 * which Admin System Documentation chapters declare any of those files in
 * their `watched_files` list and therefore may need an update.
 *
 * Mirrors the structure of scripts/check_tour_impact.php so both can run
 * together as part of the standard "before push" workflow.
 *
 * Usage:
 *   # everything staged + unstaged + untracked relative to HEAD:
 *   git diff --name-only HEAD | php scripts/check_doc_impact.php
 *
 *   # staged only:
 *   git diff --name-only --cached | php scripts/check_doc_impact.php
 *
 *   # vs origin/main (what the wrapper script does):
 *   git diff --name-only origin/main...HEAD | php scripts/check_doc_impact.php
 *
 *   # explicit:
 *   php scripts/check_doc_impact.php app/Services/RefundService.php
 *
 * Exit codes:
 *   0  no docs affected
 *   2  one or more chapters declare a watched_files entry that matched
 *      (non-zero, but intentionally informational — does NOT block the push)
 */

$repoRoot = realpath(__DIR__ . '/..');
$tocPath  = $repoRoot . '/public_html/admin/help/docs/_toc.json';
if (!is_file($tocPath)) {
    fwrite(STDERR, "No documentation TOC at $tocPath\n");
    exit(0);
}
$toc = json_decode((string) file_get_contents($tocPath), true);
if (!is_array($toc)) {
    fwrite(STDERR, "Could not parse $tocPath\n");
    exit(0);
}

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

// Flatten chapters from all parts.
$chapters = [];
foreach (($toc['parts'] ?? []) as $part) {
    foreach (($part['chapters'] ?? []) as $ch) {
        $ch['part'] = $part['title'] ?? '';
        $chapters[] = $ch;
    }
}

$affected = [];
foreach ($chapters as $ch) {
    $watched = $ch['watched_files'] ?? [];
    if (!$watched) continue;
    foreach ($watched as $w) {
        $wNorm = rtrim($w, '/');
        $isDir = (substr($w, -1) === '/');
        foreach ($changed as $c) {
            $match = false;
            if ($isDir) {
                // directory prefix match
                if (str_starts_with($c, $wNorm . '/')) $match = true;
            } else {
                if ($c === $w) $match = true;
                // also match files moved into a folder of the watched file's directory
                elseif (str_starts_with($c, $wNorm . '/')) $match = true;
            }
            if ($match) {
                $affected[$ch['slug']] = $affected[$ch['slug']] ?? ['ch' => $ch, 'hits' => []];
                $affected[$ch['slug']]['hits'][] = $c;
            }
        }
    }
}

if (!$affected) {
    fwrite(STDOUT, "✓ No documentation chapters affected by the " . count($changed) . " changed file(s).\n");
    exit(0);
}

fwrite(STDOUT, "📚 " . count($affected) . " documentation chapter(s) may need an update — please review:\n\n");
foreach ($affected as $slug => $info) {
    $ch = $info['ch'];
    fwrite(STDOUT, "  • {$ch['title']}\n");
    fwrite(STDOUT, "    slug: {$slug}\n");
    fwrite(STDOUT, "    part: {$ch['part']}\n");
    fwrite(STDOUT, "    file: public_html/admin/help/docs/{$ch['file']}\n");
    fwrite(STDOUT, "    view: /admin/help/docs/view.php?slug={$slug}\n");
    fwrite(STDOUT, "    changed files that affect this chapter:\n");
    foreach (array_unique($info['hits']) as $h) {
        fwrite(STDOUT, "      - $h\n");
    }
    fwrite(STDOUT, "\n");
}
fwrite(STDOUT, "Either update the affected chapters or note in the commit message why the docs are still accurate.\n");

exit(2);
