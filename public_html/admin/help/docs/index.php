<?php
/**
 * Admin System Documentation — index / table of contents / search.
 *
 * Lists every chapter from _toc.json grouped by part. When `?q=<terms>` is
 * present, also runs a case-insensitive AND-search across every chapter's
 * markdown and renders ranked results with highlighted snippets above the
 * table of contents.
 *
 * Admin-only. Renders inside the standard backend layout so it shows the
 * admin sidebar and matches every other admin page.
 */

require_once __DIR__ . '/../../../../app/bootstrap.php';

require_role(['admin', 'webmaster']);

$tocPath = __DIR__ . '/_toc.json';
$toc = is_file($tocPath) ? json_decode((string) file_get_contents($tocPath), true) : null;
if (!is_array($toc)) {
    http_response_code(500);
    echo 'Documentation TOC missing or invalid (_toc.json).';
    exit;
}

/**
 * Flatten chapters with the part they belong to.
 */
function gw_docs_flatten(array $toc): array
{
    $flat = [];
    foreach (($toc['parts'] ?? []) as $part) {
        foreach (($part['chapters'] ?? []) as $ch) {
            $ch['part_title'] = $part['title'] ?? '';
            $flat[] = $ch;
        }
    }
    return $flat;
}

/**
 * Run an AND-search across chapter markdown.
 * @return array ranked results: [['chapter'=>..., 'matches'=>int, 'snippets'=>string[]], ...]
 */
function gw_docs_search(array $chapters, string $q): array
{
    $q = trim($q);
    if ($q === '') return [];
    $terms = array_values(array_filter(preg_split('/\s+/', mb_strtolower($q))));
    if (!$terms) return [];

    $results = [];
    foreach ($chapters as $chapter) {
        $path = __DIR__ . '/' . ($chapter['file'] ?? '');
        if (!is_file($path)) continue;
        $content = (string) file_get_contents($path);
        // Strip HTML comments — match the renderer's behaviour so readers
        // don't see hits inside hidden screenshot TODOs.
        $content = (string) preg_replace('/<!--.*?-->/s', '', $content);
        $lower = mb_strtolower($content);
        $titleLower = mb_strtolower($chapter['title'] ?? '');

        // ALL terms must appear somewhere (AND).
        $total = 0;
        foreach ($terms as $t) {
            $body  = substr_count($lower, $t);
            $title = substr_count($titleLower, $t);
            if ($body + $title === 0) {
                continue 2;
            }
            // Title hits weighted heavier so "refund" surfaces Ch 17 above
            // chapters that merely mention refunds in passing.
            $total += $body + ($title * 10);
        }

        // Build up to 3 snippets, jumping ahead to avoid overlap.
        $snippets = [];
        $lastEnd = -1;
        // Walk through the lowercase content finding ANY term, in order.
        $allHits = [];
        foreach ($terms as $t) {
            $pos = 0;
            $len = strlen($t);
            while (($pos = strpos($lower, $t, $pos)) !== false) {
                $allHits[] = ['pos' => $pos, 'len' => $len];
                $pos += $len;
            }
        }
        usort($allHits, fn($a, $b) => $a['pos'] <=> $b['pos']);
        foreach ($allHits as $hit) {
            if (count($snippets) >= 3) break;
            if ($hit['pos'] < $lastEnd) continue; // overlap
            $start = max(0, $hit['pos'] - 90);
            $end   = min(strlen($content), $hit['pos'] + $hit['len'] + 110);
            $slice = substr($content, $start, $end - $start);
            // Tidy: collapse whitespace, strip code-fence markers + heading hashes
            $slice = preg_replace('/\s+/', ' ', $slice);
            $slice = preg_replace('/^#+\s*/', '', $slice);
            $slice = trim((string) $slice);
            $snip = htmlspecialchars($slice, ENT_QUOTES);
            // Highlight every term (case-insensitive).
            foreach ($terms as $t) {
                $pattern = '/(' . preg_quote(htmlspecialchars($t, ENT_QUOTES), '/') . ')/i';
                $snip = preg_replace($pattern, '<mark class="bg-yellow-200 text-gray-900 px-0.5 rounded">$1</mark>', (string) $snip);
            }
            $snippets[] = ($start > 0 ? '… ' : '') . $snip . ($end < strlen($content) ? ' …' : '');
            $lastEnd = $end + 30;
        }

        $results[] = [
            'chapter'  => $chapter,
            'matches'  => $total,
            'snippets' => $snippets,
        ];
    }
    usort($results, fn($a, $b) => $b['matches'] <=> $a['matches']);
    return $results;
}

