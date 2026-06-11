/**
 * Pay-membership slide-out drawer controller.
 *
 * Pairs with app/Views/partials/pay_membership_drawer.php.
 *
 *  Open from anywhere on the page:   <button data-pay-drawer-open>Pay now</button>
 *  Open programmatically:            window.PayMembershipDrawer.open()
 *  Open with overrides:              window.PayMembershipDrawer.open({tier:'FULL', term:'24M'})
 *  Auto-open conditions (on load):
 *    • The drawer has data-auto-open="1" (set server-side when pending order exists), OR
 *    • The URL contains ?pay=1
 *
 * IMPORTANT vs. the inline widget mistake we made last round: the Element
 * is mounted when the drawer OPENS, not on Pay click. So when the user
 * clicks Pay, their card details are already in the Element and
 * stripe.confirmPayment can finish in-page (3DS popup stays in-page;
 * non-3DS finishes silently and redirects to /member/?renewed=1).
 *
 * Tier / term changes void the previous pending order and mint a fresh
 * PaymentIntent (see /api/payments/membership-intent), and the Element
 * remounts with the new client_secret.
 */
(function () {
  'use strict';

  var root = null;
  var stripe = null;
  var elements = null;
  var paymentElement = null;
  var currentSecret = '';
  var currentOrderId = 0;
  var currentTier = 'FULL';
  var currentTerm = '12M';
  var pricingMatrix = null;
  var publishableKey = '';
  var isMounting = false;

  function getEl(sel, ctx) { return (ctx || root).querySelector(sel); }
  function getAll(sel, ctx) { return Array.from((ctx || root).querySelectorAll(sel)); }

  function showError(msg) {
    var box = getEl('[data-pay-drawer-error]');
    if (!box) return;
    box.textContent = msg || 'Payment could not be processed.';
    box.classList.remove('hidden');
  }
  function clearError() {
    var box = getEl('[data-pay-drawer-error]');
    if (box) { box.classList.add('hidden'); box.textContent = ''; }
  }

  function setPayBusy(busy, label) {
    var btn = getEl('[data-pay-drawer-pay]');
    if (!btn) return;
    var lbl = btn.querySelector('[data-pay-drawer-pay-label]');
    if (busy) {
      btn.disabled = true;
      if (lbl && !btn.dataset.originalLabel) {
        btn.dataset.originalLabel = lbl.textContent;
      }
      if (lbl) lbl.textContent = label || 'Processing…';
    } else {
      btn.disabled = false;
      if (lbl && btn.dataset.originalLabel) {
        lbl.textContent = btn.dataset.originalLabel;
      }
    }
  }

  function fmtA$(cents) {
    return 'A$' + (cents / 100).toFixed(2);
  }

  function updatePriceLabels() {
    if (!pricingMatrix) return;
    var tier = currentTier;
    var tierPrices = pricingMatrix[tier] || {};
    getAll('[data-pay-drawer-price]').forEach(function (el) {
      var term = el.getAttribute('data-pay-drawer-price');
      var cents = tierPrices[term];
      el.textContent = cents ? fmtA$(cents) : '—';
    });
  }

  function updateSummary(data) {
    data = data || {};
    var title = getEl('[data-pay-drawer-summary-title]');
    var order = getEl('[data-pay-drawer-summary-order]');
    var total = getEl('[data-pay-drawer-summary-total]');
    if (title) {
      var tierLabel = data.tier === 'ASSOCIATE' ? 'Associate' : 'Full Member';
      var termLabel = data.term === '36M' ? '3 years' : (data.term === '24M' ? '2 years' : '1 year');
      title.textContent = tierLabel + ' — ' + termLabel;
    }
    if (order) {
      order.textContent = data.order_number ? 'Order ' + data.order_number : '';
    }
    if (total) {
      total.textContent = data.amount_label || (data.amount_cents ? fmtA$(data.amount_cents) : '—');
    }
    var payLabel = getEl('[data-pay-drawer-pay-label]');
    if (payLabel) {
      payLabel.textContent = data.amount_label ? 'Pay ' + data.amount_label : 'Pay securely';
      var btn = getEl('[data-pay-drawer-pay]');
      if (btn) delete btn.dataset.originalLabel;
    }
  }

  function clearPlaceholder() {
    var ph = getEl('[data-pay-drawer-placeholder]');
    if (ph) ph.remove();
  }

  async function fetchMembershipIntent() {
    var csrf = root.dataset.csrf || '';
    var body = { tier: currentTier, term: currentTerm };
    var resp = await fetch('/api/payments/membership-intent', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify(body),
    });
    var data = {};
    try { data = await resp.json(); } catch (e) {}
    if (!resp.ok || !data.client_secret) {
      throw new Error(data.error || 'Could not start the payment.');
    }
    return data;
  }

  async function mountElement(force) {
    if (isMounting) return;
    isMounting = true;
    setPayBusy(true, 'Preparing secure form…');

    try {
      var data = await fetchMembershipIntent();
      pricingMatrix = data.pricing || pricingMatrix;
      publishableKey = data.publishable_key || publishableKey;
      currentOrderId = data.order_id || currentOrderId;
      updatePriceLabels();
      updateSummary(data);

      if (!window.Stripe) {
        throw new Error('Stripe.js failed to load. Refresh the page and try again.');
      }
      if (!stripe) stripe = window.Stripe(publishableKey);

      // If the secret changed (tier/term swap) or we never mounted, build
      // a fresh Elements instance. Stripe doesn't allow swapping the
      // clientSecret on an existing Elements — must recreate.
      if (force || currentSecret !== data.client_secret || !paymentElement) {
        if (paymentElement) {
          try { paymentElement.unmount(); } catch (e) {}
        }
        elements = stripe.elements({
          clientSecret: data.client_secret,
          appearance: {
            theme: 'stripe',
            variables: {
              colorPrimary: '#111827',
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
        paymentElement.mount(getEl('[data-pay-drawer-element]'));
        paymentElement.on('ready', clearPlaceholder);
        currentSecret = data.client_secret;
      }
      setPayBusy(false);
    } catch (e) {
      showError(e.message || 'Could not prepare payment.');
      setPayBusy(false);
    } finally {
      isMounting = false;
    }
  }

  async function pay() {
    clearError();
    if (!stripe || !elements) {
      await mountElement(true);
      if (!stripe || !elements) return;
    }
    setPayBusy(true, 'Confirming with Stripe…');
    try {
      var result = await stripe.confirmPayment({
        elements: elements,
        confirmParams: {
          return_url: window.location.origin + '/member/?renewed=1',
        },
      });
      if (result && result.error) {
        showError(result.error.message || 'Payment was declined.');
        setPayBusy(false);
      }
      // Otherwise Stripe redirects (3DS / wallets / final success). We
      // don't reset the button state — the page is unmounting.
    } catch (e) {
      showError(e.message || 'Payment was declined.');
      setPayBusy(false);
    }
  }

  function open(overrides) {
    if (!root) return;
    overrides = overrides || {};
    if (overrides.tier) currentTier = overrides.tier;
    if (overrides.term) currentTerm = overrides.term;
    syncSelectionInputs();
    root.classList.remove('hidden');
    requestAnimationFrame(function () {
      var bd = getEl('[data-pay-drawer-backdrop]');
      var pn = getEl('[data-pay-drawer-panel]');
      if (bd) bd.classList.add('opacity-100');
      if (pn) pn.classList.remove('translate-x-full');
    });
    document.body.style.overflow = 'hidden';
    mountElement(false);
  }

  function close() {
    if (!root) return;
    var bd = getEl('[data-pay-drawer-backdrop]');
    var pn = getEl('[data-pay-drawer-panel]');
    if (bd) bd.classList.remove('opacity-100');
    if (pn) pn.classList.add('translate-x-full');
    setTimeout(function () {
      root.classList.add('hidden');
      document.body.style.overflow = '';
    }, 300);

    // Strip ?pay=1 so a refresh doesn't auto-reopen.
    if (window.location.search.indexOf('pay=') !== -1) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete('pay');
        window.history.replaceState({}, '', url.pathname + (url.search || ''));
      } catch (e) {}
    }
  }

  function syncSelectionInputs() {
    getAll('[data-pay-drawer-tier]').forEach(function (input) {
      input.checked = input.value === currentTier;
    });
    getAll('[data-pay-drawer-term]').forEach(function (input) {
      input.checked = input.value === currentTerm;
    });
  }

  function onSelectionChange() {
    var t = getEl('[data-pay-drawer-tier]:checked');
    var p = getEl('[data-pay-drawer-term]:checked');
    var newTier = t ? t.value : currentTier;
    var newTerm = p ? p.value : currentTerm;
    if (newTier === currentTier && newTerm === currentTerm) return;
    currentTier = newTier;
    currentTerm = newTerm;
    clearError();
    // Re-fetch the intent for the new combo. The backend will void the
    // old pending order and mint a new one.
    mountElement(true);
  }

  function attachListeners() {
    document.querySelectorAll('[data-pay-drawer-open]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        open();
      });
    });
    getAll('[data-pay-drawer-close]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    getAll('[data-pay-drawer-tier]').forEach(function (input) {
      input.addEventListener('change', onSelectionChange);
    });
    getAll('[data-pay-drawer-term]').forEach(function (input) {
      input.addEventListener('change', onSelectionChange);
    });
    var payBtn = getEl('[data-pay-drawer-pay]');
    if (payBtn) payBtn.addEventListener('click', pay);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !root.classList.contains('hidden')) close();
    });
  }

  function init() {
    root = document.getElementById('pay-membership-drawer');
    if (!root) return;

    currentTier = root.dataset.defaultTier || 'FULL';
    currentTerm = root.dataset.defaultTerm || '12M';
    syncSelectionInputs();
    attachListeners();

    var autoOpenFromData = root.dataset.autoOpen === '1';
    var autoOpenFromQuery = /(?:^|[?&])pay=1(?:&|$)/.test(window.location.search);
    if (autoOpenFromData || autoOpenFromQuery) {
      // Defer slightly so the page chrome paints first.
      setTimeout(function () { open(); }, 300);
    }
  }

  window.PayMembershipDrawer = { open: open, close: close };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
