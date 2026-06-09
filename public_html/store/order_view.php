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
    ?>
    <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
      <span class="material-icons-outlined text-5xl text-gray-300">search_off</span>
      <h2 class="mt-3 text-xl font-semibold text-gray-900">Order not found</h2>
      <p class="mt-1 text-gray-500">We couldn't find that order under your account.</p>
      <a href="/store/orders" class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold transition-colors">Back to order history</a>
    </section>
    <?php
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

$itemImages = [];
if ($orderItems) {
    $productIds = array_filter(array_unique(array_map(function ($i) { return (int) $i['product_id']; }, $orderItems)));
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $imgStmt = $pdo->prepare("SELECT product_id, image_url FROM store_product_images WHERE product_id IN ($placeholders) ORDER BY product_id, sort_order ASC, id ASC");
        $imgStmt->execute(array_values($productIds));
        foreach ($imgStmt->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($itemImages[$pid])) {
                $itemImages[$pid] = $row['image_url'];
            }
        }
    }
}

$pageTitle = 'Order ' . $order['order_number'];

$justPaid = isset($_GET['success']) && $_GET['success'] === '1';
$stripeRedirectStatus = isset($_GET['redirect_status']) ? (string) $_GET['redirect_status'] : '';
$isPaid = ($order['status'] ?? '') === 'paid' || ($order['payment_status'] ?? '') === 'paid';
$paymentFailed = $stripeRedirectStatus === 'failed';
$autoRefresh = $justPaid && !$isPaid && !$paymentFailed && $stripeRedirectStatus !== 'requires_payment_method';

$statusClass = 'bg-amber-100 text-amber-900 border-amber-200';
$statusLabel = ucfirst((string) ($order['status'] ?? 'pending'));
$s = strtolower((string) ($order['status'] ?? ''));
if (in_array($s, ['paid', 'completed', 'shipped'], true)) {
    $statusClass = 'bg-green-100 text-green-800 border-green-200';
} elseif (in_array($s, ['processing', 'packed'], true)) {
    $statusClass = 'bg-blue-100 text-blue-800 border-blue-200';
} elseif (in_array($s, ['cancelled', 'refunded', 'failed'], true)) {
    $statusClass = 'bg-red-100 text-red-800 border-red-200';
}
?>

<nav class="flex items-center gap-2 text-sm text-gray-500" aria-label="Breadcrumb">
  <a href="/store" class="hover:text-gray-700">Store</a>
  <span class="material-icons-outlined text-base">chevron_right</span>
  <a href="/store/orders" class="hover:text-gray-700">Orders</a>
  <span class="material-icons-outlined text-base">chevron_right</span>
  <span class="text-gray-900 font-semibold font-mono"><?= e((string) $order['order_number']) ?></span>
</nav>

<?php if ($paymentFailed): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
    <span class="material-icons-outlined text-red-600">error_outline</span>
    <div>Payment was not successful. <a href="/checkout" class="underline font-semibold">Return to checkout</a> to try again.</div>
  </div>
<?php elseif ($stripeRedirectStatus === 'requires_payment_method'): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
    <span class="material-icons-outlined text-red-600">credit_card_off</span>
    <div>Your card was declined. <a href="/checkout" class="underline font-semibold">Return to checkout</a> to try a different card.</div>
  </div>
<?php elseif ($justPaid && $isPaid): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
    <span class="material-icons-outlined text-green-600">check_circle</span>
    <div>Thank you — your payment has been received and your order is confirmed.</div>
  </div>
<?php elseif ($autoRefresh): ?>
  <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
    <span class="material-icons-outlined text-blue-600">hourglass_top</span>
    <div>Your payment was received. Finalising your order — this page will refresh in a few seconds...</div>
  </div>
  <meta http-equiv="refresh" content="5">
<?php endif; ?>

<section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Order</p>
      <p class="font-mono text-2xl font-bold text-gray-900"><?= e((string) $order['order_number']) ?></p>
      <p class="text-sm text-gray-500 mt-1"><?= e(!empty($order['created_at']) ? date('j M Y · g:ia', strtotime($order['created_at'])) : '') ?></p>
    </div>
    <div class="flex items-center gap-3">
      <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider border <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
      <div class="text-right">
        <p class="text-xs text-gray-500">Total</p>
        <p class="text-xl font-bold text-gray-900">$<?= e(store_money((float) $order['total'])) ?></p>
      </div>
    </div>
  </div>
</section>

