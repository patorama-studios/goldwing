<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\OrderService;

$orderId = isset($_GET['orderId']) ? (int) $_GET['orderId'] : 0;
$order = $orderId > 0 ? OrderService::getOrderById($orderId) : null;
$orderNumber = '';
if ($order && !empty($order['shipping_address_json'])) {
    $decoded = json_decode((string) $order['shipping_address_json'], true);
    if (is_array($decoded)) {
        $orderNumber = (string) ($decoded['store_order_number'] ?? '');
    }
}

$pageTitle = 'Order Success';
require __DIR__ . '/../../../app/Views/partials/header.php';
require __DIR__ . '/../../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container hero__inner">
      <span class="hero__eyebrow">Australian Goldwing Association</span>
      <h1>Order confirmed</h1>
      <p class="hero__lead">Thanks for your purchase. We will email your receipt shortly.</p>
    </div>
  </section>
  <section class="page-section">
    <div class="container">
      <div class="page-card">
        <?php if ($orderNumber !== ''): ?>
          <p>Your order number is <strong><?= e($orderNumber) ?></strong>.</p>
        <?php elseif ($orderId > 0): ?>
          <p>Your order reference is <strong>#<?= e((string) $orderId) ?></strong>.</p>
        <?php endif; ?>
        <a class="button primary" href="/store">Continue shopping</a>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../../app/Views/partials/footer.php'; ?>
