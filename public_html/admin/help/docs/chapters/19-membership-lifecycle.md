# Membership lifecycle

## For administrators

### What this is

The **membership lifecycle** is the journey someone takes from first hearing about us to being a member for years. It runs: **someone applies → an admin reviews → it's approved (or rejected) → they pay → they're an active member → reminders go out near expiry → they renew (or lapse) → if they lapse, they can rejoin any time**.

Life members are a special case — once approved, they're members forever. No expiry, no renewal reminders, ever.

You'll wear your "membership secretary" hat for most of this. Day-to-day it's checking the applications queue, approving the new ones, and following up on the renewals that didn't go through.

### The lifecycle in plain English

1. **Application** — someone fills out the form on the website (Full, Associate, or Life).
2. **Review** — an admin opens the application, checks the details, approves or rejects.
3. **Approval** — the system creates their member record, gives them a member number, and emails them a password-reset link so they can set up their login. A membership "period" is created with an end date of 31 July (our membership year runs Aug–Jul).
4. **Payment** — if they paid by card during the application, they're already active. If they chose bank transfer, the period stays "pending payment" until the treasurer marks the payment received.
5. **Active** — they can log in, see member-only content, book rides, buy from the store.
6. **Renewal reminders** — 60 days before their expiry, an automatic email goes out with a Stripe link to renew. If they don't pay, a second reminder goes out at 30 days.
7. **Renew or expire** — if they pay before 31 July, they roll into a new period and stay active. If they don't, a nightly cron job marks them as **lapsed** the day after expiry.
8. **Lapsed** — they can still log in but member-only stuff is locked. They can come back any time by paying again — there's no penalty.
9. **Life members** — never expire, never get reminders, never lapse.

### What you can do at each stage

- **Application stage** — approve, reject (with a reason), or just view the details (vehicles, chapter request, magazine preference, associate sub-application).
- **Active stage** — view the member's profile, change their chapter, send them a manual payment link if they need one, edit details.
- **Renewal stage** — issue a Stripe checkout link manually if the automatic reminder didn't work for them.
- **Lapsed stage** — encourage them to rejoin; once they pay they're active again.
- **Life members** — same as active, but no renewal admin needed.

### Who's allowed

- **Admin** can do everything — view, approve, reject, send payment links, edit members.
- **Committee Member** can approve and reject applications (this is the body that formally accepts new members).
- **Treasurer** can mark bank-transfer payments as received.
- **Read-only roles** can view but not act.

If you can't see the buttons you expect, ask the site admin to check your role in Admin → Settings → Accounts & Roles.

### Where to find it in admin

- **New applications** — Admin → Applications (the queue defaults to "Pending").
- **Existing members** (active, lapsed, anyone) — Admin → Members.
- **Cron jobs** — these run on their own. You don't have to push any buttons. They send renewal reminders and flip expired members to lapsed status overnight.

### How to approve an application (step by step)

