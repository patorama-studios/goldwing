<?php
/**
 * Reusable trust/security block for any page that takes a payment inline.
 *
 * Used both by app/Views/partials/stripe_inline_payment.php (full widget)
 * AND by pages with their own bespoke Element mount (the legacy
 * /checkout.php, /become-a-member.php) so the security story is identical
 * site-wide.
 *
 * No configuration needed. Drop it above your card form.
 */
?>
<div class="rounded-2xl border border-emerald-100 bg-emerald-50/40 px-4 py-3 mb-4">
  <div class="flex items-start gap-3">
    <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex-shrink-0">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </span>
    <div class="text-xs leading-relaxed text-slate-700">
      <p class="font-semibold text-slate-900 text-sm">
        Your card details never touch our servers.
      </p>
      <p class="mt-1">
        Payment is processed by <strong>Stripe</strong>, a <strong>Level&nbsp;1 PCI
        Service Provider</strong> (the highest payment-security certification
        available) trusted by Amazon, Google, Lyft and millions of other
        businesses worldwide. The connection uses <strong>TLS&nbsp;1.2+</strong>
        with 256-bit encryption, and the AGA only ever sees the last 4 digits
        for receipt purposes.
        <a href="https://stripe.com/docs/security" target="_blank" rel="noopener"
           class="text-emerald-700 hover:underline whitespace-nowrap">
          Learn more&nbsp;↗
        </a>
      </p>
    </div>
  </div>
</div>
