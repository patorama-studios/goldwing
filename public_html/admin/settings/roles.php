<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;

require_permission('admin.roles.view');

$pdo = db();
$canManage = current_admin_can('admin.roles.manage');

$toast = $_SESSION['admin_roles_toast'] ?? '';
unset($_SESSION['admin_roles_toast']);

$rolesRaw = admin_role_builder_candidates($pdo);
$roles = array_values(array_filter($rolesRaw, fn($role) => admin_role_is_admin($role)));

$roleId = (int) ($_GET['role_id'] ?? 0);
$selectedRole = null;
foreach ($roles as $role) {
    if ((int) $role['id'] === $roleId) {
        $selectedRole = $role;
        break;
    }
}
if (!$selectedRole && $roles) {
    $selectedRole = $roles[0];
    $roleId = (int) $selectedRole['id'];
}

$permissionRegistry = admin_permission_registry();
$rolePermissions = [];
if ($roleId > 0) {
    $stmt = $pdo->prepare('SELECT permission_key, allowed FROM role_permissions WHERE role_id = :role_id');
    $stmt->execute(['role_id' => $roleId]);
    foreach ($stmt->fetchAll() as $row) {
        $rolePermissions[$row['permission_key']] = (int) $row['allowed'] === 1;
    }
}

$roleUserCounts = [];
$stmt = $pdo->query('SELECT role_id, COUNT(*) AS total FROM user_roles GROUP BY role_id');
foreach ($stmt->fetchAll() as $row) {
    $roleUserCounts[(int) $row['role_id']] = (int) $row['total'];
}

