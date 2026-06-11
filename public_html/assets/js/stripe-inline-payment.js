/**
 * Shared inline Stripe Payment Element controller.
 *
 * Auto-attaches to every <section data-stripe-widget> on the page. Each widget
 * is independent so multiple can coexist (e.g. a renewal card AND a separate
 * "add a card" card on the billing page).
 *
 * The widget DOM provides everything via data-* attributes:
 *   data-instance               — unique id within the page (e.g. "renewal")
 *   data-context                — backend tag ("membership_renewal", "store_order" …)
 *   data-order-id               — numeric order id (or "" for subscription flows)
 *   data-intent-url             — POST endpoint returning {client_secret, publishable_key}
 *   data-return-url             — Stripe will redirect here after 3DS / wallet flows
 *   data-csrf                   — CSRF token for the intent fetch
 *   data-seed-client-secret     — optional, pre-resolved secret to skip the fetch
 *   data-seed-publishable-key   — optional, pre-resolved publishable key
 *
 * The widget exposes window.AGAStripeInlinePayment.bind(rootEl, overrides) for
 * callers that build their payload dynamically (e.g. become-a-member.php
 * collects form fields before requesting the intent). Overrides:
 *   payloadProvider()   — returns the POST body the backend expects
 *   onValidate()        — return falsy + a string error message to block
 *   onBeforePay()       — async hook before stripe.confirmPayment fires
 *   onError(err)        — called for inline errors
 *
 * Requires Stripe.js v3 already loaded on the page:
 *   <script src="https://js.stripe.com/v3/" defer></script>
 */
(function () {
  'use strict';

  /** @typedef {{stripe: any, elements: any, paymentElement: any, clientSecret: string, publishableKey: string}} WidgetState */

  const STATE = new WeakMap();

  function findStripe() {
    if (typeof window === 'undefined') return null;
    return window.Stripe || null;
  }

  function showError(rootEl, message) {
    const box = rootEl.querySelector('[data-stripe-error]');
    if (!box) return;
    box.textContent = message || 'Payment could not be completed.';
    box.classList.remove('hidden');
  }

  function clearError(rootEl) {
    const box = rootEl.querySelector('[data-stripe-error]');
    if (!box) return;
    box.textContent = '';
    box.classList.add('hidden');
  }

  function setBusy(rootEl, busy, busyLabel) {
    const btn = rootEl.querySelector('[data-stripe-pay]');
    if (!btn) return;
    const label = btn.querySelector('[data-stripe-pay-label]');
    if (busy) {
      btn.dataset.originalLabel = label ? label.textContent : btn.textContent;
      btn.disabled = true;
      if (label) label.textContent = busyLabel || 'Processing…';
    } else {
      btn.disabled = false;
      if (label && btn.dataset.originalLabel) {
        label.textContent = btn.dataset.originalLabel;
      }
    }
  }

  function clearPlaceholder(rootEl) {
    const ph = rootEl.querySelector('[data-stripe-placeholder]');
    if (ph) ph.remove();
  }

  async function fetchIntent(rootEl, payloadProvider) {
    const intentUrl = rootEl.dataset.intentUrl || '/api/payments/intent';
    const csrf = rootEl.dataset.csrf || '';
    const body = Object.assign(
      {
        context: rootEl.dataset.context || 'order',
        order_id: rootEl.dataset.orderId ? Number(rootEl.dataset.orderId) : null,
      },
      payloadProvider ? (await payloadProvider()) : {}
    );
    const resp = await fetch(intentUrl, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify(body),
    });
    let data = {};
    try { data = await resp.json(); } catch (e) { /* ignore */ }
    if (!resp.ok || !data.client_secret) {
      const err = new Error(data.error || 'Could not start the payment.');
      err.status = resp.status;
      throw err;
    }
    return data;
  }

  async function ensureMounted(rootEl, payloadProvider) {
    let state = STATE.get(rootEl);
    if (state && state.paymentElement) return state;

    const Stripe = findStripe();
    if (!Stripe) {
      throw new Error('Stripe.js failed to load. Refresh the page and try again.');
    }

    // Resolve client_secret + publishable_key. Either both come from the
    // server up front (seeded) or we POST to intent_url to mint a PI.
    let clientSecret = rootEl.dataset.seedClientSecret || '';
    let publishableKey = rootEl.dataset.seedPublishableKey || '';
    if (!clientSecret || !publishableKey) {
      const data = await fetchIntent(rootEl, payloadProvider);
      clientSecret = data.client_secret;
      publishableKey = data.publishable_key || publishableKey;
    }
    if (!publishableKey) {
      throw new Error('Payment provider is not configured.');
    }

    const stripe = Stripe(publishableKey);
    const elements = stripe.elements({
      clientSecret,
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
    const paymentElement = elements.create('payment', {
      layout: { type: 'tabs', defaultCollapsed: false },
      wallets: { applePay: 'auto', googlePay: 'auto' },
    });
    const container = rootEl.querySelector('[data-stripe-element]');
    paymentElement.mount(container);
    paymentElement.on('ready', () => clearPlaceholder(rootEl));

    state = { stripe, elements, paymentElement, clientSecret, publishableKey };
    STATE.set(rootEl, state);
    return state;
  }

  function bind(rootEl, opts) {
    opts = opts || {};
    const payBtn = rootEl.querySelector('[data-stripe-pay]');
    if (!payBtn) return;

    payBtn.addEventListener('click', async function onClick() {
      clearError(rootEl);

      if (typeof opts.onValidate === 'function') {
        const err = opts.onValidate();
        if (err) {
          showError(rootEl, err);
          return;
        }
      }

      setBusy(rootEl, true, 'Preparing secure form…');
      let state;
      try {
        state = await ensureMounted(rootEl, opts.payloadProvider);
      } catch (e) {
        showError(rootEl, e.message || 'Could not prepare payment.');
        if (typeof opts.onError === 'function') opts.onError(e);
        setBusy(rootEl, false);
        return;
      }

      // If the Element only just mounted, give it a tick to be ready —
      // confirmPayment will surface a useful error if it's still loading.
      if (typeof opts.onBeforePay === 'function') {
        try { await opts.onBeforePay(state); } catch (e) {
          showError(rootEl, e.message || 'Payment aborted.');
          setBusy(rootEl, false);
          return;
        }
      }

      setBusy(rootEl, true, 'Confirming with Stripe…');
      const returnUrl = new URL(
        rootEl.dataset.returnUrl || '/member/?renewed=1',
        window.location.origin
      ).toString();

      const { error } = await state.stripe.confirmPayment({
        elements: state.elements,
        confirmParams: {
          return_url: returnUrl,
        },
      });

      if (error) {
        // confirmPayment only returns error on immediate failure (validation
        // / card decline). 3DS + redirects + wallets all go via return_url.
        showError(rootEl, error.message || 'Payment was declined.');
        if (typeof opts.onError === 'function') opts.onError(error);
        setBusy(rootEl, false);
        return;
      }
    });
  }

  function autoInit() {
    document.querySelectorAll('[data-stripe-widget]').forEach((el) => bind(el, {}));
  }

  // Public API for custom-payload pay areas (e.g. become-a-member.php).
  window.AGAStripeInlinePayment = {
    bind: bind,
    mount: ensureMounted,
    autoInit: autoInit,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }
})();
