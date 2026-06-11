<?php
require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /member/index.php?page=fallen-wings');
    exit;
}

$pdo = db();
$entry = null;

try {
    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'fallen_wings'")->fetch();
    if ($tableExists) {
        $stmt = $pdo->prepare('SELECT * FROM fallen_wings WHERE id = :id AND status = "APPROVED" LIMIT 1');
        $stmt->execute([':id' => $id]);
        $entry = $stmt->fetch();
    }
} catch (\Throwable $e) {
    $entry = null;
}

if (!$entry) {
    http_response_code(404);
    $entry = null;
}

if ($entry) {
    $parts = explode(' ', trim((string) ($entry['full_name'] ?? '')));
    if (count($parts) > 1) {
        $last = array_pop($parts);
        $formattedName = $last . ', ' . implode(' ', $parts);
    } else {
        $formattedName = $entry['full_name'] ?? '';
    }
} else {
    $formattedName = '';
}

$pageTitle = $entry ? ($entry['full_name'] . ' — Fallen Wings') : 'Fallen Wings';
$activePage = 'fallen-wings';
$activeSubPage = $activePage;
require __DIR__ . '/../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Fallen Wings'; require __DIR__ . '/../app/Views/partials/backend_mobile_topbar.php'; ?>
    <?php $lockdownPageKey = 'fallen-wings'; require __DIR__ . '/../app/Views/partials/member_lockdown.php'; ?>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div>
        <a href="/member/index.php?page=fallen-wings"
          class="inline-flex items-center gap-1 text-sm font-semibold text-gray-600 hover:text-gray-900">
          <span class="material-icons-outlined text-base">arrow_back</span>
          Back to Memorial Roll
        </a>
      </div>

      <?php if (!$entry): ?>
        <div class="bg-card-light rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
          <span class="material-icons-outlined text-gray-300 text-5xl">help_outline</span>
          <h1 class="font-display text-2xl font-bold text-gray-900 mt-3">Memorial entry not found</h1>
          <p class="text-sm text-gray-500 mt-2">This tribute may have been removed or is no longer available.</p>
        </div>
      <?php else: ?>
        <article class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
          <?php if (!empty($entry['image_url'])): ?>
            <div class="bg-gray-50 flex items-center justify-center p-6">
              <img src="<?= e($entry['image_url']) ?>" alt="Tribute image for <?= e($formattedName) ?>"
                class="max-w-full max-h-[480px] object-contain rounded-lg shadow-sm">
            </div>
          <?php endif; ?>

          <div class="p-6 md:p-8 space-y-5">
            <header class="flex items-start gap-3">
              <div class="p-2 bg-slate-100 rounded-lg text-slate-600 flex-shrink-0">
                <span class="material-icons-outlined">military_tech</span>
              </div>
              <div class="min-w-0">
                <h1 class="font-display text-3xl font-bold text-gray-900 leading-tight">
                  <?= e($entry['full_name']) ?>
                </h1>
                <?php if (!empty($entry['year_of_passing'])): ?>
                  <p class="text-sm text-gray-500 mt-1">In memoriam — <?= e((string) $entry['year_of_passing']) ?></p>
                <?php endif; ?>
                <?php if (!empty($entry['member_number'])): ?>
                  <p class="text-xs text-gray-500 mt-0.5">Member #: <?= e($entry['member_number']) ?></p>
                <?php endif; ?>
              </div>
            </header>

            <?php if (!empty($entry['tribute'])): ?>
              <div class="prose prose-sm max-w-none text-gray-700 whitespace-pre-line">
                <?= nl2br(e($entry['tribute'])) ?>
              </div>
            <?php else: ?>
              <p class="text-sm text-gray-500 italic">No tribute text on file.</p>
            <?php endif; ?>

            <?php if (!empty($entry['pdf_url'])): ?>
              <div class="pt-2">
                <a href="<?= e($entry['pdf_url']) ?>" target="_blank"
                  class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800">
                  <span class="material-icons-outlined text-base">picture_as_pdf</span>
                  Download PDF Tribute
                </a>
              </div>
            <?php endif; ?>
          </div>
        </article>
      <?php endif; ?>

    </div>
  </main>
</div>
