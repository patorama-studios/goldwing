# Cron jobs

## For administrators

### What this is

A **cron job** is a small task the website runs automatically on a schedule — no one has to click a button. Goldwing's site uses a handful of them to handle the boring-but-critical jobs that absolutely have to happen on time:

- Emailing members **renewal reminders** before their membership runs out.
- Marking memberships **lapsed** the day after they expire, so member-only access turns off.
- Doing a **file integrity scan** to catch tampering or unexpected file changes.
- Optionally, sending admins a **daily summary** of activity.

These run quietly in the background. Most of the time you don't need to think about them — but if reminders stop going out, or memberships aren't lapsing on time, this is where to look.

### What you can see

- The list of scheduled jobs and **when each one runs** (in cPanel).
- Whether each job is **enabled** (in the list at all) or **disabled** (removed from the list).
- The **last output** of each job — cPanel emails any output (errors, notices) to a configured address.
- A **"last run" marker** for each job, recorded in the database, so you can confirm jobs are actually firing.

### Who's allowed

Anyone with **cPanel access** to the hosting account can see and edit the cron schedule. That's typically just the developer and a small number of trusted admins. Editing cron jobs isn't done from the website's admin area — it's done through the hosting control panel.

### Where to find them

In **cPanel → Cron Jobs**. The page shows every scheduled command, the schedule it runs on, and a **"Cron Email"** field at the top — that's the address that gets sent any output the jobs produce. Make sure it's set to an address someone actually checks.

### The jobs that run on this site

