# Events & RSVPs

## What this covers

The calendar / events system: how chapter rides, meetings, and national rallies are created, RSVP'd to, and reminded about. Covers the standalone `calendar/` module at the repo root, the `calendar_events` table behind it, the public calendar at `/calendar/`, the admin calendar at `/calendar/events.php`, and the bits in `app/Services/` that connect it to the main site.

## Why it exists

Most of what the club does is rides, breakfasts, and meetings, and the website's job is to publish them, take RSVPs, and remind people. The big design decisions:

- **A separate `calendar/` top-level directory** — the module shipped as a near-standalone bolt-on (its own `lib/`, `config/`, `cron/`, `sql/`, `public/`). It shares `users`, `chapters`, `members`, `media`, `notices`, and `settings_*` tables but otherwise owns its own world. That isolation let the feature ship without destabilising the main admin.
- **A new `calendar_events` table, not the old `events` table.** The legacy `events` table is still in the DB (`EventService::updateDescription()` + `event_versions` still target it), but all new event work writes to `calendar_events` — see Gotchas.
- **RSVPs vs paid tickets are two different stores.** Free events use `calendar_event_rsvps` (going / cancelled + qty + notes). Paid events use `calendar_event_tickets` + `calendar_orders` + the Stripe webhook in `calendar/public/webhook_stripe.php`. Matches how the club runs events — mostly free with RSVP for catering/insurance counts, a few paid.
- **Chapter scope is a first-class field.** Every event is `CHAPTER` (one chapter) or `NATIONAL` (everyone). The weekly digest filters on this so members don't get spammed.

## How it works

### The tables

`calendar/sql/schema.sql` creates:

- **`calendar_events`** — the modern event table. Columns: `title`, `slug`, `description`, `media_id` (thumbnail), `scope` (`CHAPTER`/`NATIONAL`), `chapter_id`, `event_type` (`in_person`/`online`/`hybrid`), `timezone`, `start_at`, `end_at`, `all_day`, `recurrence_rule` (RRULE), `rsvp_enabled`, `is_paid`, `ticket_product_id`, `capacity`, `sales_close_at`, `map_url`, `map_zoom`, `online_url`, `meeting_point`, `destination`, `status` (`published`/`cancelled`, plus an implicit `pending` for member-submitted events awaiting admin approval), `created_by`, `created_at`.
- **`calendar_event_rsvps`** — one row per `(event_id, user_id)` (unique key), `qty`, `notes`, `status` (`going`/`cancelled`).
- **`calendar_event_tickets`** — paid-event tickets, linked to a `calendar_orders` row + Stripe payment.
- **`calendar_refund_requests`** — refund queue for paid tickets.
- **`calendar_event_notifications_queue`** — append-only log of sent notifications; the reminder cron uses it to dedupe.

`event_type` is `in_person | online | hybrid` — there is **no separate "rides vs meetings vs social" category enum**. Categorisation in practice happens via title, description, and which chapter created the event.

### The module

- `calendar/lib/` — `db.php` (its own PDO connection), `auth.php` (`calendar_require_login`, `calendar_require_role` — reuses the main site session), `csrf.php`, `utils.php`, `mailer.php`, `calendar_occurrences.php` (the RRULE expander).
- `calendar/public/` — `events.php` (admin list — sidebar links here), `admin_event_create.php`, `admin_event_view.php` (attendee list + refund actions), `events_public.php` (public calendar), `event_view.php` (public detail + RSVP form), `member_event_submit.php` (members propose events; land as `pending`), `ics.php` (single-event .ics), `ics_feed.php` (subscribable feed), `webhook_stripe.php`, `dashboard_events.php`, `export_attendees.php`.
- `calendar/cron/` — `reminders.php` (hourly: 7-day and 24-hour emails to anyone with a `going` RSVP or a ticket; deduped via the queue table) and `weekly_digest.php` (per-user digest of upcoming events in their chapter plus recent notices, gated by `notifications.weekly_digest_enabled` and per-user `notification_preferences`).
- `calendar/config/config.php` — DB + Stripe + mail config for the module, per environment.

### Recurring events

`calendar_events.recurrence_rule` stores an RRULE string (e.g. `FREQ=WEEKLY;INTERVAL=1;BYDAY=SA;UNTIL=20261231T000000Z`). `calendar_expand_occurrences()` is a hand-rolled subset of RFC 5545 — supports `FREQ=DAILY|WEEKLY|MONTHLY`, `INTERVAL`, `BYDAY`, `UNTIL`. It does **not** handle `BYMONTH`, `BYSETPOS`, `EXDATE`, or yearly recurrence. The public calendar, the .ics feed, the digest, and the reminders cron all call this same expander, so behaviour stays consistent.

### iCal export

- `/calendar/ics.php?event_id=N` — single VEVENT, used by "Add to calendar" buttons.
- `/calendar/ics_feed.php` — full VCALENDAR with up to 200 occurrences in a 180-day window. Members can subscribe their phone calendar to this URL.

### Services in `app/Services/`

- **`EventService`** — single method `updateDescription()`, targets the **legacy `events` table** + `event_versions`. Called from `/admin/index.php?page=events` (the old page, no longer linked).
- **`EventRsvpRepository`** — read-only helper for member profile/history pages. Defensive: it `SHOW TABLES LIKE 'event_rsvps'` first and returns `[]` cleanly when absent. Targets the legacy `events` / `event_rsvps` tables.
- **`PendingRequestsService`** — *does* know about modern `calendar_events`. Uses `TYPE_EVENT = 'event'`, treats `status = 'pending'` as open and `status = 'published'` as approved, so member-submitted events flow through the unified pending-requests inbox alongside member-of-year, content edits, etc. (See [Chapter 22](view.php?slug=22-notifications-email).)

