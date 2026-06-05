# Media library

## What this covers

How the site stores, indexes, references, and deletes uploaded files: the `media` table, `App\Services\MediaService`, the `/admin/index.php?page=media` UI, the `[media:NNN]` shortcode, and the satellite flows (Wings Magazine, Fallen Wings) that share the same `uploads/` directory.

## Why it exists

We want **one managed asset store** instead of files scattered across whichever PHP page accepted the upload:

- **Reusability.** A logo, a committee photo, a Wings cover gets a stable `id`. Pages, notices, the AI page builder, store products and committee cards all reference the same asset without copy-pasting URLs.
- **Cleanup.** Without a central record we'd have no idea which `/uploads/*.jpg` is still in use. `MediaService::deleteMedia()` walks every place an asset could be referenced (`store_product_images`, `member_bikes`, `notices`, `events`, `wings_issues`, `settings_global`, `settings_user`, page HTML columns) and nulls or rewrites the reference before removing the file from disk.

The compromise: features that wrote files before the library existed still write directly to `public_html/uploads/`. `MediaService::syncIndex()` sweeps those legacy paths into the `media` table so the admin UI can see and delete them too.

## How it works

### Where files live

Everything under `public_html/uploads/`. Top-level subfolders today: `library/`, `about/`, `avatars/`, `bikes/`, `members/`, `notices/`, `store/`, `wings/`, `fallen_wings/`. The general library upload flow drops new files straight into `public_html/uploads/` with a unique filename; subfolders are owned by their feature's own upload code (see "satellite flows" below).

### The `media` table

Defined in `database/schema.sql`. Key columns: `id`, `type` (`image`/`pdf`/`video`/`file`), `title`, `file_name`, `path` (public URL like `/uploads/abc_logo.png`), `file_path` (relative to `uploads/`), `file_type` (MIME), `file_size`, `embed_html` (for `video`), `tags`, `visibility` (`public`/`member`/`admin`, default `member`), `uploaded_by` / `uploaded_by_user_id`, `source_context` (`library`/`wings`/`store`/…), `source_table`, `source_record_id`, `created_at`.

`path` and `file_path` both exist for historical reasons — `path` is the public URL used by shortcodes and HTML, `file_path` is the cleaned relative path used for delete/dedupe. `MediaService::normalizeUploadsPath()` converts between them and refuses anything containing `..`, leading `/`, or Windows-style drive letters.

### The upload flow (library)

`/admin/index.php?page=media`, POST with `media_file`:

1. Read `media.max_upload_mb` and `media.allowed_types` from `settings_global`. Reject if oversize or MIME (sniffed with `finfo`) not in the allow-list.
2. Hard-block executable extensions (`php`, `phtml`, `phar`, `shtml`, `cgi`, `pl`, `py`, `sh`, `htaccess`) regardless of MIME.
3. Sanitize the basename to `[a-zA-Z0-9._-]`, prepend `uniqid()` for uniqueness.
4. `move_uploaded_file()` into `public_html/uploads/`.
5. If `media.image_optimization_enabled` is on and MIME is `image/jpeg|png|webp`, GD re-encodes in place (JPEG q85, PNG level 6, WebP q80). No thumbnails are generated.
6. Call `MediaService::registerUpload()` which inserts the `media` row and dedupes against any existing `file_path` or `path`.

### The `[media:NNN]` shortcode

Defined in `app/bootstrap.php` as the global `render_media_shortcodes($html)`. It scans for `[media:123]` patterns, looks each ID up in `media`, and substitutes:

- `image` → `<img src="…" alt="…">`
- `pdf` / `file` → `<a href="…">title</a>`
- `video` with `embed_html` → the raw embed snippet
- Missing row → `<span class="text-xs text-slate-500">Missing media</span>`

It runs on public page renders in `public_html/index.php`, on notices in `/admin/index.php` and `/member/index.php`, and on the page-builder live preview at `/admin/page-builder/preview.php`. Prefer the shortcode over hardcoded URLs — a future filename change only needs a `media.path` update.

### Satellite upload flows

Three features bypass the library upload code but still write into `uploads/`:

- **Wings Magazine** — `/admin/index.php?page=wings`. Same size/MIME settings, separate handler around `public_html/admin/index.php:1298`; URLs stored in `wings_issues.pdf_url` / `cover_image_url`.
- **Fallen Wings / Member of the Year** — `/admin/member-of-the-year/` plus the handler at `public_html/admin/index.php:1055`. Writes to `uploads/fallen_wings/`, validates extension only (JPG/PNG/WEBP, PDF for tributes).
- **AI page builder** — inserts `<img src="/uploads/…">` references that `syncIndex()` picks up.

