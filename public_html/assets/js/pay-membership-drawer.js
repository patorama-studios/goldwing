/**
 * Pay-membership lightbox controller — two views on a horizontal slider.
 *
 * Markup lives inline in /member/index.php (#pay-membership-drawer).
 *
 * View 1 (select) — renewal term cards (server-rendered prices), optional
 *   "also pay for my associate" checkbox, live "You pay today" total, and
 *   the mandatory confirm-details checkbox. The Continue button POSTs
 *   /api/payments/membership-intent {term, include_partner}, which creates
 *   the pending order(s) + PaymentIntent, then slides to View 2.
 *
 * View 2 (pay) — order summary lines from the API response, Stripe Payment
 *   Element (mounted before the slide so it's ready when visible), and the
 *   "Pay A$X.XX" button which calls stripe.confirmPayment with
 *   return_url=/member/?renewed=1. The back arrow returns to View 1 with
 *   the selection intact; changing it invalidates the cached intent so the
 *   next Continue fetches a fresh one.
 *
 * Triggers: any [data-pay-drawer-open] or [data-renew-trigger] element,
 *   ?pay=1 in the URL, data-auto-open="1" on the root, or
 *   window.PayMembershipDrawer.open().
 *
 * Also wires the "Cancel my membership instead" link to #renew-cancel-modal
 * (markup kept from the old renew modal).
 */
