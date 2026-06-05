# Membership lifecycle

## What this covers

The end-to-end journey of a membership: a prospect submits an application, an admin reviews it, approval kicks off a welcome email + member account + a `membership_periods` row, and a pair of cron jobs (`send_renewal_reminders.php`, `expire_memberships.php`) drive the rest — emailing before expiry, then flipping `ACTIVE` to `LAPSED` when the end date passes. Reactivation is just another paid period.

The Stripe plumbing is in [Chapter 13 — Stripe overview](view.php?slug=13-stripe-overview), the price matrix in [Chapter 14 — Membership pricing](view.php?slug=14-membership-pricing), and the admin member screen in [Chapter 20 — Members admin console](view.php?slug=20-members-admin).

## Why it exists

Split into small tables + cron jobs, not a single `members.expiry_date` column, because:

- The association needs **history**. `membership_periods` keeps every term ever bought, so a member who lapsed for years and rejoined still has the old record.
- **Pending application** ≠ **pending payment** ≠ **lapsed**. `members.status` (`PENDING / ACTIVE / LAPSED / INACTIVE`) summarises life-stage; period rows tell you *why*.
- Renewal is **batch + async**: cron builds a Stripe link, emails it, member pays weeks later. A webhook + date column would couple things that don't belong.

## How it works

### 1. Application (`/apply.php`)

The wizard at `public_html/apply.php` (also `/become-a-member`). On submit:

1. CSRF + email-uniqueness check (`MemberRepository::isEmailAvailable`).
2. Allocate a member number — **Full**: `MAX(member_number_base) + 1`. **Associate** linked to a full: reuse the full's base, bump `member_number_suffix`. Rendered by `MembershipService::displayMembershipNumber()` per `membership.member_number_format_*` (defaults `{base}` / `{base}.{suffix}`).
3. Insert `members` row with `status = 'PENDING'` and `member_type` of `FULL`, `ASSOCIATE`, or `LIFE`.
4. Insert `membership_applications` row with `status = 'PENDING'` and a JSON `notes` blob (magazine type, period key, requested chapter, vehicles, associate sub-application, payment method).
5. Send confirmation email.

No `membership_periods` yet. The member exists but cannot log in (no `users` row).

### 2. Review (`/admin/index.php?page=applications`)

Lists applications filtered by `pending / approved / rejected`. Each row links to `/admin/applications/view.php?id=…` for the full notes blob, address, vehicles, and requested chapter.

- **Approve** posts to `/admin/index.php` with `approve_id`.
- **Reject** posts with `reject_id` plus a `rejection_reason`. The application row goes to `status = 'REJECTED'` with `rejected_by`, `rejected_at`, and the reason. The `members` row stays `PENDING` so a re-application doesn't collide on the email.

### 3. Approval

The approve handler (`admin/index.php` ~L220), in order:

1. Application → `APPROVED`, stamps `approved_by` / `approved_at`.
2. Reads the term from `notes` (`membership.full.period_key` etc.); defaults to `'1Y'`, or `'LIFE'`.
3. `MembershipService::createMembershipPeriod()` inserts a `membership_periods` row with `status = 'PENDING_PAYMENT'`. Expiry via `calculateExpiry()` is **always 31 July**, N years out (membership year is Aug–Jul). `LIFE` rows have `end_date = NULL`.
4. If a paid `payments` row already exists (bank-transfer-upfront or admin-recorded), `markPaid()` flips the period to `ACTIVE` immediately.
5. Creates a `users` row + `member` role assignment + password-reset email via `NotificationService::dispatch('member_set_password', …)` if no user exists for that email.
6. Repeats 3–5 for the associate sub-application under the same `full_member_id`.
7. When the period is `ACTIVE`, `members.status = 'ACTIVE'`.

### 4. Active

Member can log in, use the member area, calendar, store. `members.status = 'ACTIVE'`; current period is the latest `membership_periods` row with `status = 'ACTIVE'`.

### 5. Renewal reminders (`cron/send_renewal_reminders.php`)

Daily. Two windows: **60** and **30 days** before `end_date`. For each due active non-LIFE period not already in `renewal_reminders`: create-or-find a `PENDING_PAYMENT` period for the day after current expiry, build a Stripe checkout session using `stripe.membership_prices.{TYPE}_1Y`, send HTML email + SMS (if phone on file), insert a `renewal_reminders` row to prevent double-firing. Writes `last_renewal_reminder_run` into `system_settings`.

### 6. Renewal payment

Member clicks, pays. The Stripe webhook ([Chapter 16](view.php?slug=16-webhooks-idempotency)) calls `MembershipService::markPaid($periodId, $paymentId)` — new period `ACTIVE`, `members.status = 'ACTIVE'`. Old period stays with its original expiry.

### 7. Expiry (`cron/expire_memberships.php`)

