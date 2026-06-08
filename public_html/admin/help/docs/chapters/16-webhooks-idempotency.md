# Webhooks & idempotency

## For administrators

### What this is

A **webhook** is Stripe's way of telling us when something happened — without us having to ask. When a member pays, Stripe rings our doorbell and says "they paid". When Stripe processes a refund, it rings again and says "that one's refunded". This is how the site finds out about payments without anyone manually checking the Stripe dashboard.

You don't *do* webhooks. They run quietly in the background, every time money moves. You only ever look at them when something's gone wrong, or to confirm everything's still working.

### What it does for you

Without webhooks, the site wouldn't know a member actually paid until someone went and looked. With webhooks:

- Orders flip from **pending** to **paid** the moment Stripe confirms the payment.
- Membership renewals activate automatically when the renewal payment clears.
- If Stripe processes a refund (e.g. a chargeback, or a refund someone issued from the Stripe dashboard rather than ours), it appears in our records too.
- Failed payments show up as failed, so you can chase the member.
- The whole site stays in sync with Stripe with no manual work.

### Who's allowed to manage this

**Admin only.** The webhook settings (the signing secret, the health line, the failure alerts) all live behind admin permissions. A treasurer or committee member can see whether webhooks are healthy when looking at the Stripe settings, but only an admin can change anything.

### Where to find it in admin

- **Admin → Settings → Payments → Stripe** — there's a **Webhook** panel showing the canonical URL (to paste into the Stripe dashboard), the masked signing secret, and a health line at the bottom: **OK / Failing / Stale**.
- **Admin → Settings → Security & Authentication** — the **Webhook failure monitoring** section, where you set how many failures in how many minutes triggers an alert email.

That's it. There's no separate "webhooks" page — it's a part of the Stripe settings.

### What you'll typically do here

In a normal month, **nothing**. The webhook just works. Realistic admin tasks:

- **Glance at the health line once a month.** It should say **OK** with a recent timestamp. If it says **Stale** (no webhooks for a while) or **Failing**, something needs looking at.
- **Rotate the signing secret when Stripe asks** — Stripe occasionally prompts you to roll the secret on a webhook endpoint. Generate a new `whsec_…` in the Stripe dashboard, paste it into Admin → Settings → Payments → Stripe → Webhook signing secret, save.
- **Replay a failed event** if you're told one didn't process. You do this from the Stripe dashboard (Developers → Webhooks → click the event → Resend), not from our admin.

### What "idempotent" means (in plain English)

It's a fancy word for a simple idea: **doing the same thing twice has the same effect as doing it once**.

Stripe sometimes tells us about the same event more than once. It might be Stripe retrying because our server was slow, or a temporary blip on the network. If we blindly did the work every time we were told, a member who paid once might be marked as having paid twice, get two confirmation emails, and have their stock decremented twice.

So the site is built to **deal with duplicates gracefully**. The first time Stripe tells us "they paid", we mark the order paid. The second, third, and fourth time Stripe tells us the same thing, we go "yep, already done" and move on. **No member is ever charged twice, refunded twice, or emailed twice** because of a Stripe retry. That's what idempotent means here.

You don't have to do anything to make this work — it's how the code is built.

### What can go wrong (and the fix)

- **The webhook health line says "Failing".** Most common cause: the signing secret in Admin → Settings → Payments → Stripe doesn't match the one shown for this endpoint in the Stripe dashboard. Fix: copy the secret from Stripe (Developers → Webhooks → click the endpoint → Reveal) and paste it into our settings. Save.
- **The health line says "Stale" (no webhooks for hours/days).** Either Stripe stopped sending (rare — check the endpoint is still enabled in the Stripe dashboard) or no transactions have happened. If the site has been quiet, this is normal.
- **Orders stuck on "pending" after payment.** A webhook failed to process. Check the Stripe dashboard (Developers → Webhooks → click the endpoint) for failed deliveries — Stripe will retry for up to 3 days automatically. If it's outside that window, click the event and hit **Resend**.
- **You rotated the secret and now everything's failing.** Make sure you pasted the new secret into *both* places: our admin Settings *and* updated the matching endpoint in the Stripe dashboard. Old in-flight retries signed with the previous secret will briefly fail until Stripe catches up — that's normal and resolves itself within minutes.
- **The "Webhook failure" alert email keeps firing.** Either there's a real problem (start with the secret mismatch above) or your threshold is too sensitive. Default is 3 failures in 10 minutes. If your shop is busy, you can widen the window.

