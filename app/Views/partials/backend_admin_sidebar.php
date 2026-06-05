<?php
$settingsPermissions = [
    'admin.settings.general.manage',
    'admin.store.view',
    'admin.payments.view',
    'admin.users.view',
    'admin.integrations.manage',
    'admin.media_library.manage',
    'admin.events.manage',
    'admin.membership_types.manage',
    'admin.logs.view',
    'admin.roles.view',
    'admin.roles.manage',
];
$pendingRequestsCount = 0;
try {
    if (class_exists(\App\Services\PendingRequestsService::class)) {
        $pendingRequestsCount = (int) (\App\Services\PendingRequestsService::counts()['__total'] ?? 0);
    }
} catch (Throwable $e) {
    $pendingRequestsCount = 0;
}

$tourAttentionCount = 0;
try {
    if (class_exists(\App\Services\TourService::class)) {
        $tourAttentionCount = (int) \App\Services\TourService::attentionCount();
    }
} catch (Throwable $e) {
    $tourAttentionCount = 0;
}

$items = [
    // OVERVIEW
    ['key' => 'dashboard', 'group' => 'Overview', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/admin/index.php', 'permission' => 'admin.dashboard.view'],
    ['key' => 'requests', 'group' => 'Overview', 'label' => 'Notification Hub', 'icon' => 'notifications_active', 'href' => '/admin/requests/', 'permission' => 'admin.requests.view', 'badge' => $pendingRequestsCount],

    // MEMBERSHIP
    ['key' => 'members', 'group' => 'Membership', 'label' => 'Members', 'icon' => 'group', 'href' => '/admin/members/', 'permission' => 'admin.members.view'],
    ['key' => 'applications', 'group' => 'Membership', 'label' => 'Applications', 'icon' => 'fact_check', 'href' => '/admin/index.php?page=applications', 'permission' => 'admin.members.view'],
    ['key' => 'payments', 'group' => 'Membership', 'label' => 'Payments', 'icon' => 'payments', 'href' => '/admin/index.php?page=payments', 'permission' => 'admin.payments.view'],

    // CONTENT
    // 'events' (legacy admin page reading the old `events` table) removed —
    // all event management now happens via the Calendar entry below.
    ['key' => 'calendar-events', 'group' => 'Content', 'label' => 'Calendar', 'icon' => 'calendar_month', 'href' => '/calendar/events.php', 'permission' => 'admin.calendar.view'],
    ['key' => 'navigation', 'group' => 'Content', 'label' => 'Pages and Nav', 'icon' => 'menu', 'href' => '/admin/navigation.php', 'permission' => 'admin.pages.edit'],
    ['key' => 'notices', 'group' => 'Content', 'label' => 'Notices', 'icon' => 'campaign', 'href' => '/admin/index.php?page=notices', 'permission' => 'admin.pages.edit'],
    ['key' => 'wings', 'group' => 'Content', 'label' => 'Wings', 'icon' => 'menu_book', 'href' => '/admin/index.php?page=wings', 'permission' => 'admin.wings_magazine.manage'],
    ['key' => 'media', 'group' => 'Content', 'label' => 'Media', 'icon' => 'photo_library', 'href' => '/admin/index.php?page=media', 'permission' => 'admin.media_library.manage'],

    // RECOGNITION
    ['key' => 'member-of-year', 'group' => 'Recognition', 'label' => 'Member of the Year', 'icon' => 'emoji_events', 'href' => '/admin/member-of-the-year', 'permission' => 'admin.member_of_year.view'],
    ['key' => 'fallen-wings', 'group' => 'Recognition', 'label' => 'Fallen Wings', 'icon' => 'military_tech', 'href' => '/admin/index.php?page=fallen-wings', 'permission' => 'admin.pages.view'],

    // STORE
    [
        'key' => 'store',
        'group' => 'Store',
        'label' => 'Store',
        'icon' => 'storefront',
        'children' => [
            ['key' => 'store-orders', 'label' => 'Orders', 'href' => '/admin/store/orders', 'path' => '/admin/store/orders', 'permission' => 'admin.orders.view'],
            ['key' => 'store-products', 'label' => 'Products (Inventory)', 'href' => '/admin/store/products', 'path' => '/admin/store/products', 'permission' => 'admin.products.manage'],
        ],
    ],

    // SYSTEM
    ['key' => 'settings', 'group' => 'System', 'label' => 'Settings', 'icon' => 'settings', 'href' => '/admin/settings/index.php', 'permission_any' => $settingsPermissions],
    ['key' => 'ai-editor', 'group' => 'System', 'label' => 'AI Page Builder', 'icon' => 'smart_toy', 'href' => '/admin/page-builder', 'permission' => 'admin.ai_page_builder.access'],
    ['key' => 'help-validator', 'group' => 'System', 'label' => 'Tour Validator', 'icon' => 'fact_check', 'href' => '/admin/help/validator.php', 'badge' => $tourAttentionCount],
    ['key' => 'help-docs', 'group' => 'System', 'label' => 'System Docs', 'icon' => 'menu_book', 'href' => '/admin/help/docs/'],
];
$user = $user ?? current_user();
if (function_exists('current_admin_can')) {
    $filteredItems = [];
    foreach ($items as $item) {
        if (!empty($item['children'])) {
            $children = array_values(array_filter($item['children'], function ($child) use ($user) {
                if (!empty($child['permission']) && !current_admin_can($child['permission'], $user)) {
                    return false;
                }
                $path = $child['path'] ?? $child['href'];
                if (function_exists('can_access_path')) {
                    return can_access_path($user, $path);
                }
                return true;
            }));
            if (!$children) {
                continue;
            }
            $item['children'] = $children;
            $filteredItems[] = $item;
            continue;
        }
        if (!empty($item['permission']) && !current_admin_can($item['permission'], $user)) {
            continue;
        }
        if (!empty($item['permission_any'])) {
            $allowed = false;
            foreach ($item['permission_any'] as $perm) {
                if (current_admin_can($perm, $user)) { $allowed = true; break; }
            }
            if (!$allowed) {
                continue;
            }
        }
        $path = $item['path'] ?? $item['href'];
        if (!function_exists('can_access_path') || can_access_path($user, $path)) {
            $filteredItems[] = $item;
        }
    }
    $items = $filteredItems;
}
$activePage = $activePage ?? 'dashboard';
$settingsActiveKeys = ['settings', 'security-log', 'reports', 'audit', 'settings-ai'];
$isSettingsActive = in_array($activePage, $settingsActiveKeys, true);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if (!$isSettingsActive && $currentPath && strpos($currentPath, '/admin/settings/') === 0) {
    $isSettingsActive = true;
}
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
  <?php
    $groups = [];
    foreach ($items as $item) {
      $groups[$item['group'] ?? 'Other'][] = $item;
    }
    $activeGroup = null;
    foreach ($items as $item) {
      $matches = false;
      if (($item['key'] ?? null) === $activePage) {
          $matches = true;
      } elseif ($item['key'] === 'settings' && $isSettingsActive) {
          $matches = true;
      } elseif (!empty($item['children']) && $currentPath) {
          foreach ($item['children'] as $child) {
              if (!empty($child['path']) && strpos($currentPath, $child['path']) === 0) {
                  $matches = true;
                  break;
              }
          }
      }
      if ($matches) {
          $activeGroup = $item['group'] ?? null;
          break;
      }
    }
  ?>
  <nav class="flex-1 overflow-y-auto py-3 px-3 space-y-0.5" data-sidebar-nav="admin">
    <?php $isFirstGroup = true; foreach ($groups as $groupName => $groupItems): ?>
      <?php $isActiveGroup = $groupName === $activeGroup; ?>
      <details class="sidebar-cat" data-sidebar-group="<?= e($groupName) ?>" data-active-group="<?= $isActiveGroup ? '1' : '0' ?>" <?= $isActiveGroup ? 'open' : '' ?>>
        <summary class="flex items-center justify-between cursor-pointer list-none px-3 <?= $isFirstGroup ? 'pt-1' : 'pt-4 mt-2 border-t border-gray-100' ?> pb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 hover:text-gray-700 transition-colors">
          <span><?= e($groupName) ?></span>
          <span class="material-icons-outlined text-sm sidebar-cat-chevron transition-transform">expand_more</span>
        </summary>
        <div class="space-y-0.5 pb-1">
          <?php foreach ($groupItems as $item): ?>
            <?php if (!empty($item['children'])): ?>
              <?php
                $isDropdownActive = false;
                if ($currentPath) {
                    foreach ($item['children'] as $child) {
                        if (!empty($child['path']) && strpos($currentPath, $child['path']) === 0) {
                            $isDropdownActive = true;
                            break;
                        }
                    }
                }
              ?>
              <details class="group rounded-lg <?= $isDropdownActive ? 'bg-primary/5' : '' ?>" <?= $isDropdownActive ? 'open' : '' ?>>
                <summary class="flex items-center gap-3 px-3 py-3 text-base font-medium rounded-lg cursor-pointer list-none transition-colors <?= $isDropdownActive ? 'text-gray-900' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900' ?>">
                  <span class="material-icons-outlined"><?= e($item['icon']) ?></span>
                  <span class="flex-1"><?= e($item['label']) ?></span>
                  <span class="material-icons-outlined text-base transition-transform group-open:rotate-180">expand_more</span>
                </summary>
                <div class="mt-1 space-y-0.5 pl-11 pr-2 pb-2">
                  <?php foreach ($item['children'] as $child): ?>
                    <?php
                      $isChildActive = false;
                      if ($activePage === $child['key']) {
                          $isChildActive = true;
                      }
                      if (!$isChildActive && $currentPath && !empty($child['path']) && strpos($currentPath, $child['path']) === 0) {
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
              <?php
                $isActive = $activePage === $item['key'];
                if ($item['key'] === 'settings' && $isSettingsActive) {
                    $isActive = true;
                }
                $badge = (int) ($item['badge'] ?? 0);
              ?>
              <a class="flex items-center gap-3 px-3 py-3 text-base font-medium rounded-lg transition-colors <?= $isActive ? 'bg-primary/10 text-gray-900' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900' ?>" href="<?= e($item['href']) ?>">
                <span class="material-icons-outlined"><?= e($item['icon']) ?></span>
                <span class="flex-1"><?= e($item['label']) ?></span>
                <?php if ($badge > 0): ?>
                  <span class="inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1.5 rounded-full bg-amber-500 text-white text-xs font-bold"><?= $badge ?></span>
                <?php endif; ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
      <?php $isFirstGroup = false; ?>
    <?php endforeach; ?>
  </nav>
  <style>
    details[data-sidebar-group] > summary::-webkit-details-marker { display: none; }
    details[data-sidebar-group] > summary { list-style: none; }
    details[data-sidebar-group][open] > summary .sidebar-cat-chevron { transform: rotate(180deg); }
  </style>
  <script>
  (function() {
    try {
      var storageKey = 'gw:sidebar:admin:v1';
      var saved = {};
      try { saved = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (e) { saved = {}; }
      var groups = document.querySelectorAll('[data-sidebar-nav="admin"] details[data-sidebar-group]');
      groups.forEach(function(d) {
        var name = d.dataset.sidebarGroup;
        var isActive = d.dataset.activeGroup === '1';
        if (saved[name] === 'open') d.setAttribute('open', '');
        else if (saved[name] === 'closed' && !isActive) d.removeAttribute('open');
        d.addEventListener('toggle', function() {
          saved[name] = d.open ? 'open' : 'closed';
          try { localStorage.setItem(storageKey, JSON.stringify(saved)); } catch (e) {}
        });
      });
    } catch (e) {}
  })();
  </script>
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
