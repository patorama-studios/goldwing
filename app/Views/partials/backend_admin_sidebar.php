<?php
$settingsChildren = [
    ['key' => 'settings-hub', 'label' => 'Settings Hub', 'href' => '/admin/settings/index.php', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-site', 'label' => 'Site Settings', 'href' => '/admin/settings/index.php?section=site', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-store', 'label' => 'Store Settings', 'href' => '/admin/settings/index.php?section=store', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-payments', 'label' => 'Payments (Stripe)', 'href' => '/admin/settings/index.php?section=payments', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-notifications', 'label' => 'Notifications', 'href' => '/admin/settings/index.php?section=notifications', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-accounts', 'label' => 'Accounts & Roles', 'href' => '/admin/settings/index.php?section=accounts', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-security', 'label' => 'Security & Authentication', 'href' => '/admin/settings/index.php?section=security', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-integrations', 'label' => 'Integrations', 'href' => '/admin/settings/index.php?section=integrations', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-media', 'label' => 'Media & Files', 'href' => '/admin/settings/index.php?section=media', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-events', 'label' => 'Events', 'href' => '/admin/settings/index.php?section=events', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-membership', 'label' => 'Membership Settings', 'href' => '/admin/settings/index.php?section=membership_pricing', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-audit-log', 'label' => 'Audit Log', 'href' => '/admin/settings/index.php?section=audit', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-advanced', 'label' => 'Advanced / Developer', 'href' => '/admin/settings/index.php?section=advanced', 'path' => '/admin/settings/index.php'],
    ['key' => 'settings-access-control', 'label' => 'Access Control', 'href' => '/admin/settings/access-control.php', 'path' => '/admin/settings/access-control.php'],
    ['key' => 'security-log', 'label' => 'Security Log', 'href' => '/admin/security/activity_log.php', 'path' => '/admin/security/activity_log.php'],
    ['key' => 'reports', 'label' => 'Reports', 'href' => '/admin/index.php?page=reports', 'path' => '/admin/index.php'],
    ['key' => 'audit', 'label' => 'Audit', 'href' => '/admin/index.php?page=audit', 'path' => '/admin/index.php'],
];
$items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/admin/index.php'],
    [
        'key' => 'settings',
        'label' => 'Settings',
        'icon' => 'settings',
        'children' => $settingsChildren,
    ],
    ['key' => 'members', 'label' => 'Members', 'icon' => 'group', 'href' => '/admin/members'],
    ['key' => 'applications', 'label' => 'Applications', 'icon' => 'fact_check', 'href' => '/admin/index.php?page=applications'],
    ['key' => 'payments', 'label' => 'Payments', 'icon' => 'payments', 'href' => '/admin/index.php?page=payments'],
    ['key' => 'events', 'label' => 'Events', 'icon' => 'event', 'href' => '/admin/index.php?page=events'],
    ['key' => 'calendar-events', 'label' => 'Calendar', 'icon' => 'calendar_month', 'href' => '/calendar/events.php'],
    ['key' => 'navigation', 'label' => 'Pages and Nav', 'icon' => 'menu', 'href' => '/admin/navigation.php'],
    ['key' => 'notices', 'label' => 'Notices', 'icon' => 'campaign', 'href' => '/admin/index.php?page=notices'],
    ['key' => 'member-of-year', 'label' => 'Member of the Year Submissions', 'icon' => 'emoji_events', 'href' => '/admin/member-of-the-year'],
    ['key' => 'fallen-wings', 'label' => 'Fallen Wings', 'icon' => 'military_tech', 'href' => '/admin/index.php?page=fallen-wings'],
    ['key' => 'wings', 'label' => 'Wings', 'icon' => 'menu_book', 'href' => '/admin/index.php?page=wings'],
    ['key' => 'media', 'label' => 'Media', 'icon' => 'photo_library', 'href' => '/admin/index.php?page=media'],
    ['key' => 'store', 'label' => 'Store', 'icon' => 'storefront', 'href' => '/admin/store/products'],
    ['key' => 'ai-editor', 'label' => 'AI Editor (comming soon)', 'icon' => 'smart_toy', 'href' => '/admin/index.php?page=ai-editor'],
];
$user = $user ?? current_user();
if (function_exists('can_access_path')) {
    $filteredItems = [];
    foreach ($items as $item) {
        if (!empty($item['children'])) {
            $children = array_values(array_filter($item['children'], function ($child) use ($user) {
                $path = $child['path'] ?? $child['href'];
                return can_access_path($user, $path);
            }));
            if (!$children) {
                continue;
            }
            $item['children'] = $children;
            $filteredItems[] = $item;
            continue;
        }
        $path = $item['path'] ?? $item['href'];
        if (can_access_path($user, $path)) {
            $filteredItems[] = $item;
        }
    }
    $items = $filteredItems;
}
$activePage = $activePage ?? 'dashboard';
$settingsActiveKeys = ['settings', 'security-log', 'reports', 'audit'];
$isSettingsActive = in_array($activePage, $settingsActiveKeys, true);
// Keep the admin sidebar logo consistent with the member area.
$logoUrl = '/uploads/library/2023/good-logo-cropped.png';
?>
<aside class="w-64 flex flex-col bg-card-light border-r border-gray-200 shadow-sm z-40 fixed inset-y-0 left-0 transform -translate-x-full transition-transform duration-200 ease-out md:translate-x-0 md:static md:flex" data-backend-sidebar aria-hidden="true">
  <div class="border-b border-gray-100 px-6 py-5">
    <div class="flex flex-col items-center text-center gap-2">
      <img src="<?= e($logoUrl) ?>" alt="Goldwing logo" class="h-36 w-auto">
      <div>
        <p class="font-display text-lg font-bold text-gray-900">Admin Area</p>
        <p class="text-sm text-gray-500">Welcome <?= e($user['name'] ?? 'Admin') ?></p>
      </div>
    </div>
  </div>
  <nav class="flex-1 overflow-y-auto py-6 px-4 space-y-1">
    <?php foreach ($items as $item): ?>
      <?php if (!empty($item['children'])): ?>
        <?php $isDropdownActive = $item['key'] === 'settings' && $isSettingsActive; ?>
        <details class="group rounded-lg <?= $isDropdownActive ? 'bg-primary/5' : '' ?>" <?= $isDropdownActive ? 'open' : '' ?>>
          <summary class="flex items-center gap-3 px-4 py-3 text-sm font-medium rounded-lg cursor-pointer list-none transition-colors <?= $isDropdownActive ? 'text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>">
            <span class="material-icons-outlined"><?= e($item['icon']) ?></span>
            <span class="flex-1"><?= e($item['label']) ?></span>
            <span class="material-icons-outlined text-base transition-transform group-open:rotate-180">expand_more</span>
          </summary>
          <div class="mt-2 space-y-1 pl-11 pr-2 pb-2">
            <?php foreach ($item['children'] as $child): ?>
              <?php
                $isChildActive = false;
                if ($activePage === 'settings' && isset($section)) {
                    $sectionKey = match ($child['key']) {
                        'settings-site' => 'site',
                        'settings-store' => 'store',
                        'settings-payments' => 'payments',
                        'settings-notifications' => 'notifications',
                        'settings-accounts' => 'accounts',
                        'settings-security' => 'security',
                        'settings-integrations' => 'integrations',
                        'settings-media' => 'media',
                        'settings-events' => 'events',
                        'settings-membership' => 'membership_pricing',
                        'settings-audit-log' => 'audit',
                        'settings-advanced' => 'advanced',
                        default => null,
                    };
                    if ($sectionKey !== null) {
                        $isChildActive = $section === $sectionKey;
                    }
                }
                if ($activePage === 'settings' && $child['key'] === 'settings-hub') {
                    $isChildActive = (!isset($section) || $section === 'site')
                        && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/settings/access-control.php') !== 0;
                }
                if ($activePage === $child['key']) {
                    $isChildActive = true;
                }
                if ($activePage === 'settings' && $child['key'] === 'settings-access-control' && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/settings/access-control.php') === 0) {
                    $isChildActive = true;
                }
              ?>
              <a class="block rounded-lg px-3 py-2 text-sm font-medium transition-colors <?= $isChildActive ? 'bg-primary/10 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>" href="<?= e($child['href']) ?>">
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
      <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center text-gray-900 font-bold text-xs">
        <?= e(strtoupper(substr($user['name'] ?? 'A', 0, 2))) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 truncate"><?= e($user['name'] ?? 'Admin') ?></p>
        <p class="text-xs text-gray-500 truncate"><?= e($user['email'] ?? '') ?></p>
      </div>
    </div>
    <a class="w-full mt-2 flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors" href="/logout.php">
      <span class="material-icons-outlined text-lg">logout</span>
      Logout
    </a>
  </div>
</aside>
