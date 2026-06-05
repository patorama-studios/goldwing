# Chapters & area reps

## What this covers

How the AGA's federated structure is modelled: the `chapters` table (Riverina, Central West, Hunter, etc.), the `area_rep` role that gives a member scoped admin powers over their own chapter, the committee roles surfaced by `CommitteeService`, and the member-driven chapter-change workflow in `PendingRequestsService`. How a member gets *into* a chapter on application is [Chapter 19](view.php?slug=19-membership-lifecycle); the members console overall is [Chapter 20](view.php?slug=20-members-admin).

## Why it exists

The AGA isn't centrally run. Each chapter operates semi-independently — own rides, own roster, own committee. The site has to reflect that:

- An **area rep** for the Riverina needs to see and edit Riverina members, but must not touch Hunter or Tasmania members.
- The **national committee** rotates every year or two, but the persistent contact addresses (`aga.president@…`, `ar.riverina@…`) have to outlive any one member.
- A member who moves house needs to **change their chapter** without silently switching themselves onto another chapter's list — admin approval keeps the rosters honest.

The model separates the **chapter** (a place), the **role** (a position with its own email + phone), and the **assignment** (who holds the role right now). That's why `committee_roles` and `member_committee_assignments` are two tables, not one column on `members`.

## How it works

### The `chapters` table

```sql
CREATE TABLE chapters (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,   -- e.g. "Riverina Chapter"
  region     VARCHAR(150) NULL,       -- legacy / free-text
  state      VARCHAR(150) NULL,       -- "NSW", "VIC", grouped on the public page
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
);
```

`members.chapter_id` is a nullable FK back to this table — a member with no chapter just has `NULL`. Reads go through `App\Services\ChapterRepository`, which is column-aware (`hasColumn()` caches `SHOW COLUMNS` so older deployments missing `state` / `is_active` / `sort_order` still work). Two helpers do almost all of the lifting:

- `ChapterRepository::listForSelection($pdo, true)` — active chapters only, ordered by `sort_order` then `name`. Used by every dropdown.
- `ChapterRepository::listForManagement($pdo)` — every chapter (active or not), for the admin settings editor.

### Committee roles via `CommitteeService`

`App\Services\CommitteeService` is the single source of truth for who holds what position. Two tables back it (migration `015`):

- `committee_roles` — the catalog. Each row has a `slug`, `name`, `category` (`national` or `chapter`), `chapter_id` (NULL for national), and its own persistent `email` + `phone`.
- `member_committee_assignments` — the join: one row per (`member_id`, `role_id`).

The public Committee page and chapter-rep listings use `CommitteeService::nationalRoles()` and `chapterRolesByState($state)`. Both render via `app/Views/partials/committee_cards.php` and deliberately surface the **role's** email/phone, not the holder's personal contact — so when the secretary changes next AGM, the inboxes don't break. Reads are cached per-request; `syncAssignments()` blows the cache.

### The `area_rep` role and chapter scoping

`area_rep` is a row in the `roles` table (renamed from `chapter_leader` by migration `2026_05_04_rename_chapter_leader_to_area_rep.sql`). The *chapter* it scopes to is taken from the rep's own `members.chapter_id`. That's the whole trick.

`App\Services\AdminMemberAccess::getChapterRestrictionId($user)` is the function every admin members page calls. It returns either `null` (no restriction — full admin or treasurer) or an integer `chapter_id` (the user is an `area_rep` and may only see/edit members in that chapter).

`/admin/members/index.php` uses the return value to bolt a `chapter_id` filter onto the query and disable the chapter dropdown. `/admin/members/view.php` 404s if an area rep tries to load a member from a different chapter (around line 92). The list view also hides the pending-chapter-change banner from area reps — those go to full admins only.

### Chapter change requests via `PendingRequestsService`

The flow:

1. Member opens `/member/index.php`, picks a new chapter from the dropdown, submits → an `INSERT INTO chapter_change_requests (member_id, requested_chapter_id, status='PENDING', requested_at=NOW())`.
2. Request appears in the admin **Notification Hub** (`/admin/requests/`) under the **Chapter Change** type — `PendingRequestsService::TYPE_CHAPTER_CHANGE` aggregates them with everything else awaiting review.
3. Admin opens the row, hits approve or reject. `PendingRequestsService::applyAction('chapter_change', $id, 'approve'|'reject', $message, $reviewerUserId)` updates the request status, stamps `approved_by` / `approved_at`, and (via the matching update path) flips `members.chapter_id`.
4. The member sees the outcome in their own notification hub via `PendingRequestsService::allForUser()`.

## Where to change it

