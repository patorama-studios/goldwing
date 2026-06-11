<?php
/**
 * Pay-membership slide-out drawer.
 *
 * Single self-contained UI for renewing a membership inline:
 *   • Tier selector (Full / Associate)
 *   • Term selector (1y / 2y / 3y) showing live prices
 *   • Order summary
 *   • Trust / security block
 *   • Stripe Payment Element (mounted on drawer open, NOT on Pay click —
 *     so the member can actually fill in the card before pressing Pay)
 *   • Pay button that calls stripe.confirmPayment in-place. 3DS handled
 *     by Stripe.js; on success Stripe redirects to /member/?renewed=1
 *
 * The drawer's controller (public_html/assets/js/pay-membership-drawer.js)
 * auto-attaches to the markup. To open it from any button:
 *
 *   <button data-pay-drawer-open>Pay now</button>
 *
 * Auto-open happens if the page is loaded with ?pay=1 in the URL OR if
 * data-pay-drawer-auto-open="1" is on the drawer root (set by /member/?page=billing
 * whenever the member has a pending order).
 *
 * Required globals on the host page (set before requiring this partial):
 *   $payDrawerData = [
 *     'csrf_token'           => Csrf::token(),
 *     'current_tier'         => 'FULL' | 'ASSOCIATE',     // default selection
 *     'current_term'         => '12M' | '24M' | '36M',
 *     'allow_both_types'     => true,                     // show ASSOCIATE option
 *     'show_24'              => true,                     // show 2-year term card
 *     'show_36'              => true,                     // show 3-year term card
 *     'auto_open'            => bool,                     // auto open on page load?
 *     'pending_order_number' => 'M-2026-…' | '',          // shown in header
 *   ];
 */

