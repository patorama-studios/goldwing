# Stripe line items for membership payments

**Goal (Pat):** When someone pays for membership with Stripe, the Stripe dashboard
should show **line items** (what they bought) + an invoice/PDF + a Customer —
like the store already does — not just a bare amount.

**Decision (2026-06-20):** Scope = **both join + renewal** (Pat). Verification =
Pat drives a Chrome test after deploy. **Implemented** (see below).

---

## Why memberships had no line items
Membership flows charged via a **bare PaymentIntent** — in Stripe that's only an
amount + metadata, no line items. The **store** already does it right
(`StoreInvoiceService`): it builds a Stripe **Invoice** with one
`createInvoiceItem` per line, finalizes it, and the customer pays the invoice's
linked PaymentIntent. That yields itemized lines + a hosted PDF + per-Customer
history. We mirrored that for memberships.

## What changed

**New service** `app/Services/MembershipInvoiceService.php` (mirrors `StoreInvoiceService`):
- `createApplicationInvoice()` — new-member join. No order/member row exists yet,
  so it bills a Customer derived from the applicant email. Returns the invoice
  PI's `client_secret` (same response shape the form already consumed).
- `createRenewalInvoice()` — renewal lightbox. One invoice covering all renewers
  (self + partner); stamps every renewer `orders` row with the invoice + PI id.
- `createOrderInvoice()` — billing-page "Pay now" for a single existing pending
  order. Refresh-safe: reuses an open/draft invoice instead of minting duplicates.
- All three create a Stripe Customer (find-by-email or reuse `members.stripe_customer_id`),
  add itemized lines (membership type + term + magazine; plus a "Card processing
  fee" line where applicable), finalize, and return the PI client_secret.
- **No Stripe idempotency key** on invoice creation: a cached invoice + our
  non-idempotent `createInvoiceItem` calls would double the line items/total.
  Each call mints a fresh invoice; abandoned (unpaid) drafts never bill.

**Wired surfaces** (`public_html/api/index.php`):
- `/api/stripe/create-application-payment-intent` (card) → `createApplicationInvoice()`.
  Non-card (bank transfer) keeps the bare PI path unchanged.
- `/api/payments/membership-intent` (renewal lightbox) → `createRenewalInvoice()`.
- `/api/payments/intent` context `membership_renewal`/`membership_pay` → `createOrderInvoice()`.

**Webhook** (`app/Services/PaymentWebhookService.php::handleInvoicePaid`):
- A PI tied to an invoice short-circuits `payment_intent.succeeded`, so activation
  for these flows now runs through **`invoice.paid`** (already supported for
  membership orders). Added: activate **every** `order_type=membership` row
  sharing the `stripe_invoice_id` (covers a partner renewal on the same invoice),
  guarded by `status != paid` for idempotency.
- New-member **application** invoices (`metadata.context=membership_application`)
  have no order row → skipped quietly (no "order not found" error). Activation
  stays with `apply.php`'s POST handler + admin approval.

**Untouched / still correct:**
- Admin application-approval path → Stripe **Checkout Session** (already itemized).
- `payments.membership_prices` Stripe Price IDs — still used by admin approval +
  renewal-reminder cron (see PRICING_WIRE_PLAN.md §7); not part of this change.

## Safety notes
- Webhook events are de-duplicated by `stripe_event_id` (`recordEvent()` insert),
  so re-delivery won't double-activate.
- `activateMembershipForOrder()` marks the order paid + period/member active.
- Server still computes every amount from the matrix (`resolveJoinPriceCents` /
  `renewalAmountCents`); the client never sends a price.
- All changed PHP passes `php -l`; `_toc.json` validated; tour-impact: none.

## Verification (Pat drives via Chrome after deploy)
1. Open `/become-a-member`, complete a join with a Stripe **test card**
   (`4242 4242 4242 4242`).
2. In the Stripe dashboard: the payment should be an **Invoice** with line items
   (e.g. "Full membership — 1 Year (Printed Wings)") + a Customer + PDF.
3. Repeat for a renewal via the member billing lightbox; confirm line items and
   that the membership activates (webhook → period ACTIVE).
4. Edit a matrix price in admin first to also reconfirm the charged amount tracks
   the matrix.
