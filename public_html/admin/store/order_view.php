<?php
if (!defined('IN_STORE_ADMIN')) exit('No direct access allowed');
use App\Services\Csrf;
use App\Services\NotificationService;
use App\Services\OrderAdminService;
use App\Services\OrderRepository;
use App\Services\PaymentWebhookService;
use App\Services\RefundService;

require_once __DIR__ . '/../../../includes/stripe_references.php';

$canManage = store_user_can($user, 'store_orders_manage');
$canRefund = store_user_can($user, 'store_refunds_manage');
$hasTable = function (string $table) use ($pdo): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
};

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
                    $markItemsFulfilled = in_array($newStatus, ['shipped', 'completed'], true);
                    store_apply_order_status($pdo, (int) $order['id'], $newStatus, null, null, $markItemsFulfilled);
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

                store_apply_order_status($pdo, (int) $order['id'], 'shipped', null, $shippedAtValue, true);

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
                $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));
                store_apply_order_status($pdo, (int) $order['id'], 'cancelled');
                store_add_order_event($pdo, (int) $order['id'], 'order.cancelled', 'Order cancelled.', $user['id'] ?? null, [
                    'reason' => $cancelReason,
                ]);
                if (!empty($order['customer_email'])) {
                    $cancelName = trim(((string) ($order['first_name'] ?? '')) . ' ' . ((string) ($order['last_name'] ?? '')));
                    if ($cancelName === '') {
                        $cancelName = (string) ($order['customer_name'] ?? '');
                    }
                    NotificationService::dispatch('store_order_cancelled', [
                        'primary_email' => $order['customer_email'],
                        'admin_emails' => NotificationService::getAdminEmails(),
                        'member_name' => $cancelName,
                        'order_number' => NotificationService::escape((string) ($order['order_number'] ?? $order['id'])),
                        'cancel_reason' => NotificationService::escape($cancelReason !== '' ? $cancelReason : 'No reason provided.'),
                    ]);
                }
                $alerts[] = ['type' => 'success', 'message' => 'Order cancelled.'];
            }
        }

        if ($action === 'mark_paid') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to mark orders paid.'];
            } else {
                $paidStatus = (string) \App\Services\SettingsService::getGlobal('store.order_paid_status', 'paid');
                if (($order['status'] ?? '') === $paidStatus) {
                    $alerts[] = ['type' => 'success', 'message' => 'Order is already marked paid.'];
                } else {
                    // Use whatever Stripe ids we already have on the row so the
                    // event log + downstream invoice link to the real transaction.
                    $existingPi = (string) ($order['stripe_payment_intent_id'] ?? '');
                    $existingSession = (string) ($order['stripe_session_id'] ?? '');
                    // Pull cart_id from the linked unified orders row metadata if present.
                    $cartId = 0;
                    try {
                        $stmt = $pdo->prepare('SELECT shipping_address_json FROM orders WHERE order_number = :n LIMIT 1');
                        $stmt->execute(['n' => $order['order_number'] ?? '']);
                        $addr = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
                        if (is_array($addr) && !empty($addr['cart_id'])) {
                            $cartId = (int) $addr['cart_id'];
                        }
                    } catch (Throwable $e) { /* ignore */ }

                    PaymentWebhookService::markStoreOrderPaid(
                        (int) $order['id'],
                        $existingPi,
                        $existingSession !== '' ? $existingSession : null,
                        $cartId
                    );

                    // Also flip the linked unified `orders` row + create the local
                    // `invoices` row, mirroring what handleInvoicePaid does after the
                    // store-side hand-off.
                    try {
                        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = :n LIMIT 1');
                        $stmt->execute(['n' => $order['order_number'] ?? '']);
                        $unifiedOrder = $stmt->fetch();
                        if ($unifiedOrder && ($unifiedOrder['status'] ?? '') !== 'paid') {
                            \App\Services\OrderService::markPaid(
                                (int) $unifiedOrder['id'],
                                $existingPi,
                                null
                            );
                            // Re-read so InvoiceService sees the freshly-paid row
                            $stmt->execute(['n' => $order['order_number'] ?? '']);
                            $unifiedOrder = $stmt->fetch();
                        }
                        if ($unifiedOrder) {
                            $invStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE order_id = :id');
                            $invStmt->execute(['id' => (int) $unifiedOrder['id']]);
                            if ((int) $invStmt->fetchColumn() === 0) {
                                \App\Services\InvoiceService::createForOrder($unifiedOrder);
                            }
                        }
                    } catch (Throwable $e) {
                        // Best-effort; the primary mark-paid already ran.
                    }
                    $alerts[] = ['type' => 'success', 'message' => 'Order marked paid. Cart converted, stock decremented, tickets generated (if any).'];
                }
            }
        }

        if ($action === 'void_order') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to void orders.'];
            } else {
                $reason = trim((string) ($_POST['void_reason'] ?? ''));
                OrderAdminService::voidStoreOrder((int) $order['id'], (int) ($user['id'] ?? 0), $reason !== '' ? $reason : null);
                OrderAdminService::sendStoreOrderVoidedNotification((int) $order['id'], $reason !== '' ? $reason : null);
                $alerts[] = ['type' => 'success', 'message' => 'Order voided. It is hidden from default lists but kept on file.'];
            }
        }

        if ($action === 'unvoid_order') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to manage orders.'];
            } else {
                OrderAdminService::unvoidStoreOrder((int) $order['id'], (int) ($user['id'] ?? 0));
                $alerts[] = ['type' => 'success', 'message' => 'Order restored.'];
            }
        }

        if ($action === 'delete_order') {
            if (!$canManage) {
                $alerts[] = ['type' => 'error', 'message' => 'You do not have permission to delete orders.'];
            } else {
                require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/store/orders');
                $confirm = strtoupper(trim((string) ($_POST['delete_confirm'] ?? '')));
                if ($confirm !== 'DELETE') {
                    $alerts[] = ['type' => 'error', 'message' => 'Type DELETE to confirm permanent removal.'];
                } else {
                    $orderNumber = (string) ($order['order_number'] ?? $order['id']);
                    OrderAdminService::deleteStoreOrder((int) $order['id']);
                    header('Location: /admin/store/orders?deleted=' . urlencode($orderNumber));
                    exit;
                }
            }
        }
    }

    $stmt = $pdo->prepare('SELECT o.*, m.first_name, m.last_name, m.email as member_email FROM store_orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.id = :id');
    $stmt->execute(['id' => $order['id']]);
    $order = $stmt->fetch();
}

