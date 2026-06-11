<?php
/**
 * Shared inline Stripe Payment Element widget.
 *
 * Renders a self-contained "Pay" card with:
 *   • Trust / security messaging (PCI, encryption, who handles the data)
 *   • Stripe Payment Element container (mounts card + wallets + Link)
 *   • Pay button + inline error display
 *   • All the JS wiring (lazy-loads Stripe.js, fetches a PaymentIntent
 *     client_secret on first interaction, calls stripe.confirmPayment
 *     with a return_url for 3DS handling)
 *
 * Usage:
 *   <?php
 *     $stripeInlinePayment = [
 *       'context'      => 'membership_renewal',         // arbitrary string for the API
 *       'order_id'     => $pendingOrder['id'],          // or null for subscription flows
 *       'amount_label' => 'A$70.00',
 *       'description'  => 'Full membership renewal (1 year)',
 *       'return_url'   => '/member/?renewed=1',
 *       'pay_button'   => 'Pay A$70.00',
 *       'intent_url'   => '/api/payments/intent',       // server returns {client_secret, ...}
 *       'csrf_token'   => Csrf::token(),
 *       // Optional — provide a pre-resolved client_secret to skip the intent_url fetch:
 *       'client_secret' => null,
 *       'publishable_key' => null,
 *       // Optional widget id suffix when multiple widgets render on one page:
 *       'instance' => 'renewal',
 *     ];
 *     require __DIR__ . '/../app/Views/partials/stripe_inline_payment.php';
 *   ?>
 *
 * Requires that the page also pulls in Stripe.js + the controller script:
 *   <script src="https://js.stripe.com/v3/" defer></script>
 *   <script src="/assets/js/stripe-inline-payment.js" defer></script>
 */

$_swCfg = $stripeInlinePayment ?? [];
$_swInstance = (string) ($_swCfg['instance'] ?? 'default');
$_swSafeId = preg_replace('/[^a-z0-9_-]/i', '', $_swInstance) ?: 'default';
$_swReturn = (string) ($_swCfg['return_url'] ?? '/member/?renewed=1');
$_swCtx = (string) ($_swCfg['context'] ?? 'order');
$_swPayLabel = (string) ($_swCfg['pay_button'] ?? 'Pay securely');
$_swAmount = (string) ($_swCfg['amount_label'] ?? '');
$_swDesc = (string) ($_swCfg['description'] ?? '');
$_swIntentUrl = (string) ($_swCfg['intent_url'] ?? '/api/payments/intent');
$_swCsrf = (string) ($_swCfg['csrf_token'] ?? '');
$_swOrderId = isset($_swCfg['order_id']) ? (int) $_swCfg['order_id'] : 0;
$_swSeed = !empty($_swCfg['client_secret']) ? (string) $_swCfg['client_secret'] : '';
$_swPubKey = !empty($_swCfg['publishable_key']) ? (string) $_swCfg['publishable_key'] : '';

$_swDataAttrs = [
    'data-stripe-widget'        => '1',
    'data-instance'             => $_swSafeId,
    'data-context'              => $_swCtx,
    'data-order-id'             => $_swOrderId > 0 ? (string) $_swOrderId : '',
    'data-intent-url'           => $_swIntentUrl,
    'data-return-url'           => $_swReturn,
    'data-csrf'                 => $_swCsrf,
    'data-seed-client-secret'   => $_swSeed,
    'data-seed-publishable-key' => $_swPubKey,
];
?>
<section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden"
  <?php foreach ($_swDataAttrs as $k => $v): ?> <?= htmlspecialchars((string)$k, ENT_QUOTES) ?>="<?= htmlspecialchars((string)$v, ENT_QUOTES) ?>"<?php endforeach; ?>>

  <!-- Heading + trust block -->
  <div class="px-6 pt-6 pb-4 border-b border-gray-100">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h3 class="font-display text-lg font-bold text-gray-900">Secure payment</h3>
        <?php if ($_swDesc !== ''): ?>
          <p class="text-sm text-slate-500 mt-0.5"><?= htmlspecialchars($_swDesc, ENT_QUOTES) ?></p>
        <?php endif; ?>
      </div>
      <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-full whitespace-nowrap">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        Encrypted
      </span>
    </div>

  </div>

  <!-- Trust copy — same partial used by every pay area site-wide -->
  <div class="px-6 pt-4">
    <?php
      $_secBlock = __DIR__ . '/stripe_security_block.php';
      if (file_exists($_secBlock)) { require $_secBlock; }
    ?>
  </div>

  <!-- Payment Element + button -->
  <div class="px-6 py-5">
    <!-- Inline error (filled by the controller on failures) -->
    <div data-stripe-error
         class="hidden mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
         role="alert"></div>

    <!-- Stripe Payment Element mounts here -->
    <div data-stripe-element id="stripe-element-<?= htmlspecialchars($_swSafeId, ENT_QUOTES) ?>"
         class="min-h-[180px] rounded-lg border border-gray-200 px-3 py-3 bg-white">
      <!-- Loading state — replaced by Stripe Elements on first interaction -->
      <div data-stripe-placeholder class="flex items-center justify-center min-h-[140px] text-sm text-slate-400">
        <span class="inline-flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 animate-spin">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
          </svg>
          Loading secure payment form…
        </span>
      </div>
    </div>

    <button type="button" data-stripe-pay
            class="mt-4 w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold text-base transition-colors shadow-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
      <span data-stripe-pay-label><?= htmlspecialchars($_swPayLabel, ENT_QUOTES) ?></span>
    </button>

    <p class="mt-3 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
      <span class="inline-flex items-center gap-1">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        256-bit SSL
      </span>
      <span>•</span>
      <span>PCI DSS Level 1</span>
      <span>•</span>
      <span>Powered by Stripe</span>
    </p>
  </div>
</section>