Daily. `SELECT … FROM membership_periods WHERE status = 'ACTIVE' AND end_date < CURDATE()`. Each match: period → `LAPSED`, member → `LAPSED`. Writes `last_expire_run` into `system_settings`.

### 8. Lapsed → Reactivation

`LAPSED` members can still log in (`users.is_active = 1`); member-only gating is what refuses content. Reactivation = pay → webhook → `markPaid()` → `ACTIVE`.

### Life members

`member_type = 'LIFE'`, `term = 'LIFE'`, `end_date = NULL`. The reminder cron `continue`s on LIFE; expiry's `end_date < CURDATE()` never matches NULL. Both jobs ignore them.

## Where to change it

- **Wizard** — `public_html/apply.php`; pricing matrix in `MembershipPricingService`.
- **Approve / Reject** — `public_html/admin/index.php` (~L220 approve, ~L651 reject); review screen `/admin/applications/view.php`.
- **Lifecycle code** — `app/Services/MembershipService.php` (`createMembershipPeriod`, `calculateExpiry`, `markPaid`).
- **Member admin** — `/admin/members/` (see [Chapter 20](view.php?slug=20-members-admin)).
- **Cron schedule** — cPanel → Cron Jobs, pointing at `cron/expire_memberships.php` and `cron/send_renewal_reminders.php`. See [Chapter 34](view.php?slug=34-cron-jobs).

## Settings

All `settings_global` unless noted.

| Key | What it does |
|---|---|
| `membership.member_number_start` | First base number (default 1000). |
| `membership.associate_suffix_start` | First associate suffix (default 1). |
| `membership.member_number_format_full` / `…_associate` | Display templates (defaults `{base}`, `{base}.{suffix}`). |
| `membership.member_number_base_padding` / `…_suffix_padding` | Zero-padding. |
| `payments.bank_transfer_instructions` | Text on the wizard's bank-transfer panel. |
| `stripe.membership_prices.*` (`config/app.php`) | Stripe Price IDs the renewal cron looks up by `{TYPE}_1Y`. |

Full pricing matrix lives in `MembershipPricingService` — see [Chapter 14](view.php?slug=14-membership-pricing). Reminder windows (60, 30 days) are hard-coded in `cron/send_renewal_reminders.php`'s `$intervals` array.

## Screenshots

<!-- SCREENSHOT: /admin/index.php?page=applications, Pending tab. Save as 19-applications-list.png. -->
<!-- ![Applications queue](../images/19-applications-list.png) -->

<!-- SCREENSHOT: /admin/applications/view.php?id=… for a pending application. Save as 19-application-detail.png. -->
<!-- ![Application detail](../images/19-application-detail.png) -->

<!-- SCREENSHOT: /admin/members/view.php for an active member, current period visible. Save as 19-member-detail.png. -->
<!-- ![Member detail](../images/19-member-detail.png) -->

<!-- SCREENSHOT: A delivered renewal reminder email. Save as 19-renewal-email.png. -->
<!-- ![Renewal email](../images/19-renewal-email.png) -->

## Gotchas

- **Expiry is timezone-driven, cron runs in the server TZ.** Bootstrap sets `date_default_timezone_set(site.timezone ?? 'Australia/Sydney')`. If you change `site.timezone` mid-day, a membership expiring "today" can tip over an hour earlier or later than you'd guess.
- **Email failure means no second chance.** If SMTP / Resend is down when `send_renewal_reminders.php` runs, the `renewal_reminders` row is *still* inserted — the member never gets that window again. The 30-day reminder is the safety net; if both fail, the member only learns at lapse. Watch `app/storage/logs/` and [Chapter 22](view.php?slug=22-notifications-email).
- **`LAPSED` doesn't auto-restrict everything.** Login still works, `users.is_active = 1`. Any member-only page that only calls `require_login()` without also checking `members.status` will let a lapsed member in. Use `AdminMemberAccess::requireActiveMember()` — audit new pages for this.
- **Approval needs a real email.** The user-creation step bails on empty `members.email`, leaving a period but no `users` row. Approve is then idempotent (already-approved), so you'll have to create the user manually.
- **Associate suffix can collide.** If `full_member_id` doesn't point at a valid full when an associate is approved manually, suffix allocation falls back to 0 and trips `uniq_member_number`. The wizard catches this; manual admin entry doesn't.
- **Member number is recorded twice** — `member_number_base` / `_suffix` (numeric, sortable) and `member_number` (display string). Both are kept in sync on insert; backfills must write both.

## Related chapters

- [13 — Stripe integration overview](view.php?slug=13-stripe-overview)
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing)
- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout)
- [16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency)
- [18 — Invoices](view.php?slug=18-invoices)
- [20 — Members admin console](view.php?slug=20-members-admin) — including profile/chapter change requests via `PendingRequestsService`.
- [21 — Chapters & area reps](view.php?slug=21-chapters-area-reps)
- [22 — Notifications & email](view.php?slug=22-notifications-email)
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — schedule + the `last_*_run` system_settings markers.
