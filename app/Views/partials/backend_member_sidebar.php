<?php
$items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/member/index.php'],
    ['key' => 'wings', 'label' => 'Wings', 'icon' => 'flight_takeoff', 'href' => '/member/index.php?page=wings'],
    ['key' => 'store', 'label' => 'Store', 'icon' => 'storefront', 'href' => '/store'],
    [
        'key' => 'notices',
        'label' => 'Notice Board',
        'icon' => 'campaign',
        'children' => [
            ['key' => 'notices-view', 'label' => 'View Notices', 'href' => '/member/index.php?page=notices-view'],
            ['key' => 'notices-create', 'label' => 'Create Notice', 'href' => '/member/index.php?page=notices-create'],
        ],
    ],
    ['key' => 'fallen-wings', 'label' => 'Fallen Wings', 'icon' => 'military_tech', 'href' => '/member/index.php?page=fallen-wings'],
    ['key' => 'member-of-the-year', 'label' => 'Member of the Year', 'icon' => 'emoji_events', 'href' => '/members/member-of-the-year'],
    ['key' => 'profile', 'label' => 'Profile', 'icon' => 'person', 'href' => '/member/index.php?page=profile'],
    ['key' => 'settings', 'label' => 'Settings', 'icon' => 'tune', 'href' => '/member/index.php?page=settings'],
    ['key' => 'billing', 'label' => 'Billing', 'icon' => 'receipt_long', 'href' => '/member/index.php?page=billing'],
    ['key' => 'history', 'label' => 'History', 'icon' => 'timeline', 'href' => '/member/index.php?page=history'],
];
$user = $user ?? current_user();
if (function_exists('can_access_path')) {
    foreach ($items as &$item) {
        if (!empty($item['children'])) {
            $item['children'] = array_values(array_filter($item['children'], function ($child) use ($user) {
                return !empty($child['href']) && can_access_path($user, $child['href']);
            }));
        }
    }
    unset($item);
    $items = array_values(array_filter($items, function ($item) use ($user) {
        if (!empty($item['href'])) {
            return can_access_path($user, $item['href']);
        }
        if (!empty($item['children'])) {
            return !empty($item['children']);
        }
        return false;
    }));
}
$activePage = $activePage ?? 'dashboard';
$activeSubPage = $activeSubPage ?? $activePage;
$memberNumber = '';
if (!empty($member)) {
    $memberNumber = App\Services\MembershipService::displayMembershipNumber((int) $member['member_number_base'], (int) $member['member_number_suffix']);
}
?>
<aside class="w-64 flex flex-col bg-card-light border-r border-gray-200 shadow-sm z-40 fixed inset-y-0 left-0 transform -translate-x-full transition-transform duration-200 ease-out md:translate-x-0 md:static md:flex" data-backend-sidebar aria-hidden="true">
  <div class="border-b border-gray-100 px-6 py-5">
    <div class="flex flex-col items-center text-center gap-2">
      <img src="/uploads/library/2023/good-logo-cropped.png" alt="Goldwing logo" class="h-36 w-auto">
      <div>
        <p class="font-display text-lg font-bold text-gray-900">Members Area</p>
        <p class="text-sm text-gray-500">Welcome <?= e($user['name'] ?? 'Member') ?></p>
      </div>
    </div>
  </div>
  <nav class="flex-1 overflow-y-auto py-6 px-4 space-y-1">
    <?php foreach ($items as $item): ?>
      <?php if (!empty($item['children'])): ?>
        <?php $isActive = $activePage === $item['key']; ?>
        <details class="group" <?= $isActive ? 'open' : '' ?>>
          <summary class="flex items-center justify-between gap-3 px-4 py-3 text-sm font-medium rounded-lg cursor-pointer transition-colors <?= $isActive ? 'bg-primary/10 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>">
            <span class="flex items-center gap-3">
              <span class="material-icons-outlined"><?= e($item['icon']) ?></span>
              <?= e($item['label']) ?>
            </span>
            <span class="material-icons-outlined text-base transition-transform group-open:rotate-180">expand_more</span>
          </summary>
          <div class="ml-10 mt-2 space-y-1">
            <?php foreach ($item['children'] as $child): ?>
              <?php $isChildActive = $activeSubPage === $child['key']; ?>
              <a class="flex items-center gap-2 px-3 py-2 text-sm rounded-lg transition-colors <?= $isChildActive ? 'bg-primary/10 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>" href="<?= e($child['href']) ?>">
                <span class="material-icons-outlined text-base">chevron_right</span>
                <?= e($child['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php else: ?>
        <?php $isActive = $activePage === $item['key']; ?>
        <a class="flex items-center gap-3 px-4 py-3 text-sm font-medium rounded-lg transition-colors <?= $isActive ? 'bg-primary/10 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>" href="<?= e($item['href']) ?>">
          <span class="material-icons-outlined"><?= e($item['icon']) ?></span>
          <?= e($item['label']) ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="p-4 border-t border-gray-100">
    <div class="flex items-center gap-3 px-4 py-3">
      <?php if (!empty($avatarUrl)): ?>
        <img src="<?= e($avatarUrl) ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover">
      <?php else: ?>
        <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center text-gray-900 font-bold text-xs">
          <?= e(strtoupper(substr($user['name'] ?? 'M', 0, 2))) ?>
        </div>
      <?php endif; ?>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 truncate"><?= e($user['name'] ?? 'Member') ?></p>
        <p class="text-xs text-gray-500 truncate">ID: <?= e($memberNumber) ?></p>
      </div>
    </div>
    <a class="w-full mt-2 flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors" href="/logout.php">
      <span class="material-icons-outlined text-lg">logout</span>
      Logout
    </a>
  </div>
</aside>
