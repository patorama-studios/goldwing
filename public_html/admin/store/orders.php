<?php
use App\Services\Csrf;

$canView = store_user_can($user, 'store_orders_view');
$canManage = store_user_can($user, 'store_orders_manage');
if (!$canView) {
    http_response_code(403);
    echo 'Forbidden';
    return;
}

$statusFilter = trim((string) ($_GET['order_status'] ?? ''));
$paymentFilter = trim((string) ($_GET['payment_status'] ?? ''));
$fulfillmentFilter = trim((string) ($_GET['fulfillment_status'] ?? ''));
$shippingFilter = trim((string) ($_GET['shipping_method'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$conditions = [];
$params = [];

if ($statusFilter !== '') {
    $conditions[] = 'o.order_status = :order_status';
    $params['order_status'] = $statusFilter;
}
if ($paymentFilter !== '') {
    $conditions[] = 'o.payment_status = :payment_status';
    $params['payment_status'] = $paymentFilter;
}
if ($fulfillmentFilter !== '') {
    $conditions[] = 'o.fulfillment_status = :fulfillment_status';
    $params['fulfillment_status'] = $fulfillmentFilter;
}
if ($shippingFilter !== '') {
    $conditions[] = 'o.fulfillment_method = :fulfillment_method';
    $params['fulfillment_method'] = $shippingFilter;
}
if ($dateFrom !== '') {
    $conditions[] = 'DATE(o.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = 'DATE(o.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
if ($search !== '') {
    $conditions[] = '(o.order_number LIKE :search OR o.customer_name LIKE :search OR o.customer_email LIKE :search OR m.first_name LIKE :search OR m.last_name LIKE :search OR m.email LIKE :search OR oi.sku_snapshot LIKE :search OR oi.title_snapshot LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$joins = ' LEFT JOIN members m ON m.id = o.member_id
    LEFT JOIN store_order_items oi ON oi.order_id = o.id
    LEFT JOIN store_products p ON p.id = oi.product_id
    LEFT JOIN store_product_variants v ON v.id = oi.variant_id';
$whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['bulk_action'] ?? '';
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $_POST['order_ids'] ?? []))));

        if (in_array($action, ['status_processing', 'status_packed', 'status_shipped'], true)) {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } elseif (!$orderIds) {
                $alerts[] = ['type' => 'error', 'message' => 'Select at least one order.'];
            } else {
                $newStatus = str_replace('status_', '', $action);
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $markItemsFulfilled = $newStatus === 'shipped';
                foreach ($orderIds as $orderId) {
                    store_apply_order_status($pdo, (int) $orderId, $newStatus, null, null, $markItemsFulfilled);
                    store_add_order_event($pdo, (int) $orderId, 'order.status', 'Order status set to ' . $newStatus . '.', $user['id'] ?? null, [
                        'order_status' => $newStatus,
                    ]);
                }

                $alerts[] = ['type' => 'success', 'message' => 'Order status updated.'];
            }
        }

        if ($action === 'export_csv') {
            $sql = 'SELECT o.*, m.first_name, m.last_name, m.email as member_email,
                COALESCE(SUM(oi.quantity), 0) as item_count,
                MAX(CASE WHEN p.track_inventory = 1 AND (
                    (oi.variant_id IS NOT NULL AND COALESCE(v.stock_quantity, 0) < 0)
                    OR (oi.variant_id IS NULL AND COALESCE(p.stock_quantity, 0) < 0)
                    OR (oi.variant_id IS NOT NULL AND COALESCE(v.stock_quantity, 0) < oi.quantity)
                    OR (oi.variant_id IS NULL AND COALESCE(p.stock_quantity, 0) < oi.quantity)
                ) THEN 1 ELSE 0 END) as needs_attention
                FROM store_orders o' . $joins . $whereSql . '
                GROUP BY o.id
                ORDER BY o.created_at DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="store-orders.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order #', 'Date', 'Customer', 'Payment Status', 'Fulfillment Status', 'Order Status', 'Total', 'Shipping Method', 'Item Count', 'Needs Attention']);
            foreach ($rows as $row) {
                $customer = trim(($row['customer_name'] ?? '') ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                if ($customer === '') {
                    $customer = $row['customer_email'] ?? ($row['member_email'] ?? '');
                }
                fputcsv($out, [
                    $row['order_number'] ?? $row['id'],
                    $row['created_at'],
                    $customer,
                    $row['payment_status'] ?? 'unpaid',
                    $row['fulfillment_status'] ?? 'unfulfilled',
                    $row['order_status'] ?? 'new',
                    store_money((float) $row['total']),
                    $row['fulfillment_method'] ?? 'shipping',
                    $row['item_count'] ?? 0,
                    (int) ($row['needs_attention'] ?? 0) > 0 ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
            exit;
        }

        if ($action === 'print_packing_slips') {
            if (!$orderIds) {
                $alerts[] = ['type' => 'error', 'message' => 'Select at least one order for packing slips.'];
            } else {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $stmt = $pdo->prepare('SELECT o.*, m.first_name, m.last_name, m.email as member_email FROM store_orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.id IN (' . $placeholders . ') ORDER BY o.created_at DESC');
                $stmt->execute($orderIds);
                $orders = $stmt->fetchAll();

                $stmt = $pdo->prepare('SELECT oi.*, (SELECT image_url FROM store_product_images i WHERE i.product_id = oi.product_id ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) as image_url FROM store_order_items oi WHERE oi.order_id IN (' . $placeholders . ') ORDER BY oi.order_id ASC, oi.id ASC');
                $stmt->execute($orderIds);
                $items = $stmt->fetchAll();

                $itemsByOrder = [];
                foreach ($items as $item) {
                    $itemsByOrder[$item['order_id']][] = $item;
                }

                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Packing Slips</title>';
                echo '<style>body{font-family:Arial,sans-serif;color:#111;margin:24px;} .slip{border:1px solid #e5e7eb;padding:16px;margin-bottom:24px;} .muted{color:#6b7280;font-size:12px;} table{width:100%;border-collapse:collapse;margin-top:12px;} th,td{border-bottom:1px solid #e5e7eb;padding:6px 8px;text-align:left;font-size:13px;} .header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;} .address{font-size:13px;line-height:1.4;} </style></head><body onload="window.print()">';
                foreach ($orders as $order) {
                    $customerName = trim((string) ($order['customer_name'] ?? ''));
                    if ($customerName === '') {
                        $customerName = trim((string) (($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')));
                    }
                    echo '<div class="slip">';
                    echo '<div class="header">';
                    echo '<div><h2>Order ' . e((string) ($order['order_number'] ?? $order['id'])) . '</h2><div class="muted">Placed ' . e((string) $order['created_at']) . '</div></div>';
                    echo '<div class="address"><strong>Ship to</strong><br>' . store_order_address_html($order) . '</div>';
                    echo '</div>';
                    echo '<div class="muted">Customer: ' . e($customerName) . ' ' . e((string) ($order['customer_email'] ?? $order['member_email'] ?? '')) . '</div>';
                    echo '<table><thead><tr><th>Item</th><th>SKU</th><th>Qty</th></tr></thead><tbody>';
                    foreach ($itemsByOrder[$order['id']] ?? [] as $item) {
                        echo '<tr><td>' . e((string) $item['title_snapshot']) . '</td><td>' . e((string) ($item['sku_snapshot'] ?? '')) . '</td><td>' . e((string) $item['quantity']) . '</td></tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                }
                echo '</body></html>';
                exit;
            }
        }
    }
}

$countSql = 'SELECT COUNT(DISTINCT o.id) FROM store_orders o' . $joins . $whereSql;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalOrders = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalOrders / $perPage));

$listSql = 'SELECT o.*, m.first_name, m.last_name, m.email as member_email,
    COALESCE(SUM(oi.quantity), 0) as item_count,
    MAX(CASE WHEN p.track_inventory = 1 AND (
        (oi.variant_id IS NOT NULL AND COALESCE(v.stock_quantity, 0) < 0)
        OR (oi.variant_id IS NULL AND COALESCE(p.stock_quantity, 0) < 0)
        OR (oi.variant_id IS NOT NULL AND COALESCE(v.stock_quantity, 0) < oi.quantity)
        OR (oi.variant_id IS NULL AND COALESCE(p.stock_quantity, 0) < oi.quantity)
    ) THEN 1 ELSE 0 END) as needs_attention
    FROM store_orders o' . $joins . $whereSql . '
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

$pageSubtitle = 'Review and manage store orders.';
?>
<section class="space-y-6">
  <form method="get" class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-end gap-3 text-sm">
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Order status</label>
        <select name="order_status" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2">
          <option value="">All</option>
          <?php foreach (['new','processing','packed','shipped','completed','cancelled'] as $status): ?>
            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Payment</label>
        <select name="payment_status" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2">
          <option value="">All</option>
          <?php foreach (['unpaid','paid','partial_refund','refunded'] as $status): ?>
            <option value="<?= e($status) ?>" <?= $paymentFilter === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Fulfillment</label>
        <select name="fulfillment_status" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2">
          <option value="">All</option>
          <?php foreach (['unfulfilled','partial','fulfilled'] as $status): ?>
            <option value="<?= e($status) ?>" <?= $fulfillmentFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Shipping</label>
        <select name="shipping_method" class="mt-2 rounded-lg border border-gray-200 bg-white px-3 py-2">
          <option value="">All</option>
          <option value="shipping" <?= $shippingFilter === 'shipping' ? 'selected' : '' ?>>Shipping</option>
          <option value="pickup" <?= $shippingFilter === 'pickup' ? 'selected' : '' ?>>Pickup</option>
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
      <div class="flex-1 min-w-[200px]">
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Search</label>
        <input type="search" name="search" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2" placeholder="Order #, member, SKU" value="<?= e($search) ?>">
      </div>
      <button class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Filter</button>
    </div>
  </form>

  <form method="post" class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-2">
        <select name="bulk_action" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
          <option value="">Bulk actions</option>
          <option value="status_processing" <?= $canManage ? '' : 'disabled' ?>>Mark processing</option>
          <option value="status_packed" <?= $canManage ? '' : 'disabled' ?>>Mark packed</option>
          <option value="status_shipped" <?= $canManage ? '' : 'disabled' ?>>Mark shipped</option>
          <option value="export_csv">Export CSV</option>
          <option value="print_packing_slips">Print packing slips</option>
        </select>
        <button class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Apply</button>
      </div>
      <div class="text-xs text-slate-500">
        <?= e((string) $totalOrders) ?> orders
      </div>
    </div>

    <div class="overflow-x-auto mt-4">
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-gray-500 border-b">
          <tr>
            <th class="py-2 pr-3"><input type="checkbox" class="rounded border-gray-300" data-bulk-toggle></th>
            <th class="py-2 pr-3">Order #</th>
            <th class="py-2 pr-3">Date</th>
            <th class="py-2 pr-3">Customer</th>
            <th class="py-2 pr-3">Payment</th>
            <th class="py-2 pr-3">Fulfillment</th>
            <th class="py-2 pr-3">Order status</th>
            <th class="py-2 pr-3">Total</th>
            <th class="py-2 pr-3">Shipping</th>
            <th class="py-2 pr-3">Items</th>
            <th class="py-2 pr-3">Flags</th>
            <th class="py-2">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach ($orders as $order): ?>
            <?php
              $customerName = trim((string) ($order['customer_name'] ?? ''));
              if ($customerName === '') {
                  $customerName = trim((string) (($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')));
              }
              $customerEmail = $order['customer_email'] ?? ($order['member_email'] ?? '');
              $needsAttention = (int) ($order['needs_attention'] ?? 0) > 0;
            ?>
            <tr>
              <td class="py-2 pr-3"><input type="checkbox" name="order_ids[]" value="<?= (int) $order['id'] ?>" class="rounded border-gray-300" data-bulk-item></td>
              <td class="py-2 pr-3 text-gray-900 font-medium"><?= e((string) ($order['order_number'] ?? $order['id'])) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e((string) $order['created_at']) ?></td>
              <td class="py-2 pr-3 text-gray-600">
                <?= e($customerName !== '' ? $customerName : 'Member') ?><br>
                <span class="text-xs text-slate-500"><?= e((string) $customerEmail) ?></span>
              </td>
              <td class="py-2 pr-3 text-gray-600"><?= e(ucwords(str_replace('_', ' ', (string) ($order['payment_status'] ?? 'unpaid')))) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e(ucfirst((string) ($order['fulfillment_status'] ?? 'unfulfilled'))) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e(ucfirst((string) ($order['order_status'] ?? 'new'))) ?></td>
              <td class="py-2 pr-3 text-gray-600">$<?= e(store_money((float) $order['total'])) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e(ucfirst((string) ($order['fulfillment_method'] ?? 'shipping'))) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e((string) ($order['item_count'] ?? 0)) ?></td>
              <td class="py-2 pr-3">
                <?php if ($needsAttention): ?>
                  <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">Needs attention</span>
                <?php else: ?>
                  <span class="text-xs text-slate-400">—</span>
                <?php endif; ?>
              </td>
              <td class="py-2">
                <a class="text-sm text-blue-600" href="/admin/store/orders/<?= (int) $order['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?>
            <tr>
              <td colspan="12" class="py-4 text-center text-gray-500">No orders found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between text-sm">
      <div class="text-slate-500">Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></div>
      <div class="flex gap-2">
        <?php if ($page > 1): ?>
          <a class="rounded-lg border border-gray-200 px-3 py-1" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="rounded-lg border border-gray-200 px-3 py-1" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<script>
  const bulkToggle = document.querySelector('[data-bulk-toggle]');
  const bulkItems = document.querySelectorAll('[data-bulk-item]');
  if (bulkToggle) {
    bulkToggle.addEventListener('change', () => {
      bulkItems.forEach(item => {
        item.checked = bulkToggle.checked;
      });
    });
  }
</script>
