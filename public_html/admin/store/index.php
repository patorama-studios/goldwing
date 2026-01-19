<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

store_require_admin();

$pdo = db();
$user = current_user();
$settings = store_get_settings();
$alerts = [];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = trim($path ?? '', '/');
$segments = $path === '' ? [] : explode('/', $path);
if (!empty($segments) && $segments[0] === 'admin') {
    array_shift($segments);
}
if (!empty($segments) && $segments[0] === 'store') {
    array_shift($segments);
}
if (!empty($segments) && $segments[0] === 'index.php') {
    array_shift($segments);
}
$page = $segments[0] ?? 'settings';
$subPage = $segments[1] ?? null;

$validPages = [
    'settings',
    'categories',
    'tags',
    'discounts',
    'products',
    'product',
    'orders',
    'order',
    'low-stock',
];
if (!in_array($page, $validPages, true)) {
    $page = 'settings';
}

if ($page === 'orders' && $subPage) {
    $page = 'order';
}

if (in_array($page, ['orders', 'order'], true)) {
    store_require_permission('store_orders_view');
}
if (in_array($page, ['products', 'product', 'low-stock'], true)) {
    store_require_permission('store_inventory_manage');
}

$pageTitles = [
    'settings' => 'Store Settings',
    'categories' => 'Store Categories',
    'tags' => 'Store Tags',
    'discounts' => 'Store Discounts',
    'products' => 'Store Products',
    'product' => 'Product Editor',
    'orders' => 'Store Orders',
    'order' => 'Order Detail',
    'low-stock' => 'Low Stock Alerts',
];
$pageTitle = $pageTitles[$page] ?? 'Store';
$pageSubtitle = '';

$viewFile = __DIR__ . '/' . $page . '.php';
if ($page === 'product') {
    $viewFile = __DIR__ . '/product_form.php';
}
if ($page === 'order') {
    $viewFile = __DIR__ . '/order_view.php';
}
if (!file_exists($viewFile)) {
    $viewFile = __DIR__ . '/settings.php';
    $page = 'settings';
}

$storeNavItems = [
    ['key' => 'settings', 'label' => 'Settings', 'href' => '/admin/settings/index.php?section=store'],
    ['key' => 'products', 'label' => 'Products', 'href' => '/admin/store/products'],
    ['key' => 'categories', 'label' => 'Categories', 'href' => '/admin/store/categories'],
    ['key' => 'tags', 'label' => 'Tags', 'href' => '/admin/store/tags'],
    ['key' => 'discounts', 'label' => 'Discounts', 'href' => '/admin/store/discounts'],
    ['key' => 'orders', 'label' => 'Orders', 'href' => '/admin/store/orders'],
    ['key' => 'low-stock', 'label' => 'Low Stock', 'href' => '/admin/store/low-stock'],
];
$activeNavKey = $page === 'order' ? 'orders' : $page;

ob_start();
require $viewFile;
$viewContent = ob_get_clean();

$activePage = 'store';

require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 flex flex-col min-w-0 bg-background-light relative">
    <?php $topbarTitle = 'Store'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <header class="bg-card-light border-b border-gray-100">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">
        <div>
          <nav aria-label="Breadcrumb" class="flex text-xs text-gray-500 mb-1">
            <ol class="flex items-center gap-2">
              <li>Admin</li>
              <li class="material-icons-outlined text-sm text-gray-400">chevron_right</li>
              <li class="font-semibold text-gray-900">Store</li>
              <?php if ($page !== 'settings'): ?>
                <li class="material-icons-outlined text-sm text-gray-400">chevron_right</li>
                <li class="font-semibold text-gray-900"><?= e($pageTitles[$page] ?? 'Store') ?></li>
              <?php endif; ?>
            </ol>
          </nav>
          <h1 class="font-display text-2xl text-ink"><?= e($pageTitles[$page] ?? 'Store') ?></h1>
          <?php if ($pageSubtitle): ?>
            <p class="text-sm text-slate-500"><?= e($pageSubtitle) ?></p>
          <?php endif; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <?php foreach ($storeNavItems as $item): ?>
            <?php $isActive = $item['key'] === $activeNavKey; ?>
            <a class="px-3 py-2 rounded-lg text-sm font-medium <?= $isActive ? 'bg-ink text-white' : 'bg-white border border-gray-200 text-gray-600 hover:text-gray-900' ?>" href="<?= e($item['href']) ?>">
              <?= e($item['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </header>

    <div class="flex-1 overflow-y-auto">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        <?php foreach ($alerts as $alert): ?>
          <div class="rounded-lg px-4 py-2 text-sm <?= $alert['type'] === 'error' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-700' ?>">
            <?= e($alert['message']) ?>
          </div>
        <?php endforeach; ?>
        <?= $viewContent ?>
      </div>
    </div>
  </main>
</div>
