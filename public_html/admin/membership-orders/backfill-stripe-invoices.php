<?php
/**
 * Admin tool: backfill itemized Stripe Invoices for ALREADY-PAID membership
 * orders that never got one (they were charged via the old bare-PaymentIntent
 * flow, so Stripe shows only an amount, no line items).
 *
 * For each candidate it creates a Stripe Invoice with line items (from
 * order_items) and marks it **paid out of band** — NO money is charged; it's a
 * historical billing record so the dashboard shows line items + a PDF under the
 * member's Customer. The order's stripe_invoice_id is stamped so it won't be
 * picked up twice. The invoice carries context=membership_backfill so the
 * invoice.paid webhook skips it (no re-activation / no date re-chaining).
 *
 * IMPORTANT: writes go to whichever Stripe mode is ACTIVE (test vs live) — the
 * banner shows which. Default view is a read-only dry run; the actual run
 * requires the confirm checkbox + CSRF and processes in batches.
 *
 * URL: /admin/membership-orders/backfill-stripe-invoices.php
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\Database;
use App\Services\StripeSettingsService;
use App\Services\MembershipInvoiceService;

require_permission('admin.members.view');

$pdo = Database::connection();

$keys = StripeSettingsService::getActiveKeys();
$mode = (string) ($keys['mode'] ?? 'test');
$publishable = (string) ($keys['publishable_key'] ?? '');
$isLive = $mode === 'live' || str_starts_with($publishable, 'pk_live');
$stripeReady = !empty($keys['secret_key']);

$BATCH_DEFAULT = 25;
$BATCH_MAX = 100;

/** Build the line items for an order from its order_items (fallback: one line from total). */
$buildLines = static function (array $order) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT name, quantity, unit_price FROM order_items WHERE order_id = :id ORDER BY id ASC');
    $stmt->execute(['id' => (int) $order['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $lines = [];
    foreach ($items as $it) {
        $cents = (int) round(((float) ($it['unit_price'] ?? 0)) * 100);
        if ($cents <= 0) {
            continue;
        }
        $lines[] = [
            'description' => trim((string) ($it['name'] ?? '')) ?: 'AGA membership',
            'cents' => $cents,
            'quantity' => max(1, (int) ($it['quantity'] ?? 1)),
        ];
    }
    if (!$lines) {
        $cents = (int) round(((float) ($order['total'] ?? 0)) * 100);
        if ($cents > 0) {
            $lines[] = ['description' => 'AGA membership — order ' . ($order['order_number'] ?? ('#' . $order['id'])), 'cents' => $cents, 'quantity' => 1];
        }
    }
    return $lines;
};

$candidateSql =
    "SELECT o.id, o.order_number, o.total, o.currency, o.created_at, o.member_id, o.status, o.payment_status,
            m.first_name, m.last_name, m.email
       FROM orders o
       LEFT JOIN members m ON m.id = o.member_id
      WHERE o.order_type = 'membership'
        AND (o.stripe_invoice_id IS NULL OR o.stripe_invoice_id = '')
        AND (LOWER(o.status) = 'paid' OR LOWER(o.payment_status) IN ('accepted','paid'))
      ORDER BY o.created_at ASC";

$totalCandidates = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders o
      WHERE o.order_type = 'membership'
        AND (o.stripe_invoice_id IS NULL OR o.stripe_invoice_id = '')
        AND (LOWER(o.status) = 'paid' OR LOWER(o.payment_status) IN ('accepted','paid'))"
)->fetchColumn();

$runResults = null;
$runError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $runError = 'Invalid CSRF token — reload and try again.';
    } elseif (($_POST['confirm'] ?? '') !== 'yes') {
        $runError = 'Tick the confirm box before running.';
    } elseif (!$stripeReady) {
        $runError = 'Stripe is not configured (no active secret key).';
    } else {
        $limit = (int) ($_POST['limit'] ?? $BATCH_DEFAULT);
        $limit = max(1, min($BATCH_MAX, $limit));
        $stmt = $pdo->prepare($candidateSql . ' LIMIT ' . $limit);
        $stmt->execute();
        $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $runResults = ['ok' => [], 'failed' => [], 'mode' => $isLive ? 'LIVE' : 'TEST'];
        foreach ($batch as $order) {
            $lines = $buildLines($order);
            if (!$lines) {
                $runResults['failed'][] = ['order' => $order['order_number'] ?? ('#' . $order['id']), 'error' => 'no line items / zero total'];
                continue;
            }
            $name = trim(((string) ($order['first_name'] ?? '')) . ' ' . ((string) ($order['last_name'] ?? '')));
            try {
                $res = MembershipInvoiceService::backfillPaidInvoiceForOrder(
                    $order,
                    $lines,
                    (string) ($order['email'] ?? ''),
                    $name,
                    $isLive // only stamp the order row on a LIVE run
                );
                $runResults['ok'][] = [
                    'order' => $order['order_number'] ?? ('#' . $order['id']),
                    'invoice_id' => $res['invoice_id'],
                    'url' => $res['hosted_invoice_url'] ?? null,
                ];
            } catch (\Throwable $e) {
                $runResults['failed'][] = ['order' => $order['order_number'] ?? ('#' . $order['id']), 'error' => $e->getMessage()];
            }
        }
        // Recount remaining after the run.
        $totalCandidates = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders o
              WHERE o.order_type = 'membership'
                AND (o.stripe_invoice_id IS NULL OR o.stripe_invoice_id = '')
                AND (LOWER(o.status) = 'paid' OR LOWER(o.payment_status) IN ('accepted','paid'))"
        )->fetchColumn();
    }
}