1. Go to **Admin → Applications**. New ones show in the **Pending** tab.
2. Click the applicant's name to open the full application. You'll see their address, vehicles, requested chapter, magazine preference, and any associate (e.g. spouse) sub-application.
3. Check the basics:
   - Email looks right (we'll send their password-reset link here)
   - Chapter request matches where they actually ride
   - Vehicles are filled in
4. Click **Approve**.
5. The system does the rest: creates the member record, allocates a member number, creates a membership period ending 31 July, and emails them a password-reset link. If they paid by card during the application, they're immediately active. If they chose bank transfer, the treasurer marks the payment when it lands.
6. Their status changes to **Approved** in the applications list, and they show up in **Admin → Members**.

To **reject**: click Reject, type a short reason (the applicant sees this), submit. The application is marked rejected and the placeholder member record stays in "pending" so a future re-application with the same email isn't blocked.

### How to handle an overdue renewal

A member's expiry has passed and they're now **lapsed**. What you do depends on the cause:

1. **Open Admin → Members**, find the member, click into their profile.
2. Check the **Periods** section — is there a "pending payment" period? That means a renewal Stripe link was created but never paid.
3. Check the **Notifications log** — did the reminder emails actually go out 60 and 30 days before expiry?
4. If reminders went out and they ignored them: send a personal email (a real one, not the templated kind) asking if they want to renew. Include a fresh Stripe link (see below).
5. If reminders never went out (e.g. SMTP was down on the day): apologise, send a manual link, and consider extending their expiry as a goodwill gesture if you can.
6. If they don't want to renew: leave them as lapsed. They can come back any time.

### How to issue a manual renewal payment link

When the automatic reminder didn't reach the member, or you just want to send a personal "time to renew" message:

1. **Admin → Members** → click the member → **Renewals** or **Payment** section.
2. Click **Send renewal link** (or **Create checkout session**).
3. The system builds a Stripe checkout link tied to their member type (Full, Associate) and 1-year renewal price.
4. You can either email it directly from this page or copy the link and paste it into your own email / SMS.
5. When they pay, Stripe tells us about it (via webhook), their period flips to active, and the member status goes back to active.

### How renewal reminder emails work

- **60 days before expiry** — first reminder goes out. Includes their member name, what they're renewing, the price, and a Stripe checkout link.
- **30 days before expiry** — second reminder (only if they haven't paid yet).
- If their phone number is on file, an SMS goes with each reminder too.
- Each member only gets each window **once** — if the email server is down on the day, that window is lost. The 30-day reminder catches most of those; if both fail, the member only finds out at lapse.
- Life members get **no** reminders. Ever.

### What can go wrong

- **"They never got the reminder."** — Check Admin → Members → their profile → Notifications log. The cron may have run while the email server was down. Send a manual link.
- **"The application is stuck in Pending."** — Approval requires an email on the member. If the applicant left email blank (very rare on a web form), the user account can't be created. Edit the application to add an email, then approve.
- **"They're showing as expired but they paid."** — Check the Stripe dashboard for a payment matching their email. If Stripe received it but our webhook missed it, ask your developer to look at Admin → Webhooks. They can re-process the event by hand.
- **"There are two member records with the same name."** — Probably a re-application with a different email. Open both, decide which is canonical, and use the merge tool (or ask your developer) to consolidate. Don't just delete — the old one might have order history.
- **"The Associate's member number didn't allocate correctly."** — If you approved an associate manually without their "full" member being approved first, the suffix lookup falls back oddly. Easiest fix is to approve the full first, then the associate.

### What gets recorded

- **Members table** — name, status (Pending / Active / Lapsed / Inactive), member type (Full / Associate / Life), member number.
- **Membership periods** — every term ever bought, with start date, end date, status, and the payment that triggered it. Lapsed-and-rejoined members keep their old period rows, so you can see they've been around before.
- **Applications** — pending, approved, rejected with reason, who approved/rejected and when.
- **Renewal reminders log** — which member got which reminder on which date, so they don't get the same one twice.
- **Notifications log** — every renewal email/SMS we sent (or tried to send).
- **Activity log** — Admin → Security Log captures approvals and rejections.

### Good practice

- **Review pending applications weekly.** A new rider shouldn't wait two weeks for someone to click Approve.
- **Investigate stuck cases promptly.** If a member's been "pending payment" for more than 30 days, follow up — they may have meant to pay and forgotten.
- **Spot-check member numbers.** Once a quarter, sort the member list by member number and look for gaps or duplicates. There shouldn't be any — but it's worth checking.
- **Don't delete lapsed members.** Their history is valuable and they often come back. Lapsed is fine.
- **Use the reject reason field properly.** The applicant sees it. A polite, specific reason ("we couldn't verify your chapter") is much better than "rejected".

### Who to ask if stuck

- **Application form not working** — your developer (it's `apply.php`).
- **Stripe payment received but member still pending** — Treasurer or developer (webhook may have missed).
- **Cron didn't run last night** — developer (check `last_renewal_reminder_run` / `last_expire_run` in system settings).
- **Two members with overlapping data** — flag to admin team, then merge carefully.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

The end-to-end journey of a membership: a prospect submits an application, an admin reviews it, approval kicks off a welcome email + member account + a `membership_periods` row, and a pair of cron jobs (`send_renewal_reminders.php`, `expire_memberships.php`) drive the rest — emailing before expiry, then flipping `ACTIVE` to `LAPSED` when the end date passes. Reactivation is just another paid period.

The Stripe plumbing is in [Chapter 13 — Stripe overview](view.php?slug=13-stripe-overview), the price matrix in [Chapter 14 — Membership pricing](view.php?slug=14-membership-pricing), and the admin member screen in [Chapter 20 — Members admin console](view.php?slug=20-members-admin).

### Why it exists

Split into small tables + cron jobs, not a single `members.expiry_date` column, because:

- The association needs **history**. `membership_periods` keeps every term ever bought, so a member who lapsed for years and rejoined still has the old record.
- **Pending application** ≠ **pending payment** ≠ **lapsed**. `members.status` (`PENDING / ACTIVE / LAPSED / INACTIVE`) summarises life-stage; period rows tell you *why*.
- Renewal is **batch + async**: cron builds a Stripe link, emails it, member pays weeks later. A webhook + date column would couple things that don't belong.

### How it works

#### 1. Application (`/apply.php`)

The wizard at `public_html/apply.php` (also `/become-a-member`). On submit:

1. CSRF + email-uniqueness check (`MemberRepository::isEmailAvailable`).
2. Allocate a member number — **Full**: `MAX(member_number_base) + 1`. **Associate** linked to a full: reuse the full's base, bump `member_number_suffix`. Rendered by `MembershipService::displayMembershipNumber()` per `membership.member_number_format_*` (defaults `{base}` / `{base}.{suffix}`).
3. Insert `members` row with `status = 'PENDING'` and `member_type` of `FULL`, `ASSOCIATE`, or `LIFE`.
4. Insert `membership_applications` row with `status = 'PENDING'` and a JSON `notes` blob (magazine type, period key, requested chapter, vehicles, associate sub-application, payment method).
5. Send confirmation email.

No `membership_periods` yet. The member exists but cannot log in (no `users` row).

#### 2. Review (`/admin/index.php?page=applications`)

Lists applications filtered by `pending / approved / rejected`. Each row links to `/admin/applications/view.php?id=…` for the full notes blob, address, vehicles, and requested chapter.

- **Approve** posts to `/admin/index.php` with `approve_id`.
- **Reject** posts with `reject_id` plus a `rejection_reason`. The application row goes to `status = 'REJECTED'` with `rejected_by`, `rejected_at`, and the reason. The `members` row stays `PENDING` so a re-application doesn't collide on the email.

#### 3. Approval

The approve handler (`admin/index.php` ~L220), in order:

1. Application → `APPROVED`, stamps `approved_by` / `approved_at`.
2. Reads the term from `notes` (`membership.full.period_key` etc.); defaults to `'1Y'`, or `'LIFE'`.
3. `MembershipService::createMembershipPeriod()` inserts a `membership_periods` row with `status = 'PENDING_PAYMENT'`. Expiry via `calculateExpiry()` is **always 31 July**, N years out (membership year is Aug–Jul). `LIFE` rows have `end_date = NULL`.
4. If a paid `payments` row already exists (bank-transfer-upfront or admin-recorded), `markPaid()` flips the period to `ACTIVE` immediately.
5. Creates a `users` row + `member` role assignment + password-reset email via `NotificationService::dispatch('member_set_password', …)` if no user exists for that email.
6. Repeats 3–5 for the associate sub-application under the same `full_member_id`.
7. When the period is `ACTIVE`, `members.status = 'ACTIVE'`.

#### 4. Active

Member can log in, use the member area, calendar, store. `members.status = 'ACTIVE'`; current period is the latest `membership_periods` row with `status = 'ACTIVE'`.

#### 5. Renewal reminders (`cron/send_renewal_reminders.php`)

Daily. Two windows: **60** and **30 days** before `end_date`. For each due active non-LIFE period not already in `renewal_reminders`: create-or-find a `PENDING_PAYMENT` period for the day after current expiry, build a Stripe checkout session using `stripe.membership_prices.{TYPE}_1Y`, send HTML email + SMS (if phone on file), insert a `renewal_reminders` row to prevent double-firing. Writes `last_renewal_reminder_run` into `system_settings`.

#### 6. Renewal payment

Member clicks, pays. The Stripe webhook ([Chapter 16](view.php?slug=16-webhooks-idempotency)) calls `MembershipService::markPaid($periodId, $paymentId)` — new period `ACTIVE`, `members.status = 'ACTIVE'`. Old period stays with its original expiry.

#### 7. Expiry (`cron/expire_memberships.php`)

Daily. `SELECT … FROM membership_periods WHERE status = 'ACTIVE' AND end_date < CURDATE()`. Each match: period → `LAPSED`, member → `LAPSED`. Writes `last_expire_run` into `system_settings`.

#### 8. Lapsed → Reactivation

`LAPSED` members can still log in (`users.is_active = 1`); member-only gating is what refuses content. Reactivation = pay → webhook → `markPaid()` → `ACTIVE`.

#### Life members

`member_type = 'LIFE'`, `term = 'LIFE'`, `end_date = NULL`. The reminder cron `continue`s on LIFE; expiry's `end_date < CURDATE()` never matches NULL. Both jobs ignore them.

### Where to change it

- **Wizard** — `public_html/apply.php`; pricing matrix in `MembershipPricingService`.
- **Approve / Reject** — `public_html/admin/index.php` (~L220 approve, ~L651 reject); review screen `/admin/applications/view.php`.
- **Lifecycle code** — `app/Services/MembershipService.php` (`createMembershipPeriod`, `calculateExpiry`, `markPaid`).
- **Member admin** — `/admin/members/` (see [Chapter 20](view.php?slug=20-members-admin)).
- **Cron schedule** — cPanel → Cron Jobs, pointing at `cron/expire_memberships.php` and `cron/send_renewal_reminders.php`. See [Chapter 34](view.php?slug=34-cron-jobs).

### Settings

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

### Gotchas

- **Expiry is timezone-driven, cron runs in the server TZ.** Bootstrap sets `date_default_timezone_set(site.timezone ?? 'Australia/Sydney')`. If you change `site.timezone` mid-day, a membership expiring "today" can tip over an hour earlier or later than you'd guess.
- **Email failure means no second chance.** If SMTP / Resend is down when `send_renewal_reminders.php` runs, the `renewal_reminders` row is *still* inserted — the member never gets that window again. The 30-day reminder is the safety net; if both fail, the member only learns at lapse. Watch `app/storage/logs/` and [Chapter 22](view.php?slug=22-notifications-email).
- **`LAPSED` doesn't auto-restrict everything.** Login still works, `users.is_active = 1`. Any member-only page that only calls `require_login()` without also checking `members.status` will let a lapsed member in. Use `AdminMemberAccess::requireActiveMember()` — audit new pages for this.
- **Approval needs a real email.** The user-creation step bails on empty `members.email`, leaving a period but no `users` row. Approve is then idempotent (already-approved), so you'll have to create the user manually.
- **Associate suffix can collide.** If `full_member_id` doesn't point at a valid full when an associate is approved manually, suffix allocation falls back to 0 and trips `uniq_member_number`. The wizard catches this; manual admin entry doesn't.
- **Member number is recorded twice** — `member_number_base` / `_suffix` (numeric, sortable) and `member_number` (display string). Both are kept in sync on insert; backfills must write both.

</details>

<!-- SCREENSHOT: /admin/index.php?page=applications, Pending tab. Save as 19-applications-list.png. -->
<!-- ![Applications queue](../images/19-applications-list.png) -->

<!-- SCREENSHOT: /admin/applications/view.php?id=… for a pending application. Save as 19-application-detail.png. -->
<!-- ![Application detail](../images/19-application-detail.png) -->

<!-- SCREENSHOT: /admin/members/view.php for an active member, current period visible. Save as 19-member-detail.png. -->
<!-- ![Member detail](../images/19-member-detail.png) -->

<!-- SCREENSHOT: A delivered renewal reminder email. Save as 19-renewal-email.png. -->
<!-- ![Renewal email](../images/19-renewal-email.png) -->

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
