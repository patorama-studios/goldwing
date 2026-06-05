<?php
/**
 * Admin System Documentation — chapter renderer.
 *
 * Loads chapters/<slug>.md (path resolved via _toc.json), renders it with
 * a minimal Markdown-to-HTML converter, and shows it inside the standard
 * backend layout with prev / next navigation.
 *
 * The Markdown subset supported:
 *   # ## ### #### headings
 *   **bold**  *italic*  `inline code`
 *   - bullet lists   1. numbered lists  (single level)
 *   ```code blocks``` (optional language tag, currently informational only)
 *   > blockquotes  (single level)
 *   --- horizontal rule
 *   [text](url)   ![alt](src)
 *   | pipe | tables | with --- separator row |
 *   Raw HTML lines pass through unchanged (so callouts can be written
 *   directly as <div class="callout"> blocks).
 *
 * This is enough for the System Documentation set; we deliberately avoid
 * pulling in a third-party Markdown library to keep the deploy surface tiny.
 */

require_once __DIR__ . '/../../../../app/bootstrap.php';

require_role(['admin', 'webmaster']);

$tocPath = __DIR__ . '/_toc.json';
$toc = is_file($tocPath) ? json_decode((string) file_get_contents($tocPath), true) : null;
if (!is_array($toc)) {
    http_response_code(500);
    echo 'Documentation TOC missing or invalid.';
    exit;
}

// Flatten chapter list with the part they belong to so we can do prev/next.
$flat = [];
foreach (($toc['parts'] ?? []) as $part) {
    foreach (($part['chapters'] ?? []) as $ch) {
        $ch['part_title'] = $part['title'] ?? '';
        $flat[$ch['slug']] = $ch;
    }
}

$slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['slug'] ?? ''));
if ($slug === '' || !isset($flat[$slug])) {
    http_response_code(404);
    echo 'Chapter not found.';
    exit;
}

$chapter = $flat[$slug];
$file = __DIR__ . '/' . ($chapter['file'] ?? '');
if (!is_file($file)) {
    http_response_code(404);
    echo 'Chapter file missing on disk: ' . e($chapter['file'] ?? '');
    exit;
}

$markdown = (string) file_get_contents($file);

// Build prev / next links from the flattened order.
$slugs = array_keys($flat);
$idx = array_search($slug, $slugs, true);
$prev = ($idx !== false && $idx > 0) ? $flat[$slugs[$idx - 1]] : null;
$next = ($idx !== false && $idx < count($slugs) - 1) ? $flat[$slugs[$idx + 1]] : null;

/**
 * Minimal Markdown renderer. See header comment above for supported syntax.
 */
function gw_render_markdown(string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $out = [];
    $i = 0;
    $n = count($lines);
    $inList = null; // 'ul' | 'ol' | null
    $inPara = false;
    $paraBuf = [];

    $flushPara = function () use (&$out, &$paraBuf, &$inPara) {
        if ($inPara && $paraBuf) {
            $out[] = '<p>' . gw_md_inline(trim(implode(' ', $paraBuf))) . '</p>';
        }
        $paraBuf = [];
        $inPara = false;
    };
    $closeList = function () use (&$out, &$inList) {
        if ($inList) {
            $out[] = '</' . $inList . '>';
            $inList = null;
        }
    };

    while ($i < $n) {
        $line = $lines[$i];

        // Code fence ```
        if (preg_match('/^```(.*)$/', $line, $m)) {
            $flushPara();
            $closeList();
            $lang = trim($m[1]);
            $codeLines = [];
            $i++;
            while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                $codeLines[] = $lines[$i];
                $i++;
            }
            $i++; // consume closing fence
            $out[] = '<pre class="not-prose bg-gray-900 text-amber-50 text-xs p-4 rounded-lg overflow-x-auto"><code'
                . ($lang ? ' data-lang="' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '') . '>'
                . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES) . '</code></pre>';
            continue;
        }

        // Horizontal rule
        if (preg_match('/^---+\s*$/', $line)) {
            $flushPara();
            $closeList();
            $out[] = '<hr class="my-6 border-gray-200">';
            $i++;
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $flushPara();
            $closeList();
            $level = strlen($m[1]);
            $tag = 'h' . min(6, $level + 0); // h1..h4
            $text = gw_md_inline(trim($m[2]));
            $out[] = "<{$tag}>{$text}</{$tag}>";
            $i++;
            continue;
        }

        // Tables (pipe syntax) — header row, separator row of dashes, body rows
        if (
            strpos($line, '|') !== false &&
            isset($lines[$i + 1]) &&
            preg_match('/^\s*\|?(\s*:?-{2,}:?\s*\|)+\s*:?-{2,}:?\s*\|?\s*$/', $lines[$i + 1])
        ) {
            $flushPara();
            $closeList();
            $headerCells = gw_md_split_row($line);
            $i += 2; // skip separator
            $bodyRows = [];
            while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') {
                $bodyRows[] = gw_md_split_row($lines[$i]);
                $i++;
            }
            $thead = '<thead><tr>';
            foreach ($headerCells as $c) {
                $thead .= '<th class="text-left text-xs uppercase tracking-wide text-gray-500 px-3 py-2 border-b border-gray-200">' . gw_md_inline($c) . '</th>';
            }
            $thead .= '</tr></thead>';
            $tbody = '<tbody>';
            foreach ($bodyRows as $row) {
                $tbody .= '<tr>';
                foreach ($row as $c) {
                    $tbody .= '<td class="text-sm text-gray-700 px-3 py-2 border-b border-gray-100 align-top">' . gw_md_inline($c) . '</td>';
                }
                $tbody .= '</tr>';
            }
            $tbody .= '</tbody>';
            $out[] = '<div class="not-prose overflow-x-auto my-4"><table class="min-w-full bg-white rounded-lg border border-gray-200">' . $thead . $tbody . '</table></div>';
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara();
            $closeList();
            $buf = [$m[1]];
            $i++;
            while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $m2)) {
                $buf[] = $m2[1];
                $i++;
            }
            $out[] = '<blockquote class="border-l-4 border-amber-400 bg-amber-50 text-gray-800 px-4 py-2 my-3 rounded-r">'
                . gw_md_inline(trim(implode(' ', $buf))) . '</blockquote>';
            continue;
        }

        // Unordered list
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($inList !== 'ul') { $closeList(); $out[] = '<ul class="list-disc pl-6 my-3 space-y-1">'; $inList = 'ul'; }
            $out[] = '<li>' . gw_md_inline($m[1]) . '</li>';
            $i++;
            continue;
        }
        // Ordered list
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
            $flushPara();
            if ($inList !== 'ol') { $closeList(); $out[] = '<ol class="list-decimal pl-6 my-3 space-y-1">'; $inList = 'ol'; }
            $out[] = '<li>' . gw_md_inline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Raw HTML line (pass through). Lets us write callouts as
        // <div class="callout"> ... </div> blocks directly.
        if (preg_match('/^\s*<[a-zA-Z!\/][^>]*>?\s*$/', $line)) {
            $flushPara();
            $closeList();
            $out[] = $line;
            $i++;
            continue;
        }

        // Blank line — flush paragraph and list
        if (trim($line) === '') {
            $flushPara();
            $closeList();
            $i++;
            continue;
        }

        // Default: paragraph buffer
        $closeList();
        $inPara = true;
        $paraBuf[] = $line;
        $i++;
    }
    $flushPara();
    $closeList();

    return implode("\n", $out);
}

