<?php
use App\Services\NavigationService;
use App\Services\SettingsService;

$currentSlug = $_GET['page'] ?? 'home';
$currentSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($currentSlug));
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$user = current_user();
$navItems = NavigationService::getNavigationTree('primary', $user);
$cartCount = 0;
$cartItems = [];
$cartSubtotal = 0.0;
$cart = null;
if ($user) {
    $cart = store_get_open_cart((int) $user['id']);
} elseif (!empty($_SESSION['guest_cart_id'])) {
    $cart = store_get_open_cart(0);
}
if ($cart) {
    $cartItems = store_get_cart_items((int) $cart['id']);
    foreach ($cartItems as $item) {
        $cartCount += (int) $item['quantity'];
        $cartSubtotal += (float) $item['unit_price'] * (int) $item['quantity'];
    }
}
$siteName = SettingsService::getGlobal('site.name', 'Australian Goldwing Association');
$tagline = SettingsService::getGlobal('site.tagline', 'Touring riders and community');
$defaultLogo = '/uploads/library/2024/good-logo-cropped-white-notag.png';
$logoUrl = trim((string) SettingsService::getGlobal('site.logo_url', ''));
if ($logoUrl === '' || preg_match('/^(https?:\\/\\/)?(localhost|127\\.0\\.0\\.1)(:\\d+)?\\//', $logoUrl)) {
    $logoUrl = $defaultLogo;
}
$showNav = SettingsService::getGlobal('site.show_nav', true);

function menu_item_is_active(array $item, string $currentSlug, string $currentPath): bool
{
    if (!empty($item['page_slug']) && $item['page_slug'] === $currentSlug) {
        return true;
    }
    if (!empty($item['url'])) {
        $itemPath = parse_url($item['url'], PHP_URL_PATH) ?? '';
        if ($itemPath && $itemPath === $currentPath) {
            return true;
        }
    }
    foreach ($item['children'] ?? [] as $child) {
        if (menu_item_is_active($child, $currentSlug, $currentPath)) {
            return true;
        }
    }
    return false;
}

function render_nav_items(array $items, string $currentSlug, string $currentPath, int $depth = 0): void
{
    foreach ($items as $item) {
        $hasChildren = !empty($item['children']);
        $isActive = menu_item_is_active($item, $currentSlug, $currentPath);
        $itemId = 'nav-item-' . ($item['id'] ?? uniqid());
        $label = $item['label'] ?? '';
        if ($label === '') {
            continue;
        }
        $isCta = isset($item['url']) && $item['url'] === '/login.php';
        $isLoggedIn = current_user() !== null;
        $resolvedUrl = $item['url'] ?? '';
        $resolvedLabel = $label;
        if ($isCta && $isLoggedIn) {
            $resolvedUrl = '/member/index.php';
            $resolvedLabel = 'Member Area';
        }
        ?>
        <li class="nav-item <?= $hasChildren ? 'has-children' : '' ?> <?= $isActive ? 'is-active' : '' ?>">
          <div class="nav-item__inner">
            <?php if (!empty($resolvedUrl)): ?>
              <a class="nav-link <?= $isCta ? 'nav-link--cta' : '' ?>" role="menuitem" href="<?= e($resolvedUrl) ?>" <?= $isActive ? 'aria-current="page"' : '' ?> <?= !empty($item['open_in_new_tab']) ? 'target="_blank" rel="noopener"' : '' ?>><?= e($resolvedLabel) ?></a>
            <?php else: ?>
              <span class="nav-link nav-link--nolink" role="menuitem"><?= e($resolvedLabel) ?></span>
            <?php endif; ?>
            <?php if ($hasChildren): ?>
              <button class="nav-subtoggle" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="<?= e($itemId) ?>" aria-label="Toggle <?= e($label) ?> submenu">
                <span class="nav-caret" aria-hidden="true"></span>
              </button>
            <?php endif; ?>
          </div>
          <?php if ($hasChildren): ?>
            <ul id="<?= e($itemId) ?>" class="dropdown" role="menu">
              <?php render_nav_items($item['children'], $currentSlug, $currentPath, $depth + 1); ?>
            </ul>
          <?php endif; ?>
        </li>
        <?php
    }
}
?>
<?php if ($showNav): ?>
<nav class="navbar">
  <div class="container nav-shell">
    <a class="brand" href="/">
      <img src="<?= e($logoUrl) ?>"
           srcset="<?= e($logoUrl) ?> 2x"
           alt="<?= e($siteName) ?> logo">
      <span class="brand-text">
        <span class="brand-title"><?= e($siteName) ?></span>
        <span class="brand-subtitle"><?= e($tagline) ?></span>
      </span>
    </a>
    <button class="nav-mobile-toggle" type="button" data-nav-toggle aria-expanded="false" aria-label="Toggle navigation">
      <span></span>
      <span></span>
      <span></span>
    </button>
    <div class="nav-links" data-nav-links>
      <ul class="nav-list" role="menubar">
        <?php render_nav_items($navItems, $currentSlug, $currentPath); ?>
      </ul>
    </div>
    <?php if ($cartCount > 0): ?>
      <button class="nav-cart" type="button" data-cart-toggle aria-label="Open cart" aria-controls="cart-drawer">
        <span class="nav-cart__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 6h15l-2 9H8L6 6Z"></path>
            <path d="M6 6L5 3H2"></path>
            <circle cx="9" cy="20" r="1.6"></circle>
            <circle cx="18" cy="20" r="1.6"></circle>
          </svg>
        </span>
        <span class="nav-cart__count"><?= e((string) $cartCount) ?></span>
      </button>
    <?php endif; ?>
  </div>
</nav>

<?php if ($cartCount > 0): ?>
  <div class="cart-drawer" id="cart-drawer" data-cart-drawer aria-hidden="true">
    <div class="cart-drawer__overlay" data-cart-close></div>
    <aside class="cart-drawer__panel" role="dialog" aria-label="Cart">
      <div class="cart-drawer__header">
        <h3>Your cart</h3>
        <button class="cart-drawer__close" type="button" data-cart-close aria-label="Close cart">×</button>
      </div>
      <div class="cart-drawer__items">
        <?php foreach ($cartItems as $item): ?>
          <div class="cart-drawer__item">
            <div>
              <strong><?= e($item['title_snapshot']) ?></strong>
              <?php if (!empty($item['variant_snapshot'])): ?>
                <div class="cart-drawer__meta"><?= e($item['variant_snapshot']) ?></div>
              <?php endif; ?>
            </div>
            <div class="cart-drawer__qty">x<?= e((string) $item['quantity']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="cart-drawer__footer">
        <div class="cart-drawer__total">
          <span>Subtotal</span>
          <strong>$<?= e(store_money($cartSubtotal)) ?></strong>
        </div>
        <div class="cart-drawer__actions">
          <a class="button" href="/store/cart">View cart</a>
          <a class="button primary" href="/store/checkout">Checkout</a>
        </div>
      </div>
    </aside>
  </div>
<?php endif; ?>
<?php endif; ?>
