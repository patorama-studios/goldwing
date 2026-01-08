<?php
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
    $conditions[] = '(p.title LIKE :search OR p.description LIKE :search)';
    $params['search'] = '%' . $search . '%';
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
$heroLead = 'Members-only store for official Goldwing Association gear, apparel, and accessories.';
?>
<div class="store-shell">
  <form class="store-filters" method="get">
    <div class="store-filter search">
      <label class="sr-only" for="store-search">Search</label>
      <input id="store-search" name="q" value="<?= e($search) ?>" placeholder="Search products">
    </div>
    <div class="store-filter">
      <label class="sr-only" for="store-category">Category</label>
      <select id="store-category" name="category">
        <option value="">All categories</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= e((string) $category['id']) ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="store-filter">
      <label class="sr-only" for="store-tag">Tag</label>
      <select id="store-tag" name="tag">
        <option value="">All tags</option>
        <?php foreach ($tags as $tag): ?>
          <option value="<?= e((string) $tag['id']) ?>" <?= $tagId === (int) $tag['id'] ? 'selected' : '' ?>><?= e($tag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="button" type="submit">Filters</button>
  </form>

  <?php if (!$products): ?>
    <div class="alert">No products match your filters yet.</div>
  <?php else: ?>
    <div class="store-grid">
      <?php foreach ($products as $product): ?>
        <article class="store-card">
          <div class="store-card__media">
            <?php if (!empty($product['image_url'])): ?>
              <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['title']) ?>">
            <?php else: ?>
              <div class="store-card__placeholder">No image</div>
            <?php endif; ?>
          </div>
          <div class="store-card__body">
            <span class="store-pill"><?= e($product['type']) ?></span>
            <h3><?= e($product['title']) ?></h3>
            <p class="store-price">$<?= e(store_money((float) $product['base_price'])) ?></p>
            <a class="button primary store-card__cta" href="/store/product/<?= e($product['slug']) ?>">View product</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
