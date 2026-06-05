<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\TourService;

require_login();
$user = current_user();
$completions = TourService::completionsFor((int) $user['id']);
$tours = TourService::toursFor($user);

// Group by audience
$grouped = ['member' => [], 'admin' => []];
foreach ($tours as $slug => $t) {
    $grouped[$t['audience'] ?? 'member'][$slug] = $t;
}

$pageTitle = 'Help &amp; Walkthroughs';
$activePage = 'help-guides';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php
    $isStaff = in_array('admin', $user['roles'] ?? [], true)
            || in_array('webmaster', $user['roles'] ?? [], true)
            || in_array('store_manager', $user['roles'] ?? [], true)
            || in_array('area_rep', $user['roles'] ?? [], true);
    if ($isStaff) {
        include __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php';
    }
  ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-4xl mx-auto">
      <header class="mb-8">
        <h1 class="font-display text-3xl text-gray-900">Help &amp; Walkthroughs</h1>
        <p class="mt-1 text-gray-600">Pick a topic to walk through it step-by-step. You can come back any time — just click the gold <strong>?</strong> in the corner.</p>
      </header>

      <?php foreach (['member' => 'For members', 'admin' => 'For administrators'] as $aud => $title): ?>
        <?php if (!$grouped[$aud]) continue; ?>
        <section class="mb-10">
          <h2 class="font-display text-xl text-gray-900 mb-4"><?= e($title) ?></h2>
          <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($grouped[$aud] as $slug => $t): ?>
              <?php $done = !empty($completions[$slug]); ?>
              <a href="<?= e($t['page_url'] ?? '#') ?>"
                 class="block bg-white rounded-2xl border border-gray-200 shadow-sm hover:border-primary hover:shadow-md transition p-5">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-primary text-2xl">help_outline</span>
                  <div class="flex-1">
                    <div class="font-semibold text-gray-900 text-base"><?= e($t['name']) ?></div>
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
        </section>
      <?php endforeach; ?>

      <?php if (empty($grouped['member']) && empty($grouped['admin'])): ?>
        <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center text-gray-500">
          No walkthroughs available yet.
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
