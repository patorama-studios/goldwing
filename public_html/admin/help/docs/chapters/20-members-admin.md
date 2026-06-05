# Members admin console

## What this covers

Everything under `/admin/members/`: the list view, the per-member detail view (Overview / Profile / Vehicles / Orders / Refunds / Activity), the CSV export, the bulk importers, the one-off cleanup tools, the central `actions.php` dispatcher, and the three services behind it — `AdminMemberAccess`, `MemberRepository`, `VehicleRepository`. This chapter is about *operating* on members. The lifecycle (PENDING → ACTIVE → LAPSED → CANCELLED) and renewals live in [Chapter 19](view.php?slug=19-membership-lifecycle).

## Why it exists

A single pane of glass. Every operation an admin or area rep performs on another person's account — orders, refunds, password reset, fix a stuck membership, archive, add a bike — has to live somewhere obvious, be auditable, and be permission-checked at three altitudes:

- **HTTP entry** — `require_permission('admin.members.view')` gates the page itself.
- **Action handler** — `actions.php` re-checks the specific verb (`canRefund`, `canImpersonate`, etc).
- **Data layer** — `MemberRepository::search` filters by `chapter_id` when the caller is an area rep.

That layering is why `AdminMemberAccess` exists as its own service — every page asks the same questions and we don't want each to re-derive the answer.

## How it works

### List view — `/admin/members/index.php`

Reads filters from the query string (`q`, `status`, `chapter_id`, `membership_type_id`, `role`, `directory_pref[]`, `vehicle_*`, `created_range`, `sort_by`, `sort_dir`, paging), hands them to `MemberRepository::search`, renders a table. A stats panel shows ACTIVE / LAPSED / PENDING counts (scoped to the chapter filter for area reps). Power features:

- **Inline editing** — full-access admins edit chapter, status, and 2FA flag from the row, POSTing `action=member_inline_update`. Area reps cannot inline-edit.
- **Bulk actions** — multi-select rows then run `assign_chapter`, `change_status`, `enable_2fa`, `send_reset_link`, `send_welcome_email`, `archive`, or `delete` (delete requires typing `CONFIRM`). Dispatched as `bulk_member_action` → JSON.
- **Directory preferences** — A–F flags every member sets on their public directory entry. Admins filter and see them regardless of the member's opt-in.

### Member detail — `/admin/members/view.php?id=X`

Tabbed view, tab in `?tab=`:

| Tab | What lives there |
|---|---|
| `overview` | Snapshot, contact, chapter, current membership period, recent orders, recent activity, danger-zone actions |
| `profile` | Editable identity record — name, email, phone, address, DOB, directory prefs. Gated by `canEditProfile`. Saves to `save_profile`. |
| `roles` | Role assignment (admin / treasurer / area_rep / member). Posts `roles_update`. Cross-ref [Chapter 07](view.php?slug=07-roles-permissions). |
| `settings` | Notification prefs, 2FA toggle / force / exempt / reset, avatar, member number rename. |
| `vehicles` | Bikes, trikes, sidecars, trailers (`VehicleRepository`). Posts `bike_add` / `bike_update` / `bike_delete`. |
| `orders` | Membership orders + storefront orders. Manual fix (`manual_order_fix`), Stripe resync (`order_resync`), accept/reject/send-link for pending memberships. |
| `refunds` | History plus the refund initiation form. Posts `refund_submit` → `RefundService`. Cross-ref [Chapter 17](view.php?slug=17-refunds). |
| `activity` | Per-member activity log slice. Cross-ref [Chapter 08](view.php?slug=08-activity-audit). |

Edit controls render conditionally on the relevant `AdminMemberAccess::can*` check. Area reps see all tabs but read-only on most.

### Actions dispatcher — `/admin/members/actions.php`

One ~2,300-line file with a giant `switch ($action)` handling every state-changing operation. Monolithic on purpose: every action shares the same prologue — parse `$_POST`, verify CSRF, load the member, check `AdminMemberAccess`, optionally `require_stepup()`, log to `activity_log`, redirect with flash. Splitting into 40 endpoints would multiply boilerplate and audit surface. Cases include `save_profile`, `change_status`, `member_archive`, `member_delete`, `send_reset_link`, `set_password`, `refund_submit`, `manual_order_fix`, `order_resync`, `twofa_force` / `_exempt` / `_reset`, `bike_*`, `manual_membership_order`, `impersonate_member`, and ~30 more.

A `$sensitiveActions` array at the top lists every verb that triggers `require_stepup()` — basically anything that mutates state.

### Impersonation — "Become this member"

`impersonate_member` writes `$_SESSION['impersonation']` with the admin's original user id + target user id, logs `impersonation.started`, and redirects to the member portal. `bootstrap.php` exposes `impersonation_context()` / `is_impersonating()`; partials use those to render a sticky banner in the member area ("You are signed in as X — return to admin"). A "stop impersonating" link clears the key.

### Export — `/admin/members/export.php`

CSV download. Requires `admin.members.import_export` **and** `require_stepup()` — exporting PII is sensitive. Columns: `Member #, Name, Email, Phone, Chapter, Membership Type, Status, Last Login, Created, Directory Preferences`. Logs `member.export` with the row count and fires `SecurityAlertService::send('member_export', ...)` so admins get an email every time. Honours the same filters as the list view, including area-rep chapter scoping.

### Import — `/admin/members/import.php` and `import_from_datafile.php`

