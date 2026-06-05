# Stripe integration overview

## What this covers

How the Goldwing site takes money. Stripe is the only payment processor we use, one Stripe account drives both membership renewals and store orders, and the test/live switch is the key prefix. This chapter is the gateway to Part 4 — the later chapters (pricing, orders, webhooks, refunds, invoices) all assume you've read this one.

## Why it exists

We picked Stripe over Eway, PayPal, and bank-direct for three reasons:

- **One account, every product.** Stripe Checkout looks the same whether the line item is a membership renewal, a club polo, or an event ticket. One dashboard, one set of keys, one webhook endpoint. Eway needed a separate hosted-payment-page per product type; PayPal was clunky for one-off invoiced renewals.
- **First-class test mode.** Every API call is automatically test or live depending on the secret-key prefix — no separate environment to sync.
- **Modern SDK + webhooks.** The official `stripe/stripe-php` SDK (13.16.0, vendored at `app/ThirdParty/stripe-php/`) handles HTTP, retries, and signature verification.

Trade-off: locked into Stripe's fees and their account-suspension risk. Acceptable for a single-association site.

## How it works

### One account, two products

**One Stripe account** for the whole site. Memberships and store both submit Checkout Sessions against it, both hit `/api/stripe_webhook.php`, both write into the `orders` table. In Stripe you tell them apart by the `metadata` attached on session creation (`member_id` vs `order_id`).

### Test vs live: it's the key prefix

No "environment" toggle in code — the mode is whichever secret key is active:

- `sk_test_…` → test mode (real cards ignored, no money moves).
- `sk_live_…` → live mode.

The validator enforces this on save:

```php
self::validateKey($testSecret, 'sk_test_', 'Test secret key', $errors);
self::validateKey($liveSecret, 'sk_live_', 'Live secret key', $errors);
```

The admin form stores **both** test and live keys side-by-side; the boolean `payments.stripe.use_test_mode` picks which pair the SDK sees. Resolution lives in `StripeSettingsService::getActiveKeys()`, reached via `StripeService::activeSecretKey()`. Safety net: live mode with a blank live secret falls back to test so checkout doesn't lock.

### Where the keys are stored

In order of precedence (highest first):

1. **`settings_global` table**, written through the admin page. Secret and webhook keys are encrypted at rest via `CryptoService` (see [Chapter 10](view.php?slug=10-encryption-secrets)). Publishable keys are stored clear — they're public anyway.
2. **Env vars** — `STRIPE_TEST_SECRET_KEY`, `STRIPE_LIVE_SECRET_KEY`, `STRIPE_TEST_PUBLISHABLE_KEY`, `STRIPE_LIVE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`. Used only when the matching DB row is empty. Handy in local dev.
3. **Legacy `settings_payments` row** (channel `primary`) — migration fallback. New deployments shouldn't rely on it.

`config/app.php` declares a `stripe` section with empty defaults — just so the array shape exists. Real values live in the DB.

### The SDK

`app/ThirdParty/stripe-php/` is vendored, loaded via `require_once __DIR__ . '/../ThirdParty/stripe-php/init.php'` at the top of `StripeService.php`. No Composer at runtime — committed in-tree so deploys skip `composer install`.

### The three services you'll touch

- **`StripeService`** (`app/Services/StripeService.php`) — low-level SDK wrapper. Every Stripe API call goes through here: `createCheckoutSession`, `createCheckoutSessionWithLineItems`, `createCustomer`, `createRefund`, `retrievePaymentIntent`, `constructEvent`. Resolves the secret key internally, so callers never see one.
- **`StripeSettingsService`** (`app/Services/StripeSettingsService.php`) — read/write keys, mode flag, feature toggles (Apple Pay, Google Pay, BNPL, guest checkout). `getPublicConfig()` feeds the front-end Stripe.js client.
- **`PaymentSettingsService`** (`app/Services/PaymentSettingsService.php`) — manages `payment_channels` / `settings_payments`: invoice prefix, last-webhook timestamp, per-year invoice counter. Only the `primary` channel is used today.

