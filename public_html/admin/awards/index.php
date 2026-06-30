<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AwardsService;
use App\Services\Csrf;

require_permission('admin.awards.view');

$user = current_user();
$canManage = function_exists('current_admin_can') && current_admin_can('admin.awards.manage', $user);

$tablesReady = AwardsService::tablesReady();

$currentYear = (int) date('Y');
$years = $tablesReady ? AwardsService::listYears() : [$currentYear];

$selectedYear = (int) ($_GET['year'] ?? $currentYear);
if ($selectedYear < 1970 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}

$rows = $tablesReady ? AwardsService::categoriesWithWinnersForYear($selectedYear, true) : [];
$featureStatus = AwardsService::getFeatureStatus();

$flash = $_SESSION['awards_flash'] ?? null;
unset($_SESSION['awards_flash']);

// Group rows by group_label for display.
$grouped = [];
foreach ($rows as $row) {
    $groupKey = $row['group_label'] ?: '_ungrouped';
    $grouped[$groupKey][] = $row;
}

// Stats summary for the top of the page.
$totalCategories = count($rows);
$totalWinnersRecorded = 0;
foreach ($rows as $row) {
    if (!empty($row['winner_id'])) {
        $totalWinnersRecorded++;
    }
}

$pageTitle = 'AGM Awards';
$activePage = 'awards';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <?php if (!$tablesReady): ?>
        <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
          The awards tables aren't ready yet. Run the migration runner at
          <a href="/admin/run-migration.php" class="underline">/admin/run-migration.php</a>
          to create them.
        </div>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <!-- Header row -->
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4" data-tour="awards-header">
        <div>
          <h1 class="font-display text-3xl font-bold text-gray-900">AGM Awards</h1>
          <p class="text-sm text-gray-500 mt-1">Manage trophy winners across the years and control when members see the awards page.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="/admin/awards/categories.php" data-tour="awards-manage-trophies-btn" class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            <span class="material-icons-outlined text-base">category</span>
            Manage Trophies
          </a>
          <?php if ($canManage): ?>
            <a href="/admin/awards/edit.php?year=<?= (int) $selectedYear ?>" data-tour="awards-add-winner-btn" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-primary/90">
              <span class="material-icons-outlined text-base">add</span>
              Add Winner
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Feature toggle card -->
      <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm" data-tour="awards-feature-toggle">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <h2 class="font-display text-lg font-bold text-gray-900 flex items-center gap-2">
              <span class="material-icons-outlined text-amber-500">visibility</span>
              Member-Facing Awards Page
            </h2>
            <?php if ($featureStatus === AwardsService::STATUS_LIVE): ?>
              <p class="text-sm text-emerald-700 mt-1">
                <strong>LIVE</strong> — Members see the full Wall of Awards at
                <a href="/members/awards" class="underline" target="_blank">/members/awards</a>.
              </p>
            <?php else: ?>
              <p class="text-sm text-amber-700 mt-1">
                <strong>COMING SOON</strong> — Members see a teaser page at
                <a href="/members/awards" class="underline" target="_blank">/members/awards</a>.
                The menu item shows up to build excitement.
              </p>
            <?php endif; ?>
          </div>
          <?php if ($canManage): ?>
            <form method="post" action="/admin/awards/actions.php" class="flex items-center gap-3">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="set_feature_status">
              <input type="hidden" name="redirect_after" value="/admin/awards/?year=<?= (int) $selectedYear ?>">
              <?php if ($featureStatus === AwardsService::STATUS_LIVE): ?>
                <input type="hidden" name="feature_status" value="<?= e(AwardsService::STATUS_COMING_SOON) ?>">
                <button class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100" type="submit">
                  <span class="material-icons-outlined text-base">pause_circle</span>
                  Switch to Coming Soon
                </button>
              <?php else: ?>
                <input type="hidden" name="feature_status" value="<?= e(AwardsService::STATUS_LIVE) ?>">
                <button class="inline-flex items-center gap-2 rounded-full bg-emerald-500 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600" type="submit">
                  <span class="material-icons-outlined text-base">rocket_launch</span>
                  Go Live
                </button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <!-- Year selector + summary -->
      <section class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm" data-tour="awards-year-selector">
        <form method="get" class="flex flex-wrap items-center gap-3">
          <label class="text-sm font-semibold text-gray-700">AGM Year:</label>
          <select name="year" onchange="this.form.submit()" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int) $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
            <?php for ($y = max($years) + 1; $y <= $currentYear + 1; $y++): ?>
              <option value="<?= (int) $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endfor; ?>
          </select>
          <span class="text-sm text-gray-500">
            <?= $totalWinnersRecorded ?> / <?= $totalCategories ?> trophies have winners recorded for <?= (int) $selectedYear ?>.
          </span>
        </form>
      </section>

      <!-- Categories grouped -->
      <?php $groupOrder = [
        'Best Original Goldwing',
        'Best Custom Goldwing',
        '_ungrouped',
      ]; ?>
      <?php foreach ($groupOrder as $groupKey): ?>
        <?php if (empty($grouped[$groupKey])) continue; ?>
        <?php $groupItems = $grouped[$groupKey]; ?>
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" data-tour="awards-trophy-group">
          <header class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
            <span class="material-icons-outlined text-amber-500">emoji_events</span>
            <h3 class="font-display text-lg font-bold text-gray-900">
              <?= $groupKey === '_ungrouped' ? 'Individual Trophies' : e($groupKey) ?>
            </h3>
          </header>
          <div class="divide-y divide-gray-100">
            <?php foreach ($groupItems as $row): ?>
              <?php
                $hasWinner = !empty($row['winner_id']);
                $winnerName = $hasWinner ? AwardsService::displayWinnerName($row) : '';
              ?>
              <div class="px-6 py-4 flex flex-col md:flex-row md:items-center gap-4">
                <?php if ($hasWinner && !empty($row['primary_photo'])): ?>
                  <img src="<?= e($row['primary_photo']) ?>" alt="" class="w-20 h-20 rounded-lg object-cover border border-gray-200">
                <?php else: ?>
                  <div class="w-20 h-20 rounded-lg bg-gray-50 border border-dashed border-gray-200 flex items-center justify-center text-gray-300">
                    <span class="material-icons-outlined">photo_camera</span>
                  </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                  <p class="font-semibold text-gray-900"><?= e($row['name']) ?></p>
                  <?php if (!empty($row['memorial_trophy_name'])): ?>
                    <p class="text-xs uppercase tracking-wider text-amber-700 font-semibold mt-0.5"><?= e($row['memorial_trophy_name']) ?></p>
                  <?php endif; ?>
                  <?php if ($hasWinner): ?>
                    <p class="text-sm text-gray-700 mt-1">
                      Winner: <strong><?= e($winnerName) ?></strong>
                      <?php if (!empty($row['bike_description'])): ?>
                        <span class="text-gray-500"> · <?= e($row['bike_description']) ?></span>
                      <?php endif; ?>
                    </p>
                  <?php else: ?>
                    <p class="text-sm text-gray-400 mt-1 italic">No winner recorded for <?= (int) $selectedYear ?>.</p>
                  <?php endif; ?>
                </div>
                <?php if ($canManage): ?>
                  <div class="flex items-center gap-2">
                    <?php if ($hasWinner): ?>
                      <a href="/admin/awards/edit.php?id=<?= (int) $row['winner_id'] ?>" class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <span class="material-icons-outlined text-base">edit</span> Edit
                      </a>
                    <?php else: ?>
                      <a href="/admin/awards/edit.php?category_id=<?= (int) $row['id'] ?>&year=<?= (int) $selectedYear ?>" class="inline-flex items-center gap-1 rounded-full bg-primary px-3 py-1.5 text-sm font-semibold text-gray-900 hover:bg-primary/90">
                        <span class="material-icons-outlined text-base">add</span> Add Winner
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php if ($tablesReady && !$rows): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-600">
          No award categories found. Visit
          <a href="/admin/awards/categories.php" class="underline">Manage Trophies</a>
          to add the trophy list.
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
