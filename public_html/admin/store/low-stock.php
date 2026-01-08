<?php
$products = $pdo->query('SELECT * FROM store_products WHERE track_inventory = 1 ORDER BY title ASC')->fetchAll();
$alertsList = [];
foreach ($products as $product) {
    $stock = 0;
    if ((int) $product['has_variants'] === 1) {
        $stmt = $pdo->prepare('SELECT SUM(COALESCE(stock_quantity, 0)) as total FROM store_product_variants WHERE product_id = :id');
        $stmt->execute(['id' => $product['id']]);
        $row = $stmt->fetch();
        $stock = (int) ($row['total'] ?? 0);
    } else {
        $stock = (int) ($product['stock_quantity'] ?? 0);
    }
    $threshold = (int) ($product['low_stock_threshold'] ?? 0);
    if ($threshold > 0 && $stock <= $threshold) {
        $alertsList[] = [
            'id' => $product['id'],
            'title' => $product['title'],
            'stock' => $stock,
            'threshold' => $threshold,
            'type' => $product['type'],
        ];
    }
}

$pageSubtitle = 'Products at or below their low stock threshold.';
?>
<section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
  <h2 class="text-lg font-semibold text-gray-900 mb-4">Low stock alerts</h2>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-gray-500 border-b">
        <tr>
          <th class="py-2 pr-3">Product</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Stock</th>
          <th class="py-2 pr-3">Threshold</th>
          <th class="py-2">Action</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($alertsList as $alert): ?>
          <tr>
            <td class="py-2 pr-3 text-gray-900 font-medium"><?= e($alert['title']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= e($alert['type']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= e((string) $alert['stock']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= e((string) $alert['threshold']) ?></td>
            <td class="py-2">
              <a class="text-sm text-blue-600" href="/admin/store/product/<?= e((string) $alert['id']) ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$alertsList): ?>
          <tr>
            <td colspan="5" class="py-4 text-center text-gray-500">No low stock alerts.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
