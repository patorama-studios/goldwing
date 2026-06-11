/**
 * Pay-membership slide-out drawer controller — two-panel slide UX.
 *
 * Drawer DOM lives inline in /member/index.php (no partial dependency).
 *
 * View 1 (default) — Select membership type + term + see live price.
 *   Pay button at the bottom says "Continue to payment →" and only fires
 *   the slide transition. NO Stripe call yet.
 *
 * View 2 (slid in) — Stripe Payment Element, fully mounted, with a real
 *   "Pay A$XX" button. Back arrow returns to view 1. The Element is
 *   mounted JUST before the slide-in completes, so by the time the
 *   member sees the form it's ready to type into.
 *
 *   When the member changes tier/term in view 1 (which can happen any
 *   time before clicking Pay), the next "Continue to payment" call
 *   re-fetches a fresh PaymentIntent and remounts the Element.
 *
 * Triggers:
 *   • Any element with data-pay-drawer-open opens to view 1
 *   • window.PayMembershipDrawer.open({tier?, term?})
 *   • Auto-open when data-auto-open="1" on the drawer root, OR ?pay=1 in URL
 *
 * NO Stripe full-page redirect anywhere. stripe.confirmPayment uses
 * return_url=/member/?renewed=1 which only fires for 3DS / wallet
 * flows; the page never leaves goldwing.org.au except for those
 * authentication overlays.
 */