$_pd = $payDrawerData ?? [];
$_pdCsrf = (string) ($_pd['csrf_token'] ?? '');
$_pdTier = strtoupper((string) ($_pd['current_tier'] ?? 'FULL'));
$_pdTerm = strtoupper((string) ($_pd['current_term'] ?? '12M'));
$_pdAllowAssoc = !empty($_pd['allow_both_types']);
$_pdShow24 = !empty($_pd['show_24']);
$_pdShow36 = !empty($_pd['show_36']);
$_pdAutoOpen = !empty($_pd['auto_open']);
$_pdPending = (string) ($_pd['pending_order_number'] ?? '');
?>
<aside id="pay-membership-drawer"
       class="fixed inset-0 z-[9000] hidden"
       role="dialog" aria-modal="true" aria-labelledby="pay-drawer-title"
       data-csrf="<?= htmlspecialchars($_pdCsrf, ENT_QUOTES) ?>"
       data-default-tier="<?= htmlspecialchars($_pdTier, ENT_QUOTES) ?>"
       data-default-term="<?= htmlspecialchars($_pdTerm, ENT_QUOTES) ?>"
       data-allow-associate="<?= $_pdAllowAssoc ? '1' : '0' ?>"
       data-show-24="<?= $_pdShow24 ? '1' : '0' ?>"
       data-show-36="<?= $_pdShow36 ? '1' : '0' ?>"
       data-auto-open="<?= $_pdAutoOpen ? '1' : '0' ?>"
       data-pending-number="<?= htmlspecialchars($_pdPending, ENT_QUOTES) ?>">

  <!-- Backdrop -->
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm opacity-0 transition-opacity duration-300"
       data-pay-drawer-backdrop data-pay-drawer-close></div>

  <!-- Panel -->
  <div class="absolute right-0 top-0 h-full w-full max-w-[560px] bg-white shadow-2xl
              transform translate-x-full transition-transform duration-300 ease-out
              flex flex-col"
       data-pay-drawer-panel>

    <!-- Header -->
    <header class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-3 flex-shrink-0">
      <div>
        <h2 id="pay-drawer-title" class="font-display text-xl font-bold text-gray-900">
          Pay your membership
        </h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Choose your membership and pay — everything stays on this page.
        </p>
      </div>
      <button type="button" data-pay-drawer-close
              class="text-slate-400 hover:text-slate-700 transition-colors"
              aria-label="Close">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             class="w-5 h-5">
          <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
      </button>
    </header>

    <!-- Scrollable body -->
    <div class="flex-1 overflow-y-auto">

      <!-- Step 1 — Membership type -->
      <section class="px-6 py-5 border-b border-gray-100">
        <h3 class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 mb-3">
          Membership type
        </h3>
        <div class="grid grid-cols-<?= $_pdAllowAssoc ? '2' : '1' ?> gap-2">
          <label class="flex items-start gap-2 rounded-xl border border-gray-200 px-3 py-3 cursor-pointer
                        has-[:checked]:border-primary has-[:checked]:bg-primary/10 transition-colors">
            <input type="radio" name="pay-drawer-tier" value="FULL"
                   class="mt-0.5" data-pay-drawer-tier>
            <span class="flex-1">
              <span class="block font-semibold text-gray-900 text-sm">Full Member</span>
              <span class="block text-xs text-slate-500 leading-snug mt-0.5">Voting member with all benefits.</span>
            </span>
          </label>
          <?php if ($_pdAllowAssoc): ?>
            <label class="flex items-start gap-2 rounded-xl border border-gray-200 px-3 py-3 cursor-pointer
                          has-[:checked]:border-primary has-[:checked]:bg-primary/10 transition-colors">
              <input type="radio" name="pay-drawer-tier" value="ASSOCIATE"
                     class="mt-0.5" data-pay-drawer-tier>
              <span class="flex-1">
                <span class="block font-semibold text-gray-900 text-sm">Associate</span>
                <span class="block text-xs text-slate-500 leading-snug mt-0.5">Household partner of a Full member.</span>
              </span>
            </label>
          <?php endif; ?>
        </div>
      </section>

      <!-- Step 2 — Term -->
      <section class="px-6 py-5 border-b border-gray-100">
        <h3 class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 mb-3">
          Term
        </h3>
        <div class="grid grid-cols-<?= ($_pdShow24 || $_pdShow36) ? '3' : '1' ?> gap-2">
          <label class="rounded-xl border border-gray-200 px-3 py-3 cursor-pointer text-center
                        has-[:checked]:border-primary has-[:checked]:bg-primary/10 transition-colors">
            <input type="radio" name="pay-drawer-term" value="12M" class="sr-only" data-pay-drawer-term>
            <span class="block font-semibold text-gray-900 text-sm">1 year</span>
            <span class="block text-xs text-slate-500 mt-1" data-pay-drawer-price="12M">—</span>
          </label>
          <?php if ($_pdShow24): ?>
            <label class="rounded-xl border border-gray-200 px-3 py-3 cursor-pointer text-center
                          has-[:checked]:border-primary has-[:checked]:bg-primary/10 transition-colors">
              <input type="radio" name="pay-drawer-term" value="24M" class="sr-only" data-pay-drawer-term>
              <span class="block font-semibold text-gray-900 text-sm">2 years</span>
              <span class="block text-xs text-slate-500 mt-1" data-pay-drawer-price="24M">—</span>
            </label>
          <?php endif; ?>
          <?php if ($_pdShow36): ?>
            <label class="rounded-xl border border-gray-200 px-3 py-3 cursor-pointer text-center
                          has-[:checked]:border-primary has-[:checked]:bg-primary/10 transition-colors">
              <input type="radio" name="pay-drawer-term" value="36M" class="sr-only" data-pay-drawer-term>
              <span class="block font-semibold text-gray-900 text-sm">3 years</span>
              <span class="block text-xs text-slate-500 mt-1" data-pay-drawer-price="36M">—</span>
            </label>
          <?php endif; ?>
        </div>
      </section>

      <!-- Step 3 — Order summary + payment -->
      <section class="px-6 py-5">
        <h3 class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 mb-3">
          Payment
        </h3>

        <!-- Live order summary -->
        <div class="rounded-xl bg-slate-50 px-4 py-3 mb-4 text-sm">
          <div class="flex justify-between items-center">
            <div>
              <div class="font-semibold text-gray-900" data-pay-drawer-summary-title>—</div>
              <div class="text-xs text-slate-500" data-pay-drawer-summary-order>—</div>
            </div>
            <div class="text-xl font-bold text-gray-900" data-pay-drawer-summary-total>—</div>
          </div>
        </div>

        <!-- Trust block — same partial as every other pay area site-wide -->
        <?php require __DIR__ . '/stripe_security_block.php'; ?>

        <!-- Inline error -->
        <div data-pay-drawer-error
             class="hidden mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
             role="alert"></div>

        <!-- Stripe Payment Element mounts here -->
        <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 min-h-[200px]"
             data-pay-drawer-element>
          <div data-pay-drawer-placeholder
               class="flex items-center justify-center min-h-[160px] text-sm text-slate-400">
            <span class="inline-flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                   class="w-4 h-4 animate-spin">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
              </svg>
              Loading secure payment form…
            </span>
          </div>
        </div>
      </section>
    </div>

    <!-- Footer with Pay button -->
    <footer class="px-6 py-4 border-t border-gray-100 bg-white flex-shrink-0">
      <button type="button" data-pay-drawer-pay
              class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl
                     bg-gray-900 hover:bg-gray-800 disabled:opacity-60 disabled:cursor-not-allowed
                     text-white font-semibold text-base transition-colors shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             class="w-4 h-4">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <span data-pay-drawer-pay-label>Pay securely</span>
      </button>
      <p class="mt-2 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
        <span>256-bit SSL</span><span>•</span>
        <span>PCI DSS Level 1</span><span>•</span>
        <span>Powered by Stripe</span>
      </p>
    </footer>
  </div>
</aside>
