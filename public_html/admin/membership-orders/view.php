<?php
/**
 * Standalone admin detail page for a single membership order.
 *
 * Replaces the embedded selected-order panel that used to live inside
 * /admin/members/view.php?tab=orders&order_id=N. The orders TABLE on the
 * member view now points "View →" links here, and emails / notifications /
 * cron-generated links can also use this URL.
 *
 * URL: /admin/membership-orders/view.php?id=N
 *
 * Actions still POST to /admin/members/actions.php (membership_order_accept,
 * _reject, _send_link, _void, _unvoid, _delete, _note) which redirects back
 * to /admin/members/view.php with a flash. The flash banner there links
 * back here if the user wants to keep working on this order.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../includes/stripe_references.php';

use App\Services\ActivityRepository;
use App\Services\AdminMemberAccess;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;

require_permission('admin.members.view');

$user = current_user();
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);
$orderId = (int) ($_GET['id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND order_type = "membership" LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    echo 'Membership order not found.';
    exit;
}

$memberId = (int) ($order['member_id'] ?? 0);
$member = $memberId > 0 ? MemberRepository::findById($memberId) : null;
if ($chapterRestriction !== null && $member && ((int) ($member['chapter_id'] ?? 0)) !== $chapterRestriction) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Items
$itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
$itemsStmt->execute(['order_id' => $orderId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Period
$period = null;
if (!empty($order['membership_period_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $order['membership_period_id']]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Local invoices for this order (if InvoiceService has run)
$localInvoices = [];
try {
    $stmt = $pdo->prepare('SELECT id, invoice_number, total, created_at, pdf_file_id FROM invoices WHERE order_id = :id ORDER BY id DESC');
    $stmt->execute(['id' => $orderId]);
    $localInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $localInvoices = [];
}

// Refunds
$refunds = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM refunds WHERE order_id = :id ORDER BY id DESC');
    $stmt->execute(['id' => $orderId]);
    $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $refunds = [];
}

// Activity log entries that mention this order (membership.* actions stash
// the order_id inside metadata).
$activityEntries = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, action, actor_type, actor_id, metadata, created_at
           FROM activity_log
          WHERE member_id = :mid
            AND action LIKE 'membership.%'
            AND (metadata LIKE :needle1 OR metadata LIKE :needle2)
          ORDER BY id DESC
          LIMIT 50"
    );
    $stmt->execute([
        'mid' => $memberId,
        'needle1' => '%"order_id":' . $orderId . '%',
        'needle2' => '%"order_id":"' . $orderId . '"%',
    ]);
    $activityEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $activityEntries = [];
}

$paymentMethod      = (string) ($order['payment_method'] ?? '');
$paymentMethodLabel = $paymentMethod !== '' ? ucwords(str_replace('_', ' ', $paymentMethod)) : '—';
$paymentStatus      = strtolower((string) ($order['payment_status'] ?? 'pending'));
$orderStatus        = strtolower((string) ($order['status'] ?? 'pending'));
$orderNumber        = (string) ($order['order_number'] ?? ('M-' . $order['id']));
$isVoided           = !empty($order['voided_at']);
$isPaidish          = in_array($paymentStatus, ['accepted', 'refunded'], true);
$canManualFix       = AdminMemberAccess::canManualOrderFix($user);
$csrfToken          = Csrf::token();
$memberName         = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : 'Member #' . $memberId;
$memberHref         = $memberId > 0 ? '/admin/members/view.php?id=' . $memberId . '&tab=orders' : '#';

$statusBadge = function (string $s): string {
    $s = strtolower($s);
    return match ($s) {
        'active', 'paid', 'accepted', 'completed' => 'bg-green-100 text-green-800',
        'pending', 'processing'                   => 'bg-yellow-100 text-yellow-800',
        'expired', 'refunded', 'failed', 'rejected' => 'bg-red-100 text-red-800',
        'cancelled'                               => 'bg-gray-100 text-gray-800',
        default => 'bg-slate-100 text-slate-800',
    };
};

$fmtMoney = function ($value, string $currency = 'AUD'): string {
    return $currency . ' $' . number_format((float) $value, 2);
};

$fmtDate = function (?string $s): string {
    if (!$s) { return '—'; }
    $t = strtotime($s);
    return $t ? date('j M Y', $t) : $s;
};

$periodLabel = '—';
if ($period) {
    $periodLabel = $fmtDate($period['start_date'] ?? null) . ' → ' .
        ((($period['term'] ?? '') === 'LIFE' || empty($period['end_date'])) ? 'N/A' : $fmtDate($period['end_date']));
}

$flash = $_SESSION['members_flash'] ?? null;
unset($_SESSION['members_flash']);

$pageTitle  = 'Order ' . $orderNumber;
$activePage = 'members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Membership order';
    require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <?php if ($flash): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-start gap-3 <?= ($flash['type'] ?? '') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' ?>">
          <span class="material-icons-outlined text-[20px] mt-0.5 flex-shrink-0"><?= ($flash['type'] ?? '') === 'error' ? 'error_outline' : 'check_circle' ?></span>
          <span class="font-medium"><?= e($flash['message'] ?? '') ?></span>
        </div>
      <?php endif; ?>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <a href="<?= e($memberHref) ?>" class="text-xs font-semibold text-blue-600 hover:underline">← Back to <?= e($memberName) ?></a>
          <h1 class="mt-1 font-display text-2xl font-bold text-gray-900">
            <?= e($orderNumber) ?>
            <?php if ($isVoided): ?>
              <span class="ml-2 inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-700" title="Voided <?= e((string) $order['voided_at']) ?><?= !empty($order['voided_reason']) ? ' — ' . e((string) $order['voided_reason']) : '' ?>">Voided</span>
            <?php endif; ?>
          </h1>
          <p class="text-sm text-slate-500">Created <?= e($fmtDate($order['created_at'] ?? null)) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
          Payment: <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusBadge($paymentStatus) ?>"><?= ucfirst($paymentStatus) ?></span>
          Order: <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusBadge($orderStatus) ?>"><?= ucfirst($orderStatus) ?></span>
        </div>
      </div>

      <section class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">

          <!-- Summary -->
          <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Summary</h3>
            <div class="grid gap-4 md:grid-cols-3 text-sm text-slate-600">
              <div>
                <p class="text-xs text-gray-500">Total</p>
                <p class="font-semibold text-gray-900"><?= e($fmtMoney($order['total'] ?? 0, (string) ($order['currency'] ?? 'AUD'))) ?></p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Payment method</p>
                <p class="font-semibold text-gray-900"><?= e($paymentMethodLabel) ?></p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Membership period</p>
                <p class="font-semibold text-gray-900"><?= e($periodLabel) ?></p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Subtotal</p>
                <p class="font-medium text-gray-900"><?= e($fmtMoney($order['subtotal'] ?? 0, (string) ($order['currency'] ?? 'AUD'))) ?></p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Tax</p>
                <p class="font-medium text-gray-900"><?= e($fmtMoney($order['tax_total'] ?? 0, (string) ($order['currency'] ?? 'AUD'))) ?></p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Paid at</p>
                <p class="font-medium text-gray-900"><?= e($order['paid_at'] ? $fmtDate($order['paid_at']) : '—') ?></p>
              </div>
            </div>
          </div>

          <!-- Items -->
          <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Items</h3>
            <?php if ($orderItems): ?>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                      <th class="py-2 pr-3">Item</th>
                      <th class="py-2 pr-3">Qty</th>
                      <th class="py-2 pr-3">Unit price</th>
                      <th class="py-2 pr-3 text-right">Line total</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <?php foreach ($orderItems as $it): ?>
                      <?php $lineTotal = (float) ($it['unit_price'] ?? 0) * (int) ($it['quantity'] ?? 1); ?>
                      <tr>
                        <td class="py-2 pr-3 text-gray-900"><?= e((string) ($it['name'] ?? '')) ?></td>
                        <td class="py-2 pr-3 text-gray-600"><?= e((string) ($it['quantity'] ?? 1)) ?></td>
                        <td class="py-2 pr-3 text-gray-600"><?= e($fmtMoney($it['unit_price'] ?? 0, (string) ($order['currency'] ?? 'AUD'))) ?></td>
                        <td class="py-2 pr-3 text-right text-gray-600"><?= e($fmtMoney($lineTotal, (string) ($order['currency'] ?? 'AUD'))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-sm text-slate-500">No items recorded.</p>
            <?php endif; ?>
          </div>

          <!-- Stripe refs -->
          <?= render_stripe_references_block($order, 'card') ?>

          <!-- Local invoices -->
          <?php if ($localInvoices): ?>
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Local invoices</h3>
              <ul class="space-y-2 text-sm">
                <?php foreach ($localInvoices as $inv): ?>
                  <li class="flex items-center justify-between">
                    <span class="font-mono text-gray-900"><?= e((string) $inv['invoice_number']) ?></span>
                    <span class="text-xs text-slate-500"><?= e($fmtMoney($inv['total'] ?? 0, (string) ($order['currency'] ?? 'AUD'))) ?> · <?= e($fmtDate($inv['created_at'] ?? null)) ?><?= !empty($inv['pdf_file_id']) ? ' · PDF attached' : '' ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <!-- Internal notes -->
          <?php if ($canManualFix): ?>
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Internal notes</h3>
              <form method="post" action="/admin/members/actions.php" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                <input type="hidden" name="tab" value="orders">
                <input type="hidden" name="orders_section" value="membership">
                <input type="hidden" name="action" value="membership_order_note">
                <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                <textarea name="note" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Append a note (timestamped by the action handler)"></textarea>
                <button class="rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white" type="submit">Append note</button>
              </form>
              <?php if (!empty($order['internal_notes'])): ?>
                <div class="mt-4">
                  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 mb-2">Existing notes</p>
                  <p class="whitespace-pre-line text-sm text-slate-700"><?= e((string) $order['internal_notes']) ?></p>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Activity -->
          <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Activity</h3>
            <?php if ($activityEntries): ?>
              <ul class="space-y-3 text-sm text-slate-600">
                <?php foreach ($activityEntries as $a): ?>
                  <li class="flex items-start justify-between gap-4">
                    <div>
                      <p class="font-semibold text-slate-700"><?= e((string) $a['action']) ?></p>
                      <?php if (!empty($a['metadata'])): ?>
                        <p class="text-xs text-slate-500 break-all"><?= e(substr((string) $a['metadata'], 0, 280)) ?></p>
                      <?php endif; ?>
                    </div>
                    <span class="text-xs text-slate-400 shrink-0"><?= e((string) $a['created_at']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-sm text-slate-500">No activity recorded for this order yet.</p>
            <?php endif; ?>
          </div>
        </div>

        <aside class="space-y-6">

          <!-- Customer -->
          <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-3">Member</h3>
            <p class="font-semibold text-gray-900"><?= e($memberName) ?></p>
            <?php if ($member): ?>
              <p class="text-sm text-slate-500"><?= e((string) ($member['email'] ?? '')) ?></p>
              <p class="text-xs text-slate-500 mt-1"><?= e((string) ($member['member_type'] ?? '')) ?> · <?= e((string) ($member['status'] ?? '')) ?></p>
              <a href="<?= e($memberHref) ?>" class="mt-3 inline-flex text-xs font-semibold text-blue-600 hover:underline">View member profile</a>
            <?php endif; ?>
          </div>

          <?php if ($canManualFix): ?>

            <!-- Bank-transfer / manual approval -->
            <?php if ($paymentStatus === 'pending' && in_array($paymentMethod, ['bank_transfer', 'manual', 'cash', 'complimentary'], true)): ?>
              <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Approve / reject</h3>
                <form method="post" action="/admin/members/actions.php" class="space-y-2">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                  <input type="hidden" name="tab" value="orders">
                  <input type="hidden" name="orders_section" value="membership">
                  <input type="hidden" name="action" value="membership_order_accept">
                  <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                  <input type="text" name="payment_reference" placeholder="Bank transfer reference (optional)" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <button class="w-full rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white" type="submit">Approve payment</button>
                </form>
                <form method="post" action="/admin/members/actions.php" class="space-y-2">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                  <input type="hidden" name="tab" value="orders">
                  <input type="hidden" name="orders_section" value="membership">
                  <input type="hidden" name="action" value="membership_order_reject">
                  <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                  <input type="text" name="reject_reason" placeholder="Rejection reason" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <button class="w-full rounded-lg border border-rose-300 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700" type="submit">Reject order</button>
                </form>
              </div>
            <?php endif; ?>

            <!-- Send Stripe checkout link -->
            <?php if (in_array($paymentStatus, ['pending', 'failed'], true) && ($paymentMethod === '' || $paymentMethod === 'stripe')): ?>
              <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Send checkout link</h3>
                <p class="text-xs text-slate-500">Mints a fresh Stripe Checkout Session and emails the member a pay link.</p>
                <form method="post" action="/admin/members/actions.php">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                  <input type="hidden" name="tab" value="orders">
                  <input type="hidden" name="orders_section" value="membership">
                  <input type="hidden" name="action" value="membership_order_send_link">
                  <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                  <button class="w-full rounded-lg border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700" type="submit">Send checkout link</button>
                </form>
              </div>
            <?php endif; ?>

            <!-- Database hygiene -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-3">
              <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Database hygiene</h3>
              <?php if ($isVoided): ?>
                <form method="post" action="/admin/members/actions.php">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                  <input type="hidden" name="tab" value="orders">
                  <input type="hidden" name="orders_section" value="membership">
                  <input type="hidden" name="action" value="membership_order_unvoid">
                  <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                  <button class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700" type="submit">Restore order (un-void)</button>
                </form>
              <?php else: ?>
                <form method="post" action="/admin/members/actions.php" onsubmit="return confirm('Void this order? It will be hidden from default lists.');">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                  <input type="hidden" name="tab" value="orders">
                  <input type="hidden" name="orders_section" value="membership">
                  <input type="hidden" name="action" value="membership_order_void">
                  <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                  <button class="w-full rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700" type="submit">Void order</button>
                </form>
              <?php endif; ?>
              <form method="post" action="/admin/members/actions.php" onsubmit="return confirmDeleteOrder(this, <?= $isPaidish ? 'true' : 'false' ?>);">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="member_id" value="<?= e((string) $memberId) ?>">
                <input type="hidden" name="tab" value="orders">
                <input type="hidden" name="orders_section" value="membership">
                <input type="hidden" name="action" value="membership_order_delete">
                <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                <input type="hidden" name="delete_confirm" value="">
                <button class="w-full rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700" type="submit">Permanently delete order</button>
              </form>
            </div>
          <?php endif; ?>

        </aside>
      </section>
    </div>
  </main>
</div>

<script>
  function confirmDeleteOrder(form, isPaidish) {
    var msg = isPaidish
      ? 'This order has been paid/refunded. Deleting will NOT affect Stripe. Are you sure?'
      : 'Permanently delete this order and its line items?';
    if (!window.confirm(msg)) { return false; }
    var typed = (window.prompt('Type DELETE to confirm permanent removal.') || '').trim().toUpperCase();
    if (typed !== 'DELETE') { alert('Cancelled — confirmation not matched.'); return false; }
    form.delete_confirm.value = 'DELETE';
    return true;
  }
</script>

<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
