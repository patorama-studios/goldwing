# Webhooks & idempotency

## What this covers

Stripe is the source of truth for "did this payment go through?". We hear about it via webhooks — Stripe POSTs a JSON event every time something happens (checkout completed, charge refunded, subscription failed). This chapter covers how the site receives those events, why they're only processed once, what each event does, and what to do when one fails.

The endpoint is `public_html/api/stripe_webhook.php`. The work happens in `App\Services\PaymentWebhookService`. The dedupe table is `webhook_events`.

## Why it exists

Stripe will retry a webhook **for up to 3 days** if it doesn't get a `2xx`. Great for reliability, but it means our handler **will** see the same event more than once. If we blindly re-ran the logic each time, a single retry would mark an order paid twice, create two invoices, send two confirmation emails, and decrement stock twice. So:

- **Verify the signature** — anyone can POST JSON at the endpoint; only Stripe knows the signing secret.
- **Record the event before processing** — with a `UNIQUE` constraint on the Stripe event ID so the second insert fails fast.
- **Make every handler idempotent** — re-running on an already-paid order is a no-op.

Standard Stripe pattern; we lean on their guidance pretty literally.

## How it works

### The endpoint

`public_html/api/stripe_webhook.php` is short on purpose. The flow:

```php
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = StripeSettingsService::getWebhookSecret();
$event     = StripeService::constructEvent($payload, $signature, $secret);
```

`StripeService::constructEvent()` is a thin wrapper around `\Stripe\Webhook::constructEvent()` (the official SDK in `app/ThirdParty/stripe-php/`). It returns `null` on a bad signature or missing secret — the endpoint then responds `400 Invalid signature` and records the failure against the payment channel.

### Idempotency

If the signature is good we call `PaymentWebhookService::recordEvent($event)`, which inserts into `webhook_events` with columns `stripe_event_id`, `type`, `payload_json`, `processed_status`, `received_at`. `stripe_event_id` is `VARCHAR(120) NOT NULL UNIQUE`. When Stripe retries the same event, the insert hits the unique constraint, `recordEvent()` returns `false`, and the endpoint responds `200 OK` without doing anything else. That's the entire dedupe story — one SQL constraint.

### Which events we handle

The dispatcher in `stripe_webhook.php` routes on `$event['type']`:

| Event type | Handler | What it does |
|---|---|---|
| `checkout.session.completed` | `handleCheckoutCompleted()` | Marks the `orders` row paid, saves `stripe_customer_id` on the member, activates the membership or store order, creates an invoice. |
| `payment_intent.succeeded` | `handlePaymentIntentSucceeded()` | Same, for flows skipping Checkout Sessions. Ignored if `intent.invoice` is set. |
| `payment_intent.payment_failed`, `payment_intent.canceled` | `handlePaymentFailed()` | Marks the order `cancelled / failed`, dispatches `membership_payment_failed`. |
| `charge.refunded` | `handleChargeRefunded()` | Marks order `refunded`, inserts a `refunds` row, demotes membership to `unpaid`, updates `store_orders.status`. |
| `invoice.paid` | `handleInvoicePaid()` | Subscription invoices: backfills `stripe_invoice_id` / `stripe_subscription_id`, activates membership periods (including associate periods in `internal_notes`), reactivates the user. |
| `invoice.payment_failed` | `handleInvoicePaymentFailed()` | Flips the order to failed and the `membership_periods` row to `PENDING_PAYMENT`. |
| `customer.subscription.updated`, `customer.subscription.deleted` | `handleSubscriptionUpdated()` | `past_due` → mark order payment failed. `canceled / unpaid / incomplete_expired` → cancel order, lapse the period, set member `INACTIVE`. |

Anything else gets recorded in `webhook_events` but no further action runs.

After the dispatcher, `markProcessed($eventId, 'processed' | 'failed', $error)` updates the row, logs to `ActivityLogger`, and on failure calls `alertOnFailures()` which checks the threshold and may fire a Security Alert email.

### How retries work

