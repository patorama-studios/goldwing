<?php
// Direct hits to /store/catalog.php used to 500 because this file is a fragment
// included by /store/index.php and assumes $pdo / $user from the parent scope.
// Redirect to the clean URL instead.
if (!isset($pdo)) {
    header('Location: /store/', true, 301);
    exit;
}

$search = trim($_GET['q'] ?? '');
$categoryId = (int) ($_GET['category'] ?? 0);
$tagId = (int) ($_GET['tag'] ?? 0);

$categories = $pdo->query('SELECT id, name FROM store_categories ORDER BY name ASC')->fetchAll();
$tags = $pdo->query('SELECT id, name FROM store_tags ORDER BY name ASC')->fetchAll();

$joins = '';
$conditions = ['p.is_active = 1'];
$params = [];
if ($categoryId) {
    $joins .= ' JOIN store_product_categories pc ON pc.product_id = p.id';
    $conditions[] = 'pc.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($tagId) {
    $joins .= ' JOIN store_product_tags pt ON pt.product_id = p.id';
    $conditions[] = 'pt.tag_id = :tag_id';
    $params['tag_id'] = $tagId;
}
if ($search !== '') {
    $conditions[] = '(p.title LIKE :search_title OR p.description LIKE :search_desc)';
    $params['search_title'] = '%' . $search . '%';
    $params['search_desc'] = '%' . $search . '%';
}

$sql = 'SELECT DISTINCT p.*, (SELECT image_url FROM store_product_images i WHERE i.product_id = p.id ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) as image_url FROM store_products p' . $joins;
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY p.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = $settings['store_name'] ?? 'Store';
?>
<section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-4 md:p-5">
  <form method="get" action="/store" class="grid grid-cols-1 md:grid-cols-[1fr_200px_200px_auto] gap-3 items-end">
    <div>
      <label for="store-search" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Search</label>
      <div class="relative">
        <span class="material-icons-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xl">search</span>
        <input id="store-search" name="q" value="<?= e($search) ?>" placeholder="Search products" class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-gray-300 focus:border-primary focus:ring-primary text-sm">
      </div>
    </div>
    <div>
      <label for="store-category" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Category</label>
      <select id="store-category" name="category" class="w-full px-3 py-2.5 rounded-lg border border-gray-300 focus:border-primary focus:ring-primary text-sm">
        <option value="">All categories</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= e((string) $category['id']) ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="store-tag" class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Tag</label>
      <select id="store-tag" name="tag" class="w-full px-3 py-2.5 rounded-lg border border-gray-300 focus:border-primary focus:ring-primary text-sm">
        <option value="">All tags</option>
        <?php foreach ($tags as $tag): ?>
          <option value="<?= e((string) $tag['id']) ?>" <?= $tagId === (int) $tag['id'] ? 'selected' : '' ?>><?= e($tag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold transition-colors">
      <span class="material-icons-outlined text-base">tune</span>
      Filter
    </button>
  </form>
</section>

<?php if (!$products): ?>
  <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
    <span class="material-icons-outlined text-5xl text-gray-300">storefront</span>
    <h2 class="mt-3 text-xl font-semibold text-gray-900">No products match your filters yet</h2>
    <p class="mt-1 text-gray-500">Try clearing filters or check back soon for new gear.</p>
    <a href="/store" class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold transition-colors">Clear filters</a>
  </section>
<?php else: ?>
  <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
    <?php foreach ($products as $product):
      $isTicket = ($product['type'] ?? '') === 'ticket';
      $typeLabel = $isTicket ? 'Ticket' : 'Apparel';
    ?>
      <a href="/store/product/<?= e($product['slug']) ?>" class="group bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md hover:-translate-y-0.5 transition-all">
        <div class="aspect-square bg-gray-50 overflow-hidden relative">
          <?php if (!empty($product['image_url'])): ?>
            <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
          <?php else: ?>
            <div class="absolute inset-0 flex items-center justify-center">
              <span class="material-icons-outlined text-6xl text-gray-300"><?= $isTicket ? 'confirmation_number' : 'checkroom' ?></span>
            </div>
          <?php endif; ?>
          <span class="absolute top-3 left-3 inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white/95 backdrop-blur text-[11px] font-bold uppercase tracking-wider text-gray-700 shadow-sm">
            <span class="material-icons-outlined text-sm"><?= $isTicket ? 'confirmation_number' : 'checkroom' ?></span>
            <?= e($typeLabel) ?>
          </span>
        </div>
        <div class="p-4 flex flex-col flex-1">
          <h3 class="font-display text-base font-semibold text-gray-900 line-clamp-2"><?= e($product['title']) ?></h3>
          <p class="mt-2 text-lg font-bold text-gray-900">$<?= e(store_money((float) $product['base_price'])) ?></p>
          <div class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900 group-hover:text-primary-strong transition-colors">
            View product
            <span class="material-icons-outlined text-base group-hover:translate-x-0.5 transition-transform">arrow_forward</span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
