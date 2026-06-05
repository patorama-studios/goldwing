# Logs & troubleshooting

## What this covers

Where to look when something on the site is broken, slow, or behaving weirdly. This chapter is the triage map: which log holds what, which table tells you which story, which third-party dashboard owns which problem, and at what point you stop poking and call the dev. Everything below assumes you're logged in as an admin and have shell or cPanel access to the server.

## Why it exists

For most of the site's life the only person who could diagnose anything was Pat, because the knowledge of "where Stripe writes its failures" or "why a webhook can fail silently" lived in his head. That doesn't scale. The goal of this chapter is that any admin can answer "what just happened?" for the common failure modes without paging the dev — and when the dev *does* need to be involved, the admin has already collected the right log lines to make the conversation short.

The site has more places that can fail than there are log destinations, so part of triage is mapping the symptom (member can't log in, payment shows pending, email never arrived) to the right place to look. The table further down is that map.

## How it works

There are six places logs and evidence accumulate:

1. **`app/storage/logs/system.log`** — the application's PHP error log. `App\Services\LogViewerService::configurePhpLogging()` runs in `app/bootstrap.php` and points `ini_set('error_log', …)` at this file, so every uncaught exception, every `error_log()` call, and every PHP warning/notice ends up here. The Settings Hub renders the last 300 lines via the same service.
2. **cPanel per-domain `error_log`** — written by Apache/PHP-FPM at the server level. Catches things that happen *before* bootstrap runs (syntax errors in `bootstrap.php` itself, fatal PHP-FPM startup issues, htaccess problems). Lives at `~/public_html/error_log` (or per-domain).
3. **cPanel "Errors" tile** — a UI view of the per-domain error_log. Easier to skim than tailing the file.
4. **MySQL audit tables** — `activity_log` (who did what — Chapter 08), `audit_log` (what changed in settings/data — Chapter 08), `login_attempts` (failed and successful logins — Chapter 12), `webhook_events` (every Stripe event we received and how we handled it — Chapter 16), `email_log` (every outbound email).
5. **Stripe Dashboard** — payment intents, charges, refunds, webhook delivery attempts, dispute notices. The source of truth for everything money-related.
6. **SMTP / Resend dashboard** — delivery, bounces, spam complaints for outbound mail.

Triage rule of thumb: start with the *user-facing* symptom, jump to the table or dashboard that owns the underlying event, and only fall back to `system.log` if neither tells you anything.

## Where to find each

| Symptom | First place to look | Then |
|---|---|---|
| User can't log in | `activity_log` (filter user_id), `login_attempts` (recent rows) | `security_settings` rate-limit fields; cleared lockout in Ch 12 |
| 2FA loop / step-up rejected | `activity_log` (auth.* events) | `trusted_devices`, `two_factor_codes` tables |
| Payment didn't go through | Stripe Dashboard → Payments | `webhook_events` table, `audit_log`, `system.log` |
| Webhook failed | `webhook_events` table (status, error column) | Stripe Dashboard → Developers → Webhooks (delivery attempts); `system.log` |
| Refund didn't apply | Stripe Dashboard → the charge → Refunds | `audit_log` (refund.*), `system.log` (Ch 17) |
| Email not received | Settings → Integrations → "Test SMTP connection" | `email_log` table, recipient's spam folder, `integrations.smtp_*` values (Ch 22) |
| FIM alert fired | `/admin/settings/?section=security` → FIM card → "Review changes" | `file_integrity_baseline` table; diff the listed files against git (Ch 11) |
| Tour broken after a deploy | `./scripts/check_tour_impact.sh` | Re-record the failing step in admin → Tours → Validator (Ch 36) |
| Doc out of date after a deploy | `./scripts/check_doc_impact.sh` | Edit the flagged chapter under `public_html/admin/help/docs/chapters/` |
| Settings change "didn't stick" | `audit_log` → filter by the setting key | `SettingsService` caches per-request — start a fresh request (new tab) |
| 500 error on a page | cPanel `error_log` first (catches pre-bootstrap fatals) | `app/storage/logs/system.log` |
| Page works locally but not on draft | cPanel "Errors" tile | Compare PHP version (`Select PHP Version` in cPanel) |

## Settings

There is **no `advanced.debug_mode`** key — production never runs with `display_errors = On`. The relevant keys under the **Advanced / Developer** section (`/admin/settings/?section=advanced`) are:

| Key | Type | Purpose |
|---|---|---|
| `advanced.maintenance_mode` | bool | When on, non-admin requests get a 503 page (`app/bootstrap.php`). Useful during a known-bad deploy. |
| `advanced.disable_password_reset_rate_limit` | bool | Temporarily disables the password-reset throttle (Ch 12) when you're helping a member recover and tripping the limit. **Re-enable when done.** |
| `advanced.feature_flags` | json | Per-subsystem feature toggles. Not directly logging-related but useful to disable a misbehaving subsystem. |

The Log Viewer itself has no settings — it always reads `app/storage/logs/system.log` (last 300 lines, capped at ~200 KB by `LogViewerService::readTail()`). The "Clear log" button on the Advanced settings page requires step-up.

## Screenshots

<!-- SCREENSHOT: /admin/settings/?section=advanced — the "System Logs" card showing tailed system.log content with the Clear log button. Save as 35-system-log-viewer.png. -->
<!-- ![System Log viewer](../images/35-system-log-viewer.png) -->

<!-- SCREENSHOT: cPanel "Errors" tile content for the goldwing.org.au account. Save as 35-cpanel-error-log.png. -->
<!-- ![cPanel error log](../images/35-cpanel-error-log.png) -->

<!-- SCREENSHOT: Stripe Dashboard → Developers → Webhooks → Endpoint details, showing recent failed delivery attempts. Save as 35-stripe-webhook-failures.png. -->
<!-- ![Stripe webhook failures](../images/35-stripe-webhook-failures.png) -->

<!-- SCREENSHOT: /admin/settings/?section=security — FIM card with one or more unexpected changes flagged, before approval. Save as 35-fim-diff.png. -->
<!-- ![FIM diff](../images/35-fim-diff.png) -->

## Tools

- **Log Viewer (Settings → Advanced)** — `/admin/settings/?section=advanced`. Tails `system.log` in the browser. Use this 90% of the time.
- **phpMyAdmin (cPanel)** — for the raw `SELECT * FROM webhook_events ORDER BY id DESC LIMIT 50` style queries the UI doesn't expose.
- **SSH live tail** — `ssh goldwing@host && tail -F /home/goldwing/draft.goldwing.org.au/app/storage/logs/system.log` — best when you can reproduce the bug on demand.
- **Stripe CLI** — `stripe events resend evt_…` to replay a webhook against the live endpoint, or `stripe listen --forward-to localhost/api/stripe_webhook.php` for local testing. See Ch 16.
- **`scripts/check_tour_impact.sh` / `check_doc_impact.sh`** — run after any non-trivial change; they flag tours and docs whose `watched_files` overlap your diff. See Ch 33.

## Gotchas

- **`display_errors` is `Off` in production.** A page that 500s shows a blank white screen or the cPanel default error page — the stack trace is *only* in `app/storage/logs/system.log` or the per-domain `error_log`. Don't waste time looking at the browser response.
- **cPanel `error_log` rotates.** It's truncated periodically by the host. Don't rely on it for anything more than a few days old — copy lines you care about into a ticket or a paste somewhere durable.
- **`activity_log` and `audit_log` never rotate.** They will grow forever. They're cheap to read because of indexes, but back them up before any bulk delete, and never `TRUNCATE` them in anger — they're our only after-the-fact record of who-did-what. A retention job is on the roadmap.
- **`SettingsService` caches per request.** If you change a setting in one tab and the other tab doesn't pick it up, that's not a bug — the other tab's request finished before yours saved. Reload the second tab.
- **Maintenance mode and debug mode are not the same.** Maintenance mode hides the site from members; it does *not* surface PHP errors. There is no "show errors in the browser" toggle by design — leaking stack traces in production is a security issue.
- **Webhook retries can mask the original failure.** Stripe will retry a failed webhook for up to 3 days. If you see "fixed itself," check `webhook_events` for the *first* row with that `event_id` — the error there is the real cause.
- **`system.log` lines are timezone-stamped from PHP's default**, set by `bootstrap.php` from `site.timezone` (default `Australia/Sydney`). cPanel's `error_log` uses the server's clock (UTC). Don't get confused comparing timestamps across the two files.

## Escalation

Call the dev when:

- You see a `Fatal error` in `system.log` you don't recognise, *and* it's happening on more than one request.
- A Stripe webhook has been failing for more than an hour with the same error and the `webhook_events` row's `error` column doesn't match anything in Ch 16's known cases.
- FIM flags a change to a file you didn't deploy — treat as a possible intrusion. Don't approve the new baseline; capture the diff and escalate immediately (Ch 11).
- The site is fully down (cPanel "Errors" empty, no MySQL connection). That's hosting-level — open a ticket with the cPanel hosting provider via their support portal first, then notify the dev.

For hosting-level outages, the cPanel support contact path is the host's helpdesk (currently raised through the cPanel WHM ticket form on the provider's portal). Pat keeps the account login and ticket history.

## Related chapters

- [08 — Activity & audit log](view.php?slug=08-activity-audit) — schemas and what each event type means.
- [11 — File integrity monitoring](view.php?slug=11-file-integrity) — how FIM baselines and diffs work.
- [12 — Login rate limiting & lockout](view.php?slug=12-rate-limit-lockout) — `login_attempts`, lockout reset.
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency) — `webhook_events`, retry semantics.
- [17 — Refunds](view.php?slug=17-refunds) — refund triage and Stripe Dashboard cross-ref.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — `email_log`, SMTP test, bounce handling.
- [33 — Deployment](view.php?slug=33-deployment) — the push-live flow; what to roll back when.
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — cron failure surfaces and where they log.
