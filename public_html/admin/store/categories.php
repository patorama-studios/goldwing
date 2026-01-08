<?php
use App\Services\Csrf;

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM store_categories WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_category') {
            $name = trim($_POST['name'] ?? '');
            $slugInput = trim($_POST['slug'] ?? '');
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            if ($name === '') {
                $alerts[] = ['type' => 'error', 'message' => 'Category name is required.'];
            } else {
                $slug = store_slugify($slugInput !== '' ? $slugInput : $name);
                $slug = store_unique_slug('store_categories', $slug, $categoryId);
                if ($categoryId > 0) {
                    $stmt = $pdo->prepare('UPDATE store_categories SET name = :name, slug = :slug WHERE id = :id');
                    $stmt->execute(['name' => $name, 'slug' => $slug, 'id' => $categoryId]);
                    $alerts[] = ['type' => 'success', 'message' => 'Category updated.'];
                    $editing = null;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO store_categories (name, slug, created_at) VALUES (:name, :slug, NOW())');
                    $stmt->execute(['name' => $name, 'slug' => $slug]);
                    $alerts[] = ['type' => 'success', 'message' => 'Category created.'];
                }
            }
        }
        if ($action === 'delete_category') {
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            if ($categoryId > 0) {
                $stmt = $pdo->prepare('DELETE FROM store_categories WHERE id = :id');
                $stmt->execute(['id' => $categoryId]);
                $alerts[] = ['type' => 'success', 'message' => 'Category removed.'];
            }
        }
    }
}

$stmt = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM store_product_categories pc WHERE pc.category_id = c.id) as product_count FROM store_categories c ORDER BY c.name ASC');
$categories = $stmt->fetchAll();

$pageSubtitle = 'Create and manage product categories.';
?>
<section class="grid gap-6 lg:grid-cols-[1fr_2fr]">
  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">
      <?= $editing ? 'Edit category' : 'Add category' ?>
    </h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_category">
      <?php if ($editing): ?>
        <input type="hidden" name="category_id" value="<?= e((string) $editing['id']) ?>">
      <?php endif; ?>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Name</label>
        <input name="name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($editing['name'] ?? '') ?>" required>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Slug</label>
        <input name="slug" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($editing['slug'] ?? '') ?>" placeholder="auto-generated">
      </div>
      <button type="submit" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">
        <?= $editing ? 'Update category' : 'Create category' ?>
      </button>
      <?php if ($editing): ?>
        <a href="/admin/store/categories" class="block text-center text-sm text-slate-500">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Categories</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-gray-500 border-b">
          <tr>
            <th class="py-2 pr-3">Name</th>
            <th class="py-2 pr-3">Slug</th>
            <th class="py-2 pr-3">Products</th>
            <th class="py-2">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach ($categories as $category): ?>
            <tr>
              <td class="py-2 pr-3 text-gray-900 font-medium"><?= e($category['name']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e($category['slug']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e((string) $category['product_count']) ?></td>
              <td class="py-2 flex items-center gap-2">
                <a class="text-sm text-blue-600" href="/admin/store/categories?edit=<?= e((string) $category['id']) ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Delete this category?');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="delete_category">
                  <input type="hidden" name="category_id" value="<?= e((string) $category['id']) ?>">
                  <button class="text-sm text-red-600" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$categories): ?>
            <tr>
              <td colspan="4" class="py-4 text-center text-gray-500">No categories yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
