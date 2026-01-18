<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Csrf;

require_role(['admin']);

$pdo = db();
$user = current_user();
$pages = $pdo->query('SELECT id, title, slug, visibility FROM pages ORDER BY title ASC')->fetchAll();

$pageTitle = 'Pages and Nav';
$activePage = 'navigation';

require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 flex flex-col min-w-0 bg-background-light relative">
    <?php $topbarTitle = 'Pages and Nav'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <header class="bg-card-light border-b border-gray-100">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">
        <div>
          <nav aria-label="Breadcrumb" class="flex text-xs text-gray-500 mb-1">
            <ol class="flex items-center gap-2">
              <li>Admin</li>
              <li class="material-icons-outlined text-sm text-gray-400">chevron_right</li>
              <li class="font-semibold text-gray-900">Menus</li>
            </ol>
          </nav>
          <h1 class="font-display text-2xl text-ink">Pages and Nav</h1>
          <p class="text-sm text-slate-500">Build dropdown menus and assign them to site locations.</p>
        </div>
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          <div class="flex flex-wrap items-center gap-3">
            <select id="menu-select" class="min-w-[220px] rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm"></select>
            <button id="menu-create-toggle" class="inline-flex items-center gap-2 rounded-lg bg-ink text-white px-4 py-2 text-sm font-medium shadow-soft">
              <span class="material-icons-outlined text-base">add</span>
              Create Menu
            </button>
          </div>
          <div class="flex items-center gap-2">
            <button id="menu-delete" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600">Delete Menu</button>
            <button id="menu-save" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink inline-flex items-center gap-2">
              <span class="material-icons-outlined text-base">save</span>
              Save Menu
            </button>
          </div>
        </div>
        <div id="menu-alert" class="hidden rounded-lg px-4 py-2 text-sm"></div>
        <div id="menu-create-panel" class="hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="grid gap-3 md:grid-cols-[2fr_1fr]">
            <div>
              <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Menu Name</label>
              <input id="menu-create-name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Primary menu">
            </div>
            <div>
              <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Assign Location</label>
              <select id="menu-create-location" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"></select>
            </div>
          </div>
          <div class="mt-3 flex justify-end gap-2">
            <button id="menu-create-cancel" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-slate-600">Cancel</button>
            <button id="menu-create-submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Create</button>
          </div>
        </div>
      </div>
    </header>

    <div class="flex-1 overflow-y-auto">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid gap-6 lg:grid-cols-[2.2fr_1fr]">
          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">Menu Structure</h2>
                <p class="text-sm text-slate-500">Drag items to reorder. Use icons to manage nesting and settings.</p>
              </div>
            </div>
            <div id="menu-items" class="space-y-3 max-w-4xl"></div>
            <p id="menu-empty" class="text-sm text-slate-500">Create a menu to begin.</p>
          </section>

          <aside class="space-y-6">
            <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-3">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Menu Details</h3>
              <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Menu Name</label>
              <input id="menu-name" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Menu name">
              <button id="menu-rename" class="w-full rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white">Save name</button>
            </section>

            <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Menu Locations</h3>
              <div id="menu-locations" class="space-y-3"></div>
            </section>

            <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Add Pages</h3>
              <input id="page-search" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Search pages">
              <div id="page-list" class="max-h-60 overflow-y-auto space-y-2 pr-1"></div>
              <button id="page-add" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Add selected pages</button>
            </section>

            <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Add Custom Link</h3>
              <input id="custom-label" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Label">
              <input id="custom-url" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="https://">
              <label class="inline-flex items-center gap-2 text-xs text-slate-500">
                <input id="custom-new-tab" type="checkbox" class="rounded border-gray-200 text-secondary">
                Open in new tab
              </label>
              <button id="custom-add" class="w-full rounded-lg bg-secondary px-4 py-2 text-sm font-semibold text-white">Add link</button>
            </section>
          </aside>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
  window.NavAdminData = {
    csrfToken: <?= json_encode(Csrf::token()) ?>,
    pages: <?= json_encode($pages) ?>
  };