(function () {
  'use strict';

  var root = null;
  var slider = null;
  var stripe = null;
  var elements = null;
  var paymentElement = null;
  var currency = 'AUD';
  var view = 'select';            // 'select' | 'pay'
  var intentSig = '';             // term|partner signature of the mounted intent
  var payAmountLabel = '';
  var busy = false;

  function get(sel) { return root ? root.querySelector(sel) : null; }
  function getAll(sel) { return root ? Array.from(root.querySelectorAll(sel)) : []; }

  function fmt(n) {
    return '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // ---- Errors (one box per view) ----------------------------------------
  function setError(which, msg) {
    var box = get('[data-pay-drawer-error-' + which + ']');
    if (!box) return;
    if (msg) { box.textContent = msg; box.classList.remove('hidden'); }
    else { box.textContent = ''; box.classList.add('hidden'); }
  }

  // ---- Selection state ---------------------------------------------------
  function selectedTerm() { return get('[data-pay-drawer-term]:checked'); }
  function partnerChecked() {
    var t = get('[data-pay-drawer-partner]');
    return !!(t && t.checked);
  }
  function ackChecked() {
    var a = get('[data-pay-drawer-ack]');
    return !!(a && a.checked);
  }
  function signature() {
    var t = selectedTerm();
    return (t ? t.value : '') + '|' + (partnerChecked() ? '1' : '0');
  }

  // Live "You pay today" total + partner price label on View 1, computed
  // from the server-rendered data-self-amount / data-partner-amount attrs.
  function recalc() {
    var t = selectedTerm();
    var totalEl = get('[data-pay-drawer-total]');
    var partnerLabel = get('[data-pay-drawer-partner-price]');
    if (!t) {
      if (totalEl) totalEl.textContent = '—';
      return;
    }
    var self = parseFloat(t.dataset.selfAmount || '0');
    var partner = parseFloat(t.dataset.partnerAmount || '0');
    var total = self + (partnerChecked() ? partner : 0);
    if (totalEl) totalEl.textContent = fmt(total) + ' ' + currency;
    if (partnerLabel) partnerLabel.textContent = '+' + fmt(partner) + ' for the same term';
  }

  // ---- View switching ----------------------------------------------------
  function setView(next) {
    view = next;
    if (!slider) return;
    var backBtn = get('[data-pay-drawer-back]');
    var titleEl = get('[data-pay-drawer-title]');
    var subEl = get('[data-pay-drawer-subtitle]');
    if (next === 'pay') {
      slider.style.transform = 'translateX(-50%)';
      if (backBtn) backBtn.classList.remove('hidden');
      if (titleEl) titleEl.textContent = 'Renew membership';
      if (subEl) subEl.textContent = 'Enter your payment details.';
    } else {
      slider.style.transform = 'translateX(0)';
      if (backBtn) backBtn.classList.add('hidden');
      if (titleEl) titleEl.textContent = 'Renew membership';
      if (subEl) subEl.textContent = 'Choose your renewal term and confirm your details.';
    }
  }

  function setContinueBusy(isBusy) {
    var btn = get('[data-pay-drawer-continue]');
    var lbl = get('[data-pay-drawer-continue-label]');
    if (btn) btn.disabled = !!isBusy;
    if (lbl) lbl.textContent = isBusy ? 'Preparing secure form…' : 'Continue to payment';
  }

  function setPayBusy(isBusy) {
    var btn = get('[data-pay-drawer-pay]');
    var lbl = get('[data-pay-drawer-pay-label]');
    if (btn) btn.disabled = !!isBusy;
    if (lbl) lbl.textContent = isBusy ? 'Confirming with Stripe…' : ('Pay ' + payAmountLabel);
  }

  // ---- View 2 order summary ----------------------------------------------
  function renderSummary(data) {
    var wrap = get('[data-pay-drawer-lines]');
    if (wrap) {
      wrap.innerHTML = '';
      (data.lines || []).forEach(function (line) {
        var row = document.createElement('div');
        row.className = 'flex items-start justify-between gap-3';
        var left = document.createElement('div');
        left.className = 'min-w-0';
        var label = document.createElement('p');
        label.className = 'text-sm font-medium text-gray-900';
        label.textContent = line.label || '';
        var sub = document.createElement('p');
        sub.className = 'text-xs text-gray-500 truncate';
        sub.textContent = line.sublabel || '';
        left.appendChild(label);
        left.appendChild(sub);
        var amt = document.createElement('span');
        amt.className = 'text-sm font-semibold text-gray-900 whitespace-nowrap';
        amt.textContent = line.amount_label || '';
        row.appendChild(left);
        row.appendChild(amt);
        wrap.appendChild(row);
      });
    }
    var totalEl = get('[data-pay-drawer-pay-total]');
    if (totalEl) totalEl.textContent = (data.amount_label || '—') + ' ' + currency;
    payAmountLabel = data.amount_label || '';
    setPayBusy(false);
  }

  // ---- Stripe ------------------------------------------------------------
  function unmountElement() {
    if (paymentElement) {
      try { paymentElement.unmount(); } catch (e) { /* already gone */ }
    }
    paymentElement = null;
    elements = null;
    intentSig = '';
    var ph = get('[data-pay-drawer-placeholder]');
    if (ph) ph.classList.remove('hidden');
  }

  async function waitForStripeJs() {
    if (window.Stripe) return;
    for (var i = 0; i < 20; i++) {
      await new Promise(function (r) { setTimeout(r, 100); });
      if (window.Stripe) return;
    }
    throw new Error('Stripe.js did not load (network or content blocker). Refresh the page and try again.');
  }

  async function fetchIntent() {
    var t = selectedTerm();
    var resp = await fetch('/api/payments/membership-intent', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf || '' },
      body: JSON.stringify({
        term: (t ? t.value : '12') + 'M',
        include_partner: partnerChecked(),
      }),
    });
    var data = {};
    try { data = await resp.json(); } catch (e) { /* non-JSON 5xx */ }
    if (!resp.ok || !data.client_secret) {
      throw new Error(data.error || ('Could not start the payment (HTTP ' + resp.status + ').'));
    }
    return data;
  }

  async function mountElement(data) {
    await waitForStripeJs();
    if (!data.publishable_key) {
      throw new Error('Stripe publishable key missing — an admin needs to configure Stripe.');
    }
    if (!stripe) stripe = window.Stripe(data.publishable_key);
    unmountElement();
    var container = get('[data-pay-drawer-element]');
    if (!container) throw new Error('Payment form container missing.');
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
    paymentElement.on('ready', function () {
      var ph = get('[data-pay-drawer-placeholder]');
      if (ph) ph.classList.add('hidden');
    });
    paymentElement.on('loaderror', function (ev) {
      setError('pay', (ev && ev.error && ev.error.message) || 'The payment form failed to load.');
    });
  }

  // ---- Continue to payment (View 1 → View 2) ------------------------------
  async function continueToPayment() {
    if (busy) return;
    setError('select', '');
    if (!selectedTerm()) {
      setError('select', 'Choose a renewal term first.');
      return;
    }
    if (!ackChecked()) {
      setError('select', 'Please confirm your membership details are correct before continuing.');
      return;
    }
    var sig = signature();
    if (sig === intentSig && paymentElement) {
      // Same selection as the mounted intent — just slide across.
      setView('pay');
      return;
    }
    busy = true;
    setContinueBusy(true);
    try {
      var data = await fetchIntent();
      renderSummary(data);
      await mountElement(data);
      intentSig = sig;
      setError('pay', '');
      setView('pay');
    } catch (e) {
      setError('select', e.message || 'Could not start the payment.');
    } finally {
      busy = false;
      setContinueBusy(false);
    }
  }

  // ---- Pay (View 2) --------------------------------------------------------
  async function pay() {
    if (busy) return;
    setError('pay', '');
    if (!stripe || !elements) {
      setError('pay', 'The payment form is not ready yet. Go back and try again.');
      return;
    }
    busy = true;
    setPayBusy(true);
    try {
      var result = await stripe.confirmPayment({
        elements: elements,
        confirmParams: {
          return_url: window.location.origin + '/member/?renewed=1',
        },
      });
      // Only reached on failure — success redirects to return_url.
      if (result && result.error) {
        setError('pay', result.error.message || 'Payment was declined.');
      }
    } catch (e) {
      setError('pay', e.message || 'Payment failed.');
    } finally {
      busy = false;
      setPayBusy(false);
    }
  }

  // ---- Open / close --------------------------------------------------------
  function open() {
    if (!root) return;
    setView('select');
    recalc();
    root.classList.remove('hidden');
    root.classList.add('flex');
    document.body.style.overflow = 'hidden';
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
      } catch (e) { /* ignore */ }
    }
  }

  // ---- Wiring ----------------------------------------------------------------
  function attachListeners() {
    document.querySelectorAll('[data-pay-drawer-open], [data-renew-trigger]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); open(); });
    });
    getAll('[data-pay-drawer-close]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    root.addEventListener('click', function (e) {
      if (e.target === root) close();
    });
    var back = get('[data-pay-drawer-back]');
    if (back) back.addEventListener('click', function () { setView('select'); });

    getAll('[data-pay-drawer-term]').forEach(function (i) {
      i.addEventListener('change', function () {
        recalc();
        if (signature() !== intentSig) unmountElement();
      });
    });
    var partner = get('[data-pay-drawer-partner]');
    if (partner) {
      partner.addEventListener('change', function () {
        recalc();
        if (signature() !== intentSig) unmountElement();
      });
    }

    var cont = get('[data-pay-drawer-continue]');
    if (cont) cont.addEventListener('click', continueToPayment);
    var payBtn = get('[data-pay-drawer-pay]');
    if (payBtn) payBtn.addEventListener('click', pay);

    // "Cancel my membership instead" → the cancel-request modal.
    var cancelModal = document.getElementById('renew-cancel-modal');
    var cancelTrigger = get('[data-renew-cancel-trigger]');
    if (cancelTrigger && cancelModal) {
      cancelTrigger.addEventListener('click', function () {
        close();
        cancelModal.classList.remove('hidden');
        cancelModal.classList.add('flex');
      });
      cancelModal.querySelectorAll('[data-renew-cancel-close]').forEach(function (c) {
        c.addEventListener('click', function () {
          cancelModal.classList.add('hidden');
          cancelModal.classList.remove('flex');
        });
      });
      cancelModal.addEventListener('click', function (e) {
        if (e.target === cancelModal) {
          cancelModal.classList.add('hidden');
          cancelModal.classList.remove('flex');
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (root && !root.classList.contains('hidden')) close();
      if (cancelModal && !cancelModal.classList.contains('hidden')) {
        cancelModal.classList.add('hidden');
        cancelModal.classList.remove('flex');
      }
    });
  }

  function init() {
    root = document.getElementById('pay-membership-drawer');
    if (!root) return;
    slider = root.querySelector('[data-pay-drawer-slider]');
    if (!slider) return;
    currency = root.dataset.currency || 'AUD';

    setView('select');
    recalc();
    attachListeners();

    // Never auto-open when a payment is already in flight (paid, awaiting
    // activation) — emailed ?pay=1 links would otherwise reopen the drawer
    // and invite a double payment.
    var paymentInFlight = root.dataset.paymentInflight === '1';
    var autoOpenFromData = root.dataset.autoOpen === '1';
    var autoOpenFromQuery = /(?:^|[?&])pay=1(?:&|$)/.test(window.location.search);
    if (!paymentInFlight && (autoOpenFromData || autoOpenFromQuery)) {
      setTimeout(open, 250);
    }
  }

  window.PayMembershipDrawer = { open: open, close: close };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