### Admin Calendar in the sidebar

`app/Views/partials/backend_admin_sidebar.php` has the explicit comment marking the switch:

```
// 'events' (legacy admin page reading the old `events` table) removed —
// all event management now happens via the Calendar entry below, which
// points at the modern calendar_events workflow.
['key' => 'calendar-events', 'label' => 'Calendar',
 'href' => '/calendar/events.php', 'permission' => 'admin.calendar.view'],
```

The legacy `?page=events` route still exists by direct URL but isn't linked anywhere.

### Notifications

- **New event created** — not currently broadcast on save. Members find out via the next weekly digest or by visiting `/calendar/`.
- **Reminders** — `calendar/cron/reminders.php` hourly, 7-day + 24-hour emails to confirmed attendees, deduped via the queue table.
- **Weekly digest** — gated by `notifications.weekly_digest_enabled` plus per-user `notification_preferences.weekly_digest`. Filters to `NATIONAL` + the user's `chapter_id`.

### Dashboard widget

`public_html/admin/index.php` around line 1667 renders an "Upcoming Events" card. **It still reads from the legacy `events` table** (`SELECT * FROM events WHERE event_date >= CURDATE()`). On environments where that table is empty (most of them now), the widget shows "No upcoming events" even when `calendar_events` is full. Migrating this card is the highest-value cleanup in this area.

## Where to change it

- **Create / edit / cancel events:** Admin sidebar → **Calendar** → `/calendar/events.php` → **Create Event** (`/calendar/admin_event_create.php`).
- **Approve member-submitted events:** same page — `pending` rows expose **Approve** / **Reject**. Also surfaces in the global pending-requests inbox.
- **Attendee list / refund requests:** `/calendar/admin_event_view.php?id=N`.
- **Defaults:** `/admin/settings/index.php?section=events` (see Settings).
- **Recurring rules:** edit the `recurrence_rule` field in the event form (raw RRULE string).

## Settings

The Events section of the Settings Hub owns these `events.*` keys (see [Chapter 32](view.php?slug=32-settings-by-section) for the full reference):

- `events.rsvp_default_enabled` (bool, default `true`) — pre-tick the RSVP toggle on new events.
- `events.visibility_default` (`member`/`public`, default `member`).
- `events.public_ticketing_enabled` (bool, default `false`).
- `events.timezone` (string, default `Australia/Sydney`).
- `events.include_map_link` (bool, default `true`).
- `events.include_zoom_link` (bool, default `true`).

Reminder/digest behaviour comes from `notifications.event_reminders_enabled` and `notifications.weekly_digest_enabled` (owned by Chapter 22).

## Screenshots

<!-- SCREENSHOT: Admin calendar list at /calendar/events.php with a `pending` row visible so Approve/Reject show. Save as 26-admin-calendar.png. -->
<!-- ![Admin calendar list](../images/26-admin-calendar.png) -->

<!-- SCREENSHOT: Public event detail at /calendar/event_view.php?slug=… showing the RSVP form. Save as 26-event-detail.png. -->
<!-- ![Public event detail](../images/26-event-detail.png) -->

<!-- SCREENSHOT: Admin attendee list at /calendar/admin_event_view.php?id=N. Save as 26-attendee-list.png. -->
<!-- ![Attendee list](../images/26-attendee-list.png) -->

## Gotchas

- **The legacy `events` table still exists — don't write to it.** All new code targets `calendar_events`. `EventService::updateDescription()` and `EventRsvpRepository::listByMember()` still reference the old table; treat them as load-bearing for whatever historical rows exist, but don't extend them.
- **The admin dashboard "Upcoming Events" card reads the legacy table.** `/admin/index.php` ~line 1524 does `SELECT * FROM events WHERE event_date >= CURDATE()`. On any environment where `events` is empty, the widget shows "No upcoming events" even if `/calendar/` is full. Migrate to `calendar_events` next time you're in that file.
- **Chapter-scoped events are only filtered in the digest, not the public calendar.** `/calendar/` shows all `published` events; the `chapter_id` filter only kicks in when a user picks it from the dropdown. "Only my chapter" by default for logged-in members would need to be added.
- **Timezones are per-event.** `calendar_events.timezone` overrides everything; if empty, fallback is `calendar_config('timezone_default', 'UTC')` — *not* the global `site.timezone`. Keep `calendar/config/config.php`'s `timezone_default` aligned with `site.timezone` (both default to `Australia/Sydney`).
- **RRULE support is partial.** `FREQ=DAILY|WEEKLY|MONTHLY` + `INTERVAL`, `BYDAY`, `UNTIL`. No yearly, no `BYSETPOS` ("last Sunday of the month" doesn't work), no exception dates.
- **The calendar module has its own session bootstrap and CSRF.** `app/bootstrap.php` does **not** run on `/calendar/` requests. Helpers like `db()`, `current_user()`, `e()` aren't available — use `calendar_db()`, `calendar_current_user()`, `calendar_e()`.
- **No event-creation broadcast.** Members aren't emailed when a new event is published. Discovery is via the weekly digest and the public calendar. Instant notify-on-publish would need wiring to `App\Services\NotificationService`.

## Related chapters

- [21 — Chapters & area reps](view.php?slug=21-chapters-area-reps) — where `chapter_id` and the `AREA_REP` role come from.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — `NotificationService`, weekly-digest plumbing, per-user notification preferences.
- [32 — Settings by section (reference)](view.php?slug=32-settings-by-section) — the full `events.*` and `notifications.*` settings list.
