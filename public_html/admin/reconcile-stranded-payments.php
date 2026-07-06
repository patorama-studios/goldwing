<?php
/**
 * Admin tool: reconcile membership renewals that were CHARGED in Stripe but
 * never activated — the "paid but still PENDING, no receipt" state.
 *
 * Background: during the July 2026 renewal outage the /api/* admin catch-all
 * returned 401 to Stripe's anonymous invoice.paid webhook POSTs, so
 * PaymentWebhookService::handleInvoicePaid never ran. The member's card was
 * charged (the on-page Payment Element confirmed the invoice's PaymentIntent),
 * but the order stayed PENDING, the membership never activated, no receipt went
 * out, and the confirmation popup fell back to their old coverage. The gate is
 * now whitelisted (see access_control_is_always_allowed) so NEW renewals are
 * fine — this tool cleans up the ones stranded in the window.
 *
 * What it does: finds every non-paid membership order that carries a
 * stripe_invoice_id, checks the REAL invoice in Stripe, and — only when Stripe
 * confirms it was actually paid — replays it through the exact same
 * handleInvoicePaid path a live webhook would take. That stacks the correct
 * term, flips the order to paid, and dispatches the receipt.
 *
 * Safety:
 *   - Never activates unless Stripe itself reports the invoice paid in full.
 *   - Skips any invoice where a sibling order is already activated (a partial
 *     heal) — those are surfaced for manual review, not auto-touched.
 *   - Idempotent: each reconcile records a synthetic webhook_events row
 *     (id "reconcile_<invoice>"), so re-clicking is a no-op; and
 *     handleInvoicePaid now skips already-paid orders, so a later real Stripe
 *     retry can't double the term.
 *
 * Usage: /admin/reconcile-stranded-payments.php
 */
if (function_exists('opcache_reset')) { @opcache_reset(); }

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ThirdParty/stripe-php/init.php';

use App\Services\Csrf;
use App\Services\Database;
use App\Services\StripeService;
use App\Services\PaymentWebhookService;

require_permission('admin.settings.general.manage');

$pdo = Database::connection();
$flashError = '';
$flashSuccess = '';
$actionResults = []; // invoiceId => ['ok'=>bool,'msg'=>string]

// Non-paid membership orders that carry a Stripe invoice id. cancelled/refunded
// are excluded so we never re-activate something deliberately reversed.
$candidateSql = "SELECT o.*, m.first_name, m.last_name, m.email AS member_email
                 FROM orders o
                 LEFT JOIN members m ON m.id = o.member_id
                 WHERE o.order_type = 'membership'
                   AND o.stripe_invoice_id IS NOT NULL AND o.stripe_invoice_id <> ''
                   AND o.status NOT IN ('paid', 'cancelled', 'refunded', 'void')
                 ORDER BY o.created_at DESC";

$loadCandidates = static function () use ($pdo, $candidateSql): array {
    $rows = $pdo->query($candidateSql)->fetchAll(PDO::FETCH_ASSOC);
    $byInvoice = [];
    foreach ($rows as $r) {
        $byInvoice[(string) $r['stripe_invoice_id']][] = $r;
    }
    return $byInvoice;
};

// Pull the real invoice from Stripe and decide whether it's safe to activate.
$assessInvoice = static function (string $invoiceId) use ($pdo): array {
    $inv = StripeService::retrieveInvoice($invoiceId);
    if (!$inv) {
        return ['eligible' => false, 'reason' => 'Could not retrieve this invoice from Stripe.', 'invoice' => null];
    }
    $status = (string) ($inv['status'] ?? '');
    $paidCents = (int) ($inv['amount_paid'] ?? 0);
    $dueCents = (int) ($inv['amount_due'] ?? 0);
    $isPaid = $status === 'paid' || ($paidCents > 0 && $paidCents >= $dueCents);

    $sib = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE stripe_invoice_id = :inv AND order_type = 'membership' AND status = 'paid'");
    $sib->execute([':inv' => $invoiceId]);
    $hasPaidSibling = (int) $sib->fetchColumn() > 0;

    if (!$isPaid) {
        return ['eligible' => false, 'reason' => 'Stripe says NOT paid (status: ' . ($status ?: 'unknown') . ').', 'invoice' => $inv];
    }
    if ($hasPaidSibling) {
        return ['eligible' => false, 'reason' => 'Another order on this invoice is already activated — reconcile manually.', 'invoice' => $inv];
    }
    return ['eligible' => true, 'reason' => 'Paid in Stripe, all orders still pending — safe to activate.', 'invoice' => $inv];
};

