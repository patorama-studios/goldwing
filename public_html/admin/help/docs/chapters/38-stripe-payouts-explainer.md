# Stripe Payouts — How Payments Reach the Bank

> **Reference:** [docs.stripe.com/payouts#payout-schedule](https://docs.stripe.com/payouts#payout-schedule)

## What

Stripe separates two distinct things that are easy to confuse:

- **Payment** — a member pays their membership fee. Money lands in Goldwing's Stripe account straight away.
- **Payout** — Stripe batches those payments together and sends one lump transfer to the club's bank account on a schedule.

A payment today does **not** mean the bank account changes today.

## Why payments are lumped together

Stripe holds funds for a short clearing period (typically **2 business days** in Australia) before they are eligible to be paid out. Once funds clear, Stripe groups everything that cleared that day into a single bank transfer.

By default this runs on a **daily** schedule — so each business day the bank receives one deposit covering all payments that cleared. That one bank deposit is called a **payout**, and it's what you see as a single line in the bank statement.

## How to see what's inside a payout

In the **Stripe Dashboard:**

1. Go to **Transactions → Payouts** in the left sidebar
2. Click any payout row — a detail panel opens
3. Scroll down to see the **list of individual transactions** included in that payout — each member payment, the amount, date, and any Stripe fees deducted

This is how you match a single bank deposit back to the actual member payments it came from.

## How to see incoming member payments

1. Go to **Transactions** in the left sidebar
2. Each row is one member payment — click it to see the member's name, amount charged, invoice, and payment status
3. The linked **Payout ID** on that page shows which bank transfer the payment was eventually bundled into

## Quick reference

| What you see in the bank | What it actually is |
|---|---|
| One deposit, e.g. $245.00 | Typically several member payments that cleared on the same day |
| Deposit arrives 1–2 days after payments | Normal — Stripe's clearing/settlement delay |
| Some days no deposit | No payments cleared that day (e.g. a weekend or no renewals) |
| Stripe fee already deducted | Stripe takes its cut before the payout; the net amount lands in the bank |

## Settings

The payout schedule is controlled under **Settings → Payouts** in the Stripe Dashboard. Options are daily / weekly / monthly / manual. Goldwing runs on **daily** (the default). Changing this requires Stripe account owner access.

## Gotchas

- The bank deposit amount is **net of Stripe fees** — it will always be slightly less than the sum of what members paid.
- A payout can include payments from multiple days if Stripe's clearing landed them together.
- If a payment is **refunded**, the refund is subtracted from a future payout — it won't appear as a separate bank withdrawal.

## Related

- [Chapter 13 — Stripe integration overview](13-stripe-overview.md)
- [Chapter 16 — Webhooks & idempotency](16-webhooks-idempotency.md)
- [Chapter 18 — Invoices](18-invoices.md)
