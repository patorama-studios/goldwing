<?php
$stmt = $pdo->prepare('SELECT * FROM store_orders WHERE user_id = :user_id ORDER BY created_at DESC');
$stmt->execute(['user_id' => $user['id']]);
$orders = $stmt->fetchAll();

$pageTitle = 'Your Orders';
$heroTitle = 'Your Orders';
$heroLead = 'Track your purchases and download tickets.';
?>
<div class="grid gap-4">
  <h2>Order history</h2>
  <?php if (!$orders): ?>
    <p>You have not placed any orders yet.</p>
    <a class="button" href="/store">Browse products</a>
  <?php else: ?>
    <div class="card">
      <table class="table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Status</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td><?= e($order['order_number']) ?></td>
              <td><?= e($order['created_at']) ?></td>
              <td><?= e(ucfirst($order['status'])) ?></td>
              <td>$<?= e(store_money((float) $order['total'])) ?></td>
              <td><a class="button" href="/store/orders/<?= e($order['order_number']) ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