</script>
<script>
  (() => {
    const state = {
      menus: [],
      locations: [],
      items: [],
      currentMenuId: null,
      pages: window.NavAdminData.pages || [],
      csrfToken: window.NavAdminData.csrfToken || ''
    };

    const menuSelect = document.getElementById('menu-select');
    const menuNameInput = document.getElementById('menu-name');
    const menuRenameButton = document.getElementById('menu-rename');
    const menuSaveButton = document.getElementById('menu-save');
    const menuDeleteButton = document.getElementById('menu-delete');
    const menuItemsContainer = document.getElementById('menu-items');
    const menuEmpty = document.getElementById('menu-empty');
    const menuAlert = document.getElementById('menu-alert');
    const menuCreateToggle = document.getElementById('menu-create-toggle');
    const menuCreatePanel = document.getElementById('menu-create-panel');
    const menuCreateName = document.getElementById('menu-create-name');
    const menuCreateLocation = document.getElementById('menu-create-location');
    const menuCreateSubmit = document.getElementById('menu-create-submit');
    const menuCreateCancel = document.getElementById('menu-create-cancel');
    const locationContainer = document.getElementById('menu-locations');
    const pageSearch = document.getElementById('page-search');
    const pageList = document.getElementById('page-list');
    const pageAdd = document.getElementById('page-add');
    const customLabel = document.getElementById('custom-label');
    const customUrl = document.getElementById('custom-url');
    const customNewTab = document.getElementById('custom-new-tab');
    const customAdd = document.getElementById('custom-add');
    let dragItemId = null;

    const apiBase = '/api/index.php';
    const apiRequest = async (path, options = {}) => {
      const opts = { ...options };
      opts.headers = opts.headers || {};
      opts.headers['Content-Type'] = 'application/json';
      if (opts.method && opts.method !== 'GET') {
        opts.headers['X-CSRF-Token'] = state.csrfToken;
      }
      const normalizedPath = path.startsWith('/api/') ? path.slice(4) : path;
      const res = await fetch(`${apiBase}${normalizedPath}`, opts);
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || 'Request failed');
      }
      return data;
    };

    const showAlert = (message, type = 'success') => {
      menuAlert.textContent = message;
      menuAlert.className = `rounded-lg px-4 py-2 text-sm ${type === 'error' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-700'}`;
      menuAlert.classList.remove('hidden');
      setTimeout(() => menuAlert.classList.add('hidden'), 3000);
    };

    const loadMenus = async () => {
      const data = await apiRequest('/api/menus');
      state.menus = data.menus || [];
      state.locations = data.locations || [];
      if (!state.currentMenuId && state.menus.length) {
        state.currentMenuId = state.menus[0].id;
      }
      renderMenuSelect();
      renderLocationAssignments();
      renderCreateLocationOptions();
      renderMenuDetails();
      if (state.currentMenuId) {
        await loadMenuItems(state.currentMenuId);
      } else {
        state.items = [];
        renderMenuItems();
      }
    };

    const loadMenuItems = async (menuId) => {
      if (!menuId) {
        return;
      }
      const data = await apiRequest(`/api/menus/${menuId}/items`);
      state.items = data.items || [];
      renderMenuItems();
    };

    const renderMenuSelect = () => {
      menuSelect.innerHTML = '';
      if (!state.menus.length) {
        const option = document.createElement('option');
        option.textContent = 'No menus yet';
        menuSelect.appendChild(option);
        menuSelect.disabled = true;
        return;
      }
      menuSelect.disabled = false;
      state.menus.forEach(menu => {
        const option = document.createElement('option');
        option.value = menu.id;
        option.textContent = menu.name;
        if (menu.id === state.currentMenuId) {
          option.selected = true;
        }
        menuSelect.appendChild(option);
      });
    };

    const renderMenuDetails = () => {
      const menu = state.menus.find(item => item.id === state.currentMenuId);
      menuNameInput.value = menu ? menu.name : '';
    };

    const renderCreateLocationOptions = () => {
      menuCreateLocation.innerHTML = '';
      const defaultOption = document.createElement('option');
      defaultOption.value = '';
      defaultOption.textContent = 'Unassigned';
      menuCreateLocation.appendChild(defaultOption);
      state.locations.forEach(location => {
        const option = document.createElement('option');
        option.value = location.location_key;
        option.textContent = location.location_key;
        menuCreateLocation.appendChild(option);
      });
    };

    const renderLocationAssignments = () => {
      locationContainer.innerHTML = '';
      state.locations.forEach(location => {
        const wrapper = document.createElement('div');
        wrapper.className = 'space-y-1';
        const label = document.createElement('label');
        label.className = 'text-xs uppercase tracking-[0.2em] text-slate-500';
        label.textContent = location.location_key;
        const select = document.createElement('select');
        select.className = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm';
        const unassigned = document.createElement('option');
        unassigned.value = '';
        unassigned.textContent = 'Unassigned';
        select.appendChild(unassigned);
        state.menus.forEach(menu => {
          const option = document.createElement('option');
          option.value = menu.id;
          option.textContent = menu.name;
          if (menu.id === location.menu_id) {
            option.selected = true;
          }
          select.appendChild(option);
        });
        select.addEventListener('change', async () => {
          try {
            await apiRequest('/api/menu-locations', {
              method: 'PUT',
              body: JSON.stringify({
                location_key: location.location_key,
                menu_id: select.value || null
              })
            });
            showAlert('Location updated.');
            await loadMenus();
          } catch (err) {
            showAlert(err.message, 'error');
          }
        });
        wrapper.appendChild(label);
        wrapper.appendChild(select);
        locationContainer.appendChild(wrapper);
      });
    };

    const createItemBadge = (text, tone) => {
      const badge = document.createElement('span');
      const toneClass = tone === 'warn'
        ? 'bg-amber-50 text-amber-700'
        : tone === 'error'
          ? 'bg-red-50 text-red-600'
          : 'bg-gray-100 text-gray-600';
      badge.className = `px-2 py-1 text-[11px] rounded-full ${toneClass}`;
      badge.textContent = text;
      return badge;
    };

    const renderMenuItems = () => {
      menuItemsContainer.innerHTML = '';
      if (!state.currentMenuId) {
        menuEmpty.textContent = 'Create a menu to begin.';
        menuEmpty.classList.remove('hidden');
        return;
      }
      if (!state.items.length) {
        menuEmpty.textContent = 'No items yet.';
        menuEmpty.classList.remove('hidden');
        return;
      }
      menuEmpty.classList.add('hidden');

      const renderItem = (item, level) => {
        const card = document.createElement('div');
        card.className = 'group bg-white border border-gray-100 rounded-xl p-3 shadow-sm hover:shadow-md transition-all';
        card.style.marginLeft = `${Math.min(level, 5) * 22}px`;

        const row = document.createElement('div');
        row.className = 'flex flex-wrap items-center gap-3';

        const drag = document.createElement('span');
        drag.className = 'material-icons-outlined text-gray-400 cursor-grab';
        drag.textContent = 'drag_indicator';
        drag.setAttribute('draggable', 'true');

        const iconWrap = document.createElement('div');
        const iconTone = item.page_id ? 'bg-blue-50 text-blue-600' : 'bg-gray-100 text-gray-500';
        iconWrap.className = `p-2 rounded-lg ${iconTone}`;
        const icon = document.createElement('span');
        icon.className = 'material-icons-outlined text-base';
        icon.textContent = item.page_id ? 'article' : 'link';
        iconWrap.appendChild(icon);

        const textWrap = document.createElement('div');
        textWrap.className = 'flex-1 min-w-[200px]';
        const labelInput = document.createElement('input');
        labelInput.className = 'w-full bg-transparent border-0 border-b border-transparent p-0 text-sm font-semibold text-gray-900 focus:border-primary focus:ring-0';
        labelInput.value = item.label || '';
        if (item.use_page_title) {
          labelInput.disabled = true;
        }
        labelInput.addEventListener('input', (event) => {
          item.label = event.target.value;
        });
        const meta = document.createElement('div');
        meta.className = 'mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500';
        if (item.page_id) {
          meta.appendChild(createItemBadge(`Page: ${item.page_title || 'Missing'}`, item.page_title ? 'neutral' : 'error'));
          if (item.page_slug) {
            meta.appendChild(createItemBadge(`/${item.page_slug === 'home' ? '' : '?page=' + item.page_slug}`, 'neutral'));
          }
        } else if (item.custom_url) {
          meta.appendChild(createItemBadge(`Link: ${item.custom_url}`, 'neutral'));
        }
        if (item.status === 'Restricted') {
          meta.appendChild(createItemBadge('Restricted', 'warn'));
        } else if (item.status === 'Missing') {
          meta.appendChild(createItemBadge('Missing page', 'error'));
        }
        textWrap.appendChild(labelInput);
        textWrap.appendChild(meta);

        const actions = document.createElement('div');
        actions.className = 'flex items-center gap-1 opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity ml-auto';

        const makeIconButton = (iconName, title, handler, tone = 'neutral') => {
          const button = document.createElement('button');
          button.type = 'button';
          const toneClass = tone === 'danger'
            ? 'text-red-500 hover:bg-red-50'
            : tone === 'accent'
              ? 'text-secondary hover:bg-emerald-50'
              : 'text-gray-500 hover:bg-gray-100';
          button.className = `p-2 rounded-lg ${toneClass}`;
          button.title = title;
          button.addEventListener('click', handler);
          const icon = document.createElement('span');
          icon.className = 'material-icons-outlined text-base';
          icon.textContent = iconName;
          button.appendChild(icon);
          return button;
        };

        if (item.page_id) {
          actions.appendChild(makeIconButton('smart_toy', 'Open AI builder', () => {
            window.location.href = `/admin/page-builder?page_id=${item.page_id}`;
          }, 'accent'));
        }
        actions.appendChild(makeIconButton('arrow_upward', 'Move up', () => moveItem(item.id, -1)));
        actions.appendChild(makeIconButton('arrow_downward', 'Move down', () => moveItem(item.id, 1)));
        actions.appendChild(makeIconButton('subdirectory_arrow_right', 'Indent', () => indentItem(item.id)));
        actions.appendChild(makeIconButton('subdirectory_arrow_left', 'Outdent', () => outdentItem(item.id)));
        actions.appendChild(makeIconButton('delete_outline', 'Remove', () => removeItem(item.id), 'danger'));

        const toggles = document.createElement('div');
        toggles.className = 'mt-3 flex flex-wrap items-center gap-4 text-xs text-slate-500';
        const autoLabel = document.createElement('label');
        autoLabel.className = 'inline-flex items-center gap-2';
        const autoInput = document.createElement('input');
        autoInput.type = 'checkbox';
        autoInput.className = 'rounded border-gray-200 text-secondary';
        autoInput.checked = !!item.use_page_title;
        autoInput.addEventListener('change', () => {
          item.use_page_title = autoInput.checked;
          if (item.use_page_title && item.page_title) {
            item.label = item.page_title;
          }
          renderMenuItems();
        });
        autoLabel.appendChild(autoInput);
        autoLabel.appendChild(document.createTextNode('Auto label from page'));

        const newTab = document.createElement('label');
        newTab.className = 'inline-flex items-center gap-2';
        const newTabInput = document.createElement('input');
        newTabInput.type = 'checkbox';
        newTabInput.className = 'rounded border-gray-200 text-secondary';
        newTabInput.checked = !!item.open_in_new_tab;
        newTabInput.addEventListener('change', () => {
          item.open_in_new_tab = newTabInput.checked;
        });
        newTab.appendChild(newTabInput);
        newTab.appendChild(document.createTextNode('Open in new tab'));

        toggles.appendChild(autoLabel);
        toggles.appendChild(newTab);

        row.appendChild(drag);
        row.appendChild(iconWrap);
        row.appendChild(textWrap);
        row.appendChild(actions);

        card.appendChild(row);
        card.appendChild(toggles);

        const clearDropIndicator = (target) => {
          target.style.outline = '';
          target.style.outlineOffset = '';
          target.style.boxShadow = '';
        };

        const setDropIndicator = (target) => {
          target.style.outline = '2px dashed #f2c94c';
          target.style.outlineOffset = '4px';
        };

        drag.addEventListener('dragstart', (event) => {
          dragItemId = item.id;
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', String(item.id));
          card.style.opacity = '0.6';
        });

        drag.addEventListener('dragend', () => {
          dragItemId = null;
          card.style.opacity = '';
          clearDropIndicator(card);
        });

        card.addEventListener('dragover', (event) => {
          if (!dragItemId || dragItemId === item.id) {
            return;
          }
          event.preventDefault();
          setDropIndicator(card);
        });

        card.addEventListener('dragleave', () => {
          clearDropIndicator(card);
        });

        card.addEventListener('drop', (event) => {
          if (!dragItemId || dragItemId === item.id) {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          const rect = card.getBoundingClientRect();
          const position = event.clientY > rect.top + rect.height / 2 ? 'after' : 'before';
          moveItemRelative(dragItemId, item.id, position);
          dragItemId = null;
          clearDropIndicator(card);
        });

        menuItemsContainer.appendChild(card);
        if (item.children && item.children.length) {
          item.children.forEach(child => renderItem(child, level + 1));
        }
      };

      state.items.forEach(item => renderItem(item, 0));
    };

    const findPath = (items, id, path = []) => {
      for (let i = 0; i < items.length; i += 1) {
        const item = items[i];
        const nextPath = path.concat([{ items, index: i, item }]);
        if (item.id === id) {
          return nextPath;
        }
        if (item.children && item.children.length) {
          const childPath = findPath(item.children, id, nextPath);
          if (childPath) {
            return childPath;
          }
        }
      }
      return null;
    };

    const moveItem = (id, direction) => {
      const path = findPath(state.items, id);
      if (!path) {
        return;
      }
      const entry = path[path.length - 1];
      const newIndex = entry.index + direction;
      if (newIndex < 0 || newIndex >= entry.items.length) {
        return;
      }
      const swapped = entry.items[newIndex];
      entry.items[newIndex] = entry.item;
      entry.items[entry.index] = swapped;
      renderMenuItems();
    };

    const moveItemRelative = (dragId, targetId, position) => {
      if (dragId === targetId) {
        return;
      }
      const dragPath = findPath(state.items, dragId);
      const targetPath = findPath(state.items, targetId);
      if (!dragPath || !targetPath) {
        return;
      }
      if (targetPath.some(entry => entry.item.id === dragId)) {
        return;
      }
      const dragEntry = dragPath[dragPath.length - 1];
      const targetEntry = targetPath[targetPath.length - 1];
      const dragItem = dragEntry.item;

      dragEntry.items.splice(dragEntry.index, 1);

      let insertIndex = targetEntry.index;
      if (dragEntry.items === targetEntry.items && dragEntry.index < targetEntry.index) {
        insertIndex -= 1;
      }
      if (position === 'after') {
        insertIndex += 1;
      }
      targetEntry.items.splice(insertIndex, 0, dragItem);
      renderMenuItems();
    };

    const indentItem = (id) => {
      const path = findPath(state.items, id);
      if (!path) {
        return;
      }
      const entry = path[path.length - 1];
      if (entry.index === 0) {
        return;
      }
      const parentItems = entry.items;
      const prevSibling = parentItems[entry.index - 1];
      parentItems.splice(entry.index, 1);
      prevSibling.children = prevSibling.children || [];
      prevSibling.children.push(entry.item);
      renderMenuItems();
    };

    const outdentItem = (id) => {
      const path = findPath(state.items, id);
      if (!path || path.length < 2) {
        return;
      }
      const entry = path[path.length - 1];
      const parentEntry = path[path.length - 2];
      const parentItems = parentEntry.items;
      const parentIndex = parentEntry.index;
      entry.items.splice(entry.index, 1);
      parentItems.splice(parentIndex + 1, 0, entry.item);
      renderMenuItems();
    };

    const removeItem = (id) => {
      const path = findPath(state.items, id);
      if (!path) {
        return;
      }
      const entry = path[path.length - 1];
      entry.items.splice(entry.index, 1);
      renderMenuItems();
    };

    menuItemsContainer.addEventListener('dragover', (event) => {
      if (!dragItemId) {
        return;
      }
      event.preventDefault();
    });

    menuItemsContainer.addEventListener('drop', (event) => {
      if (!dragItemId) {
        return;
      }
      event.preventDefault();
      const dragPath = findPath(state.items, dragItemId);
      if (!dragPath) {
        return;
      }
      const dragEntry = dragPath[dragPath.length - 1];
      const dragItem = dragEntry.item;
      dragEntry.items.splice(dragEntry.index, 1);
      state.items.push(dragItem);
      dragItemId = null;
      renderMenuItems();
    });

    const serializeItems = (items) => {
      return items.map(item => ({
        label: item.label || '',
        page_id: item.page_id || null,
        custom_url: item.custom_url || null,
        open_in_new_tab: !!item.open_in_new_tab,
        use_page_title: !!item.use_page_title,
        children: serializeItems(item.children || [])
      }));
    };

    const renderPageList = () => {
      const query = pageSearch.value.toLowerCase();
      pageList.innerHTML = '';
      const filtered = state.pages.filter(page => page.title.toLowerCase().includes(query) || page.slug.toLowerCase().includes(query));
      filtered.forEach(page => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between gap-2 text-sm text-slate-600';

        const label = document.createElement('label');
        label.className = 'flex items-center gap-2';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = page.id;
        checkbox.className = 'rounded border-gray-200 text-secondary';
        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(`${page.title} (${page.slug})`));

        row.appendChild(label);
        const aiBtn = document.createElement('button');
        aiBtn.type = 'button';
        aiBtn.className = 'p-2 rounded-lg text-secondary hover:bg-emerald-50';
        aiBtn.title = 'Open AI builder';
        const aiIcon = document.createElement('span');
        aiIcon.className = 'material-icons-outlined text-base';
        aiIcon.textContent = 'smart_toy';
        aiBtn.appendChild(aiIcon);
        aiBtn.addEventListener('click', () => {
          window.location.href = `/admin/page-builder?page_id=${page.id}`;
        });
        row.appendChild(aiBtn);
        pageList.appendChild(row);
      });
    };

    const addPagesToMenu = () => {
      const selected = Array.from(pageList.querySelectorAll('input[type="checkbox"]:checked'));
      if (!selected.length) {
        return;
      }
      selected.forEach(input => {
        const pageId = parseInt(input.value, 10);
        const page = state.pages.find(p => p.id === pageId);
        if (!page) {
          return;
        }
        state.items.push({
          id: Date.now() + Math.floor(Math.random() * 1000),
          page_id: page.id,
          page_title: page.title,
          page_slug: page.slug,
          page_visibility: page.visibility,
          label: page.title,
          custom_url: null,
          open_in_new_tab: false,
          use_page_title: true,
          status: page.visibility === 'public' ? 'Published' : 'Restricted',
          children: []
        });
      });
      renderMenuItems();
    };

    const addCustomLink = () => {
      const label = customLabel.value.trim();
      const url = customUrl.value.trim();
      if (!label || !url) {
        showAlert('Custom label and URL are required.', 'error');
        return;
      }
      state.items.push({
        id: Date.now() + Math.floor(Math.random() * 1000),
        page_id: null,
        page_title: null,
        page_slug: null,
        page_visibility: null,
        label,
        custom_url: url,
        open_in_new_tab: customNewTab.checked,
        use_page_title: false,
        status: 'Custom',
        children: []
      });
      customLabel.value = '';
      customUrl.value = '';
      customNewTab.checked = false;
      renderMenuItems();
    };

    menuSelect.addEventListener('change', async () => {
      const value = parseInt(menuSelect.value, 10);
      state.currentMenuId = value || null;
      renderMenuDetails();
      if (state.currentMenuId) {
        await loadMenuItems(state.currentMenuId);
      }
    });

    menuRenameButton.addEventListener('click', async () => {
      if (!state.currentMenuId) {
        return;
      }
      try {
        await apiRequest(`/api/menus/${state.currentMenuId}`, {
          method: 'PUT',
          body: JSON.stringify({ name: menuNameInput.value })
        });
        showAlert('Menu name updated.');
        await loadMenus();
      } catch (err) {
        showAlert(err.message, 'error');
      }
    });

    menuSaveButton.addEventListener('click', async () => {
      if (!state.currentMenuId) {
        return;
      }
      try {
        await apiRequest(`/api/menus/${state.currentMenuId}/items`, {
          method: 'PUT',
          body: JSON.stringify({ items: serializeItems(state.items) })
        });
        showAlert('Menu saved.');
        await loadMenuItems(state.currentMenuId);
      } catch (err) {
        showAlert(err.message, 'error');
      }
    });

    menuDeleteButton.addEventListener('click', async () => {
      if (!state.currentMenuId) {
        return;
      }
      if (!confirm('Delete this menu?')) {
        return;
      }
      try {
        await apiRequest(`/api/menus/${state.currentMenuId}`, {
          method: 'DELETE',
          body: JSON.stringify({})
        });
        showAlert('Menu deleted.');
        state.currentMenuId = null;
        await loadMenus();
      } catch (err) {
        showAlert(err.message, 'error');
      }
    });

    menuCreateToggle.addEventListener('click', () => {
      menuCreatePanel.classList.toggle('hidden');
    });
    menuCreateCancel.addEventListener('click', () => {
      menuCreatePanel.classList.add('hidden');
      menuCreateName.value = '';
    });
    menuCreateSubmit.addEventListener('click', async () => {
      const name = menuCreateName.value.trim();
      if (!name) {
        showAlert('Menu name is required.', 'error');
        return;
      }
      try {
        await apiRequest('/api/menus', {
          method: 'POST',
          body: JSON.stringify({ name, location_key: menuCreateLocation.value })
        });
        menuCreatePanel.classList.add('hidden');
        menuCreateName.value = '';
        showAlert('Menu created.');
        await loadMenus();
      } catch (err) {
        showAlert(err.message, 'error');
      }
    });

    pageSearch.addEventListener('input', renderPageList);
    pageAdd.addEventListener('click', addPagesToMenu);
    customAdd.addEventListener('click', addCustomLink);

    renderPageList();
    loadMenus().catch(err => showAlert(err.message, 'error'));
  })();
</script>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
