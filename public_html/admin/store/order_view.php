<?php
use App\Services\Csrf;
use App\Services\NotificationService;
use App\Services\OrderRepository;
use App\Services\RefundService;

$canManage = store_user_can($user, 'store_orders_manage');
$canRefund = store_user_can($user, 'store_refunds_manage');

$orderLookup = $subPage ?? '';
if (isset($_GET['order'])) {
    $orderLookup = $_GET['order'];
}
$order = null;
if ($orderLookup !== '') {
    if (ctype_digit((string) $orderLookup)) {
        $stmt = $pdo->prepare('SELECT o.*, m.first_name, m.last_name, m.email as member_email FROM store_orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.id = :id');
        $stmt->execute(['id' => (int) $orderLookup]);
    } else {
        $stmt = $pdo->prepare('SELECT o.*, m.first_name, m.last_name, m.email as member_email FROM store_orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.order_number = :order_number');
        $stmt->execute(['order_number' => $orderLookup]);
    }
    $order = $stmt->fetch();
}

if (!$order) {
    echo '<div class="alert error">Order not found.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_notes') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                $notes = trim($_POST['admin_notes'] ?? '');
                $stmt = $pdo->prepare('UPDATE store_orders SET admin_notes = :notes, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['notes' => $notes, 'id' => $order['id']]);
                store_add_order_event($pdo, (int) $order['id'], 'notes.updated', 'Internal notes updated.', $user['id'] ?? null);
                $alerts[] = ['type' => 'success', 'message' => 'Notes updated.'];
            }
        }

        if ($action === 'update_status') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                $newStatus = $_POST['order_status'] ?? '';
                $allowed = ['new','processing','packed','shipped','completed','cancelled'];
                if (in_array($newStatus, $allowed, true)) {
                    $stmt = $pdo->prepare('UPDATE store_orders SET order_status = :order_status, updated_at = NOW() WHERE id = :id');
                    $stmt->execute(['order_status' => $newStatus, 'id' => $order['id']]);
                    store_add_order_event($pdo, (int) $order['id'], 'order.status', 'Order status set to ' . $newStatus . '.', $user['id'] ?? null, [
                        'order_status' => $newStatus,
                    ]);
                    $alerts[] = ['type' => 'success', 'message' => 'Order status updated.'];
                }
            }
        }

        if ($action === 'ship_order') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                $carrier = trim($_POST['carrier'] ?? '');
                $trackingNumber = trim($_POST['tracking_number'] ?? '');
                $shippedAt = trim($_POST['shipped_at'] ?? '');
                $shippedAtValue = $shippedAt !== '' ? ($shippedAt . ' 00:00:00') : date('Y-m-d H:i:s');

                $existing = $pdo->prepare('SELECT id FROM store_shipments WHERE order_id = :order_id LIMIT 1');
                $existing->execute(['order_id' => $order['id']]);
                $row = $existing->fetch();
                if ($row) {
                    $stmt = $pdo->prepare('UPDATE store_shipments SET carrier = :carrier, tracking_number = :tracking_number, shipped_at = :shipped_at WHERE id = :id');
                    $stmt->execute([
                        'carrier' => $carrier,
                        'tracking_number' => $trackingNumber,
                        'shipped_at' => $shippedAtValue,
                        'id' => $row['id'],
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO store_shipments (order_id, carrier, tracking_number, shipped_at, created_at) VALUES (:order_id, :carrier, :tracking_number, :shipped_at, NOW())');
                    $stmt->execute([
                        'order_id' => $order['id'],
                        'carrier' => $carrier,
                        'tracking_number' => $trackingNumber,
                        'shipped_at' => $shippedAtValue,
                    ]);
                }

                $stmt = $pdo->prepare('UPDATE store_order_items SET fulfilled_qty = quantity WHERE order_id = :order_id');
                $stmt->execute(['order_id' => $order['id']]);

                $stmt = $pdo->prepare('UPDATE store_orders SET order_status = "shipped", shipped_at = :shipped_at, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['shipped_at' => $shippedAtValue, 'id' => $order['id']]);
                store_refresh_fulfillment_status($pdo, (int) $order['id']);

                store_add_order_event($pdo, (int) $order['id'], 'shipment.shipped', 'Order marked shipped.', $user['id'] ?? null, [
                    'carrier' => $carrier,
                    'tracking_number' => $trackingNumber,
                    'shipped_at' => $shippedAtValue,
                ]);

                $settings = store_get_settings();
                if (!empty($order['customer_email'])) {
                    NotificationService::dispatch('store_shipping_update', [
                        'primary_email' => $order['customer_email'],
                        'admin_emails' => NotificationService::getAdminEmails($settings['notification_emails'] ?? ''),
                        'order_number' => NotificationService::escape((string) $order['order_number']),
                        'carrier' => NotificationService::escape($carrier),
                        'tracking_number' => NotificationService::escape($trackingNumber),
                    ]);
                }
                $alerts[] = ['type' => 'success', 'message' => 'Shipment saved and order marked shipped.'];
            }
        }

        if ($action === 'refund_order') {
            if (!$canRefund) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to issue refunds.'];
            } else {
                require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/store/orders');
                $amountRaw = trim($_POST['refund_amount'] ?? '');
                $reason = trim($_POST['refund_reason'] ?? '');
                $remaining = OrderRepository::calculateRefundableCents((int) $order['id']);
                $amountCents = $remaining;
                if ($amountRaw !== '') {
                    $amountCents = (int) round(((float) $amountRaw) * 100);
                }
                if ($order['member_id'] === null) {
                    $alerts[] = ['type' => 'error', 'message' => 'Refunds require a linked member account.'];
                } else {
                    try {
                        RefundService::processRefund((int) $order['id'], (int) $order['member_id'], $amountCents, $reason, (int) ($user['id'] ?? 0));
                        $alerts[] = ['type' => 'success', 'message' => 'Refund processed.'];
                    } catch (Throwable $e) {
                        $alerts[] = ['type' => 'error', 'message' => $e->getMessage()];
                    }
                }
            }
        }

        if ($action === 'resend_confirmation') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :order_id');
                $stmt->execute(['order_id' => $order['id']]);
                $items = $stmt->fetchAll();
                $settings = store_get_settings();
                if (!empty($order['customer_email'])) {
                    NotificationService::dispatch('store_order_confirmation', [
                        'primary_email' => $order['customer_email'],
                        'admin_emails' => NotificationService::getAdminEmails($settings['notification_emails'] ?? ''),
                        'order_number' => NotificationService::escape((string) $order['order_number']),
                        'address_html' => store_order_address_html($order),
                        'items_html' => store_order_items_html($items),
                        'totals_html' => store_order_totals_html($order),
                    ]);
                    store_add_order_event($pdo, (int) $order['id'], 'notification.sent', 'Order confirmation resent.', $user['id'] ?? null);
                    $alerts[] = ['type' => 'success', 'message' => 'Confirmation email resent.'];
                }
            }
        }

        if ($action === 'resend_tickets') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                $stmt = $pdo->prepare('SELECT t.ticket_code, t.event_name FROM store_tickets t JOIN store_order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = :order_id');
                $stmt->execute(['order_id' => $order['id']]);
                $tickets = $stmt->fetchAll();
                if ($tickets && !empty($order['customer_email'])) {
                    $settings = store_get_settings();
                    NotificationService::dispatch('store_ticket_codes', [
                        'primary_email' => $order['customer_email'],
                        'admin_emails' => NotificationService::getAdminEmails($settings['notification_emails'] ?? ''),
                        'order_number' => NotificationService::escape((string) $order['order_number']),
                        'ticket_list_html' => store_ticket_list_html($tickets),
                    ]);
                    store_add_order_event($pdo, (int) $order['id'], 'notification.sent', 'Ticket email resent.', $user['id'] ?? null);
                    $alerts[] = ['type' => 'success', 'message' => 'Ticket email resent.'];
                }
            }
        }

        if ($action === 'cancel_order') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                $stmt = $pdo->prepare('UPDATE store_orders SET order_status = "cancelled", updated_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => $order['id']]);
                store_add_order_event($pdo, (int) $order['id'], 'order.cancelled', 'Order cancelled.', $user['id'] ?? null);
                $alerts[] = ['type' => 'success', 'message' => 'Order cancelled.'];
            }
        }
    }

    $stmt = $pdo->prepare('SELECT o.*, m.first_name, m.last_name, m.email as member_email FROM store_orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.id = :id');
    $stmt->execute(['id' => $order['id']]);
    $order = $stmt->fetch();
}