// Dry-run preview rows (cap to keep the page light).
$previewLimit = 100;
$stmt = $pdo->prepare($candidateSql . ' LIMIT ' . $previewLimit);
$stmt->execute();
$preview = $stmt->fetchAll(PDO::FETCH_ASSOC);

$money = static fn($cents, $cur = 'AUD') => $cur . ' $' . number_format(((int) $cents) / 100, 2);

$pageTitle  = 'Backfill Stripe invoices';
$activePage = 'members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Backfill Stripe invoices';
    require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div>
        <h1 class="font-display text-2xl font-bold text-gray-900">Backfill Stripe invoices</h1>
        <p class="mt-1 text-sm text-gray-500">Create itemized, paid-out-of-band Stripe invoices for already-paid membership orders that don't have one. No money is charged.</p>
      </div>

      <!-- Mode banner -->
      <div class="rounded-2xl border p-4 text-sm flex items-start gap-3 <?= $isLive ? 'border-red-300 bg-red-50 text-red-800' : 'border-amber-300 bg-amber-50 text-amber-900' ?>">
        <span class="material-icons-outlined text-[20px] mt-0.5"><?= $isLive ? 'warning' : 'science' ?></span>
        <div>
          <p class="font-semibold">Stripe is in <?= $isLive ? 'LIVE' : 'TEST' ?> mode<?= $stripeReady ? '' : ' — but no active secret key is configured' ?>.</p>
          <p class="mt-0.5"><?= $isLive
            ? 'Running now writes real invoices to your live Stripe account. These are marked paid out of band (no charge), but may double-count in Stripe revenue reports.'
            : 'Running now writes to Stripe test mode — safe for previewing how the invoices look. Switch Stripe to live mode (Settings → Payments) when you\'re ready to do it for real.' ?></p>
        </div>
      </div>

      <?php if ($runError): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-800 p-4 text-sm font-medium"><?= e($runError) ?></div>
      <?php endif; ?>

      <?php if ($runResults !== null): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 space-y-3">
          <h2 class="font-semibold text-gray-900">Run complete (<?= e($runResults['mode']) ?> mode)</h2>
          <p class="text-sm text-gray-600"><?= count($runResults['ok']) ?> invoice(s) created · <?= count($runResults['failed']) ?> failed · <?= (int) $totalCandidates ?> still remaining.</p>
          <?php if ($runResults['mode'] === 'TEST'): ?>
            <p class="text-xs text-amber-700">Test mode — order rows were <strong>not</strong> stamped, so these still appear as candidates for the live run (and re-running test mode will create duplicate test invoices).</p>
          <?php endif; ?>
          <?php if ($runResults['ok']): ?>
            <details class="text-sm"><summary class="cursor-pointer font-medium text-green-700">Created (<?= count($runResults['ok']) ?>)</summary>
              <ul class="mt-2 space-y-1 text-gray-700">
                <?php foreach ($runResults['ok'] as $r): ?>
                  <li><?= e($r['order']) ?> → <code class="text-xs"><?= e($r['invoice_id']) ?></code><?php if (!empty($r['url'])): ?> · <a class="text-blue-600 hover:underline" target="_blank" rel="noopener" href="<?= e($r['url']) ?>">view</a><?php endif; ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>
          <?php if ($runResults['failed']): ?>
            <details class="text-sm" open><summary class="cursor-pointer font-medium text-red-700">Failed (<?= count($runResults['failed']) ?>)</summary>
              <ul class="mt-2 space-y-1 text-gray-700">
                <?php foreach ($runResults['failed'] as $r): ?>
                  <li><?= e($r['order']) ?> — <?= e($r['error']) ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Summary + run form -->
      <div class="rounded-2xl border border-gray-200 bg-white p-5 space-y-4">
        <div class="flex items-baseline justify-between">
          <h2 class="font-semibold text-gray-900">Candidates</h2>
          <span class="text-2xl font-bold text-gray-900"><?= (int) $totalCandidates ?></span>
        </div>
        <p class="text-sm text-gray-600">Paid membership orders with no Stripe invoice yet. The dry-run table below previews up to <?= $previewLimit ?>.</p>

        <form method="post" class="flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <label class="text-sm">
            <span class="block font-medium text-gray-700 mb-1">Process up to</span>
            <input type="number" name="limit" value="<?= $BATCH_DEFAULT ?>" min="1" max="<?= $BATCH_MAX ?>" class="w-28 rounded-lg border-gray-300 text-sm">
          </label>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="confirm" value="yes" class="rounded border-gray-300">
            I understand this writes <?= $isLive ? 'LIVE' : 'test' ?> invoices to Stripe
          </label>
          <button type="submit" <?= ($totalCandidates > 0 && $stripeReady) ? '' : 'disabled' ?>
            class="rounded-xl px-5 py-2.5 text-sm font-semibold text-white <?= $isLive ? 'bg-red-600 hover:bg-red-700' : 'bg-gray-900 hover:bg-gray-800' ?> disabled:opacity-40 disabled:cursor-not-allowed">
            Run batch
          </button>
        </form>
      </div>

      <!-- Dry-run preview table -->
      <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900">Dry-run preview</div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="px-4 py-2 text-left">Order</th>
                <th class="px-4 py-2 text-left">Member</th>
                <th class="px-4 py-2 text-left">Date</th>
                <th class="px-4 py-2 text-left">Line items it would create</th>
                <th class="px-4 py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php if (!$preview): ?>
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Nothing to backfill — every paid membership order already has a Stripe invoice. 🎉</td></tr>
              <?php else: foreach ($preview as $row):
                $lines = $buildLines($row);
                $cur = (string) ($row['currency'] ?? 'AUD'); ?>
                <tr>
                  <td class="px-4 py-2 font-medium text-gray-900"><?= e((string) ($row['order_number'] ?? ('#' . $row['id']))) ?></td>
                  <td class="px-4 py-2 text-gray-700">
                    <?= e(trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? ''))) ?: '—') ?>
                    <span class="block text-xs text-gray-400"><?= e((string) ($row['email'] ?? '')) ?></span>
                  </td>
                  <td class="px-4 py-2 text-gray-500 whitespace-nowrap"><?= e(substr((string) ($row['created_at'] ?? ''), 0, 10)) ?></td>
                  <td class="px-4 py-2 text-gray-700">
                    <?php if (!$lines): ?><span class="text-red-600">⚠ no items / zero total</span><?php else: ?>
                      <ul class="space-y-0.5">
                        <?php foreach ($lines as $ln): ?>
                          <li><?= e($ln['description']) ?> — <?= e($money($ln['cents'], $cur)) ?><?= $ln['quantity'] > 1 ? ' × ' . (int) $ln['quantity'] : '' ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-2 text-right font-medium text-gray-900 whitespace-nowrap"><?= e($money((int) round(((float) ($row['total'] ?? 0)) * 100), $cur)) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalCandidates > count($preview)): ?>
          <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-500">Showing <?= count($preview) ?> of <?= (int) $totalCandidates ?>. Run batches until the count reaches zero.</div>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
