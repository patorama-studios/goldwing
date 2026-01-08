<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$pageTitle = 'Access Restricted';
$lockout = $_SESSION['locked_out'] ?? [];
unset($_SESSION['locked_out']);

$user = current_user();
$roles = $user['roles'] ?? [];
$role = $lockout['role'] ?? get_current_role();
$path = $lockout['path'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$pageLabel = $lockout['page_label'] ?? null;

$dashboardUrl = '/member/index.php';
if ($user && in_array('admin', $roles, true)) {
    $dashboardUrl = '/admin/index.php';
}

require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="section">
  <div class="container">
    <div class="page-card" style="max-width: 680px; margin: 0 auto;">
      <h1>Access Restricted</h1>
      <p>You don't have access to this page. Please contact a Web Admin to request access.</p>
      <?php if ($pageLabel): ?>
        <p class="text-sm text-gray-600">Page: <?= e($pageLabel) ?></p>
      <?php endif; ?>
      <p class="text-sm text-gray-600">Your role: <?= e($role) ?></p>
      <p class="text-sm text-gray-600">Requested page: <?= e($path) ?></p>
      <div class="mt-4" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <?php if ($user): ?>
          <a class="button primary" href="<?= e($dashboardUrl) ?>">Back to Dashboard</a>
        <?php else: ?>
          <a class="button primary" href="/">Go Home</a>
          <a class="button" href="/login.php">Log In</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
</body>
</html>