### What gets recorded

- **Every webhook Stripe sends us** is logged in our `webhook_events` table with its Stripe event ID, type, and status (processed or failed).
- **Every failure** is logged in the activity log (Admin → Security Log — search for `webhook`).
- **Failure clusters trigger an alert email** to the security alert recipient if you've crossed the threshold.
- **The Stripe dashboard** is the other source of truth — Developers → Webhooks shows every event Stripe sent, with the response code our server returned and the option to resend.

Between those four places, no event ever gets quietly lost.

### Good practice

- **Leave failure alerts on.** Don't disable them just because they fire occasionally — that's their job. If they're noisy, raise the threshold rather than turn them off.
- **Check the webhook health line once a month** when you're already in Stripe settings. Thirty seconds.
- **Rotate the signing secret when Stripe prompts you** — usually once a year. Don't put it off.
- **Don't share the signing secret in email or chat.** It's a key that controls who can pretend to be Stripe talking to our site. Treat it like a password.
- **If you're testing in Stripe test mode, use a separate test endpoint** — never paste a test-mode secret into the live settings.

### Who to ask if you're stuck

- **Webhook health line stuck on "Failing"** — your developer; they can check our server logs for what's actually being rejected.
- **You're not sure if a real payment "got through"** — your treasurer (they have Stripe dashboard access) can confirm against Stripe's record.
- **You think the threshold or window for alerts needs tuning** — your developer, they can help you pick sensible numbers based on your traffic.
- **The Stripe dashboard says everything's fine but our site disagrees** — your developer; this usually means a webhook arrived but a handler crashed, and they'll need to look at the logs.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

Stripe is the source of truth for "did this payment go through?". We hear about it via webhooks — Stripe POSTs a JSON event every time something happens (checkout completed, charge refunded, subscription failed). This chapter covers how the site receives those events, why they're only processed once, what each event does, and what to do when one fails.

The endpoint is `public_html/api/stripe_webhook.php`. The work happens in `App\Services\PaymentWebhookService`. The dedupe table is `webhook_events`.

### Why it exists

Stripe will retry a webhook **for up to 3 days** if it doesn't get a `2xx`. Great for reliability, but it means our handler **will** see the same event more than once. If we blindly re-ran the logic each time, a single retry would mark an order paid twice, create two invoices, send two confirmation emails, and decrement stock twice. So:

- **Verify the signature** — anyone can POST JSON at the endpoint; only Stripe knows the signing secret.
- **Record the event before processing** — with a `UNIQUE` constraint on the Stripe event ID so the second insert fails fast.
- **Make every handler idempotent** — re-running on an already-paid order is a no-op.

Standard Stripe pattern; we lean on their guidance pretty literally.

### How it works

#### The endpoint

`public_html/api/stripe_webhook.php` is short on purpose. The flow:

```php
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = StripeSettingsService::getWebhookSecret();
$event     = StripeService::constructEvent($payload, $signature, $secret);
```

`StripeService::constructEvent()` is a thin wrapper around `\Stripe\Webhook::constructEvent()` (the official SDK in `app/ThirdParty/stripe-php/`). It returns `null` on a bad signature or missing secret — the endpoint then responds `400 Invalid signature` and records the failure against the payment channel.

#### Idempotency

If the signature is good we call `PaymentWebhookService::recordEvent($event)`, which inserts into `webhook_events` with columns `stripe_event_id`, `type`, `payload_json`, `processed_status`, `received_at`. `stripe_event_id` is `VARCHAR(120) NOT NULL UNIQUE`. When Stripe retries the same event, the insert hits the unique constraint, `recordEvent()` returns `false`, and the endpoint responds `200 OK` without doing anything else. That's the entire dedupe story — one SQL constraint.