$byInvoice = $loadCandidates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $flashError = 'Invalid CSRF token. Reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $targets = [];
        if ($action === 'reconcile' && !empty($_POST['invoice_id'])) {
            $targets = [(string) $_POST['invoice_id']];
        } elseif ($action === 'reconcile_all') {
            $targets = array_keys($byInvoice);
        }

        foreach ($targets as $invoiceId) {
            if (empty($byInvoice[$invoiceId])) {
                $actionResults[$invoiceId] = ['ok' => false, 'msg' => 'No stranded orders for this invoice.'];
                continue;
            }
            $assess = $assessInvoice($invoiceId);
            if (!$assess['eligible']) {
                $actionResults[$invoiceId] = ['ok' => false, 'msg' => $assess['reason']];
                continue;
            }
            // Replay through the identical live-webhook path so the guard, partner
            // activation, extra-order loop and receipt all behave exactly as they
            // would on a real invoice.paid.
            $event = [
                'id' => 'reconcile_' . $invoiceId,
                'type' => 'invoice.paid',
                'data' => ['object' => $assess['invoice']],
            ];
            if (!PaymentWebhookService::recordEvent($event)) {
                $actionResults[$invoiceId] = ['ok' => false, 'msg' => 'Already reconciled earlier — skipped (no double activation).'];
                continue;
            }
            try {
                PaymentWebhookService::handleInvoicePaid($event);
                PaymentWebhookService::markProcessed($event['id'], 'processed', null);
                $actionResults[$invoiceId] = ['ok' => true, 'msg' => 'Activated + receipt dispatched.'];
            } catch (\Throwable $e) {
                PaymentWebhookService::markProcessed($event['id'], 'failed', $e->getMessage());
                $actionResults[$invoiceId] = ['ok' => false, 'msg' => 'Error: ' . $e->getMessage()];
            }
        }

        $byInvoice = $loadCandidates(); // refresh so activated ones drop off
        $flashSuccess = 'Reconcile run complete — see the result on each row below.';
    }
}

// Assess remaining candidates for display (one Stripe call each; a handful in
// practice). Cap the live lookups so a surprise backlog can't hammer Stripe.
$MAX_LOOKUPS = 60;
$assessments = [];
$lookups = 0;
foreach ($byInvoice as $invoiceId => $orders) {
    if ($lookups >= $MAX_LOOKUPS) {
        $assessments[$invoiceId] = ['eligible' => false, 'reason' => 'Not checked (lookup cap reached — reconcile in batches).', 'invoice' => null];
        continue;
    }
    $assessments[$invoiceId] = $assessInvoice($invoiceId);
    $lookups++;
}

$csrf = Csrf::token();
$eligibleCount = 0;
foreach ($assessments as $a) {
    if (!empty($a['eligible'])) { $eligibleCount++; }
}

