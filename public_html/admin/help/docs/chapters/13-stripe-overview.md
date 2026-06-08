# Stripe integration overview

## For administrators

### What this is

**Stripe** is the company that handles all the money for the Goldwing website. When a member renews, buys a polo from the store, or pays for an event ticket, Stripe takes the card details, talks to the bank, and pulls the money into the association's account. We never see or store card numbers ourselves — Stripe does that on their own pages.

There is **one Stripe account** behind everything — memberships, store, and events all share it. From the admin side you mostly don't need to think about Stripe; checkout just works. The bits you *will* touch are the keys (the long passwords that connect our site to Stripe), the test/live switch (a safety toggle so you can try things without real money moving), and the webhook (Stripe's way of telling us "that payment just succeeded").

### What it lets you do

- Take real card payments for memberships, store orders and event tickets
- Let members save a **card on file** with Stripe for faster future checkout (Member portal → Billing & Payments → Saved cards)
- Switch the whole site into **test mode** to try checkout without moving real money
- Switch back to **live mode** when you're ready to take real payments
- Rotate (replace) the connection keys if one is leaked or a staff member leaves
- Turn extras on or off: Apple Pay, Google Pay, Afterpay/Klarna ("buy now pay later"), guest checkout, automatic Stripe receipts
- Set whether the store needs a shipping address (only relevant for physical items)
- See whether the webhook (Stripe → us) is healthy or failing

Everything money-related downstream — orders, refunds, invoices, membership renewals — depends on this chapter being set up correctly.

### Who's allowed to do this

Changing Stripe settings is one of the most sensitive things you can do in admin. Only a **site administrator** should touch the keys. The Treasurer normally holds the Stripe Dashboard login (where you copy the keys *from*), and an admin pastes them into our site.

If you're not an admin, you won't see the Payments settings page at all.

### Where to find it in admin

![The Payments (Stripe) settings page](images/13-payments-tab.png)

{{link:/admin/settings/?section=payments|Take me to Payments (Stripe) settings}}

- **Stripe settings** — Admin → Settings Hub → **Payments (Stripe)**
- **Webhook health** — same page, near the top, shows the last time Stripe successfully reached us
- **The Stripe Dashboard itself** — `dashboard.stripe.com` (separate site, separate login, run by Stripe). This is where you go to roll keys or look up a charge.

### Common things you'll do here

**Switch from test mode to live mode** (usually done once when the site first goes live, and again briefly during testing):

1. Go to **Admin → Settings Hub → Payments (Stripe)**.
2. Confirm both **live** keys are filled in — publishable starts with `pk_live_`, secret starts with `sk_live_`.
3. Confirm the **live webhook secret** is filled in (starts with `whsec_`). This is a *different* webhook secret from the test one — see the gotcha below.
4. Untick **Use Stripe test mode** and click **Save**.

The switch is immediate — the next checkout uses live keys. No deploy, no waiting.

**Switch from live back to test mode** (to try checkout without real money):

Same page → tick **Use Stripe test mode** → **Save**. Now you can use Stripe's test card numbers (e.g. `4242 4242 4242 4242`).

**Rotate (replace) the keys** (do this if a key is leaked, a staff member with access leaves, or annually as good practice):

1. Log into the **Stripe Dashboard** → **Developers → API keys** → click **Roll** next to the key.
2. Copy the new key.
3. In our admin: **Admin → Settings Hub → Payments (Stripe)** → paste into the right slot → **Save**.
4. The old key keeps working briefly — run one real checkout to confirm before revoking the old one in the Stripe Dashboard.

The **webhook signing secret** is rotated separately, in **Dashboard → Webhooks → endpoint → Roll secret**. Paste it into the same admin page.