function gw_md_split_row(string $line): array
{
    $line = trim($line);
    if ($line === '' || $line === '|') return [];
    $line = trim($line, '|');
    $parts = preg_split('/(?<!\\\\)\|/', $line) ?: [];
    return array_map(fn($s) => trim($s), $parts);
}

function gw_md_inline(string $s): string
{
    // Escape HTML first so generated <strong>/<em>/<a>/<img> tags survive.
    $s = htmlspecialchars($s, ENT_QUOTES);
    // Images ![alt](src) — must run BEFORE links
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        return '<img src="' . $m[2] . '" alt="' . $m[1] . '" class="rounded-lg border border-gray-200 my-3 max-w-full" loading="lazy">';
    }, $s);
    // Links [text](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = $m[2];
        $external = preg_match('#^https?://#', $url) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $url . '"' . $external . ' class="text-amber-700 hover:underline">' . $m[1] . '</a>';
    }, $s);
    // Inline code `..`
    $s = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 text-gray-800 px-1.5 py-0.5 rounded text-[0.9em]">$1</code>', $s);
    // Bold **..**
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    // Italic *..*  (non-greedy, avoid eating ** by requiring non-* on each side)
    $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);
    return $s;
}

$rendered = gw_render_markdown($markdown);

$user = current_user();
$pageTitle = $chapter['title'] . ' — System Documentation';
$activePage = 'help-docs';
require __DIR__ . '/../../../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php include __DIR__ . '/../../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-4xl mx-auto">
      <nav class="text-xs text-gray-500 mb-3 flex items-center gap-2">
        <a href="/admin/help/docs/" class="hover:text-amber-700">System Documentation</a>
        <span>›</span>
        <span class="text-gray-600"><?= e($chapter['part_title']) ?></span>
      </nav>
      <header class="mb-6">
        <h1 class="font-display text-3xl text-gray-900"><?= e($chapter['title']) ?></h1>
        <div class="text-xs text-gray-400 font-mono mt-1"><?= e($chapter['slug']) ?> · <?= e($chapter['file']) ?></div>
      </header>

      <article class="prose prose-amber max-w-none bg-white rounded-2xl border border-gray-200 shadow-sm p-6 md:p-8">
        <?= $rendered ?>
      </article>

      <nav class="mt-6 flex items-center justify-between gap-3">
        <?php if ($prev): ?>
          <a href="view.php?slug=<?= urlencode($prev['slug']) ?>"
             class="flex-1 bg-white border border-gray-200 hover:border-amber-400 rounded-xl px-4 py-3 transition">
            <div class="text-xs text-gray-500">← Previous</div>
            <div class="text-sm font-medium text-gray-900"><?= e($prev['title']) ?></div>
          </a>
        <?php else: ?>
          <div class="flex-1"></div>
        <?php endif; ?>
        <a href="/admin/help/docs/" class="px-4 py-3 text-sm text-gray-600 hover:text-amber-700">All chapters</a>
        <?php if ($next): ?>
          <a href="view.php?slug=<?= urlencode($next['slug']) ?>"
             class="flex-1 bg-white border border-gray-200 hover:border-amber-400 rounded-xl px-4 py-3 text-right transition">
            <div class="text-xs text-gray-500">Next →</div>
            <div class="text-sm font-medium text-gray-900"><?= e($next['title']) ?></div>
          </a>
        <?php else: ?>
          <div class="flex-1"></div>
        <?php endif; ?>
      </nav>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../../app/Views/partials/backend_footer.php'; ?>
