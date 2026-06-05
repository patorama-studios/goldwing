# Pages, navigation & menus

## What this covers

The public CMS-like part of the site: the `pages` table (slug + title + body + visibility + per-page access), the draft → live publishing flow with versioned snapshots, the structured block schema the visual builder edits, and the menus that join those pages into the navigation. AI editing of pages — chat, diffs, model wiring — is the next chapter. This one is the storage and routing layer underneath.

## Why it exists

Two needs collided. The committee wants to update headlines, prices and announcements without a developer redeploy — so pages can't live in PHP files. The team also wants a *visual* editor, and a free-form HTML textarea can't safely round-trip one. So pages store *both* a structured JSON schema (source of truth for the builder) and a flattened HTML render (what the public site serves). A draft buffer on top lets editors stage changes and push live when ready, and every publish snapshots the HTML into `page_versions` so a bad change can be rolled back from the admin UI.

Menus exist for the same reason: the public nav used to be a partial in `app/Views/`. Now it's a database table, a drag-and-drop UI, and three named locations (`primary`, `secondary`, `footer`) that templates render from.

## How it works

### The `pages` table

Each row is one public route. The slug is what `public_html/index.php?page=<slug>` matches on — `home` is special and renders at `/`. Columns of note:

- `slug` — URL-safe identifier (`^[a-z0-9-]+$`).
- `title` — browser title and menu-item label when "use page title" is checked.
- `visibility` — `public`, `member`, or `admin`. Coarse-grained.
- `access_level` — finer-grained, layered on top. Either `public` or `role:<role_key>`. Evaluated by `PageBuilderService::canAccessPage()` *before* `visibility`. See [Chapter 07](view.php?slug=07-roles-permissions).
- `html_content` — legacy/canonical flat HTML the public page renders.
- `draft_html` / `live_html` — the draft buffer and the last-published render. New code writes both; old code only had `html_content`, so `PageService::draftHtml()` / `liveHtml()` fall back through all three.
- `schema_json` — the structured block tree the visual builder edits.

`PageService` is the thin CRUD layer: `getBySlug()`, `getById()`, `listEditablePages()`, `updateDraft()`, `publishDraft()`, `updateContentWithSchema()`. It calls `ensureHomePage()` on first read so a fresh install always has a `home` row to edit.

### Draft → live → version

`updateDraft()` writes only to `draft_html` and `access_level`. The public site never reads `draft_html`, so saving a draft is non-destructive.

`publishDraft()` copies the current draft into `live_html` *and* `html_content`, and the page-builder API endpoint (`/admin/page-builder/api.php?action=publish`) inserts a row into `page_versions` with a new `version_number`, a label like `v3 – Update`, the full HTML snapshot, and the publishing user. Rolling back writes the chosen version's `html_snapshot` back into the draft buffer rather than swapping live directly, so the editor can preview before re-publishing.

The flow is audited (`AuditService::log(..., 'push_live', ...)`) and a system message is dropped into the page's chat log.

### `PageSchemaService` — the block grammar

`PageSchemaService` (683 lines, the biggest service in `app/Services/`) defines and enforces the structured-content schema the visual builder edits. It owns:

- The allowed **layouts** (`default`, `full-width`, `landing`).
- The allowed **block types** — `hero`, `text`, `image`, `gallery`, `video`, `button`, `cta`, `quote`, `faq`, `pricing`, `testimonial`, `form`, `section`, `columns`, `spacer`, `divider`, plus dynamic blocks (`latest_posts`, `upcoming_events`, `user_profile`, `membership_status`, `notifications`).
- Recursive containers (`section` holds blocks; `columns` holds columns that hold blocks) and the logic to apply per-block updates inside the tree (`applyBlockUpdates()`).
- Decode and normalisation helpers for AI responses, so model output can be merged back without trashing the schema.

Everything the AI page builder does goes through this service. If you add a new block type, start here — and teach the renderer to draw it.

### Menus and navigation

`NavigationService` owns three tables: `menus`, `menu_items`, and `menu_locations`.

- A **menu** is a named collection of items (e.g. "Primary Menu").
- A **menu item** has either a `page_id` (link to a CMS page) or a `custom_url` (external link or hard-coded path), plus `parent_id` for nesting and `sort_order` for ordering, both managed by the admin UI's Up / Down / Indent / Outdent buttons.
- A **menu location** is a named slot templates render from. Three are seeded: `primary`, `secondary`, `footer`. Only one menu can be assigned to each location — assigning a new menu replaces the previous binding silently.