$q = trim((string) ($_GET['q'] ?? ''));
$results = $q !== '' ? gw_docs_search(gw_docs_flatten($toc), $q) : [];

// Admin-audience tours — surface them in a "Walkthroughs" panel so admins can
// browse what interactive tours are available without going chapter-by-chapter.
$tourManifestPath = __DIR__ . '/../../../../config/tour-manifest.json';
$tourManifest = is_file($tourManifestPath) ? json_decode((string) file_get_contents($tourManifestPath), true) : null;
$adminTours = [];
if (is_array($tourManifest) && isset($tourManifest['tours']) && is_array($tourManifest['tours'])) {
    foreach ($tourManifest['tours'] as $slug => $entry) {
        if (!is_array($entry)) continue;
        $audience = (string) ($entry['audience'] ?? 'member');
        if ($audience !== 'admin') continue;
        $adminTours[$slug] = $entry;
    }
    // Sort by name for predictable ordering
    uasort($adminTours, fn($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
}

$user = current_user();
$pageTitle = $q !== '' ? "Search: $q — System Documentation" : 'System Documentation';
$activePage = 'help-docs';
require __DIR__ . '/../../../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php include __DIR__ . '/../../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-5xl mx-auto">
      <header class="mb-6">
        <div class="flex items-center gap-2 text-xs uppercase tracking-wide text-gray-500 mb-2">
          <span class="material-icons-outlined text-base">menu_book</span>
          <span>Admin documentation</span>
        </div>
        <h1 class="font-display text-3xl text-gray-900"><?= e($toc['title'] ?? 'System Documentation') ?></h1>
        <?php if (!empty($toc['intro'])): ?>
          <p class="mt-2 text-gray-600 max-w-3xl"><?= e($toc['intro']) ?></p>
        <?php endif; ?>
      </header>

      <form method="get" action="/admin/help/docs/" class="mb-6">
        <div class="flex items-center gap-2 bg-white rounded-2xl border border-gray-200 shadow-sm px-4 py-2 focus-within:border-amber-400 focus-within:ring-1 focus-within:ring-amber-400">
          <span class="material-icons-outlined text-gray-400">search</span>
          <input type="search" name="q" value="<?= e($q) ?>" autofocus
                 placeholder="Search the docs — try refund, 2FA, stripe key, lockout, area rep…"
                 class="flex-1 bg-transparent outline-none text-sm text-gray-900 placeholder-gray-400 py-1">
          <?php if ($q !== ''): ?>
            <a href="/admin/help/docs/" class="text-xs text-gray-500 hover:text-amber-700">Clear</a>
          <?php endif; ?>
          <button type="submit" class="px-3 py-1 text-xs font-semibold rounded-lg bg-primary text-gray-900 hover:bg-amber-300 transition">Search</button>
        </div>
        <p class="text-xs text-gray-500 mt-2">Searches every chapter for all your words (AND match). Hits in chapter titles rank higher.</p>
      </form>

      <?php if ($q === ''): ?>
        <a href="/admin/help/brand-style.php" target="_blank" rel="noopener"
           class="block bg-white rounded-2xl border border-gray-200 shadow-sm hover:border-primary hover:shadow-md transition mb-8 overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 via-white to-emerald-50 flex items-center gap-3">
            <span class="material-icons-outlined text-primary">palette</span>
            <div class="flex-1">
              <h2 class="font-display text-lg text-gray-900">Brand &amp; visual reference</h2>
              <p class="text-sm text-gray-600 mt-0.5">Live in-browser style guide — colours, type, buttons, cards, alerts. Renders with the real site CSS.</p>
            </div>
            <span class="material-icons-outlined text-gray-400">open_in_new</span>
          </div>
        </a>
      <?php endif; ?>

      <?php if ($q === '' && $adminTours): ?>
        <details class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-8 group" open>
          <summary class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-white cursor-pointer flex items-center gap-2 list-none">
            <span class="material-icons-outlined text-secondary">play_circle</span>
            <h2 class="font-display text-lg text-gray-900 flex-1">Interactive walkthroughs for admins</h2>
            <span class="text-xs text-gray-500"><?= count($adminTours) ?> available</span>
            <span class="material-icons-outlined text-gray-400 group-open:rotate-180 transition-transform">expand_more</span>
          </summary>
          <div class="px-6 py-4">
            <p class="text-sm text-gray-600 mb-4">
              These run live against the actual UI. Click any one — we'll send you to the right page and the walkthrough starts automatically. If the tour walks through a detail screen (an order, a product, a member), we'll land you on the list first so you can pick the item.
            </p>
            <div class="grid sm:grid-cols-2 gap-3">
              <?php foreach ($adminTours as $slug => $t):
                $pageUrl = (string) ($t['page_url'] ?? '/');
                $sep = (strpos($pageUrl, '?') === false) ? '?' : '&';
                $href = $pageUrl . $sep . 'tour=' . urlencode($slug);
              ?>
                <a href="<?= e($href) ?>"
                   class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:border-secondary hover:bg-emerald-50 transition group/tour">
                  <span class="material-icons-outlined text-secondary mt-0.5">play_circle</span>
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-gray-900"><?= e($t['name'] ?? $slug) ?></div>
                    <?php if (!empty($t['blurb'])): ?>
                      <p class="text-xs text-gray-600 mt-0.5 line-clamp-2"><?= e($t['blurb']) ?></p>
                    <?php endif; ?>
                    <div class="text-xs text-gray-400 font-mono mt-1"><?= e($slug) ?></div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($q !== ''): ?>
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-8">
          <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-white">
            <h2 class="font-display text-lg text-gray-900">
              <?= count($results) === 0 ? 'No results' : (count($results) . ' chapter' . (count($results) === 1 ? '' : 's') . ' match') ?>
              <span class="text-sm text-gray-500 font-normal"> for &ldquo;<?= e($q) ?>&rdquo;</span>
            </h2>
          </div>
          <?php if (!$results): ?>
            <div class="px-6 py-8 text-center text-gray-500 text-sm">
              Nothing matched. Try fewer words, or check the full list of chapters below.
            </div>
          <?php else: ?>
            <ul class="divide-y divide-gray-100">
              <?php foreach ($results as $r): ?>
                <?php $ch = $r['chapter']; ?>
                <li class="px-6 py-4 hover:bg-amber-50 transition">
                  <a href="view.php?slug=<?= urlencode($ch['slug']) ?>" class="block">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <div class="text-sm font-semibold text-gray-900"><?= e($ch['title']) ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= e($ch['part_title']) ?></div>
                      </div>
                      <span class="text-xs text-gray-400 font-mono"><?= (int) $r['matches'] ?> hits</span>
                    </div>
                    <?php foreach ($r['snippets'] as $snip): ?>
                      <p class="text-xs text-gray-700 leading-relaxed mt-2 ml-0"><?= $snip ?></p>
                    <?php endforeach; ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <h2 class="font-display text-lg text-gray-900 mt-10 mb-3">All chapters</h2>
      <?php endif; ?>

      <div class="space-y-6">
        <?php foreach (($toc['parts'] ?? []) as $part): ?>
          <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-white">
              <h2 class="font-display text-lg text-gray-900"><?= e($part['title']) ?></h2>
              <?php if (!empty($part['blurb'])): ?>
                <p class="text-sm text-gray-600 mt-1"><?= e($part['blurb']) ?></p>
              <?php endif; ?>
            </div>
            <ul class="divide-y divide-gray-100">
              <?php foreach (($part['chapters'] ?? []) as $ch): ?>
                <?php
                  $exists = is_file(__DIR__ . '/' . ($ch['file'] ?? ''));
                  $stub = false;
                  if ($exists) {
                      $first = (string) @file_get_contents(__DIR__ . '/' . $ch['file'], false, null, 0, 4096);
                      $stub = (stripos($first, 'STATUS: stub') !== false);
                  }
                ?>
                <li>
                  <a href="view.php?slug=<?= urlencode($ch['slug']) ?>"
                     class="flex items-start gap-3 px-6 py-3 hover:bg-amber-50 transition">
                    <span class="material-icons-outlined text-primary text-lg mt-0.5">article</span>
                    <div class="flex-1 min-w-0">
                      <div class="text-sm font-medium text-gray-900"><?= e($ch['title']) ?></div>
                      <div class="text-xs text-gray-500 mt-0.5 font-mono"><?= e($ch['slug']) ?></div>
                    </div>
                    <?php if (!$exists): ?>
                      <span class="text-xs text-red-600 font-semibold">Missing file</span>
                    <?php elseif ($stub): ?>
                      <span class="text-xs text-amber-700 font-semibold">Stub — to write</span>
                    <?php else: ?>
                      <span class="text-xs text-emerald-700 font-semibold">Written</span>
                    <?php endif; ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>
      </div>

      <footer class="mt-8 text-xs text-gray-500">
        Edit chapters at <code class="font-mono">public_html/admin/help/docs/chapters/</code>. The
        <code class="font-mono">doc-sync-check</code> skill compares your changes against each chapter's
        <code class="font-mono">watched_files</code> in <code class="font-mono">_toc.json</code> before every push.
      </footer>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../../app/Views/partials/backend_footer.php'; ?>
