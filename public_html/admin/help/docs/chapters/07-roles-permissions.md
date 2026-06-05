# Roles & permissions

## What this covers

How we decide what a logged-in user can see and do — the role-based access control (RBAC) system. Two things sit at the centre:

1. **Roles** — buckets we put users in (`admin`, `area_rep`, `store_manager`, `member`, plus aliases like `webmaster` and `committee`).
2. **Permission keys** — granular capabilities like `admin.payments.refund`. Roles "have" permissions; pages ask "does this user have that permission?"

Layered on top is **path-level access control** so pages can be opened or closed by URL without touching code.

## Why it exists

Goldwing is run by volunteers wearing several hats, and we wanted least-privilege without making every change a developer ticket.

- **The treasurer needs to issue refunds, but should not change SMTP settings.**
- **An area rep should see and edit *their chapter's* members — not the whole national list.**
- **A store-only volunteer should fulfil orders without seeing financial reports.**
- **The webmaster should edit pages and read the audit log without touching payments.**

A single-role system would force us to either grant too much (give the treasurer "admin") or write bespoke checks in every file. Permission keys plus a UI let committee members self-serve onboarding a new volunteer at the right level.

## How it works

### Where roles are stored

Three database tables:

- `roles` — one row per role, with `is_system` and `is_active` flags.
- `user_roles` — join table linking `users.id` to `roles.id`. A user can hold multiple roles.
- `role_permissions` — per-role grants (`role_id`, `permission_key`, `allowed`).

At login, `App\Services\AuthService::getUserRoles()` queries the join and stuffs the resulting **array of role-name strings** onto `$_SESSION['user']['roles']`. So in PHP, `$user['roles']` is always a plain array like `['admin', 'area_rep']` — not a JSON column, not comma-separated.

### The built-in roles

| Role | Used for |
|---|---|
| `admin` | Full access. Committee members and the site administrator. |
| `webmaster` | Legacy/alias role still used by hard-coded checks in `/admin/help/*` and `TourService` — normalised to `admin`. |
| `store_manager` | Quartermaster — products, orders, fulfilment. No member or settings access. |
| `area_rep` | Chapter-scoped: sees only their own chapter's members. See [Chapter 21](view.php?slug=21-chapters-area-reps). |
| `member` | Every logged-in member. Gates `/member/` and the store. |
| `committee` | Alias for `admin`. Old data may still hold this string. |

### Permission keys

Defined in `includes/admin_permissions.php → admin_permission_registry()`, grouped into eight categories — a representative sample:

- **Core Admin** — `admin.dashboard.view`, `admin.users.view/create/edit/disable`, `admin.roles.view/manage`
- **Membership** — `admin.members.view/edit/renew`, `admin.members.manual_payment`, `admin.members.import_export`, `admin.payments.view/refund`
- **Store / Orders** — `admin.store.view`, `admin.products.manage`, `admin.orders.view/fulfil/refund_cancel`
- **Content / Pages** — `admin.pages.view/edit/publish`, `admin.media_library.manage`, `admin.wings_magazine.manage`
- **Events / Calendar** — `admin.calendar.view/manage`, `admin.events.manage`
- **Notification Hub** — `admin.requests.view/action`
- **Builders / Tools** — `admin.ai_page_builder.access/edit/publish`, `admin.settings.general.manage`, `admin.logs.view`, `admin.integrations.manage`

The registry is the source of truth for the role builder UI — add a key here and it shows up as a new checkbox next page load.

### The helpers you'll see everywhere

`current_admin_can($permissionKey, $user = null)` — in `includes/admin_permissions.php`. Joins the user's roles to `role_permissions`, returns true/false. Used inline for menu items, button visibility, and per-action checks.

```php
if (current_admin_can('admin.payments.refund')) {
    echo '<button>Refund</button>';
}
```

`require_permission($permissionKey)` — `require_login()` then `current_admin_can()`. On failure renders a 403 page for HTML or returns `{"error":"Forbidden"}` for `/api/*` and JSON. Use at the top of any permission-gated admin page.

`require_role(['admin'])` — in `app/bootstrap.php`. Hard role gate; bypasses the permission registry entirely. Used by older code, by `/admin/help/*` pages (which check `['admin', 'webmaster']`), and as a backstop on the most sensitive pages.

`can_access_path($userOrRoles, $path)` — in `includes/access_control.php`. Looks the path up in `pages_registry`, joins to `page_role_access`. The engine behind the Access Control page.