$orderItems = [];
if ($hasTable('store_order_items')) {
    $stmt = $pdo->prepare('SELECT oi.*, (SELECT image_url FROM store_product_images i WHERE i.product_id = oi.product_id ORDER BY i.sort_order ASC, i.id ASC LIMIT 1) as image_url FROM store_order_items oi WHERE oi.order_id = :order_id ORDER BY oi.id ASC');
    $stmt->execute(['order_id' => $order['id']]);
    $orderItems = $stmt->fetchAll();
}

$shipments = [];
if ($hasTable('store_shipments')) {
    $stmt = $pdo->prepare('SELECT * FROM store_shipments WHERE order_id = :order_id ORDER BY created_at DESC');
    $stmt->execute(['order_id' => $order['id']]);
    $shipments = $stmt->fetchAll();
}
$shipment = $shipments[0] ?? null;
$shippedDateValue = '';
if (!empty($shipment['shipped_at'])) {
    $shippedDateValue = substr($shipment['shipped_at'], 0, 10);
}

$tickets = [];
if ($hasTable('store_tickets') && $hasTable('store_order_items')) {
    $stmt = $pdo->prepare('SELECT t.ticket_code, t.event_name FROM store_tickets t JOIN store_order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = :order_id ORDER BY t.id ASC');
    $stmt->execute(['order_id' => $order['id']]);
    $tickets = $stmt->fetchAll();
}

$orderEvents = [];
if (store_table_exists($pdo, 'store_order_events')) {
    $stmt = $pdo->prepare('SELECT e.*, u.name as actor_name FROM store_order_events e LEFT JOIN users u ON u.id = e.created_by_user_id WHERE e.order_id = :order_id ORDER BY e.created_at DESC');
    $stmt->execute(['order_id' => $order['id']]);
    $orderEvents = $stmt->fetchAll();
}

$refundableCents = OrderRepository::calculateRefundableCents((int) $order['id']);
$refundableAmount = number_format($refundableCents / 100, 2);

$refunds = [];
if (store_table_exists($pdo, 'store_refunds')) {
    $stmt = $pdo->prepare('SELECT * FROM store_refunds WHERE order_id = :order_id ORDER BY created_at DESC');
    $stmt->execute(['order_id' => $order['id']]);
    $refunds = $stmt->fetchAll();
}

$pageSubtitle = 'Order ' . ($order['order_number'] ?? $order['id']);
$isVoided = !empty($order['voided_at']);
$isPaidOrRefunded = in_array((string) ($order['payment_status'] ?? ''), ['paid', 'partial_refund', 'refunded'], true);
?>
<?php if ($isVoided): ?>
  <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    <strong>Voided</strong> on <?= e((string) $order['voided_at']) ?>
    <?php if (!empty($order['voided_reason'])): ?> &middot; <?= e((string) $order['voided_reason']) ?><?php endif; ?>
    &middot; this order is hidden from the default order list.
  </div>