$stmt = $pdo->prepare('SELECT oi.*, (SELECT image_url FROM store_product_images i WHERE i.product_id = oi.product_id ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) as image_url FROM store_order_items oi WHERE oi.order_id = :order_id ORDER BY oi.id ASC');
$stmt->execute(['order_id' => $order['id']]);
$orderItems = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM store_shipments WHERE order_id = :order_id ORDER BY created_at DESC');
$stmt->execute(['order_id' => $order['id']]);
$shipments = $stmt->fetchAll();
$shipment = $shipments[0] ?? null;
$shippedDateValue = '';
if (!empty($shipment['shipped_at'])) {
    $shippedDateValue = substr($shipment['shipped_at'], 0, 10);
}

$stmt = $pdo->prepare('SELECT t.ticket_code, t.event_name FROM store_tickets t JOIN store_order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = :order_id ORDER BY t.id ASC');
$stmt->execute(['order_id' => $order['id']]);
$tickets = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT e.*, u.name as actor_name FROM store_order_events e LEFT JOIN users u ON u.id = e.created_by_user_id WHERE e.order_id = :order_id ORDER BY e.created_at DESC');
$stmt->execute(['order_id' => $order['id']]);
$orderEvents = $stmt->fetchAll();

$refundableCents = OrderRepository::calculateRefundableCents((int) $order['id']);
$refundableAmount = number_format($refundableCents / 100, 2);

