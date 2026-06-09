<?php
$stmt = $pdo->prepare('SELECT * FROM store_orders WHERE user_id = :user_id ORDER BY created_at DESC');
$stmt->execute(['user_id' => $user['id']]);
$orders = $stmt->fetchAll();

$pageTitle = 'Your Orders';

$statusPill = function (string $status): array {
    $s = strtolower($status);
    if (in_array($s, ['paid', 'completed', 'shipped'], true)) {
        return ['Paid', 'bg-green-100 text-green-800 border-green-200'];
    }
    if (in_array($s, ['processing', 'packed'], true)) {
        return [ucfirst($status), 'bg-blue-100 text-blue-800 border-blue-200'];
    }
    if (in_array($s, ['cancelled', 'refunded', 'failed'], true)) {
        return [ucfirst($status), 'bg-red-100 text-red-800 border-red-200'];
    }
    return [ucfirst($status ?: 'Pending'), 'bg-amber-100 text-amber-900 border-amber-200'];
};
?>

<?php if (!$orders): ?>
  <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
    <span class="material-icons-outlined text-6xl text-gray-300">receipt_long</span>
    <h2 class="mt-4 font-display text-2xl font-bold text-gray-900">No orders yet</h2>
    <p class="mt-2 text-gray-500">When you make your first purchase it'll show up here.</p>
    <a href="/store" class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors">
      <span class="material-icons-outlined">storefront</span>
      Browse products
    </a>
  </section>
<?php else: ?>
  <section class="space-y-3">
    <?php foreach ($orders as $order):
      [$statusLabel, $statusClass] = $statusPill((string) ($order['status'] ?? ''));
      $createdAt = !empty($order['created_at']) ? date('j M Y · g:ia', strtotime($order['created_at'])) : '';
    ?>
      <a href="/store/orders/<?= e((string) $order['order_number']) ?>"
         class="block bg-card-light rounded-2xl shadow-sm border border-gray-100 hover:shadow-md hover:border-gray-200 transition-all">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4 px-5 py-4">
          <div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
            <span class="material-icons-outlined text-primary-strong">receipt_long</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="font-mono text-sm font-semibold text-gray-900"><?= e((string) $order['order_number']) ?></p>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider border <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5"><?= e($createdAt) ?></p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-lg font-bold text-gray-900">$<?= e(store_money((float) $order['total'])) ?></p>
            <p class="text-xs text-gray-500">View details &rsaquo;</p>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
