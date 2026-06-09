<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\OrderService;
use App\Services\BaseUrlService;

require_login();

$pdo = db();
$user = current_user();

$orderNumber = isset($_GET['order']) ? trim((string) $_GET['order']) : '';
$orderId = isset($_GET['orderId']) ? (int) $_GET['orderId'] : 0;

$order = null;
if ($orderNumber !== '') {
    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE order_number = :n AND user_id = :u');
    $stmt->execute(['n' => $orderNumber, 'u' => $user['id']]);
    $order = $stmt->fetch();
}

if (!$order && $orderId > 0) {
    $legacy = OrderService::getOrderById($orderId);
    if ($legacy && !empty($legacy['shipping_address_json'])) {
        $decoded = json_decode((string) $legacy['shipping_address_json'], true);
        if (is_array($decoded) && !empty($decoded['store_order_number'])) {
            $orderNumber = (string) $decoded['store_order_number'];
            $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE order_number = :n AND user_id = :u');
            $stmt->execute(['n' => $orderNumber, 'u' => $user['id']]);
            $order = $stmt->fetch();
        }
    }
}

$orderItems = [];
if ($order) {
    $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :id ORDER BY id ASC');
    $stmt->execute(['id' => $order['id']]);
    $orderItems = $stmt->fetchAll();
}

$stripeRedirectStatus = isset($_GET['redirect_status']) ? (string) $_GET['redirect_status'] : '';
$paymentFailed = $stripeRedirectStatus === 'failed' || $stripeRedirectStatus === 'requires_payment_method';
$isPaid = $order && (($order['status'] ?? '') === 'paid' || ($order['payment_status'] ?? '') === 'paid');
$awaitingWebhook = $order && !$isPaid && !$paymentFailed;
$bankTransfer = $order && (($order['fulfillment_method'] ?? '') === 'pickup' || stripos((string) ($order['payment_method'] ?? ''), 'bank') !== false);

$mainSiteUrl = BaseUrlService::configuredBaseUrl();
if ($mainSiteUrl === '') {
    $mainSiteUrl = '/';
}

$pageTitle = 'Order confirmed';
$activePage = 'store';
$activeSubPage = 'orders';

require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Thank you';
    require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-6">

      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-8 md:p-10 text-center">
        <?php if ($paymentFailed): ?>
          <div class="mx-auto w-20 h-20 rounded-full bg-red-50 flex items-center justify-center mb-5">
            <span class="material-icons-outlined text-5xl text-red-500">error_outline</span>
          </div>
          <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900">Payment unsuccessful</h1>
          <p class="text-gray-500 mt-2">Your card was declined or the payment didn't go through. No order was placed.</p>
        <?php else: ?>
          <div class="mx-auto w-20 h-20 rounded-full bg-green-50 flex items-center justify-center mb-5">
            <span class="material-icons-outlined text-5xl text-green-600">check_circle</span>
          </div>
          <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900">Thanks for your order!</h1>
          <?php if ($bankTransfer): ?>
            <p class="text-gray-500 mt-2">We've recorded your order. It will be confirmed once your bank transfer clears.</p>
          <?php elseif ($awaitingWebhook): ?>
            <p class="text-gray-500 mt-2">Your payment was received. We're finalising your order — it'll appear in your history shortly.</p>
          <?php else: ?>
            <p class="text-gray-500 mt-2">Your payment has been received. A receipt is on its way to <strong class="text-gray-700"><?= e($user['email'] ?? 'your email') ?></strong>.</p>
          <?php endif; ?>

          <?php if ($order): ?>
            <div class="mt-6 inline-flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-5 py-3">
              <span class="text-xs uppercase tracking-wider text-gray-500">Order number</span>
              <span class="font-mono text-base font-semibold text-gray-900"><?= e((string) $order['order_number']) ?></span>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <?php if ($order && $orderItems && !$paymentFailed): ?>
        <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="font-display text-lg font-semibold text-gray-900">What you ordered</h2>
          </div>
          <ul class="divide-y divide-gray-100">
            <?php foreach ($orderItems as $item): ?>
              <li class="flex items-start gap-4 px-6 py-4">
                <div class="w-12 h-12 rounded-lg bg-gray-100 flex items-center justify-center shrink-0">
                  <span class="material-icons-outlined text-gray-400">inventory_2</span>
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
          <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
            <span class="text-sm text-gray-500">Total paid</span>
            <span class="text-lg font-bold text-gray-900">$<?= e(store_money((float) $order['total'])) ?></span>
          </div>
        </section>

        <?php if (!empty($order['shipping_address_line1'])): ?>
          <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
            <h2 class="font-display text-lg font-semibold text-gray-900 mb-2">Shipping to</h2>
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
      <?php endif; ?>

      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-display text-lg font-semibold text-gray-900 mb-3">What's next?</h2>
        <ul class="space-y-2 text-sm text-gray-700">
          <li class="flex items-start gap-2"><span class="material-icons-outlined text-base text-gray-400 mt-0.5">mail_outline</span><span>We've emailed your receipt and order details.</span></li>
          <?php if ($bankTransfer): ?>
            <li class="flex items-start gap-2"><span class="material-icons-outlined text-base text-gray-400 mt-0.5">account_balance</span><span>Once your bank transfer is received, we'll mark your order paid and start fulfilment.</span></li>
          <?php else: ?>
            <li class="flex items-start gap-2"><span class="material-icons-outlined text-base text-gray-400 mt-0.5">local_shipping</span><span>You'll get an update with tracking once your order ships.</span></li>
          <?php endif; ?>
          <li class="flex items-start gap-2"><span class="material-icons-outlined text-base text-gray-400 mt-0.5">history</span><span>You can view all your orders anytime from the Store &rsaquo; Order history.</span></li>
        </ul>
      </section>

      <div class="flex flex-col sm:flex-row gap-3 pt-2">
        <a href="/member/index.php" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors">
          <span class="material-icons-outlined">dashboard</span>
          Back to dashboard
        </a>
        <a href="<?= e($mainSiteUrl) ?>" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold transition-colors">
          <span class="material-icons-outlined">public</span>
          Main website
        </a>
        <?php if ($order): ?>
          <a href="/store/orders/<?= e((string) $order['order_number']) ?>" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold transition-colors">
            <span class="material-icons-outlined">receipt_long</span>
            View order details
          </a>
        <?php endif; ?>
      </div>

      <?php if ($paymentFailed): ?>
        <div class="text-center pt-2">
          <a href="/checkout" class="text-sm font-semibold text-gray-700 hover:text-gray-900 underline">Try checkout again</a>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
