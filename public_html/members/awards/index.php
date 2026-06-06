<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AwardsService;

require_login();

$user = current_user();
$tablesReady = AwardsService::tablesReady();
$isLive = $tablesReady && AwardsService::isLive();

$years = $tablesReady ? AwardsService::listYears() : [];
$selectedYear = (int) ($_GET['year'] ?? ($years[0] ?? date('Y')));
$rows = ($tablesReady && $isLive) ? AwardsService::categoriesWithWinnersForYear($selectedYear, false) : [];

$grouped = [];
foreach ($rows as $row) {
    $groupKey = $row['group_label'] ?: '_ungrouped';
    $grouped[$groupKey][] = $row;
}

$pageTitle = 'AGM Awards';
$activePage = 'awards';
$activeSubPage = $activePage;
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <?php if (!$isLive): ?>
        <!-- COMING SOON TEASER -->
        <section class="relative overflow-hidden rounded-3xl border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-amber-100 p-8 md:p-12 shadow-sm">
          <div class="absolute -right-12 -top-12 w-64 h-64 rounded-full bg-amber-200/40 blur-3xl"></div>
          <div class="absolute -left-16 -bottom-12 w-72 h-72 rounded-full bg-amber-300/30 blur-3xl"></div>
          <div class="relative max-w-2xl">
            <span class="inline-flex items-center gap-2 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-amber-700">
              <span class="material-icons-outlined text-base">workspace_premium</span>
              Coming Soon
            </span>
            <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900 mt-4">
              The AGA Wall of Awards
            </h1>
            <p class="text-lg text-gray-700 mt-4 leading-relaxed">
              We're building a permanent home for every AGM trophy — past, present, and future.
              Sixteen categories. Decades of winners. The memorial trophies that honour the
              legends who came before us.
            </p>
            <p class="text-base text-gray-600 mt-4 leading-relaxed">
              Soon you'll be able to browse the full history of the
              <strong>Burden Memorial Trophy</strong>, the
              <strong>Harry Ward Memorial</strong>,
              the <strong>Greg O'Loughlin People's Choice</strong> and every other Goldwing trophy ever
              awarded — and see your own achievements on your profile.
            </p>

            <!-- Trophy preview chips -->
            <div class="mt-8">
              <p class="text-xs font-bold uppercase tracking-wider text-amber-700 mb-3">The 16 trophies</p>
              <div class="flex flex-wrap gap-2">
                <?php foreach (['Best Original Classic', 'Best Original GL1500', 'Best Original GL1800', 'Best Original F6B', 'Best Custom Classic', 'Best Custom GL1500', 'Best Custom GL1800', 'Best Custom F6B', 'Best Goldwing & Trailer', 'Best Goldwing Trike', 'Best Goldwing & Sidecar', 'Best non-Goldwing', 'Longest Distance — Over 65', 'Longest Distance', 'Longest Distance Pillion', "People's Choice"] as $chip): ?>
                  <span class="inline-flex items-center gap-1.5 rounded-full bg-white/80 border border-amber-200 px-3 py-1 text-xs font-medium text-gray-700">
                    <span class="material-icons-outlined text-amber-500 text-sm">emoji_events</span>
                    <?= e($chip) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-3">
              <span class="inline-flex items-center gap-2 rounded-full bg-amber-500 px-5 py-2.5 text-sm font-bold text-white shadow">
                <span class="material-icons-outlined text-base">schedule</span>
                Launching soon
              </span>
              <span class="text-sm text-gray-600">We're loading past winners — keep an eye on this space.</span>
            </div>
          </div>
        </section>

        <!-- "What's coming" preview -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="rounded-2xl border border-gray-200 bg-white p-6">
            <span class="material-icons-outlined text-amber-500 text-3xl">view_carousel</span>
            <h3 class="font-display text-lg font-bold text-gray-900 mt-3">Browse by year</h3>
            <p class="text-sm text-gray-600 mt-2">Every AGM since the very first, with photos of the winning bikes and the people who rode them.</p>
          </div>
          <div class="rounded-2xl border border-gray-200 bg-white p-6">
            <span class="material-icons-outlined text-amber-500 text-3xl">history_edu</span>
            <h3 class="font-display text-lg font-bold text-gray-900 mt-3">Trophy hall of fame</h3>
            <p class="text-sm text-gray-600 mt-2">See every winner of the memorial trophies — Burden, Harry Ward, Harry Gates, Shirley Ward, Greg O'Loughlin — across the decades.</p>
          </div>
          <div class="rounded-2xl border border-gray-200 bg-white p-6">
            <span class="material-icons-outlined text-amber-500 text-3xl">military_tech</span>
            <h3 class="font-display text-lg font-bold text-gray-900 mt-3">Your trophy cabinet</h3>
            <p class="text-sm text-gray-600 mt-2">Won an award? Your member profile will show off every trophy you've earned over the years.</p>
          </div>
        </div>

      <?php else: ?>
        <!-- LIVE WALL OF AWARDS -->
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900 flex items-center gap-3">
              <span class="material-icons-outlined text-amber-500 text-4xl">workspace_premium</span>
              AGM Awards
            </h1>
            <p class="text-gray-600 mt-2">Every trophy, every winner — celebrating the riders of the Australian Goldwing Association.</p>
          </div>
          <form method="get" class="flex items-center gap-2">
            <label class="text-sm font-semibold text-gray-700">Year:</label>
            <select name="year" onchange="this.form.submit()" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold">
              <?php foreach ($years as $y): ?>
                <option value="<?= (int) $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= (int) $y ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </header>

        <?php $groupOrder = ['Best Original Goldwing', 'Best Custom Goldwing', '_ungrouped']; ?>
        <?php $rendered = false; foreach ($groupOrder as $groupKey): ?>
          <?php if (empty($grouped[$groupKey])) continue; ?>
          <?php $rendered = true; ?>
          <section>
            <h2 class="font-display text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
              <span class="h-px bg-amber-300 flex-1"></span>
              <span class="px-3 text-amber-700 uppercase text-sm tracking-wider"><?= $groupKey === '_ungrouped' ? 'Individual Trophies' : e($groupKey) ?></span>
              <span class="h-px bg-amber-300 flex-1"></span>
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              <?php foreach ($grouped[$groupKey] as $row): ?>
                <?php
                  $hasWinner = !empty($row['winner_id']);
                  $winnerName = $hasWinner ? AwardsService::displayWinnerName($row) : '';
                ?>
                <article class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                  <?php if ($hasWinner && !empty($row['primary_photo'])): ?>
                    <img src="<?= e($row['primary_photo']) ?>" alt="<?= e($row['name']) ?>" class="w-full h-48 object-cover">
                  <?php else: ?>
                    <div class="w-full h-48 bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                      <span class="material-icons-outlined text-amber-300 text-6xl">emoji_events</span>
                    </div>
                  <?php endif; ?>
                  <div class="p-5">
                    <?php if (!empty($row['memorial_trophy_name'])): ?>
                      <p class="text-xs font-bold uppercase tracking-wider text-amber-700 mb-1"><?= e($row['memorial_trophy_name']) ?></p>
                    <?php endif; ?>
                    <h3 class="font-display font-bold text-gray-900 leading-tight"><?= e($row['name']) ?></h3>
                    <?php if ($hasWinner): ?>
                      <p class="text-sm text-gray-700 mt-3">
                        <span class="text-gray-500">Winner</span><br>
                        <span class="font-semibold text-gray-900"><?= e($winnerName) ?></span>
                      </p>
                      <?php if (!empty($row['bike_description'])): ?>
                        <p class="text-xs text-gray-500 mt-2"><?= e($row['bike_description']) ?></p>
                      <?php endif; ?>
                    <?php else: ?>
                      <p class="text-sm text-gray-400 mt-3 italic">Winner not yet recorded</p>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>

        <?php if (!$rendered): ?>
          <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center">
            <span class="material-icons-outlined text-amber-300 text-5xl">emoji_events</span>
            <p class="text-gray-600 mt-3">No winners recorded yet for <?= (int) $selectedYear ?>.</p>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </main>
</div>