#### Which events we handle

The dispatcher in `stripe_webhook.php` routes on `$event['type']`:

| Event type | Handler | What it does |
|---|---|---|
| `checkout.session.completed` | `handleCheckoutCompleted()` | Marks the `orders` row paid, saves `stripe_customer_id` on the member, activates the membership or store order, creates an invoice. For store orders also closes out the cart (`store_carts.status = 'converted'`) — see below. |
| `payment_intent.succeeded` | `handlePaymentIntentSucceeded()` | Same, for the on-page Payment Element flows (store checkout + membership apply). Ignored if `intent.invoice` is set. Also closes out the cart for store orders. |
| `payment_intent.payment_failed`, `payment_intent.canceled` | `handlePaymentFailed()` | Marks the order `cancelled / failed`, dispatches `membership_payment_failed`. |
| `charge.refunded` | `handleChargeRefunded()` | Marks order `refunded`, inserts a `refunds` row, demotes membership to `unpaid`, updates `store_orders.status`. |
| `invoice.paid` | `handleInvoicePaid()` | Subscription invoices: backfills `stripe_invoice_id` / `stripe_subscription_id`, activates membership periods (including associate periods in `internal_notes`), reactivates the user. |
| `invoice.payment_failed` | `handleInvoicePaymentFailed()` | Flips the order to failed and the `membership_periods` row to `PENDING_PAYMENT`. |
| `customer.subscription.updated`, `customer.subscription.deleted` | `handleSubscriptionUpdated()` | `past_due` → mark order payment failed. `canceled / unpaid / incomplete_expired` → cancel order, lapse the period, set member `INACTIVE`. |

Anything else gets recorded in `webhook_events` but no further action runs.

> **Cart conversion happens here, not at checkout-create time.** For store orders, `markStoreOrderPaid()` (called by both `handleCheckoutCompleted` and `handlePaymentIntentSucceeded`) is what flips `store_carts.status` from `active` to `converted`. The `/api/stripe/create-payment-intent` endpoint deliberately leaves the cart `active` so a card decline or abandoned session doesn't lock the member out. The cart is looked up by `metadata.cart_id` on the PI/Session, with a fallback to the user's currently-active cart row.

After the dispatcher, `markProcessed($eventId, 'processed' | 'failed', $error)` updates the row, logs to `ActivityLogger`, and on failure calls `alertOnFailures()` which checks the threshold and may fire a Security Alert email.

#### How retries work

- **Stripe side:** non-`2xx` triggers exponential-backoff retries for up to 3 days, then it's surfaced on the Dashboard.
- **Our side:** an unhandled exception returns `500 Webhook error` — but the row was **already inserted** by `recordEvent()`. So Stripe's retries dedupe and are silently skipped. The event is *recorded* exactly once even when processing crashed. To re-drive a failure, see Gotchas.

### Where to change it

- **Endpoint URL** — Settings Hub → Payments → Stripe shows the canonical URL (`/api/stripe/webhook` via Apache rewrite onto `/api/stripe_webhook.php`). Paste into the Stripe Dashboard webhook destination.
- **Add an event handler** — write the method in `PaymentWebhookService`, add an `if ($type === '...')` branch in `stripe_webhook.php`. Make it idempotent (check current status before mutating).
- **Failure-alert behaviour** — Settings Hub → Security & Authentication → "Webhook failure monitoring" (see Settings).
- **Rotate the signing secret** — Settings Hub → Payments → Stripe → "Webhook signing secret". Paste the new `whsec_…` and save (the field rejects anything not starting with `whsec_`). Then roll the secret on the matching endpoint in the Stripe Dashboard. Briefly, old in-flight retries signed with the previous secret will fail verification and retry until Stripe catches up.

### Settings

Stripe payment settings — `StripeSettingsService`, stored in `settings_global`:

