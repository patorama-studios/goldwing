<?php
/**
 * Shared Markdown renderer for the Admin System Documentation.
 *
 * Used by view.php (single-chapter viewer), manual.php (print-ready full
 * manual) and scripts/build_manual.php (CLI manual build). Extracted from
 * view.php so all three render chapters identically.
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
 *   {{tour:slug}} and {{link:url|label}} action shortcodes.
 *
 * Print mode: define GW_DOCS_PRINT (true) before including this file to make
 * shortcodes render as static text chips, internal view.php?slug= links
 * become #ch-<slug> anchors, and /admin/... links become absolute site URLs.
 * Define GW_DOCS_IMG_BASE to override where images/... paths resolve to
 * (defaults to /admin/help/docs/images/).
 *
 * We deliberately avoid pulling in a third-party Markdown library to keep
 * the deploy surface tiny.
 */

function gw_docs_print_mode(): bool
{
    return defined('GW_DOCS_PRINT') && GW_DOCS_PRINT;
}

/**
 * Minimal Markdown renderer. See header comment above for supported syntax.
 */
function gw_render_markdown(string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    // Strip HTML comments entirely (including multi-line ones). Comments are
    // used for screenshot placeholders and other authoring notes that should
    // not surface to readers.
    $md = preg_replace('/<!--.*?-->/s', '', $md);
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
        // <div class="callout"> ... </div> blocks directly, and lets a
        // single-line <details><summary>Label</summary> survive intact.
        // Matches any line that starts with `<` (followed by a letter, !, or /)
        // and ends with `>` — covers single tags, closing tags, and complete
        // inline elements like `<summary>Dev notes</summary>`.
        if (preg_match('/^\s*<[a-zA-Z!\/].*>\s*$/', $line)) {
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

/**
 * Load the tour manifest once and cache it for shortcode lookups.
 * Returns a slug -> entry map, or an empty array if the manifest is missing.
 */
function gw_docs_tour_manifest(): array
{
    static $tours = null;
    if ($tours !== null) return $tours;
    $path = __DIR__ . '/../../../../config/tour-manifest.json';
    if (!is_file($path)) { $tours = []; return $tours; }
    $data = json_decode((string) file_get_contents($path), true);
    $tours = is_array($data) && isset($data['tours']) && is_array($data['tours']) ? $data['tours'] : [];
    return $tours;
}

function gw_md_inline(string $s): string
{
    // Resolve action shortcodes BEFORE htmlspecialchars so the generated
    // HTML survives the escaping pass. We slot a placeholder while we work
    // and substitute the real HTML at the end.
    $placeholders = [];
    // {{tour:tour-slug}} — renders a "Walk me through this" button that
    // opens the tour's page with ?tour=slug; the tour engine autostarts.
    // In print mode it becomes a static note pointing at the on-screen tour.
    $s = preg_replace_callback('/\{\{tour:([a-z0-9_-]+)\}\}/i', function ($m) use (&$placeholders) {
        $slug = $m[1];
        $tours = gw_docs_tour_manifest();
        if (gw_docs_print_mode()) {
            $label = isset($tours[$slug]) ? (string) ($tours[$slug]['name'] ?? $slug) : $slug;
            $html = '<span class="tour-ref">&#9654;&nbsp;Interactive walkthrough available on-screen: '
                  . htmlspecialchars($label, ENT_QUOTES) . '</span>';
        } elseif (!isset($tours[$slug])) {
            $html = '<span class="inline-flex items-center gap-1 text-xs text-red-600 bg-red-50 border border-red-200 rounded px-2 py-0.5">⚠ tour not in manifest: ' . htmlspecialchars($slug, ENT_QUOTES) . '</span>';
        } else {
            $entry = $tours[$slug];
            $pageUrl = (string) ($entry['page_url'] ?? '/');
            $sep = (strpos($pageUrl, '?') === false) ? '?' : '&';
            // Use the existing site-wide URL param so help_button.php's
            // inline autostart fires for us. (Our tour-engine.js autostart
            // ALSO accepts this and provides the sessionStorage persistence
            // for tours whose page_match differs from page_url.)
            $href = $pageUrl . $sep . 'gw_tour=' . urlencode($slug);
            $label = htmlspecialchars((string) ($entry['name'] ?? $slug), ENT_QUOTES);
            $html = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" '
                  . 'class="not-prose inline-flex items-center gap-1.5 my-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-secondary text-white hover:bg-emerald-700 shadow-sm transition" '
                  . 'data-tour-launch="' . htmlspecialchars($slug, ENT_QUOTES) . '">'
                  . '<span class="material-icons-outlined text-base">play_circle</span>'
                  . '<span>Walk me through: ' . $label . '</span>'
                  . '</a>';
        }
        $key = '@@SHORTCODE_' . count($placeholders) . '@@';
        $placeholders[$key] = $html;
        return $key;
    }, $s);
    // {{link:url|label}} — renders a styled deep-link button. Use when there's
    // no tour for the action but you want to give admins a single-click way
    // to land on the right admin page. Print mode: label plus the URL in text.
    $s = preg_replace_callback('/\{\{link:([^|}]+)\|([^}]+)\}\}/', function ($m) use (&$placeholders) {
        $url = trim($m[1]);
        $label = htmlspecialchars(trim($m[2]), ENT_QUOTES);
        if (gw_docs_print_mode()) {
            $html = '<span class="link-ref">' . $label . ' <span class="link-url">(' . htmlspecialchars($url, ENT_QUOTES) . ')</span></span>';
        } else {
            $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
                  . 'class="not-prose inline-flex items-center gap-1.5 my-1 px-3 py-1.5 text-sm font-medium rounded-lg border border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100 transition">'
                  . '<span class="material-icons-outlined text-base">launch</span>'
                  . '<span>' . $label . '</span>'
                  . '</a>';
        }
        $key = '@@SHORTCODE_' . count($placeholders) . '@@';
        $placeholders[$key] = $html;
        return $key;
    }, $s);

    // Escape HTML first so generated <strong>/<em>/<a>/<img> tags survive.
    $s = htmlspecialchars($s, ENT_QUOTES);
    // Images ![alt](src) — must run BEFORE links
    // Rewrite relative paths so they resolve correctly from the viewer URL.
    // Authors may write images/foo.png OR ../images/foo.png — both should
    // end up pointing at the docs images directory (or GW_DOCS_IMG_BASE).
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        $src = $m[2];
        $base = defined('GW_DOCS_IMG_BASE') ? GW_DOCS_IMG_BASE : '/admin/help/docs/images/';
        if (preg_match('#^(?:\.\./)?images/(.+)$#', $src, $mm)) {
            $src = $base . $mm[1];
        }
        return '<img src="' . $src . '" alt="' . $m[1] . '" class="rounded-lg border border-gray-200 my-3 max-w-full" loading="lazy">';
    }, $s);
    // Links [text](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = $m[2];
        if (gw_docs_print_mode()) {
            // Cross-references between chapters become in-document anchors so
            // they stay clickable inside the exported PDF.
            if (preg_match('/^view\.php\?slug=([a-zA-Z0-9_-]+)/', $url, $mm)) {
                return '<a href="#ch-' . $mm[1] . '" class="xref">' . $m[1] . '</a>';
            }
            // Site-relative links become absolute so PDF readers can follow them.
            if (str_starts_with($url, '/')) {
                return '<a href="https://goldwing.org.au' . $url . '" class="xref">' . $m[1] . '</a>';
            }
        }
        $external = preg_match('#^https?://#', $url) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $url . '"' . $external . ' class="text-amber-700 hover:underline">' . $m[1] . '</a>';
    }, $s);
    // Inline code `..`
    $s = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 text-gray-800 px-1.5 py-0.5 rounded text-[0.9em]">$1</code>', $s);
    // Bold **..**
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    // Italic *..*  (non-greedy, avoid eating ** by requiring non-* on each side)
    $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);
    // Restore action shortcodes from the placeholders captured at the top.
    if (!empty($placeholders)) {
        $s = strtr($s, $placeholders);
    }
    return $s;
}
