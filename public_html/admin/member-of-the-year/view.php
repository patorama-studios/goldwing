<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

require_role(['super_admin', 'admin', 'web_admin']);

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$tableReady = false;
try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'member_of_year_nominations'")->fetch();
} catch (Throwable $e) {
    $tableReady = false;
}

$nomination = null;
$submittedByUser = null;
if ($tableReady && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM member_of_year_nominations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $nomination = $stmt->fetch();

    if ($nomination && !empty($nomination['submitted_by_user_id'])) {
        $userStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => (int) $nomination['submitted_by_user_id']]);
        $submittedByUser = $userStmt->fetch();
    }
}

$flash = $_SESSION['member_of_year_flash'] ?? null;
unset($_SESSION['member_of_year_flash']);

$statusOptions = ['new', 'reviewed', 'shortlisted', 'winner'];

function status_badge_classes(string $status): string
{
    return match ($status) {
        'new' => 'bg-blue-50 text-blue-700',
        'reviewed' => 'bg-amber-50 text-amber-700',
        'shortlisted' => 'bg-indigo-50 text-indigo-700',
        'winner' => 'bg-emerald-50 text-emerald-700',
        default => 'bg-slate-100 text-slate-700',
    };
}

$pageTitle = 'Member of the Year Submission';
$activePage = 'member-of-year';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if (!$tableReady): ?>
        <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
          Member of the Year nominations are not available yet. Run the migration in <code>database/migrations/2025_04_22_member_of_year.sql</code>.
        </div>
      <?php endif; ?>
      <?php if ($flash): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if (!$nomination): ?>
        <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
          <p class="text-sm text-gray-600">Submission not found.</p>
          <a class="inline-flex items-center gap-2 mt-4 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" href="/admin/member-of-the-year">Back to submissions</a>
        </section>
      <?php else: ?>
        <?php require __DIR__ . '/../../../app/Views/admin/member_of_year/MemberOfYearAdminDetail.php'; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