$activePage = 'settings';
$pageTitle = 'Admin Role Builder';
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

      <div class="grid grid-cols-1 lg:grid-cols-[280px_minmax(0,1fr)] gap-6">
        <aside class="bg-card-light rounded-2xl border border-gray-100 p-4 space-y-4">
          <div class="space-y-2">
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Roles</label>
            <div class="relative">
              <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-base">search</span>
              <input id="role-search" type="search" placeholder="Search roles" class="w-full rounded-lg border border-gray-200 bg-white pl-10 pr-3 py-2 text-sm shadow-sm">
            </div>
            <?php if ($canManage): ?>
              <a class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-ink" href="/admin/settings/roles.php?role_id=0">
                <span class="material-icons-outlined text-sm">add</span>
                New Role
              </a>
            <?php endif; ?>
          </div>

          <div class="space-y-2" id="role-list">
            <?php foreach ($roles as $role): ?>
              <?php
                $isActive = (int) $role['id'] === $roleId;
                $roleLabel = ucwords(str_replace(['_', '-'], ' ', (string) ($role['name'] ?? '')));
                $userCount = $roleUserCounts[(int) $role['id']] ?? 0;
              ?>
              <a class="block rounded-xl border px-3 py-3 text-sm transition-colors <?= $isActive ? 'border-primary bg-primary/5 text-gray-900' : 'border-gray-100 bg-white text-gray-700 hover:border-gray-200' ?>" href="/admin/settings/roles.php?role_id=<?= (int) $role['id'] ?>" data-role-name="<?= e(strtolower($roleLabel)) ?>">
                <div class="flex items-start justify-between gap-2">
                  <div>
                    <p class="font-semibold text-gray-900"><?= e($roleLabel) ?></p>
                    <p class="text-xs text-slate-500">Slug: <?= e((string) ($role['slug'] ?? '')) ?></p>
                  </div>
                  <?php if ((int) ($role['is_system'] ?? 0) === 1): ?>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-600">System</span>
                  <?php endif; ?>
                </div>
                <p class="mt-2 text-xs text-slate-500"><?= $userCount ?> assigned</p>
              </a>
            <?php endforeach; ?>
            <?php if (!$roles): ?>
              <p class="text-sm text-slate-500">No admin roles yet.</p>
            <?php endif; ?>
          </div>
        </aside>

        <section class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-6">
          <?php if ($selectedRole || $roleId === 0): ?>
            <?php
              $isSystemRole = (int) ($selectedRole['is_system'] ?? 0) === 1;
              $roleName = $selectedRole['name'] ?? '';
              $roleSlug = $selectedRole['slug'] ?? '';
              $roleDescription = $selectedRole['description'] ?? '';
              $roleActive = (int) ($selectedRole['is_active'] ?? 1) === 1;
              $userCount = $roleUserCounts[(int) ($selectedRole['id'] ?? 0)] ?? 0;
            ?>
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
              <div>
                <h1 class="font-display text-2xl font-bold text-gray-900">Admin Role Builder</h1>
                <p class="text-sm text-slate-500">Define what each admin role can access across the portal.</p>
              </div>
              <?php if ($selectedRole && $canManage && !$isSystemRole): ?>
                <form method="post" action="/admin/settings/roles-save.php" onsubmit="return confirm('Delete this role? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="role_id" value="<?= (int) $selectedRole['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-600 <?= $userCount > 0 ? 'opacity-50 cursor-not-allowed' : '' ?>" type="submit" <?= $userCount > 0 ? 'disabled' : '' ?>>
                    <span class="material-icons-outlined text-sm">delete</span>
                    Delete role
                  </button>
                </form>
              <?php endif; ?>
            </div>

            <form method="post" action="/admin/settings/roles-save.php" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="role_id" value="<?= (int) $roleId ?>">
              <input type="hidden" name="action" value="save">

              <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div>
                  <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Role name</label>
                  <input type="text" name="name" value="<?= e((string) $roleName) ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" <?= ($isSystemRole || !$canManage) ? 'readonly' : '' ?> required>
                </div>
                <div>
                  <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Slug</label>
                  <input type="text" name="slug" value="<?= e((string) $roleSlug) ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" <?= ($isSystemRole || !$canManage) ? 'readonly' : '' ?> placeholder="membership_admin">
                </div>
              </div>

              <div>
                <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Description</label>
                <textarea name="description" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" <?= $canManage ? '' : 'readonly' ?>><?= e((string) $roleDescription) ?></textarea>
              </div>

              <label class="inline-flex items-center gap-3">
                <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300" <?= ($roleActive ? 'checked' : '') ?> <?= ($isSystemRole || !$canManage) ? 'disabled' : '' ?>>
                <span class="text-sm font-medium text-gray-700">Role is active</span>
              </label>

              <div class="space-y-4">
                <?php foreach ($permissionRegistry as $category => $permissions): ?>
                  <section class="rounded-2xl border border-gray-100 bg-white p-4" data-permission-group="<?= e($category) ?>">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                      <div>
                        <h2 class="font-display text-lg font-bold text-gray-900"><?= e($category) ?></h2>
                        <p class="text-xs text-slate-500">Toggle permissions in this category.</p>
                      </div>
                      <?php if ($canManage): ?>
                        <div class="flex flex-wrap gap-2">
                          <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-slate-600" data-group-action="allow" data-group="<?= e($category) ?>">Select all</button>
                          <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-slate-600" data-group-action="deny" data-group="<?= e($category) ?>">Clear all</button>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                      <?php foreach ($permissions as $permission): ?>
                        <?php
                          $key = $permission['key'];
                          $isChecked = (bool) ($rolePermissions[$key] ?? false);
                        ?>
                        <label class="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm">
                          <input type="checkbox" class="permission-toggle rounded border-gray-300" name="permissions[]" value="<?= e($key) ?>" <?= $isChecked ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                          <span class="font-medium text-gray-700"><?= e($permission['label'] ?? $key) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endforeach; ?>
              </div>

              <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-gray-100">
                <?php if (!$canManage): ?>
                  <p class="text-xs text-slate-500">Only Admins can manage roles.</p>
                <?php else: ?>
                  <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save role</button>
                <?php endif; ?>
              </div>
            </form>
          <?php else: ?>
            <p class="text-sm text-slate-500">Select a role to begin.</p>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
</div>

<script>
  const searchInput = document.getElementById('role-search');
  const roleList = document.getElementById('role-list');

  if (searchInput && roleList) {
    searchInput.addEventListener('input', () => {
      const term = searchInput.value.trim().toLowerCase();
      roleList.querySelectorAll('[data-role-name]').forEach(item => {
        const haystack = item.dataset.roleName || '';
        item.classList.toggle('hidden', term !== '' && !haystack.includes(term));
      });
    });
  }

  document.querySelectorAll('[data-group-action]').forEach(button => {
    button.addEventListener('click', () => {
      const group = button.dataset.group;
      const action = button.dataset.groupAction;
      document.querySelectorAll(`[data-permission-group="${group}"] .permission-toggle`).forEach(toggle => {
        toggle.checked = action === 'allow';
      });
    });
  });
</script>
</body>
</html>
