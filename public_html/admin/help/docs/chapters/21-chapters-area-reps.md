# Chapters & area reps

## For administrators

### What this is

The AGA is a federation of **chapters** — Riverina, Central West, Hunter, Tasmania and the rest. Every member belongs to one chapter (or none, if they haven't picked yet). Each chapter has its own rides, its own roster, and usually its own small committee.

The site mirrors that structure:

- **Chapters** are a list you can edit (name, state, active/inactive).
- **Members** are tied to a chapter via their profile.
- **Area Reps** are admins with a *scope* — they only ever see and edit members in their own chapter.
- **Committee positions** (president, secretary, treasurer, area rep, etc.) are recorded per chapter, with their own persistent contact email and phone so they survive the people in them rotating out.

### What you can do here

- Add a new chapter (rare — usually only when the association formally creates one).
- Assign a member to a chapter (or change which chapter they belong to).
- Mark a member as an Area Rep for their chapter.
- Record who currently holds each committee position (president, secretary, area rep, etc.) and edit the contact email/phone tied to that *position*.
- Approve or reject a member's request to switch chapters.

### Who's allowed

- **Admin** — everything on this page. Add chapters, move members between chapters, assign Area Reps, approve chapter-change requests.
- **Area Rep** — sees and edits only members in their own chapter. Cannot add chapters, cannot move members to a different chapter, cannot make someone else an Area Rep.
- **Treasurer / Committee Member** — full member visibility but no chapter-management powers by default.

If an Area Rep needs to cover two chapters (e.g. acting in another chapter's role temporarily), the current workaround is to give them the full Admin role for that period. There's no built-in "rep for multiple chapters" toggle.

### Where to find it

- **Member chapter assignments** — Admin → Members → click the member → **Profile** tab → **Chapter** dropdown.
- **The list of chapters** — Admin → Settings → **Membership & Pricing** → scroll to the **Chapters** table.
- **Committee role assignments** — Admin → Members → click the member → **Committee Roles** panel.
- **Pending chapter-change requests** — Admin → **Notification Hub** (the bell icon / `/admin/requests/`) → filter by **Chapter Change**.
- **Assigning the Area Rep role to a user** — Admin → Settings → **Access Control**.

### Where this shows up on the public site

The committee and chapter assignments you make in the admin feed three pages on the site automatically — you don't edit those pages by hand. Whenever you tick a Committee Role on a member, change their chapter, or mark them as Area Rep, the public side reflects it within minutes (a small cache refresh).

- **National Committee** — `/?page=committee` (linked from the public nav under **About → National Committee**). Shows everyone you've ticked as a national committee member (President, Vice President, Secretary, Treasurer, etc.) with the role title and the contact email/phone set on the *role*.
- **Chapters and Area Representatives** — `/?page=chapters-representatives` (linked under **About → Chapters and Area Representatives**). Grouped by state. Each chapter shows its current Area Rep with their name and the contact email/phone set on the role.
- **Members directory** — the in-portal directory (`/member/index.php?page=directory`) shows each member's chapter against their name, so members can find people in their own chapter.

What this means in practice: at the AGM when committee roles rotate, you don't open the committee page and edit text. You go to Admin → Members → the new role-holder → tick the role. The public page updates itself.

If a member is missing from the public committee or chapter rep page despite having a role ticked, three usual reasons:

1. The role is ticked in the database but their **member status** is inactive — only active members appear publicly.
2. The role's **contact email/phone** has never been set on the role itself (separate from the person's profile) — there's no admin UI for editing that yet; ask your developer.
3. The page cache is stale — opening the page in an incognito window or waiting a few minutes clears it.

### How to add a new chapter

{{link:/admin/settings/index.php?section=membership_pricing|Take me to Membership & Pricing}}

Admins only. You'll do this rarely — usually only when the national committee formally creates a new chapter.

1. Admin → Settings → **Membership & Pricing**.
2. Scroll to the **Chapters** table at the bottom.
3. Click **Add chapter**.
4. Fill in:
    - **Name** (e.g. "Riverina Chapter")
    - **State** (NSW, VIC, etc. — used to group chapters on the public site)
    - **Sort order** (lower numbers appear first in dropdowns)
    - **Active** — leave ticked. Untick only if the chapter has been formally retired.
5. Save.

The new chapter immediately appears in every chapter dropdown across the site (apply form, member profile, events).

### How to change a member's chapter

1. Admin → Members → search for the member → click their row.
2. Open the **Profile** tab.
3. Change the **Chapter** dropdown to the new chapter.
4. Save.

This is the direct admin override. The member is moved straight away — no approval step, no email. Use it for clear-cut cases (the member moved house and rang you, or you're fixing a data error). For everything else, encourage the member to use the chapter-change request from their own profile so there's a record of why it happened.

### How to make someone an Area Rep

1. Admin → Settings → **Access Control**.
2. Find the user (search by name or email).
3. Tick the **Area Rep** role for that user.
4. Save.

The *chapter* the rep is scoped to is automatically taken from their own member profile — whatever's set in their **Profile → Chapter** is the chapter they can administer. So if you're making someone the Riverina Area Rep, double-check their profile chapter is set to Riverina first.

Next time they log in, the Members admin page will only show them their own chapter's members, and the chapter dropdown will be locked.

### How chapter-change requests work

![The Notification Hub — chapter-change requests land here under the 'Chapter Change' filter (submitter names sanitized)](images/21-notification-hub.png)

{{tour:admin-notification-hub}}

Members can request a chapter change themselves — they don't need to ring the admin.

1. The member opens the **My Account** page on the site and picks a different chapter from the dropdown.
2. A pending request lands in Admin → **Notification Hub**, filed under **Chapter Change**.
3. You open the request, see who's asking and what chapter they want to move to, and click **Approve** or **Reject**. You can add a short message — the member sees it.
4. On approve, the member's chapter is updated immediately. On reject, nothing changes and the member sees your reason.

Until you act, the member stays in their current chapter and keeps getting that chapter's events and notices.

### What can go wrong

- **An Area Rep says they can't see members they should be able to.** Their own profile chapter is probably wrong. The rep sees whatever chapter their *member record* says they're in — fix that in Admin → Members → their profile.
- **A member isn't showing up in any chapter's list.** Their `chapter_id` is empty. Open their profile and set it. The list view groups these under "unassigned" so admins can clean them up.
- **Two Area Reps for the same chapter.** Technically allowed — the role doesn't enforce one-per-chapter. The convention is one rep per chapter; if you see two, check whether one is meant to be stepping down.
- **A committee email isn't reaching the right person.** Committee contact info (`ar.riverina@…`, `aga.president@…`) lives on the *role*, not on the member who currently holds it. Editing the member's email won't fix it. The role's email is set when the role is created — talk to your developer if it needs updating; there's no UI yet.
- **Member submitted a chapter change but says nothing happened.** Check the Notification Hub for a pending **Chapter Change** request. Until an admin approves it, the member is still in their old chapter.

### What gets recorded

- **The chapter assignment itself** lives on the member record (their profile shows it).
- **Chapter changes via the request flow** leave a row in `chapter_change_requests` with who asked, when, who approved/rejected, and the message — visible from the Notification Hub history.
- **Direct admin chapter changes** (via the profile dropdown) are written to the **activity log** — Admin → Security Log, search the member's name.
- **Committee role assignments** are tracked separately — who held which role, when. Useful for the AGM minutes.

### Good practice

- **Don't quietly move a member between chapters.** They'll keep showing up to the wrong rides. A phone call or short email first.
- **One Area Rep per chapter** is the convention. If you need two for a handover, set a date and remove the outgoing rep when it's done.
- **Review Area Reps annually** — after each AGM is a good time. Anyone no longer in the role should have it removed from Access Control.
- **Keep committee role contact info on the role, not the person.** That way, when the secretary changes at the next AGM, the inbox doesn't go dead. If a contact email needs updating, ask your developer rather than re-pointing it at a different member.
- **Encourage members to use the chapter-change request flow** rather than ringing you. It leaves a clean record and the member gets an email confirming the outcome.

### Who to ask if stuck

- **Area Rep can't see members they should** — check their profile chapter first; if that's right, ping your developer.
- **Need to edit a committee role's email or phone** — your developer (no admin UI for this yet).
- **Need to add or retire a chapter and unsure of the procedure** — your national committee, then come back and use the Membership & Pricing page.
- **Stuck on a pending chapter-change request** — full admin can approve/reject it; Area Reps don't see these.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

How the AGA's federated structure is modelled: the `chapters` table (Riverina, Central West, Hunter, etc.), the `area_rep` role that gives a member scoped admin powers over their own chapter, the committee roles surfaced by `CommitteeService`, and the member-driven chapter-change workflow in `PendingRequestsService`. How a member gets *into* a chapter on application is [Chapter 19](view.php?slug=19-membership-lifecycle); the members console overall is [Chapter 20](view.php?slug=20-members-admin).

### Why it exists

The AGA isn't centrally run. Each chapter operates semi-independently — own rides, own roster, own committee. The site has to reflect that:

- An **area rep** for the Riverina needs to see and edit Riverina members, but must not touch Hunter or Tasmania members.
- The **national committee** rotates every year or two, but the persistent contact addresses (`aga.president@…`, `ar.riverina@…`) have to outlive any one member.
- A member who moves house needs to **change their chapter** without silently switching themselves onto another chapter's list — admin approval keeps the rosters honest.

The model separates the **chapter** (a place), the **role** (a position with its own email + phone), and the **assignment** (who holds the role right now). That's why `committee_roles` and `member_committee_assignments` are two tables, not one column on `members`.

### How it works

#### The `chapters` table

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

#### Committee roles via `CommitteeService`

`App\Services\CommitteeService` is the single source of truth for who holds what position. Two tables back it (migration `015`):

- `committee_roles` — the catalog. Each row has a `slug`, `name`, `category` (`national` or `chapter`), `chapter_id` (NULL for national), and its own persistent `email` + `phone`.
- `member_committee_assignments` — the join: one row per (`member_id`, `role_id`).

The public Committee page and chapter-rep listings use `CommitteeService::nationalRoles()` and `chapterRolesByState($state)`. Both render via `app/Views/partials/committee_cards.php` and deliberately surface the **role's** email/phone, not the holder's personal contact — so when the secretary changes next AGM, the inboxes don't break. Reads are cached per-request; `syncAssignments()` blows the cache.

#### The `area_rep` role and chapter scoping

`area_rep` is a row in the `roles` table (renamed from `chapter_leader` by migration `2026_05_04_rename_chapter_leader_to_area_rep.sql`). The *chapter* it scopes to is taken from the rep's own `members.chapter_id`. That's the whole trick.

`App\Services\AdminMemberAccess::getChapterRestrictionId($user)` is the function every admin members page calls. It returns either `null` (no restriction — full admin or treasurer) or an integer `chapter_id` (the user is an `area_rep` and may only see/edit members in that chapter).

`/admin/members/index.php` uses the return value to bolt a `chapter_id` filter onto the query and disable the chapter dropdown. `/admin/members/view.php` 404s if an area rep tries to load a member from a different chapter (around line 92). The list view also hides the pending-chapter-change banner from area reps — those go to full admins only.

#### Chapter change requests via `PendingRequestsService`

The flow:

1. Member opens `/member/index.php`, picks a new chapter from the dropdown, submits → an `INSERT INTO chapter_change_requests (member_id, requested_chapter_id, status='PENDING', requested_at=NOW())`.
2. Request appears in the admin **Notification Hub** (`/admin/requests/`) under the **Chapter Change** type — `PendingRequestsService::TYPE_CHAPTER_CHANGE` aggregates them with everything else awaiting review.
3. Admin opens the row, hits approve or reject. `PendingRequestsService::applyAction('chapter_change', $id, 'approve'|'reject', $message, $reviewerUserId)` updates the request status, stamps `approved_by` / `approved_at`, and (via the matching update path) flips `members.chapter_id`.
4. The member sees the outcome in their own notification hub via `PendingRequestsService::allForUser()`.

### Where to change it

- **Edit the list of chapters** — `/admin/settings/index.php?section=membership_pricing`. The membership pricing page also exposes a chapters editor (name, state, active flag, sort order). Inserts and updates are written directly to the `chapters` table (around lines 545 and 572 of that file).
- **Assign committee or rep roles to a member** — `/admin/members/view.php?id={memberId}` → the "Committee Roles" panel. The form posts `committee_role_ids[]` and the handler calls `CommitteeService::syncAssignments($memberId, $roleIds)`. The catalog dropdown pulls from `CommitteeService::catalogForAssignment()` and pre-sorts chapter roles so the member's own chapter's roles appear first.
- **Toggle the `area_rep` role on a user** — `/admin/settings/access-control.php`. The role is treated like any other (see [Chapter 07](view.php?slug=07-roles-permissions)); the *chapter* scoping is automatic via the rep's `members.chapter_id`.
- **Approve or reject a chapter change** — `/admin/requests/` (the Notification Hub), filter by **Chapter Change**.
- **Change a member's chapter directly** (admin override, no member request) — `/admin/members/view.php?id={memberId}` → Profile tab → Chapter dropdown.

### Settings

There is **no `settings_global` key** for "default chapter" — new applicants must explicitly pick one in the apply form, and `members.chapter_id` stays `NULL` until they do. Chapter contact emails live on the **role**, not on the chapter (`committee_roles.email`, populated when the role catalogue was seeded with the persistent `ar.<chapter>@goldwing.org.au` addresses); there is no chapter-level email column.

The handful of related settings that *do* exist (number padding, magazine type, etc.) belong to membership and are documented in [Chapter 19](view.php?slug=19-membership-lifecycle).

### Gotchas / known issues

- **An area rep sees ONLY their own chapter.** Confirmed in `AdminMemberAccess::getChapterRestrictionId()` and the filter clamp in `/admin/members/index.php`. There is no "rep for multiple chapters" support — covering two chapters currently means giving the user the full `admin` role.
- **A member with no chapter is rare but valid.** `members.chapter_id` is nullable. The list groups these under an `unassigned` bucket. Area reps never see them (the match is integer equality, not "matches OR null").
- **Chapter changes are admin-approved, not self-serve.** Submitting a new chapter only creates a `PENDING` row in `chapter_change_requests`. Until an admin acts on it via the Notification Hub, `members.chapter_id` is unchanged and the member keeps receiving their old chapter's notices and events.
- **Committee role contact info is intentionally separate from the holder's contact info.** Don't "fix" a stale phone number by editing the *member* — edit `committee_roles` (no UI for this yet; it's an SQL change).
- **`Riverina Chapter Members@2026.xlsx` and `riverina_members.json` in the repo root are one-off import snapshots** for the Riverina chapter, consumed by `/migrate_riverina.php`. Not live data — a backfill of one chapter's roster from a legacy spreadsheet. Leave them alone unless re-running that specific import.
- **`/admin/members/merge_suburbs.php`** is a one-shot backfill that fills empty `members.suburb` from CSV imports — *not* chapter-mapping logic, despite its name suggesting "suburb → chapter" inference. Chapter assignment is always explicit (admin sets it, member requests it). The file's docblock says to delete it once the merge is complete.

</details>

<!-- SCREENSHOT: Chapters editor at /admin/settings/index.php?section=membership_pricing, scrolled to the "Chapters" table. Capture as goldwing.org.au. Save as public_html/admin/help/images/21-chapters-editor.png. -->
<!-- ![Chapters editor](../images/21-chapters-editor.png) -->

<!-- SCREENSHOT: /admin/members/ logged in as an area_rep — the chapter filter is disabled and shows only their chapter, the helper text "Showing chapter members only" is visible. Save as 21-area-rep-members-view.png. -->
<!-- ![Area rep members view](../images/21-area-rep-members-view.png) -->

<!-- SCREENSHOT: Notification Hub at /admin/requests/?type=chapter_change showing one pending chapter change request with approve/reject buttons. Save as 21-chapter-change-request.png. -->
<!-- ![Chapter change request](../images/21-chapter-change-request.png) -->

## Related chapters

- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — how `area_rep` slots into the role catalog and what permissions it carries.
- [19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) — chapter is chosen on the apply form and committed at approval.
- [20 — Members admin console](view.php?slug=20-members-admin) — the page area reps spend most of their time on; chapter scoping is enforced here.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — chapter-scoped notice and event delivery uses `members.chapter_id`.
- [26 — Events & RSVPs](view.php?slug=26-events-rsvps) — events have a `chapter_id` and a `scope` (`NATIONAL` or chapter-scoped); the member portal feed filters on the viewer's chapter.