`normalize_access_roles($roles)` — lower-cases, trims, resolves aliases (`chapter_leader`→`area_rep`, `treasurer`→`admin`, `committee`→`admin`, `super_admin`→`admin`, …). Always pipe untrusted role lists through this before comparing.

### Area reps and `AdminMemberAccess`

`App\Services\AdminMemberAccess` is the per-action access service for the Members console. It answers "can this admin do *this thing* to *this member record*?" The verbs (`canEditProfile`, `canResetPassword`, `canRefund`, `canImpersonate`, etc.) delegate to `current_admin_can()`. The one extra rule is `getChapterRestrictionId($user)` — it returns the chapter id an `area_rep` is locked to, or `null` for full-access roles. Members queries filter by that id, so area reps literally cannot see members from other chapters. See [Chapter 21](view.php?slug=21-chapters-area-reps).

## Where to change it

- **Admin → Settings → Admin Role Builder** (`/admin/settings/roles.php`) — create new roles and tick/untick permissions. Saved by `/admin/settings/roles-save.php`. System roles can be renamed but not deleted.
- **Admin → Settings → Access Control** (`/admin/settings/access-control.php`) — pick a role, then allow/deny each registered page path. Pages and patterns are auto-synced from the codebase by `sync_access_registry()` on each page load.
- **Admin → Settings → Accounts & Roles** (`/admin/settings/index.php?section=accounts`) — assign roles to specific users.
- **Code** — add a new permission key in `includes/admin_permissions.php` (`admin_permission_registry()`), then reference it via `current_admin_can()` or `require_permission()`.

## Settings

This chapter doesn't own any `settings_global` keys. The role/permission tables (`roles`, `user_roles`, `role_permissions`, `pages_registry`, `page_role_access`) are their own storage and are edited through the UIs above.


<!-- SCREENSHOT: /admin/settings/roles.php with a custom role selected, permission checkboxes visible. Save as 07-role-builder.png. -->
<!-- ![Admin Role Builder](../images/07-role-builder.png) -->

<!-- SCREENSHOT: /admin/settings/access-control.php with role=area_rep selected, showing the allow/deny matrix. Save as 07-access-control.png. -->
<!-- ![Page Access Control](../images/07-access-control.png) -->

## Gotchas

- **`webmaster` is half-alive.** `normalize_access_role()` aliases it to `admin`, but a handful of files — `TourService.php`, `/admin/help/edit.php`, `/admin/help/docs/view.php`, `help_button.php`, `api_steps.php` — still do `in_array('webmaster', $user['roles'])` directly. If you ever rename or remove `webmaster`, grep for the literal string first.
- **Adding a permission key is two steps.** Append it to `admin_permission_registry()` *and* tick it on the relevant role in `/admin/settings/roles.php` (or seed via `admin_default_role_permissions()`). Forget step two and no one — not even admins — has the new permission.
- **Roles are checked in TWO places.** `backend_admin_sidebar.php` filters menu items via `current_admin_can()`, *and* each page should also call `require_permission()` or `require_role()`. Hiding a menu item is **not** a security control — anyone who knows the URL can hit it if the page doesn't gate itself.
- **`committee` lingers in old data.** A migration dropped `committee`, `treasurer`, `webmaster`, `super_admin`, `membership_admin`, `store_admin`, `content_admin` from `roles`, but imports and integrations may still send these strings. `normalize_access_roles()` is what saves us.
- **An empty custom role can do nothing** — not even see the dashboard.
- **`require_role(['admin'])` ignores the permission registry.** Intentional — it's the backstop for the highest-stakes pages. But a custom "Membership Admin" role with every `admin.*` permission ticked will still *not* pass a `require_role(['admin'])` check. Use `require_permission()` for fine-grained gating; reserve `require_role()` for break-glass pages.
- **`area_rep` scoping depends on `users.member_id`.** If the area-rep account isn't linked to a member row, `getChapterRestrictionId()` returns `null` and they see *no* members at all.

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — how roles get loaded onto the session at login.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — `SecurityPolicyService` reads roles to decide who must enrol.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — role and permission changes are stamped to `audit_log`.
- [17 — Refunds](view.php?slug=17-refunds) — gated by `admin.payments.refund`.
- [20 — Members admin console](view.php?slug=20-members-admin) — where `AdminMemberAccess` is consumed.
- [21 — Chapters & area reps](view.php?slug=21-chapters-area-reps) — full chapter-scoping detail.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — gated by `admin.settings.general.manage`.