Both require `admin.members.import_export` + step-up. `import.php` takes a CSV upload from the admin UI. `import_from_datafile.php` is a one-shot endpoint that reads `scripts/data/import_main_life.csv` or `import_associates.csv` directly from disk — the file header literally says "DELETE THIS FILE once the import is complete." Both share the column-mapping helpers (`normalizeHeader`, `parseCsvBoolean`, `fetchMemberColumns`) and go through `MemberRepository`.

### Cleanup utilities

- **`merge_suburbs.php`** — backfills `members.suburb` from the import CSVs *only where empty*, matching on `member_number_base.suffix`. Dry-run by default; POST `mode=apply` to commit. Never overwrites.
- **`backfill_member_baseline.php`** — promotes PENDING → ACTIVE, links members to existing `users` by email (creates one with a random password if none exists), assigns the `member` role where missing. The "baseline" gives every member a working login + an audit baseline so future profile edits diff against something. Dry-run by default; POST `apply=1` to commit.

### `MemberRepository` and `VehicleRepository`

`MemberRepository` is the read/write surface for `members`, `membership_periods`, `user_roles`, and member-auth rows. Public surface: `search`, `findById`, `findByEmail`, `updateProfile`, `directoryPreferences()` (the canonical A–F map), plus helpers that gracefully degrade when an optional column is missing — it inspects `information_schema` on first call and caches per request. `VehicleRepository` does the same for `member_vehicles` (`listByMember`, `getById`, `create`, `update`, `delete`, `setPrimary`) with `ALLOWED_TYPES = ['bike', 'trike', 'sidecar', 'trailer']`.

### Activity logging — every sensitive action

Every state-changing case ends with `ActivityLogger::log(...)`. Verbs you'll see: `member.profile_updated`, `member.password_reset_link_sent`, `member.password_set`, `member.archived`, `member.deleted`, `member.status_changed`, `member.vehicle_*`, `member.twofa_*`, `order.refunded`, `order.updated`, `impersonation.started` / `_stopped`, `member.export`. Schema and viewer in [Chapter 08](view.php?slug=08-activity-audit).

## Where to change it

- New column on the list view → `MemberRepository::search` (SELECT + table render in `index.php`).
- New tab on the detail view → add to `$tabOptions` + `$tabIcons` in `view.php` and render the panel block.
- New state-changing verb → add a `case` in `actions.php` and append to `$sensitiveActions` if it mutates anything. Don't forget the `ActivityLogger::log` line.
- New per-action permission → add a `can*` method to `AdminMemberAccess` and check it both in the page render and in the action handler.

## Settings

No global settings are unique to this chapter. The directory-preference visibility flags (A–F) live per-member on the `members` row itself, not in `settings_global`.

## Screenshots

<!-- SCREENSHOT: List view at /admin/members/index.php with filters expanded. Capture on draft.goldwing.org.au as a full admin. Save to public_html/admin/help/images/20-members-list.png and uncomment. -->
<!-- ![Members list view](../images/20-members-list.png) -->

<!-- SCREENSHOT: A member detail page at /admin/members/view.php?id=… with the tab bar visible. Save as 20-member-detail-tabs.png. -->
<!-- ![Member detail with tabs](../images/20-member-detail-tabs.png) -->

<!-- SCREENSHOT: The export step-up prompt before /admin/members/export.php runs. Save as 20-export-stepup.png. -->
<!-- ![Member export step-up](../images/20-export-stepup.png) -->

<!-- SCREENSHOT: The impersonation banner visible in the member portal after clicking "Become this member". Save as 20-impersonation-banner.png. -->
<!-- ![Impersonation banner](../images/20-impersonation-banner.png) -->

## Gotchas

- **Area reps see ONLY their chapter.** `AdminMemberAccess::getChapterRestrictionId($user)` returns the rep's own `members.chapter_id` and `index.php` / `export.php` force-set `$filters['chapter_id']` to it. If a rep's own chapter id is null, they see nothing — fix the rep's record, not the access logic.
- **Impersonation does NOT bypass 2FA on the impersonated account.** It writes a session key, not auth credentials. If the impersonated account triggers a step-up challenge you'll be prompted as them — and you won't have their TOTP. Use it for read-only "see what they see" cases; for actual fixes, edit on the admin side.
- **Export is sensitive — step-up required.** Same for both importers. Don't try to script around this from the browser.
- **`actions.php` is a monolith — long file.** ~2,300 lines, one switch. Resist splitting without first factoring out the shared prologue (CSRF + load member + access check + step-up + log + flash). Half-refactoring would scatter the audit story.
- **Directory preference visibility is asymmetric on purpose.** Admins always see A–F flags regardless of opt-in; public directory and member self-service obey the flags. Don't add an admin-side toggle that "hides" them — admins need the full picture.
- **`import_from_datafile.php`, `merge_suburbs.php`, `backfill_member_baseline.php` are one-shot migration tools.** Their headers say "DELETE THIS FILE once done." They exist for traceability of the original data load; they should not be live on a long-running install.
- **Schema-aware fallbacks.** Both repositories use `SHOW COLUMNS` / `SHOW TABLES` and cache per request. That lets code survive on environments where an optional column hasn't been migrated — but a typo in a column name silently makes the feature vanish instead of erroring. Check `information_schema` if a field appears to "not save."

## Related chapters

- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — what `require_stepup()` does and how the export / import prompts get triggered.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — the `admin.members.*` capabilities `AdminMemberAccess` delegates to.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — every action this console takes ends up there.
- [17 — Refunds](view.php?slug=17-refunds) — the refund flow that the Refunds tab triggers.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — what a "member" actually is and how status transitions happen.
- [21 — Chapters & area reps](view.php?slug=21-chapters-area-reps) — the chapter model that scopes area-rep visibility.
