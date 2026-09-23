# Member engagement report

## For administrators

### What this is

**Admin → Reports** is the "how is the site actually going?" page. It answers the questions the committee keeps asking: how many members log in, what they use once they're in, how long they stay, who the keenest members are, whether newcomers are finding their way in, and what the site has delivered since launch (memberships and store orders paid online, Wings reads, ride RSVPs).

It is read-only. Nothing on the page changes a member record.

### What's on the page

Pick a **range** at the top (last 7 / 30 / 90 days, 12 months, or since launch). Everything below follows it except the *Newcomers*, *Since launch* and *Dormant members* panels, which have their own fixed windows.

- **Headline tiles** — members who logged in (and what share of active members that is, with the change against the previous period), visits, median visit length, Wings reads, and how many people are on the site right now.
- **Logins per day / week** — a line for total logins and one for how many different members they came from. Daily bars for ranges up to 30 days, weekly beyond that.
- **Time of day** — when members are on the site, in Sydney time.
- **Where members go** — page views by portal area (Wings, Calendar, Directory, Store, Notices, Billing…) with the number of different members who opened each.
- **Leaderboards** — top five for logins, Wings reads ("Bookworms"), ride RSVPs ("Road captains"), bikes in the garage ("Garage kings") and the longest current run of consecutive weeks with a login. Admin accounts are left out, the same rule as the Sunday briefing email.
- **Newcomers** — active members who joined in the last 90 days: how many have logged in, the typical wait from approval to first login, their first stop after the dashboard, and a "worth a nudge" list of those who have never logged in, each with an **Email** link.
- **Since launch** — the running totals: share of active members who have ever logged in, total logins, memberships and store orders paid online (count and dollars), ride RSVPs, Wings reads, profile details members updated themselves, applications submitted online, help tours completed.
- **Chapter league** — share of each chapter's active members who logged in during the range. Friendly rivalry fuel for the area reps.
- **Dormant members to chase** — active members with no login for 90+ days whose renewal falls due within 120 days. The most actionable list on the page: these are the renewals most likely to slip.

Both lists have a **CSV** button. Downloads are recorded in the Audit Hub as `report.export`.

### Who's allowed

Roles with `admin.logs.view` — the same permission as the Audit Hub. The page shows member names, so keep it to the committee.

### Where to find it

{{link:/admin/reports/|Take me to Reports}}

Admin → **Overview → Reports**. The old `/admin/index.php?page=reports` address redirects here.

### Good practice

- **Read the "Since launch" panel to the committee once a quarter.** It is the plain-English answer to "was the website worth it".
- **Work the dormant list before renewal season.** A phone call to someone who has not logged in for four months beats a third reminder email.
- **Use the newcomer list within a fortnight of each approval batch.** If someone has an account but has never logged in, their welcome email probably went to spam.
- **Don't publish the leaderboards to members** without asking the committee first. Names on a public board is a different decision from an admin-only report.

### What can go wrong

- **"Page-view tracking is not switched on yet"** — Migration 051 has not been run on the live database. Run it once from the Migration Runner; the "Where members go" and visit panels start filling from the next page load. Everything else works regardless.
- **A yellow "Some sections could not be calculated" banner** — one of the queries hit a table or column the live database does not have. The rest of the page still renders; the named section shows blanks. Send the developer the text of the banner.
- **Visit lengths look short.** They are measured from the first page to the last page of a visit, so a member who opens Wings and reads for twenty minutes without clicking anything else counts as a one-page visit, which is left out of the median. Treat the figure as a floor, not a stopwatch.

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

Two services and one page:

| Piece | Where | Job |
|---|---|---|
| `App\Services\PageViewLogger` | `app/Services/PageViewLogger.php` | Writes one `page_views` row per HTML page load by a logged-in user. Called once at the end of `app/bootstrap.php`, after `enforce_page_access()`. |
| `App\Services\EngagementReportService` | `app/Services/EngagementReportService.php` | All report queries, computed on demand. `build($rangeKey)` returns one array the page renders; `prune()` deletes page views older than 13 months. |
| Reports page | `public_html/admin/reports/index.php` | Server-rendered; Chart.js 4 from `cdn.jsdelivr.net` (already in the CSP `script-src`) for the two charts; `?export=dormant|newcomers` streams CSV. |

### Why it is built this way