- **Edit the list of chapters** — `/admin/settings/index.php?section=membership_pricing`. The membership pricing page also exposes a chapters editor (name, state, active flag, sort order). Inserts and updates are written directly to the `chapters` table (around lines 545 and 572 of that file).
- **Assign committee or rep roles to a member** — `/admin/members/view.php?id={memberId}` → the "Committee Roles" panel. The form posts `committee_role_ids[]` and the handler calls `CommitteeService::syncAssignments($memberId, $roleIds)`. The catalog dropdown pulls from `CommitteeService::catalogForAssignment()` and pre-sorts chapter roles so the member's own chapter's roles appear first.
- **Toggle the `area_rep` role on a user** — `/admin/settings/access-control.php`. The role is treated like any other (see [Chapter 07](view.php?slug=07-roles-permissions)); the *chapter* scoping is automatic via the rep's `members.chapter_id`.
- **Approve or reject a chapter change** — `/admin/requests/` (the Notification Hub), filter by **Chapter Change**.
- **Change a member's chapter directly** (admin override, no member request) — `/admin/members/view.php?id={memberId}` → Profile tab → Chapter dropdown.

## Settings

There is **no `settings_global` key** for "default chapter" — new applicants must explicitly pick one in the apply form, and `members.chapter_id` stays `NULL` until they do. Chapter contact emails live on the **role**, not on the chapter (`committee_roles.email`, populated when the role catalogue was seeded with the persistent `ar.<chapter>@goldwing.org.au` addresses); there is no chapter-level email column.

The handful of related settings that *do* exist (number padding, magazine type, etc.) belong to membership and are documented in [Chapter 19](view.php?slug=19-membership-lifecycle).

## Screenshots

<!-- SCREENSHOT: Chapters editor at /admin/settings/index.php?section=membership_pricing, scrolled to the "Chapters" table. Capture as draft.goldwing.org.au. Save as public_html/admin/help/images/21-chapters-editor.png. -->
<!-- ![Chapters editor](../images/21-chapters-editor.png) -->

<!-- SCREENSHOT: /admin/members/ logged in as an area_rep — the chapter filter is disabled and shows only their chapter, the helper text "Showing chapter members only" is visible. Save as 21-area-rep-members-view.png. -->
<!-- ![Area rep members view](../images/21-area-rep-members-view.png) -->

<!-- SCREENSHOT: Notification Hub at /admin/requests/?type=chapter_change showing one pending chapter change request with approve/reject buttons. Save as 21-chapter-change-request.png. -->
<!-- ![Chapter change request](../images/21-chapter-change-request.png) -->

## Gotchas / known issues

- **An area rep sees ONLY their own chapter.** Confirmed in `AdminMemberAccess::getChapterRestrictionId()` and the filter clamp in `/admin/members/index.php`. There is no "rep for multiple chapters" support — covering two chapters currently means giving the user the full `admin` role.
- **A member with no chapter is rare but valid.** `members.chapter_id` is nullable. The list groups these under an `unassigned` bucket. Area reps never see them (the match is integer equality, not "matches OR null").
- **Chapter changes are admin-approved, not self-serve.** Submitting a new chapter only creates a `PENDING` row in `chapter_change_requests`. Until an admin acts on it via the Notification Hub, `members.chapter_id` is unchanged and the member keeps receiving their old chapter's notices and events.
- **Committee role contact info is intentionally separate from the holder's contact info.** Don't "fix" a stale phone number by editing the *member* — edit `committee_roles` (no UI for this yet; it's an SQL change).
- **`Riverina Chapter Members@2026.xlsx` and `riverina_members.json` in the repo root are one-off import snapshots** for the Riverina chapter, consumed by `/migrate_riverina.php`. Not live data — a backfill of one chapter's roster from a legacy spreadsheet. Leave them alone unless re-running that specific import.
- **`/admin/members/merge_suburbs.php`** is a one-shot backfill that fills empty `members.suburb` from CSV imports — *not* chapter-mapping logic, despite its name suggesting "suburb → chapter" inference. Chapter assignment is always explicit (admin sets it, member requests it). The file's docblock says to delete it once the merge is complete.

## Related chapters

- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — how `area_rep` slots into the role catalog and what permissions it carries.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — chapter is chosen on the apply form and committed at approval.
- [20 — Members admin console](view.php?slug=20-members-admin) — the page area reps spend most of their time on; chapter scoping is enforced here.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — chapter-scoped notice and event delivery uses `members.chapter_id`.
- [26 — Events & RSVPs](view.php?slug=26-events-rsvps) — events have a `chapter_id` and a `scope` (`NATIONAL` or chapter-scoped); the member portal feed filters on the viewer's chapter.