- **Renewal reminders** — runs daily. Emails (and SMS's, where a phone is on file) members who are due to renew in 60 days, and again at 30 days. Builds them a ready-to-pay Stripe checkout link.
- **Membership expiry** — runs daily, just after midnight. Looks for any active memberships whose end date has passed, and marks them as **lapsed**. After this runs, those members lose member-only access until they renew.
- **File integrity scan** — runs hourly (or nightly, depending on configuration). Compares the site's files against a saved baseline and alerts the security recipient if anything has changed unexpectedly. Cross-reference with [Chapter 11 — File integrity monitoring](view.php?slug=11-file-integrity).
- **Daily admin summary** — optional. Emails the first admin a one-paragraph digest each morning: how many active members, how many pending applications, how many memberships are due soon. A useful "is the site still alive?" heartbeat.

### What you might need to do

- **Turn one off temporarily** — flag the developer first. They'll delete (or comment out) the cron entry in cPanel. Restoring is just re-adding the line.
- **Check why a job stopped running** — look at the "Cron Email" inbox for error output, and ask the developer to check the `last_*_run` marker in the database for that job. If the marker hasn't advanced in days, the job isn't firing.
- **Verify the schedule** — open cPanel → Cron Jobs and read the times in the left column. The schedule is in the *server's* clock, not necessarily Sydney time (see "What can go wrong" below).

### What can go wrong

- **A cron entry gets deleted by accident.** Renewal reminders stop, no one notices for a few days, members lapse without warning. Always check the cron list is complete after any hosting work.
- **Cron output isn't being emailed.** If the "Cron Email" field is blank, a fatal error in a cron script is silent — you only find out when a member complains. Always keep an address in that field, and make sure someone reads it.
- **Renewal reminders not going out.** Members miss their renewal date and lapse with no warning. Symptoms: members reporting "I didn't know I needed to renew." Check the cron entry exists, the email log, and ask the developer to check the `renewal_reminders` heartbeat.
- **File integrity scan not running.** No alerts when files change. Symptoms: silence (which is the problem). Ask the developer to confirm the scan is enabled and the baseline is set.
- **Cron output fills up disk.** Rare, but a script that errors loudly every hour can fill the cPanel mailbox. If "Cron Email" is bouncing, that's the cause.

### Good practice

- **Don't disable a cron job without flagging the developer.** What looks like "I'll just turn off reminders for a week" can lead to members lapsing silently.
- **Check the cPanel cron history once a month.** Confirm all four jobs are still in the list and the "Cron Email" address is still set.
- **Make sure the cron-output email goes somewhere monitored.** A shared admin inbox is fine — a personal address that no one checks during holidays is not.
- **If a job seems to have stopped, look at the "last run" marker before assuming the script is broken.** Often cron itself has been disabled (after a hosting change, for instance) and the script is fine.

### Who to ask if a job seems broken

The **developer**. Cron jobs run outside the website's admin area and need cPanel access (or SSH) to diagnose. If reminders aren't going out or memberships aren't lapsing on time, that's a developer ticket, not something to troubleshoot from the admin panel.

---

<details>
<summary><strong>Dev notes</strong></summary>

## What this covers

The four scheduled PHP scripts in `cron/` — what each one does, when it runs, what it writes to the database, who gets emailed when it works, and what to do when it doesn't. Plus the cPanel-side setup: how to add a cron entry, where its output goes, and the timezone trap that catches everyone the first time.

## Why it exists

A handful of jobs simply can't wait for a human to remember them.

- **Renewals are time-critical.** A member who lapses without warning is a member we lose. Two reminders (60 and 30 days before expiry) need to fire reliably whether or not anyone logged into the admin that day.
- **Status has to match reality.** If `members.status` still says `ACTIVE` the day after a term ended, every member-only gate is wrong. `expire_memberships.php` keeps the database aligned with the calendar.
- **Tampering needs to be caught quickly.** FIM ([Chapter 11](view.php?slug=11-file-integrity)) only works if it actually runs.
- **Admins want a heartbeat.** The optional daily summary is the cheapest "is the site still alive?" signal.

Each script is deliberately tiny — one file, no framework, no daemons. cPanel runs them, MySQL holds their state, and each leaves a `last_*_run` marker so you can spot a job that stopped firing.

## How it works

Every cron script starts with `require_once __DIR__ . '/../app/bootstrap.php';` — same bootstrap as every web request. `.env` loads, `db()` and `config()` are defined, the timezone is applied. CLI-safe: no headers, just SQL + email.

### `cron/send_renewal_reminders.php` — daily, `0 6 * * *`

Walks two windows: active periods ending in exactly 60 days, and exactly 30 days. For each (skipping LIFE members, and skipping any `period_id + reminder_type` already in `renewal_reminders`): creates-or-finds a `PENDING_PAYMENT` period for the day after expiry, builds a Stripe checkout session against `stripe.membership_prices.{TYPE}_1Y`, sends an HTML email via `EmailService` plus an SMS via `SmsService` if a phone is on file, then inserts a `renewal_reminders` row so the same window never fires twice. Writes `last_renewal_reminder_run` to `system_settings`. Tables: `membership_periods`, `members` (read), `renewal_reminders` (write), `system_settings` (write). Lifecycle context in [Chapter 19](view.php?slug=19-membership-lifecycle).

**If it fails:** check `app/storage/logs/` ([Chapter 35](view.php?slug=35-logs-troubleshooting)) and SMTP/Resend status. An exception during send will skip the `renewal_reminders` insert so a rerun will retry — but a silent SMTP drop inserts the row anyway and the member loses that window.

### `cron/expire_memberships.php` — daily, `5 0 * * *`

One SQL pass: `WHERE status = 'ACTIVE' AND end_date < CURDATE()`. For each match: period → `LAPSED`, member → `LAPSED`. Writes `last_expire_run`. No email. Tables: `membership_periods`, `members`, `system_settings`.

**Idempotent by design** — the `ACTIVE`-only filter means a rerun finds nothing. Safe to invoke twice or backfill from SSH.

**If it fails:** lapsed members will still show `ACTIVE`. Run `php cron/expire_memberships.php` on the server; check `last_expire_run`.

### `cron/daily_summary_admin.php` — optional, `15 6 * * *`

Picks the first admin user, counts active members + pending applications + members due within 60 days, emails a one-paragraph summary, writes `last_daily_summary_run`. Disable by removing the cron entry — no settings toggle. Non-fatal if it fails.

### `cron/fim_scan.php` — hourly or nightly

Wraps `FileIntegrityService::scan()` over the configured paths. If the baseline differs from disk it records `CHANGES_DETECTED`, emails the security-alert recipient via `SecurityAlertService::send('fim_changes', …)`, and logs `security.fim_changes_detected`. Exits early when `fim_enabled` is false. Baseline / alert model is in [Chapter 11](view.php?slug=11-file-integrity).

**If it fails:** the most common error is `RuntimeException('Baseline not set.')` on a fresh install. Approve a baseline in admin and rerun.

### cPanel setup

In cPanel → **Cron Jobs**, add an entry per script with the schedule on the left and the command on the right:

```
0 6 * * *    /usr/bin/php /home2/goldwing/cron/send_renewal_reminders.php
5 0 * * *    /usr/bin/php /home2/goldwing/cron/expire_memberships.php
15 6 * * *   /usr/bin/php /home2/goldwing/cron/daily_summary_admin.php
0 * * * *    /usr/bin/php /home2/goldwing/cron/fim_scan.php
```

cPanel emails any stdout/stderr the script produces to the address in the **"Cron Email"** field at the top of the same page. Set it. Without it, a fatally broken cron is silent — you'll only notice when a member complains they never got a reminder.

**Manual runs** for testing or backfill: SSH into the cPanel account and run `php cron/send_renewal_reminders.php` from the project root. Same script, same DB, same emails — be careful in production.

## Where to change it

- **Schedules and the entry list:** cPanel → Cron Jobs. Live edits take effect on the next minute boundary.
- **Reminder windows (60 / 30 days):** hard-coded in `cron/send_renewal_reminders.php`'s `$intervals` array.
- **FIM enable / paths / excludes:** Admin → Security & Authentication. The cron honours the DB toggle.
- **Daily-summary recipient:** the first admin in the `users` table. To send elsewhere, edit the script.
- **Pricing the reminder uses:** `stripe.membership_prices` in `config/app.php`.

## Settings

| Setting | What it controls |
|---|---|
| `fim_enabled` (Security) | When false, `fim_scan.php` exits without scanning. |
| `fim_paths`, `fim_exclude_paths` (Security) | What `fim_scan.php` hashes. |
| `site.contact_email` | Fallback recipient when `SecurityAlertService` has no configured target. |
| `stripe.membership_prices.{TYPE}_1Y` (`config/app.php`) | Price IDs the renewal reminder builds checkout links from. |
| `system_settings.last_renewal_reminder_run` / `last_expire_run` / `last_daily_summary_run` | Heartbeat markers. If these stop advancing, cron is broken. |

## Gotchas

- **Server timezone is not site timezone.** cron schedules are interpreted in the *server's* timezone (whatever cPanel is set to), not `site.timezone`. `0 6 * * *` is 6am server-time. The bootstrap re-applies `site.timezone` *inside* the script for `CURDATE()` / `NOW()`, so SQL is consistent — but the trigger moment is server-clock. If the server is UTC and Sydney is +10, your "6am" reminder fires at 4pm local. Check what cPanel reports and align.
- **A stopped cron is silent unless cPanel email is set.** No "Cron Email" address = no notification when a script fatals or stops running entirely. Set it. Watch the `last_*_run` markers as a backup.
- **`send_renewal_reminders.php` and `expire_memberships.php` read the same data.** Reminders run at 06:00, expiry runs at 00:05. Expiry runs *first* (earlier in the day), so a period that lapsed overnight is already `LAPSED` by the time reminders walk the list — and `ACTIVE`-only filters skip it. Don't swap the order; don't push reminders earlier than expiry without thinking it through.
- **CLI PHP can use a different `php.ini` than the web SAPI.** `/usr/bin/php` may load a different config than the FPM worker serving the site — different memory limits, different extensions, different env vars. If a cron works in the browser via a debug page but fails on schedule, suspect this first. `php -i | grep "Loaded Configuration"` on SSH tells you which ini.
- **`renewal_reminders` is the only thing stopping double sends.** Truncating that table will email everyone twice. Don't.
- **What's *not* on cron, despite feeling like it should be:** `scripts/check_tour_impact.php` and `scripts/check_doc_impact.php` run pre-push, never on a schedule. The catalogue import ([Chapter 30](view.php?slug=30-catalogue-import)) is manual on purpose. Database backups are handled by cPanel's daily backup, not by anything in `cron/`.

</details>

<!-- SCREENSHOT: cPanel → Cron Jobs page showing the four entries and the "Cron Email" field populated. Save as 34-cpanel-cron-list.png. -->
<!-- ![cPanel cron list](../images/34-cpanel-cron-list.png) -->

<!-- SCREENSHOT: A sample renewal reminder email as received by a member (Hi {first_name} … Renew now). Save as 34-renewal-email.png. -->
<!-- ![Sample renewal email](../images/34-renewal-email.png) -->

## Related chapters

- [11 — File integrity monitoring](view.php?slug=11-file-integrity) — what `fim_scan.php` actually does and how the baseline works.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — the lifecycle the two membership crons drive.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — how the renewal email gets sent and where to debug delivery failures.
- [33 — Deployment](view.php?slug=33-deployment) — the deploy flow that also updates the cron files on disk.
- [35 — Logs & troubleshooting](view.php?slug=35-logs-troubleshooting) — where cron errors land and how to read them.
