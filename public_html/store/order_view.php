<?php
$orderNumber = $subPage ?? '';
if (isset($_GET['order'])) {
    $orderNumber = $_GET['order'];
}
$order = null;
if ($orderNumber !== '') {
    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE order_number = :order_number AND user_id = :user_id');
    $stmt->execute(['order_number' => $orderNumber, 'user_id' => $user['id']]);
    $order = $stmt->fetch();
}

if (!$order) {
    echo '<div class="alert error">Order not found.</div>';
    return;
}

$stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :order_id');
$stmt->execute(['order_id' => $order['id']]);
$orderItems = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM store_shipments WHERE order_id = :order_id ORDER BY created_at DESC');
$stmt->execute(['order_id' => $order['id']]);
$shipments = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT t.ticket_code, t.event_name, oi.title_snapshot FROM store_tickets t JOIN store_order_items oi ON oi.id = t.order_item_id WHERE oi.order_id = :order_id ORDER BY t.id ASC');
$stmt->execute(['order_id' => $order['id']]);
$tickets = $stmt->fetchAll();

$pageTitle = 'Order ' . $order['order_number'];
$heroTitle = 'Order ' . $order['order_number'];
$heroLead = 'Status: ' . ucfirst($order['status']);
?>
<div class="grid gap-6">
  <div class="card">
    <h3>Order summary</h3>
    <p>Status: <strong><?= e(ucfirst($order['status'])) ?></strong></p>
    <p>Placed: <?= e($order['created_at']) ?></p>
    <?= store_order_address_html($order) ?>
  </div>

  <div class="card">
    <h3>Items</h3>
    <table class="table">
      <thead>
        <tr>
          <th>Item</th>
          <th>Qty</th>
          <th>Price</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orderItems as $item): ?>
          <tr>
            <td>
              <?= e($item['title_snapshot']) ?>
              <?php if (!empty($item['variant_snapshot'])): ?>
                <div style="font-size:0.85rem; color: var(--muted);"><?= e($item['variant_snapshot']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e((string) $item['quantity']) ?></td>
            <td>$<?= e(store_money((float) $item['unit_price_final'])) ?></td>
            <td>$<?= e(store_money((float) $item['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?= store_order_totals_html($order) ?>
  </div>

  <?php if ($shipments): ?>
    <div class="card">
      <h3>Shipment tracking</h3>
      <?php foreach ($shipments as $shipment): ?>
        <p>
          Carrier: <?= e($shipment['carrier'] ?? 'Carrier') ?><br>
          Tracking: <?= e($shipment['tracking_number'] ?? 'N/A') ?><br>
          Shipped: <?= e($shipment['shipped_at'] ?? 'Pending') ?>
        </p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($tickets): ?>
    <div class="card">
      <h3>Ticket codes</h3>
      <table class="table">
        <thead>
          <tr>
            <th>Event</th>
            <th>Ticket code</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $ticket): ?>
            <tr>
              <td><?= e($ticket['event_name'] ?? $ticket['title_snapshot']) ?></td>
              <td><strong><?= e($ticket['ticket_code']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
