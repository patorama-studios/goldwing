<?php
use App\Services\Csrf;

$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$conditions = [];
$params = [];
if ($statusFilter !== '') {
    $conditions[] = 'status = :status';
    $params['status'] = $statusFilter;
}
if ($dateFrom !== '') {
    $conditions[] = 'DATE(created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = 'DATE(created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}

$sql = 'SELECT * FROM store_orders';
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageSubtitle = 'Review and manage store orders.';
?>
<section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-6">
  <form method="get" class="flex flex-wrap items-end gap-3 text-sm">
    <div>
      <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Status</label>
      <select name="status" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2">
        <option value="">All</option>
        <?php foreach (['pending','paid','fulfilled','cancelled','refunded'] as $status): ?>
          <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="text-xs uppercase tracking-[0.2em] text-slate-500">From</label>
      <input type="date" name="date_from" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2" value="<?= e($dateFrom) ?>">
    </div>
    <div>
      <label class="text-xs uppercase tracking-[0.2em] text-slate-500">To</label>
      <input type="date" name="date_to" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2" value="<?= e($dateTo) ?>">
    </div>
    <button class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Filter</button>
  </form>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-gray-500 border-b">
        <tr>
          <th class="py-2 pr-3">Order</th>
          <th class="py-2 pr-3">Customer</th>
          <th class="py-2 pr-3">Date</th>
          <th class="py-2 pr-3">Status</th>
          <th class="py-2 pr-3">Total</th>
          <th class="py-2">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($orders as $order): ?>
          <tr>
            <td class="py-2 pr-3 text-gray-900 font-medium"><?= e($order['order_number']) ?></td>
            <td class="py-2 pr-3 text-gray-600">
              <?= e($order['customer_name'] ?? 'Member') ?><br>
              <span class="text-xs text-slate-500"><?= e($order['customer_email'] ?? '') ?></span>
            </td>
            <td class="py-2 pr-3 text-gray-600"><?= e($order['created_at']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= e(ucfirst($order['status'])) ?></td>
            <td class="py-2 pr-3 text-gray-600">$<?= e(store_money((float) $order['total'])) ?></td>
            <td class="py-2">
              <a class="text-sm text-blue-600" href="/admin/store/orders/<?= e($order['order_number']) ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?>
          <tr>
            <td colspan="6" class="py-4 text-center text-gray-500">No orders found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