<?php endif; ?>
<section class="grid gap-6 lg:grid-cols-[2fr_1fr]">
  <div class="space-y-6">
    <div data-tour="admin-process-order-summary" class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
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

    <?php
    // Stripe references — payment intent / invoice / session links to dashboard.stripe.com.
    // Pulls from both store_orders columns and the joined orders columns so we surface
    // whichever ids are populated (the new Invoice flow writes stripe_invoice_id +
    // stripe_payment_intent_id; older Checkout-Session orders only have stripe_session_id).
    $stripeRefSource = $order;
    if (!empty($order['order_number']) && empty($stripeRefSource['stripe_invoice_id'] ?? null)) {
        try {
            $stmt = $pdo->prepare('SELECT stripe_invoice_id, stripe_payment_intent_id, stripe_session_id, stripe_subscription_id, stripe_charge_id FROM orders WHERE order_number = :n LIMIT 1');
            $stmt->execute(['n' => $order['order_number']]);
            $linked = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($linked) {
                foreach ($linked as $k => $v) {
                    if (!empty($v) && empty($stripeRefSource[$k] ?? null)) {
                        $stripeRefSource[$k] = $v;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fall through with just store_orders columns; the helper handles empty.
        }
    }
    echo render_stripe_references_block($stripeRefSource, 'card');
    ?>

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

    <div data-tour="admin-process-order-items" class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
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
      <div data-tour="admin-process-order-fulfillment" class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
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
          <button data-tour="admin-process-order-ship-button" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink" type="submit" <?= $canManage ? '' : 'disabled' ?>>Save tracking + mark shipped</button>
        </form>
      </div>
    <?php endif; ?>

    <div data-tour="admin-process-order-refund" class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
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
    <?php
    // "Mark as paid" — exercises the same code path the invoice.paid webhook
    // runs, for orders that were paid on Stripe but never got the callback
    // (test mode without a test webhook endpoint, manual reconciliation, etc).
    $paymentStatusForActions = strtolower((string) ($order['payment_status'] ?? ''));
    $canMarkPaid = !in_array($paymentStatusForActions, ['paid', 'partial_refund', 'refunded'], true);
    ?>
    <?php if ($canMarkPaid): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 shadow-sm space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Manual mark-as-paid</h3>
        <p class="text-xs text-amber-700">Use when Stripe shows the payment succeeded but the webhook didn't reach us (common in test mode). Runs the same flow as <code>invoice.paid</code>: converts the cart, decrements stock, generates tickets, creates the invoice row.</p>
        <form method="post" onsubmit="return confirm('Mark this order as paid and run the post-payment side effects?');">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="mark_paid">
          <button class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" type="submit" <?= $canManage ? '' : 'disabled' ?>>Mark payment as paid</button>
        </form>
      </div>
    <?php endif; ?>

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

      <div class="border-t border-gray-100 pt-3 mt-3 space-y-3">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Database hygiene</p>
        <?php if ($isVoided): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="unvoid_order">
            <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium" <?= $canManage ? '' : 'disabled' ?>>Restore order (un-void)</button>
          </form>
        <?php else: ?>
          <form method="post" class="space-y-2">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="void_order">
            <input name="void_reason" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Reason (optional)">
            <button class="w-full rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-700" type="submit" <?= $canManage ? '' : 'disabled' ?> onclick="return confirm('Void this order? It will be hidden from default lists but kept on file.');">Void order</button>
          </form>
        <?php endif; ?>
        <form method="post" class="space-y-2" onsubmit="return confirmDelete(this);">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="delete_order">
          <input name="delete_confirm" class="w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm" placeholder='Type "DELETE" to confirm'>
          <?php if ($isPaidOrRefunded): ?>
            <p class="text-xs text-red-600">Warning: this order has been paid/refunded. Stripe records will NOT be affected.</p>
          <?php endif; ?>
          <button class="w-full rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700" type="submit" <?= $canManage ? '' : 'disabled' ?>>Permanently delete order</button>
        </form>
      </div>
    </div>
    <script>
      function confirmDelete(form) {
        var value = (form.delete_confirm.value || '').trim().toUpperCase();
        if (value !== 'DELETE') {
          alert('Type DELETE (in capitals) to confirm permanent removal.');
          return false;
        }
        return confirm('This will permanently delete the order, its items, events, refunds, and shipments. Continue?');
      }
    </script>

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
