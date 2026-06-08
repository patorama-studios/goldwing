<?php
/**
 * Admin tool: take a pending store order, build a Stripe Invoice for it via
 * StoreInvoiceService, and surface the hosted invoice URL so it can be copied
 * or emailed to the customer.
 *
 * Use cases:
 *   - "Rob's order got stuck in the broken-checkout era — send him a payment link"
 *   - Phone/email order taken offline — quote and invoice via Stripe
 *   - Customer's card declined on the on-page Element — give them a hosted
 *     Stripe page to retry on without re-entering the cart
 *
 * The tool is idempotent: clicking "Generate" on the same order twice reuses
 * the existing draft/open Stripe Invoice instead of creating a duplicate.
 *
 * GET:  show a search box + (if `order` is in the query string) the order
 *       detail + a Generate button + the hosted URL if already generated.
 * POST: action=generate → call StoreInvoiceService and persist; reload.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ThirdParty/stripe-php/init.php';

use App\Services\Csrf;
use App\Services\Database;
use App\Services\StoreInvoiceService;
use App\Services\StripeService;

require_permission('admin.settings.general.manage');

$pdo = Database::connection();
$orderNumber = trim((string) ($_GET['order'] ?? $_POST['order'] ?? ''));

$flashError = '';
$flashSuccess = '';
$order = null;
$items = [];
$invoiceUrl = null;
$invoiceId = null;
$paymentIntentId = null;

if ($orderNumber !== '') {
    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE order_number = :n LIMIT 1');
    $stmt->execute([':n' => $orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$order) {
        $flashError = 'No store_order found for ' . $orderNumber . '.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :id ORDER BY id ASC');
        $stmt->execute([':id' => $order['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $flashError = 'Invalid CSRF token. Reload and try again.';
    } elseif (!$order) {
        $flashError = 'Pick an order first.';
    } elseif (($_POST['action'] ?? '') === 'generate') {
        try {
            // Try to find the live cart attached to the user (so the cart can be
            // converted by the invoice.paid webhook on payment)
            $cartId = 0;
            if (!empty($order['user_id'])) {
                $stmt = $pdo->prepare("SELECT id FROM store_carts WHERE user_id = :uid AND status = 'active' ORDER BY id DESC LIMIT 1");
                $stmt->execute([':uid' => (int) $order['user_id']]);
                $cartId = (int) ($stmt->fetchColumn() ?: 0);
            }
            $bundle = StoreInvoiceService::ensureInvoiceForOrder((int) $order['id'], $cartId > 0 ? $cartId : null);
            $invoiceUrl = $bundle['invoice_url'] ?? null;
            $invoiceId = $bundle['invoice_id'] ?? null;
            $paymentIntentId = $bundle['payment_intent_id'] ?? null;
            $flashSuccess = 'Stripe invoice ready — copy the link below or email it to the customer.';
            // refresh order row to pick up updated stripe_invoice_id / pi
            $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $order['id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: $order;
        } catch (\Throwable $e) {
            $flashError = 'Stripe error: ' . $e->getMessage();
        }
    }
}

// If the order already has a stripe_invoice_id but we haven't fetched the URL
// this turn (e.g. on a fresh GET), pull it now so the page shows what's live.
if ($order && empty($invoiceUrl) && !empty($order['stripe_invoice_id'])) {
    $existing = StripeService::retrieveInvoice($order['stripe_invoice_id']);
    if ($existing) {
        $invoiceId = $existing['id'] ?? null;
        $invoiceUrl = $existing['hosted_invoice_url'] ?? null;
        $piRef = $existing['payment_intent'] ?? null;
        if (is_string($piRef) && $piRef !== '') {
            $paymentIntentId = $piRef;
        } elseif (is_array($piRef) && !empty($piRef['id'])) {
            $paymentIntentId = $piRef['id'];
        }
        $invoiceStatus = $existing['status'] ?? null;
    }
}

$csrf = Csrf::token();
$customerEmail = $order['customer_email'] ?? '';
$customerName = $order['customer_name'] ?? '';

$mailtoSubject = 'Your Goldwing order ' . ($order['order_number'] ?? '') . ' — payment link';
$mailtoBodyLines = [];
if ($customerName !== '') {
    $mailtoBodyLines[] = 'Hi ' . $customerName . ',';
} else {
    $mailtoBodyLines[] = 'Hi,';
}
$mailtoBodyLines[] = '';
$mailtoBodyLines[] = "Here's the secure payment link for your order " . ($order['order_number'] ?? '') . ':';
$mailtoBodyLines[] = '';
$mailtoBodyLines[] = $invoiceUrl ?? '(link not yet generated)';
$mailtoBodyLines[] = '';
$mailtoBodyLines[] = "Click the link to view the itemized invoice and pay securely with Stripe. Reply to this email if you have any questions.";
$mailtoBodyLines[] = '';
$mailtoBodyLines[] = 'Thanks,';
$mailtoBodyLines[] = 'Australian Goldwing Association';
$mailtoBody = implode("\n", $mailtoBodyLines);
$mailtoHref = 'mailto:' . rawurlencode($customerEmail)
    . '?subject=' . rawurlencode($mailtoSubject)
    . '&body=' . rawurlencode($mailtoBody);

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Send store-order invoice link — Admin</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    body { font-family: system-ui, sans-serif; background: #f8fafc; padding: 2rem; }
    .wrap { max-width: 800px; margin: 0 auto; display: grid; gap: 1.25rem; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; }
    h1 { font-size: 1.4rem; font-weight: 700; margin: 0 0 1rem; }
    h2 { font-size: 1rem; font-weight: 600; margin: 0 0 .75rem; color: #1c1a17; }
    form.row { display: flex; gap: .5rem; align-items: center; }
    form.row input[type="text"] { flex: 1; padding: .55rem .75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .9rem; }
    button, .btn { background: #1c1a17; color: #fff; border: 0; padding: .55rem 1rem; border-radius: 8px; font-size: .9rem; cursor: pointer; text-decoration: none; display: inline-block; }
    button.secondary, .btn.secondary { background: #f1f5f9; color: #1c1a17; border: 1px solid #cbd5e1; }
    .alert { padding: .75rem 1rem; border-radius: 8px; font-size: .9rem; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    table.summary { width: 100%; border-collapse: collapse; font-size: .9rem; }
    table.summary th, table.summary td { padding: .4rem .6rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
    table.summary th { background: #f8fafc; font-weight: 500; color: #64748b; }
    .url-box { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; padding: .75rem; font-family: ui-monospace, monospace; font-size: .8rem; word-break: break-all; }
    .note { font-size: .8rem; color: #94a3b8; }
    .actions { display: flex; gap: .5rem; margin-top: 1rem; flex-wrap: wrap; }
    .back { display: inline-block; margin-top: 1rem; font-size: .85rem; color: #3b82f6; text-decoration: none; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>📧 Send store-order invoice link</h1>
      <p class="note">Look up a <code>store_orders</code> row by its order number, build a Stripe Invoice with itemized line items via the new Invoice flow, then copy the hosted URL or fire it off in your mail client. Idempotent — running this on an order that already has a draft/open invoice reuses the existing one.</p>
      <form class="row" method="GET" action="">
        <input type="text" name="order" value="<?= htmlspecialchars($orderNumber) ?>" placeholder="e.g. M-2026-000034" autofocus>
        <button type="submit">Look up</button>
      </form>
    </div>

    <?php if ($flashError): ?><div class="alert error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>
    <?php if ($flashSuccess): ?><div class="alert success"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>

    <?php if ($order): ?>
      <div class="card">
        <h2>Order <?= htmlspecialchars((string) $order['order_number']) ?></h2>
        <table class="summary">
          <tr><th>Status</th><td><?= htmlspecialchars((string) ($order['status'] ?? '')) ?></td></tr>
          <tr><th>Customer</th><td><?= htmlspecialchars((string) $customerName) ?> &lt;<?= htmlspecialchars((string) $customerEmail) ?>&gt;</td></tr>
          <tr><th>Total</th><td>$<?= number_format((float) ($order['total'] ?? 0), 2) ?></td></tr>
          <tr><th>Created</th><td><?= htmlspecialchars((string) ($order['created_at'] ?? '')) ?></td></tr>
          <?php if (!empty($order['stripe_invoice_id'])): ?>
            <tr><th>Existing invoice id</th><td><code><?= htmlspecialchars((string) $order['stripe_invoice_id']) ?></code><?= isset($invoiceStatus) ? ' — status: ' . htmlspecialchars((string) $invoiceStatus) : '' ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($order['stripe_payment_intent_id'])): ?>
            <tr><th>Existing PI id</th><td><code><?= htmlspecialchars((string) $order['stripe_payment_intent_id']) ?></code></td></tr>
          <?php endif; ?>
        </table>

        <?php if ($items): ?>
          <h2 style="margin-top:1.25rem;">Items (<?= count($items) ?>)</h2>
          <table class="summary">
            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line total</th></tr></thead>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($item['title_snapshot'] ?? '')) ?><?= !empty($item['variant_snapshot']) ? ' <small>(' . htmlspecialchars($item['variant_snapshot']) . ')</small>' : '' ?></td>
                <td><?= htmlspecialchars((string) ($item['quantity'] ?? '')) ?></td>
                <td>$<?= number_format((float) ($item['unit_price_final'] ?? 0), 2) ?></td>
                <td>$<?= number_format((float) ($item['line_total'] ?? 0), 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>

        <form method="POST" action="" class="actions" style="margin-top:1.25rem;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="order" value="<?= htmlspecialchars((string) $order['order_number']) ?>">
          <input type="hidden" name="action" value="generate">
          <button type="submit">🧾 <?= $invoiceUrl ? 'Re-fetch invoice link' : 'Generate Stripe invoice' ?></button>
          <?php if ($invoiceUrl): ?>
            <a class="btn secondary" href="<?= htmlspecialchars($invoiceUrl) ?>" target="_blank" rel="noopener">Open invoice on Stripe ↗</a>
            <a class="btn secondary" href="<?= $mailtoHref ?>">📧 Email link to <?= htmlspecialchars($customerEmail) ?></a>
          <?php endif; ?>
        </form>

        <?php if ($invoiceUrl): ?>
          <h2 style="margin-top:1.25rem;">Hosted invoice URL</h2>
          <div class="url-box" id="urlBox"><?= htmlspecialchars($invoiceUrl) ?></div>
          <div class="actions" style="margin-top:.5rem;">
            <button class="secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('urlBox').textContent.trim()); this.textContent='Copied ✓'; setTimeout(()=>this.textContent='📋 Copy URL', 2000);">📋 Copy URL</button>
          </div>
          <?php if ($invoiceId): ?>
            <p class="note" style="margin-top:.5rem;">Stripe invoice <code><?= htmlspecialchars($invoiceId) ?></code><?= $paymentIntentId ? ' · PI <code>' . htmlspecialchars($paymentIntentId) . '</code>' : '' ?></p>
          <?php endif; ?>
        <?php else: ?>
          <p class="note" style="margin-top:1rem;">Click <strong>Generate Stripe invoice</strong> to create the invoice + line items in Stripe. The customer pays via Stripe's hosted invoice page (no need to revisit goldwing.org.au). The <code>invoice.paid</code> webhook will finalize the order and convert the cart automatically.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <a class="back" href="/admin/">← Back to admin</a>
  </div>
</body>
</html>
