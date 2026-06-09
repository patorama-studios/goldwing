<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$pdo = db();
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

// Members-only store: every page requires login. Guest checkout is disabled.
require_login();
$user = current_user();
$member = function_exists('current_member_profile') ? current_member_profile() : null;

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

$pageTitles = [
    'catalog'  => 'Store',
    'product'  => 'Product',
    'cart'     => 'Your Cart',
    'checkout' => 'Checkout',
    'orders'   => 'Order History',
    'order'    => 'Order',
];
$pageTitle = $pageTitles[$page] ?? ($settings['store_name'] ?? 'Store');
$activePage = 'store';
$activeSubPage = $page;

require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php require __DIR__ . '/../../app/Views/partials/feedback_widget.php'; ?>
    <?php $topbarTitle = $pageTitle;
    require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <section class="bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
          <div>
            <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900"><?= e($pageTitle) ?></h1>
            <p class="text-gray-500 mt-1">Members-only store for official Australian Goldwing Association gear.</p>
          </div>
          <?php if (in_array($page, ['catalog', 'product'], true)): ?>
            <a href="/store/cart" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary/10 hover:bg-primary/20 text-gray-900 font-semibold text-sm transition-colors">
              <span class="material-icons-outlined">shopping_cart</span>
              View cart
            </a>
          <?php elseif (in_array($page, ['cart', 'checkout'], true)): ?>
            <a href="/store" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm transition-colors">
              <span class="material-icons-outlined">storefront</span>
              Continue shopping
            </a>
          <?php elseif (in_array($page, ['orders', 'order'], true)): ?>
            <a href="/store" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm transition-colors">
              <span class="material-icons-outlined">storefront</span>
              Back to store
            </a>
          <?php endif; ?>
        </div>
      </section>

      <?= $viewContent ?>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