- **Stripe side:** non-`2xx` triggers exponential-backoff retries for up to 3 days, then it's surfaced on the Dashboard.
- **Our side:** an unhandled exception returns `500 Webhook error` — but the row was **already inserted** by `recordEvent()`. So Stripe's retries dedupe and are silently skipped. The event is *recorded* exactly once even when processing crashed. To re-drive a failure, see Gotchas.

## Where to change it

- **Endpoint URL** — Settings Hub → Payments → Stripe shows the canonical URL (`/api/stripe/webhook` via Apache rewrite onto `/api/stripe_webhook.php`). Paste into the Stripe Dashboard webhook destination.
- **Add an event handler** — write the method in `PaymentWebhookService`, add an `if ($type === '...')` branch in `stripe_webhook.php`. Make it idempotent (check current status before mutating).
- **Failure-alert behaviour** — Settings Hub → Security & Authentication → "Webhook failure monitoring" (see Settings).
- **Rotate the signing secret** — Settings Hub → Payments → Stripe → "Webhook signing secret". Paste the new `whsec_…` and save (the field rejects anything not starting with `whsec_`). Then roll the secret on the matching endpoint in the Stripe Dashboard. Briefly, old in-flight retries signed with the previous secret will fail verification and retry until Stripe catches up.

## Settings

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


<!-- SCREENSHOT: Settings Hub → Payments → Stripe section, showing the read-only webhook URL field, the masked webhook signing secret, and the health line ("Status: OK, Last webhook: ..., Last error: None"). Save as 16-stripe-webhook-card.png. -->
<!-- ![Stripe webhook card](../images/16-stripe-webhook-card.png) -->

<!-- SCREENSHOT: Settings Hub → Security & Authentication, the "Webhook failure monitoring" subsection with the enabled checkbox, threshold (3), and window (10). Save as 16-webhook-alert-settings.png. -->
<!-- ![Webhook failure alert settings](../images/16-webhook-alert-settings.png) -->

## Gotchas

- **`bootstrap.php` runs on webhooks too.** The endpoint starts a fresh PHP session (via `DbSessionHandler`) on every POST. Stripe doesn't send the session cookie, so the session is always empty but *does* exist — that's why you see phantom rows in `sessions` with no `user_id`. Documented in [Chapter 01 — Gotchas](view.php?slug=01-system-overview).
- **A failed handler still consumes the event.** `recordEvent()` inserts before the handler runs, so a `500` does not undo the insert. To re-drive a failed event: delete the row from `webhook_events` (Stripe will retry within its retry window) or resend it from the Stripe Dashboard (which sends a *new* event ID and re-enters the pipeline).
- **No row-level lock around the dedupe insert.** Two simultaneous deliveries of the same event could both win the unique check. Hasn't happened in production; the fix would be `SELECT ... FOR UPDATE` or `INSERT ... ON DUPLICATE KEY UPDATE` with a returned-row check.
- **Subscription invoices route via `invoice.paid`, not `payment_intent.succeeded`.** Both handlers early-return on `!empty($intent['invoice'])` — copy that guard in any new payment-intent handler or you'll double-process renewals.
- **Local testing with the Stripe CLI:** run `stripe listen --forward-to localhost/api/stripe/webhook`, paste the printed `whsec_…` into Settings → Stripe **while in test mode**, then `stripe trigger checkout.session.completed`. Swap the secret back before testing live.
- **There is no dedicated Payments Debug page.** The Stripe card in Settings (webhook health) plus direct SQL against `webhook_events` is the current debugging surface. A future page should expose recent events with status/error, a "replay" button, and per-event-type failure counts.

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — keys, modes, channels, the wrapper service.
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — what produces the `orders` rows the webhook then marks paid.
- [17 — Refunds](view.php?slug=17-refunds) — what `handleChargeRefunded()` triggers when the refund originates on the Stripe side.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — the dispatcher behind `membership_payment_failed`, `store_order_confirmation`, and the security alert emails.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how the Stripe secret and the security thresholds are stored and audited.
