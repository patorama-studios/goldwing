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
require_once __DIR__ . '/markdown.php';

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
      <div class="flex items-center justify-between gap-3 mb-3">
        <nav class="text-xs text-gray-500 flex items-center gap-2">
          <a href="/admin/help/docs/" class="hover:text-amber-700">System Documentation</a>
          <span>›</span>
          <span class="text-gray-600"><?= e($chapter['part_title']) ?></span>
        </nav>
        <form method="get" action="/admin/help/docs/" class="flex items-center gap-1 bg-white border border-gray-200 rounded-lg px-2 py-1 focus-within:border-amber-400">
          <span class="material-icons-outlined text-gray-400 text-sm">search</span>
          <input type="search" name="q" placeholder="Search docs…"
                 class="bg-transparent outline-none text-xs text-gray-900 placeholder-gray-400 w-40">
        </form>
      </div>
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