- **Nothing new is logged except page views.** Logins (`user_logins`), Wings reads (`activity_log` rows `wings.read` / `wings.download`), RSVPs, orders, bikes, applications and profile updates were all already recorded, so most of the page worked from the first deploy with history back to launch.
- **Page views are the one gap.** Without them there is no way to know which areas members use or how long they stay. The row is deliberately thin: `user_id`, `member_id`, `is_admin`, `area`, `path` (path plus the portal `?page=` key only), `created_at`. No IP address, no other query parameters, no user agent — `activity_log` already holds those for logins.
- **Time on site is stitched, not measured.** `stitchVisits()` groups a user's page views into visits wherever the gap is under 30 minutes (the Google Analytics convention). Visit length is last view minus first, so single-page visits are zero and are excluded from the median. No JavaScript heartbeat, nothing on the member side. If precise minutes are ever wanted, the upgrade is a beacon every 60 seconds into a `last_seen` column — not a rewrite.
- **Computed on demand, no rollups, no cron.** At a few hundred members the whole page is a handful of indexed queries. The cron jobs on this host are not guaranteed to be scheduled, so nothing here depends on one — retention pruning runs from the report page itself.
- **Every section is wrapped in `q()`.** A schema mismatch on the live database blanks one panel and lists the error in the banner instead of a 500 (same pattern as `AuditHubService` and the Sunday summary cron). `tableExists()` / `columnExists()` cover the tables and columns that arrived in later migrations (`page_views`, `member_profile_updates`, `tour_completions`, `members.do_not_renew`).
- **Admins are excluded** from member figures and leaderboards via the `NOT_ADMIN` fragment (users holding the `admin` role), matching `cron/daily_summary_admin.php`. Area reps and the quartermaster are ordinary members with extra powers and stay in.
- **Permission reuses `admin.logs.view`.** New permission keys are not auto-granted — `current_admin_can()` looks up `role_permissions` rows — so a new `admin.reports.view` key would have needed a seeding migration. The Audit Hub key already gated the old `?page=reports` redirect. Upgrade path: add the key to `admin_permission_registry()` and copy the `admin.logs.view` grants across in a migration.

### What `PageViewLogger::record()` skips

GET requests only, and only when the `Accept` header contains `text/html` — so `fetch()` / XHR calls, calendar feeds, ICS downloads, images and PDF fetches never count. Also skipped: guests, impersonation sessions (an admin viewing as a member must not pollute that member's stats), and paths matching `/api/`, `/auth/`, `/uploads/`, `/assets/`, plus `*_feed.php`, `ics*.php`, `download_wings.php`, `export*.php`, `login.php`, `logout.php`, `stepup.php`, the `debug-*` / `diagnose-*` scripts, `run-migration.php`, `check_db.php`, `db_test.php` and `migrate*.php`. A missing `page_views` table is swallowed silently (Migration 051 not run yet); any other insert error goes to `error_log`.

A page that redirects after bootstrap (for example `/admin/index.php?page=reports`) is recorded before the redirect, so a redirect chain counts twice. Harmless at this scale; fix by recording at shutdown only for 200 responses if it ever matters.

### Area mapping

`PageViewLogger::areaFor($path, $page)` maps a request to one of the keys in `PageViewLogger::AREAS`. `/member/index.php?page=x` maps by the `page` value (both `notices-*` pages fold into `notices`); the flipbook reader counts as Wings; `/store*`, `/calendar*` (except `/calendar/admin*`), `/agm*`, `/members/member-of-the-year`, `/members/awards`; checkout, order and membership-success pages fold into `checkout`; anything under `/admin` is `admin`; everything else is `public`. Add a new portal area by adding its key to `AREAS` and a mapping line — the report labels come from the same array.

### Migration

`database/migrations/2026_09_23_page_views.sql`, applied on the live site through **Migration 051** in `public_html/admin/run-migration.php` (same DDL inlined — keep both in sync). Indexes on `created_at`, `(user_id, created_at)` and `(area, created_at)` cover every query the report runs. No foreign keys, on purpose: deleting a user must never fail because of analytics rows.

### Tests

`tests/test_engagement_report.php` checks the pure helpers (area mapping, visit stitching, median, streaks including the ISO year boundary, series fill) without a database:

```
/Applications/MAMP/bin/php/php8.4.17/bin/php tests/test_engagement_report.php
```

### Gotchas

- **`YEARWEEK(created_at, 3)`** is ISO year-week, matching PHP's `date('oW')`. Streaks convert each week to an absolute week index via `strtotime('YYYYWww')`, so a run across New Year is unbroken.
- **Hour-of-day is shifted into the site timezone** by comparing the DB's `NOW()` offset with PHP's; if the two servers ever disagree by a non-whole number of minutes the histogram is off by that amount. Purely cosmetic.
- **`GROUP BY m.id, u.id`** on every leaderboard is deliberate — the name expression mixes `members.*` and `users.name`, and `ONLY_FULL_GROUP_BY` rejects `u.name` grouped by `m.id` alone because `users.member_id` is not unique.
- **Newcomers = active members with `members.created_at` in the last 90 days.** Members imported at launch all carry the import date, which is why the window is on `created_at` rather than the historical `join_date`.
- **The dormant "last login" comes from `user_logins`, not `member_auth.last_login_at`.** The latter is only touched when a `member_auth` row exists, so it reads "never" for members who log in through the `users` table alone.
- **`prune()` deletes.** It is the one write in this feature. It only touches `page_views` older than `RETENTION_MONTHS` (13), and runs each time the report is opened.

</details>

<!-- SCREENSHOT: /admin/reports/?range=30d showing the headline tiles, both charts and the leaderboards. Capture on goldwing.org.au as admin once tracking has run for a month. Save as 39-engagement-report.png. -->
<!-- ![Member engagement report](../images/39-engagement-report.png) -->

## Related chapters

- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where the Wings reads and login events come from.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — what "renewal due" means for the dormant list.
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — the Sunday briefing shares the leaderboard queries and the admin-exclusion rule.