$stmt = $pdo->prepare('SELECT * FROM store_refunds WHERE order_id = :order_id ORDER BY created_at DESC');
$stmt->execute(['order_id' => $order['id']]);
$refunds = $stmt->fetchAll();

$pageSubtitle = 'Order ' . ($order['order_number'] ?? $order['id']);
?>
<section class="grid gap-6 lg:grid-cols-[2fr_1fr]">
  <div class="space-y-6">
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-gray-900 mb-1">Order summary</h2>
          <p class="text-sm text-slate-500">Placed <?= e((string) $order['created_at']) ?></p>
        </div>
        <div class="text-sm text-slate-500">
          Payment: <span class="font-semibold text-slate-700"><?= e(ucwords(str_replace('_', ' ', (string) ($order['payment_status'] ?? 'unpaid')))) ?></span><br>
          Order status: <span class="font-semibold text-slate-700"><?= e(ucfirst((string) ($order['order_status'] ?? 'new'))) ?></span><br>
          Fulfillment: <span class="font-semibold text-slate-700"><?= e(ucfirst((string) ($order['fulfillment_status'] ?? 'unfulfilled'))) ?></span>
        </div>
      </div>
      <div class="mt-4 grid gap-4 md:grid-cols-2 text-sm text-slate-600">
        <div>
          <p><span class="font-semibold text-slate-700">Subtotal:</span> $<?= e(store_money((float) $order['subtotal'])) ?></p>
          <p><span class="font-semibold text-slate-700">Discounts:</span> $<?= e(store_money((float) $order['discount_total'])) ?></p>
          <p><span class="font-semibold text-slate-700">Shipping:</span> $<?= e(store_money((float) $order['shipping_total'])) ?></p>
          <p><span class="font-semibold text-slate-700">Processing fee:</span> $<?= e(store_money((float) $order['processing_fee_total'])) ?></p>
          <p class="font-semibold text-slate-700 mt-2">Total: $<?= e(store_money((float) $order['total'])) ?></p>
        </div>
        <div>
          <p><span class="font-semibold text-slate-700">Paid at:</span> <?= e((string) ($order['paid_at'] ?? '—')) ?></p>
          <p><span class="font-semibold text-slate-700">Shipped at:</span> <?= e((string) ($order['shipped_at'] ?? '—')) ?></p>
          <p><span class="font-semibold text-slate-700">Fulfilled at:</span> <?= e((string) ($order['fulfilled_at'] ?? '—')) ?></p>
          <p><span class="font-semibold text-slate-700">Shipping method:</span> <?= e(ucfirst((string) ($order['fulfillment_method'] ?? 'shipping'))) ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Customer</h3>
      <div class="text-sm text-slate-600">
        <?php
          $customerName = trim((string) ($order['customer_name'] ?? ''));
          if ($customerName === '') {
              $customerName = trim((string) (($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')));
          }
          $customerEmail = $order['customer_email'] ?? ($order['member_email'] ?? '');
        ?>
        <p class="font-semibold text-slate-700"><?= e($customerName !== '' ? $customerName : 'Member') ?></p>
        <p><?= e((string) $customerEmail) ?></p>
        <?php if (!empty($order['member_id'])): ?>
          <p class="mt-2"><a class="text-blue-600" href="/admin/members/view.php?id=<?= (int) $order['member_id'] ?>">View member profile</a></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Addresses</h3>
      <div class="grid gap-4 md:grid-cols-2 text-sm text-slate-600">
        <div>
          <p class="font-semibold text-slate-700 mb-2">Shipping</p>
          <?= store_order_address_html($order) ?>
        </div>
        <div>
          <p class="font-semibold text-slate-700 mb-2">Billing</p>
          <p class="text-slate-500">Billing address not collected; default to shipping.</p>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Items</h3>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="text-left text-xs uppercase text-gray-500 border-b">
            <tr>
              <th class="py-2 pr-3">Item</th>
              <th class="py-2 pr-3">SKU</th>
              <th class="py-2 pr-3">Qty</th>
              <th class="py-2 pr-3">Price</th>
              <th class="py-2 pr-3">Fulfillment</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <?php foreach ($orderItems as $item): ?>
              <?php
                $fulfilledQty = (int) ($item['fulfilled_qty'] ?? 0);
                $itemStatus = 'Unfulfilled';
                if ($fulfilledQty >= (int) $item['quantity']) {
                    $itemStatus = 'Fulfilled';
                } elseif ($fulfilledQty > 0) {
                    $itemStatus = 'Partial';
                }
              ?>
              <tr>
                <td class="py-2 pr-3 text-gray-900">
                  <div class="flex items-center gap-3">
                    <?php if (!empty($item['image_url'])): ?>
                      <img src="<?= e((string) $item['image_url']) ?>" alt="" class="h-10 w-10 rounded object-cover">
                    <?php endif; ?>
                    <div>
                      <div class="font-medium"><?= e((string) $item['title_snapshot']) ?></div>
                      <?php if (!empty($item['variant_snapshot'])): ?>
                        <div class="text-xs text-slate-500"><?= e((string) $item['variant_snapshot']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="py-2 pr-3 text-gray-600"><?= e((string) ($item['sku_snapshot'] ?? '')) ?></td>
                <td class="py-2 pr-3 text-gray-600"><?= e((string) $item['quantity']) ?></td>
                <td class="py-2 pr-3 text-gray-600">$<?= e(store_money((float) $item['unit_price_final'])) ?></td>
                <td class="py-2 pr-3 text-gray-600"><?= e($itemStatus) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$orderItems): ?>
              <tr>
                <td colspan="5" class="py-4 text-center text-gray-500">No items found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($tickets): ?>
      <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
        <?= store_ticket_list_html($tickets) ?>
      </div>
    <?php endif; ?>

    <?php if ($order['fulfillment_method'] === 'shipping'): ?>
      <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
        <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Fulfillment</h3>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="ship_order">
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Carrier</label>
            <input name="carrier" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($shipment['carrier'] ?? '')) ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Tracking number</label>
            <input name="tracking_number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($shipment['tracking_number'] ?? '')) ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Shipped date</label>
            <input type="date" name="shipped_at" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($shippedDateValue) ?>">
          </div>
          <button class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink" type="submit" <?= $canManage ? '' : 'disabled' ?>>Save tracking + mark shipped</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Refunds</h3>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="refund_order">
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Amount (AUD)</label>
          <input name="refund_amount" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Leave blank for full refund" value="">
          <p class="text-xs text-slate-500 mt-1">Refundable: $<?= e($refundableAmount) ?></p>
        </div>
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Reason</label>
          <input name="refund_reason" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Reason for refund">
        </div>
        <button class="w-full rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600" type="submit" <?= $canRefund ? '' : 'disabled' ?> onclick="return confirm('Process this refund in Stripe?');">Process refund</button>
      </form>

      <?php if ($refunds): ?>
        <div class="mt-4 text-sm text-slate-600">
          <p class="font-semibold text-slate-700 mb-2">Refund history</p>
          <ul class="space-y-2">
            <?php foreach ($refunds as $refund): ?>
              <li class="flex items-center justify-between">
                <span>#<?= e((string) $refund['id']) ?> · $<?= e(number_format(($refund['amount_cents'] ?? 0) / 100, 2)) ?> · <?= e((string) $refund['created_at']) ?></span>
                <span class="text-xs text-slate-400"><?= e((string) ($refund['status'] ?? '')) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Activity timeline</h3>
      <?php if ($orderEvents): ?>
        <ul class="space-y-3 text-sm text-slate-600">
          <?php foreach ($orderEvents as $event): ?>
            <li class="flex items-start justify-between gap-4">
              <div>
                <p class="font-semibold text-slate-700"><?= e((string) $event['message']) ?></p>
                <p class="text-xs text-slate-400"><?= e((string) ($event['event_type'] ?? '')) ?> · <?= e((string) ($event['actor_name'] ?? 'System')) ?></p>
              </div>
              <span class="text-xs text-slate-400"><?= e((string) $event['created_at']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-sm text-slate-500">No activity yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-6">
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-3">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</h3>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="update_status">
        <select name="order_status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
          <?php foreach (['new','processing','packed','shipped','completed','cancelled'] as $status): ?>
            <option value="<?= e($status) ?>" <?= ($order['order_status'] ?? 'new') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium" <?= $canManage ? '' : 'disabled' ?>>Update status</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="cancel_order">
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium" <?= $canManage ? '' : 'disabled' ?> onclick="return confirm('Cancel this order?');">Cancel order</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="resend_confirmation">
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium" <?= $canManage ? '' : 'disabled' ?>>Resend confirmation</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="resend_tickets">
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium" <?= $canManage ? '' : 'disabled' ?>>Resend ticket email</button>
      </form>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-3">Internal notes</h3>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="update_notes">
        <textarea name="admin_notes" rows="6" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e((string) ($order['admin_notes'] ?? '')) ?></textarea>
        <button class="w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white" type="submit" <?= $canManage ? '' : 'disabled' ?>>Save notes</button>
      </form>
    </div>
  </aside>
</section>