<div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6 items-start">
  <div class="space-y-4">
    <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="font-display text-lg font-semibold text-gray-900">Items</h2>
      </div>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($orderItems as $item):
          $imgUrl = $itemImages[(int) ($item['product_id'] ?? 0)] ?? '';
        ?>
          <li class="flex items-start gap-4 px-6 py-4">
            <div class="w-14 h-14 rounded-lg bg-gray-50 border border-gray-100 overflow-hidden shrink-0 relative">
              <?php if ($imgUrl): ?>
                <img src="<?= e($imgUrl) ?>" alt="" class="w-full h-full object-cover">
              <?php else: ?>
                <div class="absolute inset-0 flex items-center justify-center">
                  <span class="material-icons-outlined text-gray-300">inventory_2</span>
                </div>
              <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-gray-900"><?= e($item['title_snapshot']) ?></p>
              <?php if (!empty($item['variant_snapshot'])): ?>
                <p class="text-xs text-gray-500"><?= e($item['variant_snapshot']) ?></p>
              <?php endif; ?>
              <p class="text-xs text-gray-500 mt-0.5">Qty <?= e((string) (int) $item['quantity']) ?> · $<?= e(store_money((float) $item['unit_price_final'])) ?> ea</p>
            </div>
            <div class="text-sm font-semibold text-gray-900 whitespace-nowrap">$<?= e(store_money((float) $item['line_total'])) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <?php if (($order['fulfillment_method'] ?? '') === 'pickup'): ?>
      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
          <span class="material-icons-outlined text-gray-400">store</span>
          Pickup
        </h2>
        <p class="text-sm text-gray-700 whitespace-pre-line"><?= e((string) ($order['pickup_instructions_snapshot'] ?? '')) ?></p>
      </section>
    <?php elseif (!empty($order['shipping_address_line1'])): ?>
      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
          <span class="material-icons-outlined text-gray-400">local_shipping</span>
          Shipping to
        </h2>
        <div class="text-sm text-gray-700 leading-6">
          <div class="font-medium text-gray-900"><?= e((string) ($order['shipping_name'] ?? '')) ?></div>
          <div><?= e((string) $order['shipping_address_line1']) ?></div>
          <?php if (!empty($order['shipping_address_line2'])): ?>
            <div><?= e((string) $order['shipping_address_line2']) ?></div>
          <?php endif; ?>
          <div><?= e(trim(((string) ($order['shipping_city'] ?? '')) . ' ' . ((string) ($order['shipping_state'] ?? '')) . ' ' . ((string) ($order['shipping_postal_code'] ?? '')))) ?></div>
          <div class="text-gray-500"><?= e((string) ($order['shipping_country'] ?? '')) ?></div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($shipments): ?>
      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
          <span class="material-icons-outlined text-gray-400">local_shipping</span>
          Shipment tracking
        </h2>
        <ul class="space-y-3">
          <?php foreach ($shipments as $shipment): ?>
            <li class="border border-gray-100 rounded-xl p-4 bg-gray-50">
              <p class="text-sm text-gray-700"><span class="font-semibold">Carrier:</span> <?= e($shipment['carrier'] ?? 'Carrier') ?></p>
              <p class="text-sm text-gray-700"><span class="font-semibold">Tracking:</span> <?= e($shipment['tracking_number'] ?? 'N/A') ?></p>
              <p class="text-sm text-gray-700"><span class="font-semibold">Shipped:</span> <?= e($shipment['shipped_at'] ?? 'Pending') ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if ($tickets): ?>
      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
          <span class="material-icons-outlined text-gray-400">confirmation_number</span>
          Ticket codes
        </h2>
        <ul class="space-y-2">
          <?php foreach ($tickets as $ticket): ?>
            <li class="flex items-center justify-between bg-primary/5 border border-primary/20 rounded-xl px-4 py-3">
              <span class="text-sm text-gray-700"><?= e($ticket['event_name'] ?? $ticket['title_snapshot']) ?></span>
              <span class="font-mono text-sm font-bold text-gray-900"><?= e($ticket['ticket_code']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>

  <aside class="lg:sticky lg:top-6 self-start">
    <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="font-display text-lg font-semibold text-gray-900">Order totals</h2>
      </div>
      <div class="px-6 py-4 space-y-2 text-sm">
        <div class="flex justify-between text-gray-700">
          <span>Subtotal</span>
          <span>$<?= e(store_money((float) $order['subtotal'])) ?></span>
        </div>
        <?php if ((float) ($order['discount_total'] ?? 0) > 0): ?>
          <div class="flex justify-between text-green-700">
            <span>Discount</span>
            <span>-$<?= e(store_money((float) $order['discount_total'])) ?></span>
          </div>
        <?php endif; ?>
        <div class="flex justify-between text-gray-700">
          <span>Shipping</span>
          <span>$<?= e(store_money((float) $order['shipping_total'])) ?></span>
        </div>
        <?php if ((float) ($order['processing_fee_total'] ?? 0) > 0): ?>
          <div class="flex justify-between text-gray-700">
            <span>Processing fee</span>
            <span>$<?= e(store_money((float) $order['processing_fee_total'])) ?></span>
          </div>
        <?php endif; ?>
        <div class="flex justify-between pt-3 mt-2 border-t border-gray-200 text-base font-bold text-gray-900">
          <span>Total</span>
          <span>$<?= e(store_money((float) $order['total'])) ?></span>
        </div>
      </div>
    </section>
  </aside>
</div>