**Check the webhook is healthy** (do this if checkouts succeed but orders aren't appearing):

Go to **Admin → Settings Hub → Payments (Stripe)** and look at the webhook health indicator. **OK** means Stripe is talking to us; **failing** means it isn't. The most common cause of "failing" is a mismatched webhook secret after a test/live switch — see "What can go wrong" below.

### What can go wrong (and what to do)

- **Checkout suddenly breaks — clicking "Pay" does nothing.** Usually means the secret key is empty or wrong. Go to Payments (Stripe) and confirm both keys for the active mode (test or live) are filled in. If they look fine but it's still broken, the underlying encryption key (`APP_KEY`, a server-side secret) may have changed — flag this to your developer immediately; it's a server-config problem, not something you can fix in admin.
- **"That key doesn't look right" on save.** Each slot expects a specific prefix — test keys start `sk_test_` / `pk_test_`, live keys start `sk_live_` / `pk_live_`. If you paste a live key into the test slot or vice versa, the form will reject it. Check what you copied.
- **Webhook says "failing" after switching test → live (or live → test).** The webhook secret is different for test mode vs live mode. When you flip the toggle, you also need the matching webhook secret pasted in. Grab it from the Stripe Dashboard → Webhooks → click the endpoint → Reveal signing secret.
- **Orders aren't appearing even though Stripe shows the charge.** Webhook problem. Stripe took the money but couldn't tell us about it. Check webhook health, fix the secret, and ask the developer to replay the missed events from the Stripe Dashboard.
- **A member renewal "won't auto-renew next year."** Correct — we deliberately don't use Stripe Subscriptions. Every renewal is a fresh one-off payment, and the system emails the member a new payment link when they're due. Members never get auto-charged. This is by design.

### What gets recorded

- **In Stripe** — every payment, refund, customer and webhook attempt. Search by member email or order number in the Stripe Dashboard.
- **In our admin** — every order with the Stripe charge ID, every refund with the Stripe refund receipt, and the webhook health log so we know if Stripe and us have lost contact.
- **Encrypted at rest** — the Stripe secret key and webhook signing secret are stored encrypted in our database. Even someone with database access can't read them without the server-side encryption key.

### Good practice

- **Always test in test mode first.** Before changing anything pricing-related, switch to test mode, run a fake checkout with `4242 4242 4242 4242`, confirm everything looks right, then switch back to live.
- **Keep the Stripe Dashboard login with the Treasurer.** Pasting keys into our admin is one thing — being able to *generate* new keys is more sensitive. Limit Stripe Dashboard access.
- **Rotate keys yearly, and immediately if anyone with access leaves.** It's painless and is the cheapest insurance you can buy.
- **Don't edit anything called "legacy payment settings" if you see it.** That's an old fallback row that the system reads only if the new form is empty. Once you've saved the new form, leave the legacy stuff alone.
- **After a test ↔ live switch, always re-check webhook health within five minutes.** This is the most common place for setup to get out of sync.

### Who to ask if you're stuck

- **Lost Stripe Dashboard access** — the Treasurer.
- **Webhook is failing and you don't know why** — your developer.
- **Charges in Stripe but missing orders in admin** — your developer (they can replay webhooks).
- **A specific charge looks wrong** — Stripe support (chat in the Stripe Dashboard, usually quick).
- **Anything mentioning "APP_KEY," "encrypted," or "SDK"** — your developer. These are server-side and not fixable from admin.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

How the Goldwing site takes money. Stripe is the only payment processor we use, one Stripe account drives both membership renewals and store orders, and the test/live switch is the key prefix. This chapter is the gateway to Part 4 — the later chapters (pricing, orders, webhooks, refunds, invoices) all assume you've read this one.

### Why it exists

We picked Stripe over Eway, PayPal, and bank-direct for three reasons:

- **One account, every product.** Stripe Checkout looks the same whether the line item is a membership renewal, a club polo, or an event ticket. One dashboard, one set of keys, one webhook endpoint. Eway needed a separate hosted-payment-page per product type; PayPal was clunky for one-off invoiced renewals.
- **First-class test mode.** Every API call is automatically test or live depending on the secret-key prefix — no separate environment to sync.
- **Modern SDK + webhooks.** The official `stripe/stripe-php` SDK (13.16.0, vendored at `app/ThirdParty/stripe-php/`) handles HTTP, retries, and signature verification.

Trade-off: locked into Stripe's fees and their account-suspension risk. Acceptable for a single-association site.

### How it works

#### One account, two products

**One Stripe account** for the whole site. Memberships and store both submit Checkout Sessions against it, both hit `/api/stripe_webhook.php`, both write into the `orders` table. In Stripe you tell them apart by the `metadata` attached on session creation (`member_id` vs `order_id`).

#### Test vs live: it's the key prefix

No "environment" toggle in code — the mode is whichever secret key is active:

- `sk_test_…` → test mode (real cards ignored, no money moves).
- `sk_live_…` → live mode.

The validator enforces this on save:

```php
self::validateKey($testSecret, 'sk_test_', 'Test secret key', $errors);
self::validateKey($liveSecret, 'sk_live_', 'Live secret key', $errors);
```

The admin form stores **both** test and live keys side-by-side; the boolean `payments.stripe.use_test_mode` picks which pair the SDK sees. Resolution lives in `StripeSettingsService::getActiveKeys()`, reached via `StripeService::activeSecretKey()`. Safety net: live mode with a blank live secret falls back to test so checkout doesn't lock.

#### Where the keys are stored

In order of precedence (highest first):

1. **`settings_global` table**, written through the admin page. Secret and webhook keys are encrypted at rest via `CryptoService` (see [Chapter 10](view.php?slug=10-encryption-secrets)). Publishable keys are stored clear — they're public anyway.
2. **Env vars** — `STRIPE_TEST_SECRET_KEY`, `STRIPE_LIVE_SECRET_KEY`, `STRIPE_TEST_PUBLISHABLE_KEY`, `STRIPE_LIVE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`. Used only when the matching DB row is empty. Handy in local dev.
3. **Legacy `settings_payments` row** (channel `primary`) — migration fallback. New deployments shouldn't rely on it.

`config/app.php` declares a `stripe` section with empty defaults — just so the array shape exists. Real values live in the DB.

#### The SDK

`app/ThirdParty/stripe-php/` is vendored, loaded via `require_once __DIR__ . '/../ThirdParty/stripe-php/init.php'` at the top of `StripeService.php`. No Composer at runtime — committed in-tree so deploys skip `composer install`.

#### The three services you'll touch

- **`StripeService`** (`app/Services/StripeService.php`) — low-level SDK wrapper. Every Stripe API call goes through here: `createCheckoutSession`, `createCheckoutSessionForPrice` (single Stripe price ID), `createCheckoutSessionForPrices` (multiple price IDs in one session — used by member renewal when bundling a partner), `createCheckoutSessionWithLineItems` (ad-hoc `price_data` items), `createCustomer`, `createRefund`, `retrievePaymentIntent`, `constructEvent`. Resolves the secret key internally, so callers never see one.
- **`StripeSettingsService`** (`app/Services/StripeSettingsService.php`) — read/write keys, mode flag, feature toggles (Apple Pay, Google Pay, BNPL, guest checkout). `getPublicConfig()` feeds the front-end Stripe.js client.
- **`PaymentSettingsService`** (`app/Services/PaymentSettingsService.php`) — manages `payment_channels` / `settings_payments`: invoice prefix, last-webhook timestamp, per-year invoice counter. Only the `primary` channel is used today.

#### Webhooks

Stripe POSTs every payment event to `/api/stripe_webhook.php`, which:

1. Reads the raw body and `Stripe-Signature` header.
2. Verifies the signature against the **webhook signing secret** (`whsec_…`, in `payments.stripe.webhook_secret`, encrypted). Bad sig → HTTP 400, logs `Invalid signature` to the webhook health row.
3. De-duplicates by event ID via `PaymentWebhookService::recordEvent()` — Stripe retries, we never double-fulfil.
4. Dispatches `checkout.session.completed`, `payment_intent.*`, `charge.refunded`, `invoice.*`, and `customer.subscription.*` to handlers in `PaymentWebhookService`.

Deep-dive: [Chapter 16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency).

#### Saved cards (card-on-file)

Members can save a card via Stripe Elements on `/member/index.php?page=billing`. Implementation:

- Front-end: a "Saved cards" panel mounts Stripe Elements inline, asks `/api/billing/setup-intent` for a SetupIntent `client_secret`, then calls `stripe.confirmCardSetup()`. Card data goes Stripe → Stripe — never touches our server.
- Back-end: four routes under `/api/billing/` in `public_html/api/index.php`:
  - `POST /api/billing/setup-intent` — creates/links `members.stripe_customer_id` (same lazy-create as `/api/billing/portal`), then `StripeService::createSetupIntent` with `usage => off_session`.
  - `GET /api/billing/payment-methods` — `StripeService::listPaymentMethods($customerId, 'card')` + `retrieveCustomer` to flag `invoice_settings.default_payment_method`.
  - `POST /api/billing/payment-methods/{id}/default` — `updateCustomer` with `invoice_settings.default_payment_method`.
  - `DELETE /api/billing/payment-methods/{id}` — `detachPaymentMethod`. If the deleted PM was the default, the handler promotes the next remaining card so we never leave a dangling default pointer.
- First card added is auto-promoted to default client-side, so the customer always has a valid default once they save at least one.
- Independent of `payments.stripe.customer_portal_enabled` — the portal toggle controls the Stripe-hosted portal button; saved cards are always available when Stripe is configured.

#### What's NOT in Stripe: subscriptions

We deliberately do **not** use Stripe Subscriptions. Every membership renewal is a one-shot Checkout Session (`mode => 'payment'`); `cron/expire_memberships.php` and `cron/send_renewal_reminders.php` email a fresh payment link when a member is due. Why: members come and go mid-year and lapsed renewals shouldn't auto-charge; tiers vary (12M/24M, Full/Associate, Life) and we adjust the matrix manually; the committee likes a human-in-the-loop step. `StripeService::createSubscription()` exists but is unused.

### Where to change it

- **Admin UI:** Settings Hub → Payments (Stripe), under `public_html/admin/settings/`. Saves go through `StripeSettingsService::saveAdminSettings()`.
- **Env (local dev only):** put `STRIPE_TEST_SECRET_KEY=sk_test_…` in `.env.local` and leave the DB row blank.
- **Code:** `StripeService.php` for new SDK calls; `StripeSettingsService.php` for new toggles; `PaymentSettingsService.php` for channel-level fields.

#### Switching test ↔ live

1. Settings Hub → Payments (Stripe). Confirm the **live** publishable and secret keys are filled in (`pk_live_` / `sk_live_`).
2. Confirm the **live** webhook secret (`whsec_…`) matches the endpoint you've configured in the Stripe Dashboard for live mode.
3. Untick "Use Stripe test mode" and save.

`payments.stripe.use_test_mode` flips immediately — next checkout uses live keys. No deploy, no cache.

#### Rotating keys

1. Stripe Dashboard → Developers → API keys → Roll key.
2. Paste into Settings Hub → Payments (Stripe), save.
3. The old key keeps working briefly — verify a real checkout before revoking it.
4. Webhook signing secret rotates **separately** (Dashboard → Webhooks → endpoint → Roll secret). Paste the new `whsec_…` into the same admin page.

### Settings

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

### Gotchas

- **`APP_KEY` decrypts the secrets.** Lose or change `APP_KEY` and the encrypted keys decode to empty strings — *every Stripe call silently no-ops* (`StripeService` returns `null` when the secret is blank). Checkout suddenly broken? Check `APP_KEY` first.
- **Keys are paired, mode is a single bool.** `validateKey()` rejects an `sk_live_` in the test slot. Don't be clever.
- **Legacy `settings_payments` row still exists.** `getSettings()` falls back to it when new fields are blank. Once you've saved through the new form it's no longer consulted — don't edit it directly.
- **`use_test_mode` infers from legacy on first read.** When the toggle's `updated_at` is null, the service falls back to the legacy `mode` field. The first save clears the ambiguity.
- **Webhook secret is per-endpoint, per-mode.** Different `whsec_…` for test vs live and per URL. Switch test→live without updating it → webhook 400s → `webhookHealth()` flips to `failing`.
- **No subscriptions means no automatic dunning.** Expired renewal links just sit there; `cron/send_renewal_reminders.php` does the nudging.
- **SDK is vendored, not Composer.** To upgrade: replace `app/ThirdParty/stripe-php/` from the upstream tarball, bump `VERSION`, re-test.

</details>

<!-- SCREENSHOT: Settings Hub → Payments (Stripe) page with both test and live key sections visible and the "Use Stripe test mode" toggle on. Capture on goldwing.org.au as admin. Save as public_html/admin/help/images/13-stripe-settings.png. -->
<!-- ![Stripe settings page](../images/13-stripe-settings.png) -->

<!-- SCREENSHOT: Stripe Dashboard → Developers → Webhooks, showing the goldwing.org.au endpoint with the signing secret partially visible. Save as 13-stripe-webhook-endpoint.png. -->
<!-- ![Stripe webhook endpoint](../images/13-stripe-webhook-endpoint.png) -->

## Related chapters

- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — how the secret/webhook keys are encrypted with `APP_KEY`.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — Stripe Price IDs and tier mapping.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — Checkout Session creation, `orders` table, success/cancel.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — `/api/stripe_webhook.php`, sig verification, dedupe.
- [17 — Refunds](view.php?slug=17-refunds) — `RefundService` and the partial-refund flow.
- [18 — Invoices](view.php?slug=18-invoices) — `InvoiceService`, PDF generation, invoice counter.
- [27 — Store architecture](view.php?slug=27-store-architecture) — how the store shares the same Stripe account.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — `settings_global` and the `encrypt` flag.
