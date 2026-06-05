<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\TourService;

require_login();
$user = current_user();
$completions = TourService::completionsFor((int) $user['id']);

// Members see ONLY member-audience tours. Even if a member happens to have a
// role that admin-audience tours would also include (e.g. area_rep), the
// member-facing page is deliberately limited to member walkthroughs so the
// list stays simple and there's no link out to admin URLs.
$allTours = TourService::allTours();
$tours = [];
foreach ($allTours as $slug => $t) {
    if (($t['audience'] ?? 'member') === 'member') {
        $tours[$slug] = $t;
    }
}

$pageTitle  = 'Help &amp; Walkthroughs';
$activePage = 'help';
require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php include __DIR__ . '/../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-3xl mx-auto">
      <header class="mb-8">
        <h1 class="font-display text-3xl text-gray-900">Help &amp; Walkthroughs</h1>
        <p class="mt-1 text-gray-600">
          Pick a topic to walk through it step-by-step.
          You can come back any time &mdash; just click the gold <strong>?</strong> in the corner of any page.
        </p>
      </header>

      <?php if (!$tours): ?>
        <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center text-gray-500">
          No walkthroughs available yet.
        </div>
      <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
          <?php foreach ($tours as $slug => $t): ?>
            <?php
              $done = !empty($completions[$slug]);
              // Build a clean URL that points to the tour's page with ?gw_tour=<slug>
              $url = $t['page_url'] ?? '#';
              if ($url !== '#') {
                  $sep = (strpos($url, '?') !== false) ? '&' : '?';
                  $url .= $sep . 'gw_tour=' . rawurlencode($slug);
              }
            ?>
            <a href="<?= e($url) ?>"
               class="block bg-white rounded-2xl border border-gray-200 shadow-sm hover:border-primary hover:shadow-md transition p-5">
              <div class="flex items-start gap-3">
                <span class="material-icons-outlined text-primary text-2xl">help_outline</span>
                <div class="flex-1">
                  <div class="font-semibold text-gray-900 text-base"><?= e($t['name'] ?? $slug) ?></div>
                  <?php if (!empty($t['blurb'])): ?>
                    <p class="text-sm text-gray-600 mt-1"><?= e($t['blurb']) ?></p>
                  <?php endif; ?>
                </div>
                <span class="text-xs font-semibold <?= $done ? 'text-emerald-700' : 'text-gray-400' ?>">
                  <?= $done ? 'Done' : 'Not yet' ?>
                </span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="mt-10 bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-display text-xl text-gray-900 mb-2">Need a hand from a real person?</h2>
        <p class="text-sm text-gray-600">
          If you're stuck or something doesn't look right,
          email <a href="mailto:webmaster@goldwing.org.au?subject=Help%20with%20the%20AGA%20members%20site" class="text-secondary hover:underline">webmaster@goldwing.org.au</a>
          and we'll help you sort it out.
        </p>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
