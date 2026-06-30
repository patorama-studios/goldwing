<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AwardsService;
use App\Services\Csrf;

require_permission('admin.awards.view');

$user = current_user();
$canManage = function_exists('current_admin_can') && current_admin_can('admin.awards.manage', $user);

if (!AwardsService::tablesReady()) {
    $_SESSION['awards_flash'] = ['type' => 'error', 'message' => 'Awards tables are not ready. Run the migration runner.'];
    header('Location: /admin/awards/');
    exit;
}

$categories = AwardsService::listCategories(true);

$flash = $_SESSION['awards_flash'] ?? null;
unset($_SESSION['awards_flash']);

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $editing = AwardsService::findCategory($editId);
}
$isNew = isset($_GET['new']);

$pageTitle = 'Trophy Categories';
$activePage = 'awards';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div class="flex items-center justify-between" data-tour="categories-header">
        <div>
          <a href="/admin/awards/" class="text-sm text-gray-500 hover:text-gray-700 inline-flex items-center gap-1">
            <span class="material-icons-outlined text-base">arrow_back</span> Back to awards
          </a>
          <h1 class="font-display text-3xl font-bold text-gray-900 mt-2">Trophy Categories</h1>
          <p class="text-sm text-gray-500 mt-1">The 16 trophy types awarded at the AGM. Rarely changed.</p>
        </div>
        <?php if ($canManage): ?>
          <a href="/admin/awards/categories.php?new=1" data-tour="categories-new-btn" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-primary/90">
            <span class="material-icons-outlined text-base">add</span> New Category
          </a>
        <?php endif; ?>
      </div>

      <?php if ($flash): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if ($canManage && ($editing || $isNew)): ?>
        <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm" data-tour="categories-form">
          <h2 class="font-display text-lg font-bold text-gray-900 mb-4">
            <?= $editing ? 'Edit Category' : 'New Category' ?>
          </h2>
          <form method="post" action="/admin/awards/actions.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="redirect_after" value="/admin/awards/categories.php">
            <?php if ($editing): ?>
              <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label class="block">
                <span class="text-sm font-semibold text-gray-700">Name *</span>
                <input type="text" name="name" required maxlength="180"
                       value="<?= e($editing['name'] ?? '') ?>"
                       class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-sm font-semibold text-gray-700">Sort Order</span>
                <input type="number" name="sort_order" min="0" max="9999"
                       value="<?= (int) ($editing['sort_order'] ?? 0) ?>"
                       class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label class="block">
                <span class="text-sm font-semibold text-gray-700">Group Label</span>
                <input type="text" name="group_label" maxlength="120"
                       value="<?= e($editing['group_label'] ?? '') ?>"
                       placeholder="e.g. Best Original Goldwing"
                       class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">Categories with the same group label render together on the wall.</p>
              </label>
              <label class="block">
                <span class="text-sm font-semibold text-gray-700">Memorial Trophy Name</span>
                <input type="text" name="memorial_trophy_name" maxlength="180"
                       value="<?= e($editing['memorial_trophy_name'] ?? '') ?>"
                       placeholder="e.g. Burden Memorial Trophy"
                       class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>

            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Description</span>
              <textarea name="description" rows="2" maxlength="500"
                        class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e($editing['description'] ?? '') ?></textarea>
            </label>

            <label class="inline-flex items-center gap-2">
              <input type="checkbox" name="is_active" value="1" <?= !$editing || (int) ($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>
                     class="rounded border-gray-300 text-primary focus:ring-primary">
              <span class="text-sm text-gray-700">Active (show on member-facing pages)</span>
            </label>

            <div class="flex items-center gap-2 pt-2">
              <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-gray-900 hover:bg-primary/90">
                <span class="material-icons-outlined text-base">save</span>
                <?= $editing ? 'Save changes' : 'Create category' ?>
              </button>
              <a href="/admin/awards/categories.php" class="rounded-full border border-gray-200 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" data-tour="categories-table">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
            <tr>
              <th class="px-4 py-3">Sort</th>
              <th class="px-4 py-3">Name</th>
              <th class="px-4 py-3">Group</th>
              <th class="px-4 py-3">Memorial Trophy</th>
              <th class="px-4 py-3">Status</th>
              <?php if ($canManage): ?><th class="px-4 py-3"></th><?php endif; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($categories as $c): ?>
              <tr class="<?= $editing && (int) $editing['id'] === (int) $c['id'] ? 'bg-amber-50' : '' ?>">
                <td class="px-4 py-3 text-gray-500"><?= (int) $c['sort_order'] ?></td>
                <td class="px-4 py-3 font-medium text-gray-900"><?= e($c['name']) ?></td>
                <td class="px-4 py-3 text-gray-600"><?= e($c['group_label'] ?? '') ?></td>
                <td class="px-4 py-3 text-amber-700"><?= e($c['memorial_trophy_name'] ?? '') ?></td>
                <td class="px-4 py-3">
                  <?php if ((int) $c['is_active'] === 1): ?>
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Active</span>
                  <?php else: ?>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-500">Inactive</span>
                  <?php endif; ?>
                </td>
                <?php if ($canManage): ?>
                  <td class="px-4 py-3 text-right">
                    <a href="/admin/awards/categories.php?edit=<?= (int) $c['id'] ?>" class="text-sm font-semibold text-primary hover:underline">Edit</a>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (!$categories): ?>
              <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">No categories yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </section>

    </div>
  </main>
</div>
<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