$fmtName = static function (array $o): string {
    $n = trim((string) ($o['first_name'] ?? '') . ' ' . (string) ($o['last_name'] ?? ''));
    return $n !== '' ? $n : ('member #' . (int) ($o['member_id'] ?? 0));
};
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reconcile stranded payments — Admin</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    body { font-family: system-ui, sans-serif; background: #f8fafc; padding: 2rem; color: #1c1a17; }
    .wrap { max-width: 1000px; margin: 0 auto; display: grid; gap: 1.25rem; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; }
    h1 { font-size: 1.4rem; font-weight: 700; margin: 0 0 .5rem; }
    .note { font-size: .85rem; color: #64748b; line-height: 1.5; }
    .alert { padding: .75rem 1rem; border-radius: 8px; font-size: .9rem; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    th, td { padding: .5rem .6rem; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    th { background: #f8fafc; font-weight: 500; color: #64748b; }
    code { font-family: ui-monospace, monospace; font-size: .78rem; }
    button { background: #1c1a17; color: #fff; border: 0; padding: .45rem .85rem; border-radius: 8px; font-size: .82rem; cursor: pointer; }
    button.all { background: #166534; }
    button:disabled { background: #cbd5e1; cursor: not-allowed; }
    .pill { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .72rem; font-weight: 600; }
    .pill.ok { background: #dcfce7; color: #166534; }
    .pill.no { background: #fef3c7; color: #92400e; }
    .pill.res-ok { background: #dcfce7; color: #166534; }
    .pill.res-no { background: #fee2e2; color: #991b1b; }
    .back { display: inline-block; margin-top: .5rem; font-size: .85rem; color: #3b82f6; text-decoration: none; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>🩹 Reconcile stranded payments</h1>
      <p class="note">
        Membership renewals that Stripe <strong>charged</strong> but our webhook never activated (the July 2026 gate outage).
        Each row is checked against the live Stripe invoice; only invoices Stripe confirms as <strong>paid</strong> can be
        activated, and doing so replays the normal <code>invoice.paid</code> path — correct term, order marked paid, receipt sent.
        Idempotent: safe to re-run.
      </p>
    </div>

    <?php if ($flashError): ?><div class="alert error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>
    <?php if ($flashSuccess): ?><div class="alert success"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>

    <div class="card">
      <?php if (!$byInvoice): ?>
        <p class="note">✅ No stranded membership payments — every membership order with a Stripe invoice is already paid.</p>
      <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap;">
          <h2 style="font-size:1rem; margin:0;"><?= count($byInvoice) ?> invoice(s) with non-paid orders — <?= $eligibleCount ?> safe to activate</h2>
          <?php if ($eligibleCount > 0): ?>
            <form method="POST" onsubmit="return confirm('Activate all <?= $eligibleCount ?> Stripe-confirmed paid invoice(s)?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="reconcile_all">
              <button type="submit" class="all">Reconcile all <?= $eligibleCount ?> eligible</button>
            </form>
          <?php endif; ?>
        </div>

        <table>
          <thead>
            <tr>
              <th>Order(s)</th>
              <th>Member</th>
              <th>Order total</th>
              <th>Stripe invoice</th>
              <th>Assessment</th>
              <th>Action / result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($byInvoice as $invoiceId => $orders): ?>
              <?php
                $assess = $assessments[$invoiceId] ?? ['eligible' => false, 'reason' => 'Not assessed.', 'invoice' => null];
                $inv = $assess['invoice'];
                $result = $actionResults[$invoiceId] ?? null;
                $orderTotal = 0.0;
                foreach ($orders as $o) { $orderTotal += (float) ($o['total'] ?? 0); }
              ?>
              <tr>
                <td>
                  <?php foreach ($orders as $o): ?>
                    <div><code><?= htmlspecialchars((string) ($o['order_number'] ?? ('#' . $o['id']))) ?></code>
                      <span class="note">(<?= htmlspecialchars((string) ($o['status'] ?? '')) ?>)</span></div>
                  <?php endforeach; ?>
                </td>
                <td>
                  <?php foreach ($orders as $o): ?>
                    <div><?= htmlspecialchars($fmtName($o)) ?></div>
                  <?php endforeach; ?>
                </td>
                <td>$<?= number_format($orderTotal, 2) ?></td>
                <td>
                  <code><?= htmlspecialchars($invoiceId) ?></code><br>
                  <?php if ($inv): ?>
                    <span class="note">status: <?= htmlspecialchars((string) ($inv['status'] ?? '?')) ?> ·
                      paid $<?= number_format(((int) ($inv['amount_paid'] ?? 0)) / 100, 2) ?> /
                      due $<?= number_format(((int) ($inv['amount_due'] ?? 0)) / 100, 2) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="pill <?= !empty($assess['eligible']) ? 'ok' : 'no' ?>"><?= !empty($assess['eligible']) ? 'ELIGIBLE' : 'HOLD' ?></span>
                  <div class="note"><?= htmlspecialchars((string) $assess['reason']) ?></div>
                </td>
                <td>
                  <?php if ($result): ?>
                    <span class="pill <?= $result['ok'] ? 'res-ok' : 'res-no' ?>"><?= $result['ok'] ? 'DONE' : 'NO-OP' ?></span>
                    <div class="note"><?= htmlspecialchars((string) $result['msg']) ?></div>
                  <?php elseif (!empty($assess['eligible'])): ?>
                    <form method="POST" onsubmit="return confirm('Activate this membership from its paid Stripe invoice?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="reconcile">
                      <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoiceId) ?>">
                      <button type="submit">Activate</button>
                    </form>
                  <?php else: ?>
                    <span class="note">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <a class="back" href="/admin/">← Back to admin</a>
    </div>
  </div>
</body>
</html>
