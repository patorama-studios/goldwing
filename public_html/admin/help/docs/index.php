<?php
/**
 * Admin System Documentation — index / table of contents.
 *
 * Lists every chapter from _toc.json grouped by part. Each chapter links to
 * view.php?slug=... which renders the markdown file under chapters/.
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

$user = current_user();
$pageTitle = 'System Documentation';
$activePage = 'help-docs';
require __DIR__ . '/../../../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php include __DIR__ . '/../../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-5xl mx-auto">
      <header class="mb-8">
        <div class="flex items-center gap-2 text-xs uppercase tracking-wide text-gray-500 mb-2">
          <span class="material-icons-outlined text-base">menu_book</span>
          <span>Admin documentation</span>
        </div>
        <h1 class="font-display text-3xl text-gray-900"><?= e($toc['title'] ?? 'System Documentation') ?></h1>
        <?php if (!empty($toc['intro'])): ?>
          <p class="mt-2 text-gray-600 max-w-3xl"><?= e($toc['intro']) ?></p>
        <?php endif; ?>
      </header>

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