`syncIndex()` (the "Sync media index" button) walks `store_product_images`, `member_bikes`, `notices`/`events.attachment_url`, `wings_issues`, `files`, `settings_global` (logo/favicon/email_logo), `settings_user` (avatar_url), and every page's HTML, and registers anything pointing at `/uploads/…` that isn't already a `media` row.

## Where to change it

- **Admin UI:** Admin sidebar → **Media** → `/admin/index.php?page=media`. Permission gate: `admin.media_library.manage`.
- **Settings:** Admin → Settings Hub → **Media & Files** (`/admin/settings/index.php?section=media`). Same permission.
- **Code:**
  - `app/Services/MediaService.php` — register, sync, delete, reference counts.
  - `app/bootstrap.php` (`render_media_shortcodes`) — shortcode rendering.
  - `public_html/admin/index.php` around line 1226 — the library upload handler.
  - `database/schema.sql` — the `media` table.

## Settings

All under the `media.*` category in `settings_global`:

| Key | Default | What it does |
|---|---|---|
| `media.max_upload_mb` | `10` | Upload size cap (MB). `0` disables the check. |
| `media.allowed_types` | `[]` | Allow-list of MIME types. Empty array = allow anything (subject to the executable-extension blocklist). |
| `media.image_optimization_enabled` | `false` | Re-encode JPEG/PNG/WebP through GD on upload to shrink them. No resize, no thumbnails. |
| `media.privacy_default` | `'member'` | Default `visibility` for new uploads. One of `public` / `member` / `admin`. |


<!-- SCREENSHOT: Media library grid at /admin/index.php?page=media. Save as 25-media-library-grid.png. -->
<!-- ![Media library grid](../images/25-media-library-grid.png) -->

<!-- SCREENSHOT: Upload modal / form on the same page, showing title/tags/visibility. Save as 25-media-upload.png. -->
<!-- ![Upload form](../images/25-media-upload.png) -->

<!-- SCREENSHOT: Page builder content area with a [media:NNN] shortcode inserted, beside the rendered preview from /admin/page-builder/preview.php. Save as 25-media-shortcode.png. -->
<!-- ![Shortcode in page HTML](../images/25-media-shortcode.png) -->

## Gotchas

- **`public_html/uploads/` ships in deploys.** The folder is in the git repo, so anything committed lives forever in history. Don't commit member PII you uploaded for testing — and don't `git clean` the server's `uploads/` folder, you'll wipe production media.
- **Server uploads aren't in git.** Production uploads exist on cPanel only. A fresh checkout has placeholder/dev media. Back up `uploads/` before destructive work.
- **File ownership.** `public_html/uploads/` must be writable by the PHP-FPM user (DEPLOY.md step 6). If uploads start failing with "Upload failed" after a deploy, check ownership and `chmod`. A wrong-owner subfolder breaks just that feature (e.g. only Wings uploads fail).
- **Delete file vs. delete row.** `deleteMedia()` removes both, but only if `file_path` passes the safe-path check. Legacy rows with only `path` rely on `normalizeUploadsPath()` to reconstruct it; a malformed `path` yields `blocked_unsafe_path` in the audit log and leaves the file on disk.
- **Shortcodes resolve at render time.** Delete a media row and every `[media:NNN]` referencing it renders as "Missing media". `referenceCounts()` shows DB references but does **not** scan page HTML for shortcodes — check before deleting.
- **No thumbnails, no resizes.** "Optimization" is in-place re-encoding only. A 6000-pixel phone photo stays 6000 pixels.
- **The library upload writes to `public_html/uploads/`** (flat), not `public_html/uploads/library/` despite the subfolder name.
- **`uploaded_by` vs. `uploaded_by_user_id`.** Both columns exist and get the same value — `uploaded_by` is the legacy FK to `users(id)`, `uploaded_by_user_id` is what newer code reads.

## Related chapters

- [23 — Pages, navigation & menus](view.php?slug=23-pages-navigation) — where `[media:NNN]` shortcodes get embedded.
- [24 — AI page builder](view.php?slug=24-ai-page-builder) — inserts media references into generated page HTML.
- [26 — Events & RSVPs](view.php?slug=26-events-rsvps) — `events.attachment_url` is one of the references the library tracks.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — where `media.*` settings live and how `SettingsService` reads them.
- [33 — Deployment](view.php?slug=33-deployment) — why `uploads/` is in the repo, server file ownership, and why never to `git clean` on the server.
