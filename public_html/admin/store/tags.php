<?php
use App\Services\Csrf;

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM store_tags WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_tag') {
            $name = trim($_POST['name'] ?? '');
            $slugInput = trim($_POST['slug'] ?? '');
            $tagId = (int) ($_POST['tag_id'] ?? 0);
            if ($name === '') {
                $alerts[] = ['type' => 'error', 'message' => 'Tag name is required.'];
            } else {
                $slug = store_slugify($slugInput !== '' ? $slugInput : $name);
                $slug = store_unique_slug('store_tags', $slug, $tagId);
                if ($tagId > 0) {
                    $stmt = $pdo->prepare('UPDATE store_tags SET name = :name, slug = :slug WHERE id = :id');
                    $stmt->execute(['name' => $name, 'slug' => $slug, 'id' => $tagId]);
                    $alerts[] = ['type' => 'success', 'message' => 'Tag updated.'];
                    $editing = null;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO store_tags (name, slug, created_at) VALUES (:name, :slug, NOW())');
                    $stmt->execute(['name' => $name, 'slug' => $slug]);
                    $alerts[] = ['type' => 'success', 'message' => 'Tag created.'];
                }
            }
        }
        if ($action === 'delete_tag') {
            $tagId = (int) ($_POST['tag_id'] ?? 0);
            if ($tagId > 0) {
                $stmt = $pdo->prepare('DELETE FROM store_tags WHERE id = :id');
                $stmt->execute(['id' => $tagId]);
                $alerts[] = ['type' => 'success', 'message' => 'Tag removed.'];
            }
        }
    }
}

$stmt = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM store_product_tags pt WHERE pt.tag_id = t.id) as product_count FROM store_tags t ORDER BY t.name ASC');
$tags = $stmt->fetchAll();

$pageSubtitle = 'Create and manage product tags.';
?>
<section class="grid gap-6 lg:grid-cols-[1fr_2fr]">
  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">
      <?= $editing ? 'Edit tag' : 'Add tag' ?>
    </h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_tag">
      <?php if ($editing): ?>
        <input type="hidden" name="tag_id" value="<?= e((string) $editing['id']) ?>">
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
        <?= $editing ? 'Update tag' : 'Create tag' ?>
      </button>
      <?php if ($editing): ?>
        <a href="/admin/store/tags" class="block text-center text-sm text-slate-500">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Tags</h2>
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
          <?php foreach ($tags as $tag): ?>
            <tr>
              <td class="py-2 pr-3 text-gray-900 font-medium"><?= e($tag['name']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e($tag['slug']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e((string) $tag['product_count']) ?></td>
              <td class="py-2 flex items-center gap-2">
                <a class="text-sm text-blue-600" href="/admin/store/tags?edit=<?= e((string) $tag['id']) ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Delete this tag?');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="delete_tag">
                  <input type="hidden" name="tag_id" value="<?= e((string) $tag['id']) ?>">
                  <button class="text-sm text-red-600" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tags): ?>
            <tr>
              <td colspan="4" class="py-4 text-center text-gray-500">No tags yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