### Webhooks

Stripe POSTs every payment event to `/api/stripe_webhook.php`, which:

1. Reads the raw body and `Stripe-Signature` header.
2. Verifies the signature against the **webhook signing secret** (`whsec_…`, in `payments.stripe.webhook_secret`, encrypted). Bad sig → HTTP 400, logs `Invalid signature` to the webhook health row.
3. De-duplicates by event ID via `PaymentWebhookService::recordEvent()` — Stripe retries, we never double-fulfil.
4. Dispatches `checkout.session.completed`, `payment_intent.*`, `charge.refunded`, `invoice.*`, and `customer.subscription.*` to handlers in `PaymentWebhookService`.

Deep-dive: [Chapter 16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency).

### What's NOT in Stripe: subscriptions

We deliberately do **not** use Stripe Subscriptions. Every membership renewal is a one-shot Checkout Session (`mode => 'payment'`); `cron/expire_memberships.php` and `cron/send_renewal_reminders.php` email a fresh payment link when a member is due. Why: members come and go mid-year and lapsed renewals shouldn't auto-charge; tiers vary (12M/24M, Full/Associate, Life) and we adjust the matrix manually; the committee likes a human-in-the-loop step. `StripeService::createSubscription()` exists but is unused.

## Where to change it

- **Admin UI:** Settings Hub → Payments (Stripe), under `public_html/admin/settings/`. Saves go through `StripeSettingsService::saveAdminSettings()`.
- **Env (local dev only):** put `STRIPE_TEST_SECRET_KEY=sk_test_…` in `.env.local` and leave the DB row blank.
- **Code:** `StripeService.php` for new SDK calls; `StripeSettingsService.php` for new toggles; `PaymentSettingsService.php` for channel-level fields.

### Switching test ↔ live

1. Settings Hub → Payments (Stripe). Confirm the **live** publishable and secret keys are filled in (`pk_live_` / `sk_live_`).
2. Confirm the **live** webhook secret (`whsec_…`) matches the endpoint you've configured in the Stripe Dashboard for live mode.
3. Untick "Use Stripe test mode" and save.

`payments.stripe.use_test_mode` flips immediately — next checkout uses live keys. No deploy, no cache.

### Rotating keys

1. Stripe Dashboard → Developers → API keys → Roll key.
2. Paste into Settings Hub → Payments (Stripe), save.
3. The old key keeps working briefly — verify a real checkout before revoking it.
4. Webhook signing secret rotates **separately** (Dashboard → Webhooks → endpoint → Roll secret). Paste the new `whsec_…` into the same admin page.

## Settings

All keys are under `payments.stripe.*` in `settings_global`, plus a few `payments.*` siblings:

| Key | Type | Enc | Notes |
|---|---|---|---|
| `payments.stripe.use_test_mode` | bool | – | Test/live switch |
| `payments.stripe.test_publishable_key` | string | – | `pk_test_…` |
| `payments.stripe.test_secret_key` | string | **yes** | `sk_test_…` |
| `payments.stripe.live_publishable_key` | string | – | `pk_live_…` |
| `payments.stripe.live_secret_key` | string | **yes** | `sk_live_…` |
| `payments.stripe.webhook_secret` | string | **yes** | `whsec_…` |
| `payments.stripe.allow_guest_checkout` | bool | – | Store-side |
| `payments.stripe.require_shipping_for_physical` | bool | – | Store-side |
| `payments.stripe.digital_only_minimal` | bool | – | Skip address for digital-only carts |
| `payments.stripe.enable_apple_pay` | bool | – | In `getPublicConfig()` |
| `payments.stripe.enable_google_pay` | bool | – | In `getPublicConfig()` |
| `payments.stripe.enable_bnpl` | bool | – | Afterpay/Klarna |
| `payments.stripe.send_receipts` | bool | – | Stripe emails its own receipt |
| `payments.stripe.save_invoice_refs` | bool | – | Persist Stripe IDs locally |
| `payments.stripe.checkout_enabled` | bool | – | Checkout kill-switch |
| `payments.stripe.customer_portal_enabled` | bool | – | Billing portal sessions |
| `payments.stripe.generate_pdf` | bool | – | PDF invoices — see [18](view.php?slug=18-invoices) |
| `payments.stripe.invoice_prefix` | string | – | Default `INV` |
| `payments.stripe.invoice_email_template` | string | – | Invoice email body |
| `payments.membership_prices` | array | – | Price IDs per tier — see [14](view.php?slug=14-membership-pricing) |
| `payments.membership_default_term` | string | – | `12M` or `24M` |
| `payments.membership_allow_both_types` | bool | – | Offer Full *and* Associate |
| `payments.bank_transfer_instructions` | string | – | Bank-transfer fallback copy |