`getNavigationTree($locationKey, $user)` is what front-end templates call. It loads the menu bound to the location, builds a parent/child tree, filters out items the user can't see (`canViewPage()` plus path-based access control for custom URLs), and returns the result. Results are cached per-location per-role-set for 60 seconds in `app/cache/nav_*.json`; any menu save calls `clearNavigationCache()` to invalidate.

If no menus exist, `ensureDefaultMenu()` seeds a "Primary Menu" with Home / About / Ride Calendar / Membership / Events + a Member Login custom link, and binds it to `primary`. If a location has no menu assigned, `fallbackNavigation()` returns every public page so the site never renders an empty navbar.

## Where to change it

- **Edit page content (visual):** Admin sidebar → **Pages** → pick a page → opens `/admin/page-builder/`. See [Chapter 24](view.php?slug=24-ai-page-builder).
- **Edit page content (raw):** `/admin/pages/editor.php?id=<n>` (HTML + metadata); `/admin/pages/builder.php?id=<n>` (block-level).
- **List all pages:** `/admin/pages/` (alias `/admin/index.php?page=pages`). Guarded by `admin.pages.edit`.
- **Menus and locations:** Admin sidebar → **Pages and Nav** → `/admin/navigation.php`.
- **Per-page access:** the access dropdown on the page itself (saves via `/admin/page-builder/api.php?action=access`).
- **Public reading path:** `public_html/index.php` resolves `?page=<slug>`, calls `PageService::getBySlug()`, applies `PageBuilderService::canAccessPage()`, and renders `liveHtml()`. Templates pull menus via `NavigationService::getNavigationTree('primary' | 'footer' | …)`.

## Settings

This chapter has no settings of its own in `settings_global`, but two related ones matter:

- `ai.template_header_html` — HTML injected above the page body on every public render.
- `ai.template_footer_html` — same, below the body.

Both are stripped of element IDs (`PageBuilderService::stripElementIds`) before render so they don't collide with the per-page builder IDs. See [Chapter 31](view.php?slug=31-settings-architecture).

## Screenshots

<!-- SCREENSHOT: Admin Pages list at /admin/pages/ showing the editable pages with slug, title, access, and updated_at. Save as 23-pages-list.png and uncomment below. -->
<!-- ![Pages list](../images/23-pages-list.png) -->

<!-- SCREENSHOT: Navigation manager at /admin/navigation.php showing menu select, items list with Indent/Outdent, and the Menu Locations panel. Save as 23-navigation-manager.png. -->
<!-- ![Navigation manager](../images/23-navigation-manager.png) -->

<!-- SCREENSHOT: Page version history sidebar in the page builder showing v1 / v2 / v3 entries with rollback buttons. Save as 23-page-versions.png. -->
<!-- ![Version history](../images/23-page-versions.png) -->

## Gotchas

- **Deleting a page leaves menu items orphaned.** `menu_items.page_id` is a soft link (no FK), so deletion hides the item from the public render (LEFT JOIN drops it) but the row stays in `menu_items` showing `status: 'Missing'` in the admin UI. Review menus after deleting a page.
- **`access_level` is separate from `visibility`.** A page can be `visibility: public` but `access_level: role:committee` — the role check wins. If you're debugging "why can't members see this page?", check both columns.
- **The publish endpoint trusts the page ID.** It checks `require_permission('admin.ai_page_builder.publish')` but maintains no explicit allow-list — non-editable pages are kept out by *not appearing in `listEditablePages()`*. Gate system pages there, not at publish time.
- **`schema_json` is the source of truth — flat HTML is a render.** Pasting arbitrary HTML into the raw editor works once, but the next visual edit reconciles against the schema and hand-rolled markup may not round-trip cleanly. For custom layouts, add a block type in `PageSchemaService`.
- **Navigation is cached for 60 seconds per role-set.** Menu saves call `clearNavigationCache()`, but changing a page's `visibility` or `access_level` directly in the DB won't invalidate — clear `app/cache/nav_*.json` or call `NavigationService::clearCache()`.
- **Don't confuse pages with notices.** Notices are short pinned admin messages at `/admin/index.php?page=notices` — separate table, never in menus. If an editor asks "where do I change the banner?", they probably mean notices.

## Related chapters

- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — how `role:<key>` access levels resolve.
- [24 — AI page builder](view.php?slug=24-ai-page-builder) — the visual / chat editor that reads and writes the schema described here.
- [25 — Media library](view.php?slug=25-media-library) — where images referenced from page blocks live.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `ai.template_header_html` and `ai.template_footer_html` are stored.