(function () {
  'use strict';

  var root = null;
  var slider = null;
  var stripe = null;
  var elements = null;
  var paymentElement = null;
  var currentSecret = '';
  var currentOrderId = 0;
  var currentOrderNumber = '';
  var currentTier = 'FULL';
  var currentTerm = '12M';
  var currentAmountLabel = '';
  var pricingMatrix = null;
  var publishableKey = '';
  var view = 'select';            // 'select' | 'pay'
  var isFetchingIntent = false;

  function get(sel) { return root ? root.querySelector(sel) : null; }
  function getAll(sel) { return root ? Array.from(root.querySelectorAll(sel)) : []; }

  function fmtA$(cents) { return 'A$' + (cents / 100).toFixed(2); }

  // ---- Error helpers (per view) ----------------------------------------
  function showSelectError(msg) {
    var box = get('[data-pay-drawer-error-select]');
    if (box) { box.textContent = msg || ''; box.classList.remove('hidden'); }
  }
  function clearSelectError() {
    var box = get('[data-pay-drawer-error-select]');
    if (box) { box.textContent = ''; box.classList.add('hidden'); }
  }
  function showPayError(msg) {
    var box = get('[data-pay-drawer-error-pay]');
    if (box) { box.textContent = msg || ''; box.classList.remove('hidden'); }
  }
  function clearPayError() {
    var box = get('[data-pay-drawer-error-pay]');
    if (box) { box.textContent = ''; box.classList.add('hidden'); }
  }

  // ---- Pay button state -----------------------------------------------
  function setPayLabel(text) {
    var lbl = get('[data-pay-drawer-pay-label]');
    if (lbl) lbl.textContent = text;
  }
  function setPayDisabled(disabled) {
    var btn = get('[data-pay-drawer-pay]');
    if (btn) btn.disabled = !!disabled;
  }

  // ---- View switching --------------------------------------------------
  function setView(next) {
    view = next;
    var backBtn = get('[data-pay-drawer-back]');
    var titleEl = get('[data-pay-drawer-title]');
    var subEl   = get('[data-pay-drawer-subtitle]');
    if (next === 'pay') {
      slider.style.transform = 'translateX(-50%)';
      if (backBtn) backBtn.classList.remove('hidden');
      if (titleEl) titleEl.textContent = 'Enter your card';
      if (subEl)   subEl.textContent   = 'You can come back and change your plan.';
      setPayLabel(currentAmountLabel ? 'Pay ' + currentAmountLabel : 'Pay');
      setPayDisabled(false);
    } else {
      slider.style.transform = 'translateX(0)';
      if (backBtn) backBtn.classList.add('hidden');
      if (titleEl) titleEl.textContent = 'Renew your membership';
      if (subEl)   subEl.textContent   = 'Pick a plan — payment comes next.';
      setPayLabel('Continue to payment →');
      // Pay button is disabled on the select view — the Continue button
      // inside the panel does the work. We hide the footer button by
      // making it match (visually it's the same affordance).
      setPayDisabled(false);
    }
  }

  // ---- Selection inputs <-> state -------------------------------------
  function syncInputs() {
    getAll('[data-pay-drawer-tier]').forEach(function (i) {
      i.checked = i.value === currentTier;
    });
    getAll('[data-pay-drawer-term]').forEach(function (i) {
      i.checked = i.value === currentTerm;
    });
  }

  function onSelectionChange() {
    var t = get('[data-pay-drawer-tier]:checked');
    var p = get('[data-pay-drawer-term]:checked');
    var newTier = t ? t.value : currentTier;
    var newTerm = p ? p.value : currentTerm;
    if (newTier === currentTier && newTerm === currentTerm) return;
    currentTier = newTier;
    currentTerm = newTerm;
    // Just update the live summary from the cached pricing matrix.
    // We don't hit the server again until "Continue to payment" — saves
    // a request per radio click.
    updateSummaryFromMatrix();
    // Any cached PI / Element no longer matches the new selection, so
    // wipe the mount; we'll rebuild on Continue.
    if (paymentElement) {
      try { paymentElement.unmount(); } catch (e) {}
      paymentElement = null;
      elements = null;
      currentSecret = '';
      restorePlaceholder();
    }
  }

  function updatePriceLabels() {
    if (!pricingMatrix) return;
    var tierPrices = pricingMatrix[currentTier] || {};
    getAll('[data-pay-drawer-price]').forEach(function (el) {
      var term = el.getAttribute('data-pay-drawer-price');
      var cents = tierPrices[term];
      el.textContent = cents ? fmtA$(cents) : '—';
    });
  }

  function updateSummaryFromMatrix() {
    if (!pricingMatrix) return;
    var tierPrices = pricingMatrix[currentTier] || {};
    var cents = tierPrices[currentTerm];
    var tierLabel = currentTier === 'ASSOCIATE' ? 'Associate' : 'Full Member';
    var termLabel = currentTerm === '36M' ? '3 years' : (currentTerm === '24M' ? '2 years' : '1 year');
    var summary = {
      tier: currentTier,
      term: currentTerm,
      amount_cents: cents,
      amount_label: cents ? fmtA$(cents) : '—',
      order_number: currentOrderNumber,
    };
    summary.tierLabel = tierLabel;
    summary.termLabel = termLabel;
    updateSummary(summary);
  }

  function updateSummary(d) {
    var title = (d.tierLabel || (d.tier === 'ASSOCIATE' ? 'Associate' : 'Full Member')) +
                ' — ' + (d.termLabel || (d.term === '36M' ? '3 years' : (d.term === '24M' ? '2 years' : '1 year')));
    var amt = d.amount_label || (d.amount_cents ? fmtA$(d.amount_cents) : '—');
    var ord = d.order_number ? 'Order ' + d.order_number : '';
    [
      ['[data-pay-drawer-summary-title]', title],
      ['[data-pay-drawer-summary-order]', ord],
      ['[data-pay-drawer-summary-total]', amt],
      ['[data-pay-drawer-recap-title]',   title],
      ['[data-pay-drawer-recap-order]',   ord],
      ['[data-pay-drawer-recap-total]',   amt],
    ].forEach(function (pair) {
      var el = get(pair[0]);
      if (el) el.textContent = pair[1];
    });
    currentAmountLabel = amt && amt !== '—' ? amt : '';
  }

  function restorePlaceholder() {
    var container = get('[data-pay-drawer-element]');
    if (!container) return;
    if (!container.querySelector('[data-pay-drawer-placeholder]')) {
      container.innerHTML = '<div data-pay-drawer-placeholder class="flex items-center justify-center min-h-[180px] text-sm text-slate-400"><span class="inline-flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 animate-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Loading secure payment form…</span></div>';
    }
  }
  function clearPlaceholder() {
    var ph = get('[data-pay-drawer-placeholder]');
    if (ph) ph.remove();
  }

  // ---- Server: fetch PI for current selection -------------------------
  async function fetchIntent() {
    var csrf = root.dataset.csrf || '';
    var resp = await fetch('/api/payments/membership-intent', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ tier: currentTier, term: currentTerm }),
    });
    var data = {};
    try { data = await resp.json(); } catch (e) {}
    if (!resp.ok || !data.client_secret) {
      throw new Error(data.error || 'Could not start the payment.');
    }
    return data;
  }

  // ---- Mount the Stripe Element on the payment view -------------------
  async function ensureElementMounted() {
    if (isFetchingIntent) return false;
    isFetchingIntent = true;
    showPayError('');
    showSelectError('');
    try {
      // 1) Fetch intent. This call decides amount + creates/reuses the
      // pending order on the server. Returns client_secret + matrix.
      var data;
      try {
        data = await fetchIntent();
      } catch (e) {
        // Surface a clear, actionable message — both views show it so
        // the user sees something whichever view they're on.
        var msg = e.message || 'Could not start the payment.';
        showSelectError(msg);
        showPayError(msg);
        throw e;
      }

      pricingMatrix = data.pricing || pricingMatrix;
      publishableKey = data.publishable_key || publishableKey;
      currentOrderId = data.order_id || currentOrderId;
      currentOrderNumber = data.order_number || currentOrderNumber;
      updatePriceLabels();
      updateSummary(data);

      // 2) Make sure Stripe.js is loaded. If the <script> tag is still
      // pending (defer / network), wait briefly and surface a useful
      // error if it never lands.
      if (!window.Stripe) {
        for (var i = 0; i < 20; i++) {
          await new Promise(function (r) { return setTimeout(r, 100); });
          if (window.Stripe) break;
        }
        if (!window.Stripe) {
          var m = 'Stripe.js did not load (network or CSP block). Refresh the page.';
          showPayError(m);
          throw new Error(m);
        }
      }
      if (!publishableKey) {
        var m2 = 'Stripe publishable key missing — admin must configure Stripe.';
        showPayError(m2);
        throw new Error(m2);
      }
      if (!stripe) stripe = window.Stripe(publishableKey);

      // 3) Mount (or remount on tier/term change) the Payment Element.
      // The container is in View 2 which is off-screen on the slider —
      // mounting into an off-screen container works fine for Stripe;
      // the iframe sizes itself on first render.
      if (currentSecret !== data.client_secret || !paymentElement) {
        if (paymentElement) { try { paymentElement.unmount(); } catch (e) {} }
        var container = get('[data-pay-drawer-element]');
        if (!container) {
          var m3 = 'Payment form container missing in DOM.';
          showPayError(m3);
          throw new Error(m3);
        }
        try {
          elements = stripe.elements({
            clientSecret: data.client_secret,
            appearance: {
              theme: 'stripe',
              variables: {
                colorPrimary: '#dc2626',
                colorBackground: '#ffffff',
                colorText: '#111827',
                borderRadius: '8px',
                fontFamily: 'Inter, system-ui, sans-serif',
              },
            },
          });
          paymentElement = elements.create('payment', {
            layout: { type: 'tabs', defaultCollapsed: false },
            wallets: { applePay: 'auto', googlePay: 'auto' },
          });
          paymentElement.mount(container);
          paymentElement.on('ready', clearPlaceholder);
          paymentElement.on('loaderror', function (ev) {
            var m4 = (ev && ev.error && ev.error.message) || 'Stripe Element failed to load.';
            showPayError(m4);
          });
          currentSecret = data.client_secret;
        } catch (mountErr) {
          showPayError('Could not mount payment form: ' + (mountErr.message || mountErr));
          throw mountErr;
        }
      }
      return true;
    } catch (e) {
      return false;
    } finally {
      isFetchingIntent = false;
    }
  }

  // ---- The footer Pay button ------------------------------------------
  async function payClicked() {
    if (view === 'select') {
      await continueToPayment();
      return;
    }
    // view === 'pay'
    clearPayError();
    if (!stripe || !elements) {
      showPayError('Payment form not ready yet. Wait a moment and try again.');
      return;
    }
    setPayLabel('Confirming with Stripe…');
    setPayDisabled(true);
    try {
      var result = await stripe.confirmPayment({
        elements: elements,
        confirmParams: {
          return_url: window.location.origin + '/member/?renewed=1',
        },
      });
      if (result && result.error) {
        showPayError(result.error.message || 'Payment was declined.');
        setPayLabel('Pay ' + (currentAmountLabel || ''));
        setPayDisabled(false);
      }
      // Otherwise Stripe handles 3DS / Link in-page and redirects on
      // success; nothing to do here.
    } catch (e) {
      showPayError(e.message || 'Payment failed.');
      setPayLabel('Pay ' + (currentAmountLabel || ''));
      setPayDisabled(false);
    }
  }

  async function continueToPayment() {
    clearSelectError();
    if (!pricingMatrix || !pricingMatrix[currentTier] || !pricingMatrix[currentTier][currentTerm]) {
      // First click — pricing matrix not yet loaded. Fetch it now so the
      // select view shows real prices, then the user can click Continue
      // again. (We try-mount immediately if the matrix already arrived.)
    }
    setPayLabel('Preparing secure form…');
    setPayDisabled(true);
    var ok = await ensureElementMounted();
    setPayDisabled(false);
    if (!ok) {
      setPayLabel('Continue to payment →');
      return;
    }
    setView('pay');
  }

  // ---- Open / close ----------------------------------------------------
  function open(overrides) {
    if (!root) return;
    overrides = overrides || {};
    if (overrides.tier) currentTier = overrides.tier;
    if (overrides.term) currentTerm = overrides.term;
    syncInputs();
    setView('select');
    // Centered lightbox — the root has `hidden` + `items-start justify-center`
    // flex classes. Removing `hidden` and adding `flex` shows the modal.
    root.classList.remove('hidden');
    root.classList.add('flex');
    document.body.style.overflow = 'hidden';
    // Kick off the intent fetch right away — that way the price labels
    // populate, the order summary shows, and the Element is already
    // primed when the user clicks Continue to payment.
    if (!pricingMatrix) {
      ensureElementMounted().catch(function () {}).then(function () {
        setView('select');
      });
    } else {
      updateSummaryFromMatrix();
      updatePriceLabels();
    }
  }

  function close() {
    if (!root) return;
    root.classList.add('hidden');
    root.classList.remove('flex');
    document.body.style.overflow = '';
    setView('select');
    if (window.location.search.indexOf('pay=') !== -1) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete('pay');
        window.history.replaceState({}, '', url.pathname + (url.search || ''));
      } catch (e) {}
    }
  }

  // ---- Wiring ----------------------------------------------------------
  function attachListeners() {
    document.querySelectorAll('[data-pay-drawer-open]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); open(); });
    });
    getAll('[data-pay-drawer-close]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    var back = get('[data-pay-drawer-back]');
    if (back) back.addEventListener('click', function () { setView('select'); });

    getAll('[data-pay-drawer-tier]').forEach(function (i) {
      i.addEventListener('change', onSelectionChange);
    });
    getAll('[data-pay-drawer-term]').forEach(function (i) {
      i.addEventListener('change', onSelectionChange);
    });

    var cont = get('[data-pay-drawer-continue]');
    if (cont) cont.addEventListener('click', continueToPayment);

    var pay = get('[data-pay-drawer-pay]');
    if (pay) pay.addEventListener('click', payClicked);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && root && !root.classList.contains('hidden')) close();
    });
  }

  function init() {
    root = document.getElementById('pay-membership-drawer');
    if (!root) return;
    slider = root.querySelector('[data-pay-drawer-slider]');
    if (!slider) return;

    currentTier = root.dataset.defaultTier || 'FULL';
    currentTerm = root.dataset.defaultTerm || '12M';
    syncInputs();
    setView('select');
    attachListeners();

    var autoOpenFromData = root.dataset.autoOpen === '1';
    var autoOpenFromQuery = /(?:^|[?&])pay=1(?:&|$)/.test(window.location.search);
    if (autoOpenFromData || autoOpenFromQuery) {
      setTimeout(function () { open(); }, 250);
    }
  }

  window.PayMembershipDrawer = { open: open, close: close };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
