<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$pdo = db();
$user = current_user();
$settings = store_get_settings();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = trim($path ?? '', '/');
$segments = $path === '' ? [] : explode('/', $path);
if (!empty($segments) && $segments[0] === 'store') {
    array_shift($segments);
}
if (!empty($segments) && $segments[0] === 'index.php') {
    array_shift($segments);
}

$page = $segments[0] ?? 'catalog';
$subPage = $segments[1] ?? null;

$validPages = ['catalog', 'product', 'cart', 'checkout', 'orders', 'order'];
if (!in_array($page, $validPages, true)) {
    $page = 'catalog';
}

if ($page === 'orders' && $subPage) {
    $page = 'order';
}

$membersOnly = !empty($settings['members_only']);
if ($membersOnly || in_array($page, ['cart', 'checkout', 'orders', 'order'], true)) {
    require_login();
    $user = current_user();
}

$viewFile = __DIR__ . '/' . $page . '.php';
if ($page === 'order') {
    $viewFile = __DIR__ . '/order_view.php';
}
if (!file_exists($viewFile)) {
    $viewFile = __DIR__ . '/catalog.php';
    $page = 'catalog';
}

ob_start();
require $viewFile;
$viewContent = ob_get_clean();

$pageTitle = $pageTitle ?? ($settings['store_name'] ?? 'Store');
$heroTitle = $heroTitle ?? ($settings['store_name'] ?? 'Store');
$heroLead = $heroLead ?? 'Members-only store for official Goldwing Association gear.';

require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact store-hero">
    <div class="container hero__inner">
      <span class="hero__eyebrow">Goldwing Association</span>
      <h1><?= e($heroTitle) ?></h1>
      <p class="hero__lead"><?= e($heroLead) ?></p>
    </div>
  </section>

  <section class="page-section">
    <div class="container">
      <div class="page-card">
        <?= $viewContent ?>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