Env-var fallbacks (used only when the DB value is blank): `STRIPE_TEST_PUBLISHABLE_KEY`, `STRIPE_TEST_SECRET_KEY`, `STRIPE_LIVE_PUBLISHABLE_KEY`, `STRIPE_LIVE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`. Plus `APP_KEY` in `.env` is what makes the encrypted columns decryptable.

## Screenshots

<!-- SCREENSHOT: Settings Hub → Payments (Stripe) page with both test and live key sections visible and the "Use Stripe test mode" toggle on. Capture on draft.goldwing.org.au as admin. Save as public_html/admin/help/images/13-stripe-settings.png. -->
<!-- ![Stripe settings page](../images/13-stripe-settings.png) -->

<!-- SCREENSHOT: Stripe Dashboard → Developers → Webhooks, showing the goldwing.org.au endpoint with the signing secret partially visible. Save as 13-stripe-webhook-endpoint.png. -->
<!-- ![Stripe webhook endpoint](../images/13-stripe-webhook-endpoint.png) -->

## Gotchas

- **`APP_KEY` decrypts the secrets.** Lose or change `APP_KEY` and the encrypted keys decode to empty strings — *every Stripe call silently no-ops* (`StripeService` returns `null` when the secret is blank). Checkout suddenly broken? Check `APP_KEY` first.
- **Keys are paired, mode is a single bool.** `validateKey()` rejects an `sk_live_` in the test slot. Don't be clever.
- **Legacy `settings_payments` row still exists.** `getSettings()` falls back to it when new fields are blank. Once you've saved through the new form it's no longer consulted — don't edit it directly.
- **`use_test_mode` infers from legacy on first read.** When the toggle's `updated_at` is null, the service falls back to the legacy `mode` field. The first save clears the ambiguity.
- **Webhook secret is per-endpoint, per-mode.** Different `whsec_…` for test vs live and per URL. Switch test→live without updating it → webhook 400s → `webhookHealth()` flips to `failing`.
- **No subscriptions means no automatic dunning.** Expired renewal links just sit there; `cron/send_renewal_reminders.php` does the nudging.
- **SDK is vendored, not Composer.** To upgrade: replace `app/ThirdParty/stripe-php/` from the upstream tarball, bump `VERSION`, re-test.

## Related chapters

- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — how the secret/webhook keys are encrypted with `APP_KEY`.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — Stripe Price IDs and tier mapping.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — Checkout Session creation, `orders` table, success/cancel.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — `/api/stripe_webhook.php`, sig verification, dedupe.
- [17 — Refunds](view.php?slug=17-refunds) — `RefundService` and the partial-refund flow.
- [18 — Invoices](view.php?slug=18-invoices) — `InvoiceService`, PDF generation, invoice counter.
- [27 — Store architecture](view.php?slug=27-store-architecture) — how the store shares the same Stripe account.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — `settings_global` and the `encrypt` flag.
