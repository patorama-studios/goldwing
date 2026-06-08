<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;

require_permission('admin.roles.manage');

sync_access_registry();

$roleOptions = access_control_roles();
$roleLabels = access_control_role_labels();
$selectedRole = normalize_access_role($_GET['role'] ?? 'member');
if (!in_array($selectedRole, $roleOptions, true)) {
    $selectedRole = 'member';
}

$toast = $_SESSION['access_control_toast'] ?? '';
unset($_SESSION['access_control_toast']);

$pdo = db();
$stmt = $pdo->prepare('SELECT p.*, COALESCE(pra.can_access, 0) AS can_access
    FROM pages_registry p
    LEFT JOIN page_role_access pra ON pra.page_id = p.id AND pra.role = :role
    WHERE p.is_enabled = 1
    ORDER BY p.nav_group ASC, p.label ASC');
$stmt->execute(['role' => $selectedRole]);
$rows = $stmt->fetchAll() ?: [];

$groups = [];
foreach ($rows as $row) {
    $group = $row['nav_group'] ?: 'General';
    if (!isset($groups[$group])) {
        $groups[$group] = [];
    }
    $groups[$group][] = $row;
}

$activePage = 'settings';
$pageTitle = 'Page Access Control';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Settings Hub'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($toast): ?>
        <div class="rounded-lg px-4 py-2 text-sm bg-green-50 text-green-700"><?= e($toast) ?></div>
      <?php endif; ?>

      <form id="access-control-form" method="post" action="/admin/settings/access-control-save.php" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="role" value="<?= e($selectedRole) ?>">
        <input type="hidden" name="reset_defaults" id="reset-defaults" value="0">

        <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl font-bold text-gray-900">Page Access Control</h1>
              <nav class="mt-1 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="/admin/" class="hover:text-slate-700">Admin</a>
                <span class="text-slate-300">/</span>
                <a href="/admin/settings/" class="hover:text-slate-700">Settings</a>
                <span class="text-slate-300">/</span>
                <span class="font-semibold text-gray-900 border-b-2 border-primary pb-0.5">Page Access Control</span>
              </nav>
              <p class="text-sm text-slate-500 mt-2 max-w-3xl">Control which roles can access which pages. Pages hidden from navigation are also blocked via direct URL.</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
              <div>
                <label for="role-select" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Role</label>
                <select id="role-select" class="mt-1 min-w-[220px] rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-sm">
                  <?php foreach ($roleOptions as $role): ?>
                    <option value="<?= e($role) ?>" <?= $role === $selectedRole ? 'selected' : '' ?>><?= e($roleLabels[$role] ?? $role) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="relative">
                <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-base">search</span>
                <input id="page-search" type="search" placeholder="Search pages" class="w-full sm:w-64 rounded-lg border border-gray-200 bg-white pl-10 pr-3 py-2.5 text-sm shadow-sm">
              </div>
            </div>
          </div>
        </div>

        <?php foreach ($groups as $groupName => $pages): ?>
          <section class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-group-block="<?= e($groupName) ?>">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div>
                <h2 class="font-display text-lg font-bold text-gray-900"><?= e($groupName) ?></h2>
                <p class="text-xs text-slate-500">Manage access for this section.</p>
              </div>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-slate-600" data-group-action="allow" data-group="<?= e($groupName) ?>">Allow all in this group</button>
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-slate-600" data-group-action="deny" data-group="<?= e($groupName) ?>">Deny all in this group</button>
              </div>
            </div>
            <div class="divide-y divide-gray-100">
              <?php foreach ($pages as $page): ?>
                <?php
                  $pathPattern = $page['path_pattern'];
                  $matchType = $page['match_type'] === 'prefix' ? 'Prefix' : 'Exact';
                  $searchIndex = strtolower($page['label'] . ' ' . $pathPattern);
                ?>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 py-4 access-row" data-group="<?= e($groupName) ?>" data-search="<?= e($searchIndex) ?>">
                  <div>
                    <p class="text-sm font-semibold text-gray-900"><?= e($page['label']) ?></p>
                    <p class="text-xs text-slate-500"><?= e($pathPattern) ?> <span class="text-slate-400">(<?= e($matchType) ?>)</span></p>
                  </div>
                  <label class="inline-flex items-center gap-2">
                    <input type="hidden" name="page_ids[]" value="<?= (int) $page['id'] ?>">
                    <input type="checkbox" class="rounded border-gray-200 access-toggle" name="access[<?= (int) $page['id'] ?>]" value="1" <?= (int) $page['can_access'] === 1 ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Allowed</span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>

        <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
          <button type="button" id="reset-button" class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50">
            <span class="material-icons-outlined text-base">restart_alt</span>
            Reset to defaults
          </button>
          <div class="flex items-center gap-3">
            <a href="/admin/settings/access-control.php?role=<?= e($selectedRole) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
              <span class="material-icons-outlined text-base">save</span>
              Save Changes
            </button>
          </div>
        </div>
      </form>
    </div>
  </main>
</div>

<div id="reset-modal" class="fixed inset-0 hidden items-center justify-center bg-black/40 p-4">
  <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-card">
    <h3 class="font-display text-lg font-bold text-gray-900">Reset permissions?</h3>
    <p class="text-sm text-slate-600 mt-2">This resets all page access for the selected role back to the default matrix.</p>
    <div class="flex justify-end gap-2 mt-6">
      <button type="button" id="reset-cancel" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-slate-600">Cancel</button>
      <button type="button" id="reset-confirm" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">Reset</button>
    </div>
  </div>
</div>

<script>
  const roleSelect = document.getElementById('role-select');
  const searchInput = document.getElementById('page-search');
  const resetButton = document.getElementById('reset-button');
  const resetModal = document.getElementById('reset-modal');
  const resetCancel = document.getElementById('reset-cancel');
  const resetConfirm = document.getElementById('reset-confirm');
  const resetDefaultsInput = document.getElementById('reset-defaults');
  const form = document.getElementById('access-control-form');

  roleSelect.addEventListener('change', () => {
    const url = new URL(window.location.href);
    url.searchParams.set('role', roleSelect.value);
    window.location.href = url.toString();
  });

  searchInput.addEventListener('input', () => {
    const term = searchInput.value.trim().toLowerCase();
    document.querySelectorAll('.access-row').forEach(row => {
      const haystack = row.dataset.search || '';
      row.classList.toggle('hidden', term !== '' && !haystack.includes(term));
    });
    document.querySelectorAll('[data-group-block]').forEach(group => {
      const visibleRows = group.querySelectorAll('.access-row:not(.hidden)').length;
      group.classList.toggle('hidden', visibleRows === 0);
    });
  });

  document.querySelectorAll('[data-group-action]').forEach(button => {
    button.addEventListener('click', () => {
      const group = button.dataset.group;
      const action = button.dataset.groupAction;
      document.querySelectorAll(`.access-row[data-group="${group}"] .access-toggle`).forEach(toggle => {
        toggle.checked = action === 'allow';
      });
    });
  });

  resetButton.addEventListener('click', () => {
    resetModal.classList.remove('hidden');
    resetModal.classList.add('flex');
  });

  resetCancel.addEventListener('click', () => {
    resetModal.classList.add('hidden');
    resetModal.classList.remove('flex');
  });

  resetConfirm.addEventListener('click', () => {
    resetDefaultsInput.value = '1';
    form.submit();
  });
</script>
<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
</body>
</html>
