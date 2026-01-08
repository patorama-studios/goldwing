<?php
use App\Services\Csrf;
use App\Services\NotificationService;
use App\Services\StripeService;

$orderNumber = $subPage ?? '';
if (isset($_GET['order'])) {
    $orderNumber = $_GET['order'];
}
$order = null;
if ($orderNumber !== '') {
    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE order_number = :order_number');
    $stmt->execute(['order_number' => $orderNumber]);
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
            $notes = trim($_POST['admin_notes'] ?? '');
            $stmt = $pdo->prepare('UPDATE store_orders SET admin_notes = :notes, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['notes' => $notes, 'id' => $order['id']]);
            $alerts[] = ['type' => 'success', 'message' => 'Notes updated.'];
        }

        if ($action === 'mark_fulfilled') {
            $stmt = $pdo->prepare('UPDATE store_orders SET status = "fulfilled", fulfilled_at = NOW(), updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $order['id']]);
            $alerts[] = ['type' => 'success', 'message' => 'Order marked fulfilled.'];
        }

        if ($action === 'save_tracking') {
            $carrier = trim($_POST['carrier'] ?? '');
            $trackingNumber = trim($_POST['tracking_number'] ?? '');
            $shippedAt = trim($_POST['shipped_at'] ?? '');
            $existing = $pdo->prepare('SELECT id FROM store_shipments WHERE order_id = :order_id LIMIT 1');
            $existing->execute(['order_id' => $order['id']]);
            $row = $existing->fetch();
            if ($row) {
                $stmt = $pdo->prepare('UPDATE store_shipments SET carrier = :carrier, tracking_number = :tracking_number, shipped_at = :shipped_at WHERE id = :id');
                $stmt->execute([
                    'carrier' => $carrier,
                    'tracking_number' => $trackingNumber,
                    'shipped_at' => $shippedAt ?: null,
                    'id' => $row['id'],
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO store_shipments (order_id, carrier, tracking_number, shipped_at, created_at) VALUES (:order_id, :carrier, :tracking_number, :shipped_at, NOW())');
                $stmt->execute([
                    'order_id' => $order['id'],
                    'carrier' => $carrier,
                    'tracking_number' => $trackingNumber,
                    'shipped_at' => $shippedAt ?: null,
                ]);
            }

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
            $alerts[] = ['type' => 'success', 'message' => 'Tracking saved and email sent.'];
        }

        if ($action === 'resend_confirmation') {
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
                $alerts[] = ['type' => 'success', 'message' => 'Confirmation email resent.'];
            }
        }

        if ($action === 'resend_tickets') {
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
                $alerts[] = ['type' => 'success', 'message' => 'Ticket email resent.'];
            }
        }

        if ($action === 'refund_payment') {
            require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/store/orders.php');
            if (!empty($order['stripe_payment_intent_id'])) {
                $refund = StripeService::createRefund($order['stripe_payment_intent_id']);
                if ($refund) {
                    $stmt = $pdo->prepare('UPDATE store_orders SET status = "refunded", updated_at = NOW() WHERE id = :id');
                    $stmt->execute(['id' => $order['id']]);
                    $alerts[] = ['type' => 'success', 'message' => 'Refund initiated in Stripe.'];
                } else {
                    $alerts[] = ['type' => 'error', 'message' => 'Refund failed. Check Stripe settings.'];
                }
            } else {
                $alerts[] = ['type' => 'error', 'message' => 'No Stripe payment intent found.'];
            }
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id');
    $stmt->execute(['id' => $order['id']]);
    $order = $stmt->fetch();
}

$stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :order_id');
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

$pageSubtitle = 'Order ' . $order['order_number'];
?>
<section class="grid gap-6 lg:grid-cols-[2fr_1fr]">
  <div class="space-y-6">
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h2 class="text-lg font-semibold text-gray-900 mb-2">Order details</h2>
      <p class="text-sm text-slate-500">Placed <?= e($order['created_at']) ?> - Status <?= e(ucfirst($order['status'])) ?></p>
      <?= store_order_address_html($order) ?>
      <?= store_order_items_html($orderItems) ?>
      <?= store_order_totals_html($order) ?>
    </div>

    <?php if ($tickets): ?>
      <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
        <?= store_ticket_list_html($tickets) ?>
      </div>
    <?php endif; ?>

    <?php if ($order['fulfillment_method'] === 'shipping'): ?>
      <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
        <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Shipment</h3>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="save_tracking">
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Carrier</label>
            <input name="carrier" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($shipment['carrier'] ?? '') ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Tracking number</label>
            <input name="tracking_number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($shipment['tracking_number'] ?? '') ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Shipped date</label>
            <input type="date" name="shipped_at" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($shippedDateValue) ?>">
          </div>
          <button class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink" type="submit">Save tracking + send email</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <aside class="space-y-6">
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-3">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="mark_fulfilled">
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Mark fulfilled</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="resend_confirmation">
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Resend confirmation</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="resend_tickets">
        <button class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium">Resend ticket email</button>
      </form>
      <form method="post" onsubmit="return confirm('Refund this order in Stripe?');">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="refund_payment">
        <button class="w-full rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600">Refund via Stripe</button>
      </form>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
      <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-3">Admin notes</h3>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="update_notes">
        <textarea name="admin_notes" rows="5" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e($order['admin_notes'] ?? '') ?></textarea>
        <button class="w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white" type="submit">Save notes</button>
      </form>
    </div>
  </aside>
</section>