| Key | What |
|---|---|
| `payments.stripe.webhook_secret` | The `whsec_…` signing secret. **Encrypted at rest.** Falls back to `STRIPE_WEBHOOK_SECRET` env var, then to a legacy JSON blob, in that order. |
| `payments.stripe.secret_key` | Stripe API secret key — not used by the webhook directly but needed when handlers call back to Stripe. |

Webhook monitoring — `SecuritySettingsService`, stored in the `security_settings` row (id = 1):

| Column | Default | What |
|---|---|---|
| `webhook_alerts_enabled` | `1` | Master switch for the failure-threshold alert. |
| `webhook_alert_threshold` | `3` | Fire an alert if this many failures land inside the window. |
| `webhook_alert_window_minutes` | `10` | The rolling window. |
| `alerts_json.webhook_failure` | `true` | Whether the `webhook_failure` alert type is enabled in the dispatcher. |
| `alert_email` | (unset) | Recipient for security alerts. Falls back to admin emails. |

Per-channel webhook health (on `settings_payments`, written by `PaymentSettingsService::updateWebhookStatus()`): `last_webhook_received_at` and `last_webhook_error`. Rendered in the Stripe settings card as **OK / Failing / Stale** via `StripeSettingsService::webhookHealth()`.

### Gotchas

- **`bootstrap.php` runs on webhooks too.** The endpoint starts a fresh PHP session (via `DbSessionHandler`) on every POST. Stripe doesn't send the session cookie, so the session is always empty but *does* exist — that's why you see phantom rows in `sessions` with no `user_id`. Documented in [Chapter 01 — Gotchas](view.php?slug=01-system-overview).
- **A failed handler still consumes the event.** `recordEvent()` inserts before the handler runs, so a `500` does not undo the insert. To re-drive a failed event: delete the row from `webhook_events` (Stripe will retry within its retry window) or resend it from the Stripe Dashboard (which sends a *new* event ID and re-enters the pipeline).
- **No row-level lock around the dedupe insert.** Two simultaneous deliveries of the same event could both win the unique check. Hasn't happened in production; the fix would be `SELECT ... FOR UPDATE` or `INSERT ... ON DUPLICATE KEY UPDATE` with a returned-row check.
- **Subscription invoices route via `invoice.paid`, not `payment_intent.succeeded`.** Both handlers early-return on `!empty($intent['invoice'])` — copy that guard in any new payment-intent handler or you'll double-process renewals.
- **Local testing with the Stripe CLI:** run `stripe listen --forward-to localhost/api/stripe/webhook`, paste the printed `whsec_…` into Settings → Stripe **while in test mode**, then `stripe trigger checkout.session.completed`. Swap the secret back before testing live.
- **There is no dedicated Payments Debug page.** The Stripe card in Settings (webhook health) plus direct SQL against `webhook_events` is the current debugging surface. A future page should expose recent events with status/error, a "replay" button, and per-event-type failure counts.

</details>

<!-- SCREENSHOT: Settings Hub → Payments → Stripe section, showing the read-only webhook URL field, the masked webhook signing secret, and the health line ("Status: OK, Last webhook: ..., Last error: None"). Save as 16-stripe-webhook-card.png. -->
<!-- ![Stripe webhook card](../images/16-stripe-webhook-card.png) -->

<!-- SCREENSHOT: Settings Hub → Security & Authentication, the "Webhook failure monitoring" subsection with the enabled checkbox, threshold (3), and window (10). Save as 16-webhook-alert-settings.png. -->
<!-- ![Webhook failure alert settings](../images/16-webhook-alert-settings.png) -->

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — keys, modes, channels, the wrapper service.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — what produces the `orders` rows the webhook then marks paid.
- [17 — Refunds](view.php?slug=17-refunds) — what `handleChargeRefunded()` triggers when the refund originates on the Stripe side.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — the dispatcher behind `membership_payment_failed`, `store_order_confirmation`, and the security alert emails.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how the Stripe secret and the security thresholds are stored and audited.
