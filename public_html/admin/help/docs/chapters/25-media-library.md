# Media library

## For administrators

### What this is

The **media library** is one central place where every photo, PDF, and other file uploaded to the site lives. Member photos, committee shots, Wings Magazine PDFs, Fallen Wings / Member-of-the-Year submissions, page banners, store product images — they all sit in the same library.

The point: upload a file once, then reuse it anywhere on the site without having to upload it again.

### What you can do

- **Upload new files** — drag-and-drop, or browse from your computer.
- **Find old files** — search by title or tag, filter by type (image / PDF / video / other).
- **Insert images into pages** — either pick them in the AI Page Builder, or paste a `[media:NN]` shortcode into a page's HTML.
- **Replace a file across the whole site** — change one file in the library and every page that references it updates automatically.
- **Delete files you no longer need** — with a check first to make sure nothing on the site is still using it.

### Who's allowed

**Admin** only. The library can hold sensitive things (member photos, draft Wings issues), so general committee members don't get access by default.

### Where to find it

**Admin → Media** (in the left sidebar).

Settings for the library live separately at **Admin → Settings → Media & Files** — that's where you set the maximum upload size and the allowed file types.

### How to upload a file

1. Go to **Admin → Media**.
2. Either **drag the file** onto the page, or click **Upload** and pick it from your computer.
3. (Optional) Give it a **title** and some **tags** so you can find it later.
4. (Optional) Set **visibility** — public (anyone), member (logged-in members only), admin (only admins).
5. Click **Save**.

Supported file types include common images (JPG, PNG, WebP), PDFs, and most document/video formats. The site rejects anything risky (PHP files, scripts, etc.) automatically.

### How to reference an image in a page

Two ways:

1. **Via the AI Page Builder** — when you ask the builder to add an image, pick from the library and it inserts the right reference for you. (See Chapter 24.)
2. **Using a shortcode** — paste `[media:NN]` into the page's HTML, where `NN` is the file's library ID (you'll see this next to the file in the library). When the page renders, the shortcode is replaced with the actual image, PDF link, or video embed.

Prefer the shortcode over pasting a raw `/uploads/...` URL — if the filename ever changes, the shortcode keeps working. A hardcoded URL doesn't.

### File limits

- **Max upload size** — set in **Settings → Media & Files** (default 10 MB).
- **Allowed types** — also set in **Settings → Media & Files**. Empty = anything except risky executable files.
- Phone photos are often 5–10 MB. Magazine PDFs can be larger — bump the limit if needed before uploading a new Wings issue.

### What can go wrong

- **"Upload failed" / file too big** — the file is over the **max upload size** limit. Either resize the image (most phone photos can drop to 1–2 MB without anyone noticing) or raise the limit in **Settings → Media & Files**.
- **"File type not allowed"** — the file's type isn't on the allow-list, or it's a blocked type (PHP, scripts). Save it as a JPG/PNG/PDF and try again.
- **A member's photo is accidentally public** — when uploading anything personal, double-check the **visibility** setting. The site default is "member" (logged-in members only), but it's worth verifying for sensitive uploads.
- **A page suddenly shows "Missing media"** — someone deleted a file the page was still using. The fix: re-upload the file and update the page to reference the new one, or restore the old file if you have a backup.
- **A photo looks huge / squashed on a page** — the library doesn't resize images. A 6000-pixel phone photo is still 6000 pixels in the library. Resize before uploading for best results.

### Good practice

- **Use descriptive filenames.** `2024-state-rally-group-shot.jpg` beats `IMG_2934.jpg`. Future-you will thank you when searching the library.
- **Resize big photos before uploading.** A 1500–2000 pixel wide JPEG is plenty for any page on the site, and loads much faster than a 6000-pixel one.
- **Don't delete files without checking what uses them.** The library shows reference counts in the database, but it can't always tell whether a file is embedded in page HTML. Search for the file's ID (`[media:NN]`) across pages before deleting.
- **Tag "do not delete" files.** Logos, the site favicon, Wings covers, anything load-bearing — give it a `do-not-delete` tag (or similar) so the next admin doesn't bin it by mistake.
- **Re-run "Sync media index" after big uploads.** If you've uploaded a stack of files outside the main library flow (Wings issues, member-of-the-year submissions), the Sync button re-indexes everything so it all shows up in the library view.

### Who to ask if stuck

- **"It won't let me upload this type"** — check the allow-list in **Settings → Media & Files**, or ask another admin to add the type.
- **"I deleted something I shouldn't have"** — your developer may be able to restore it from a server backup. Production uploads aren't in git, so the sooner you ask, the better the chance.
- **"Uploads have stopped working entirely after a deploy"** — that's almost always a server file-permissions issue. Flag it to your developer with the exact error message.

---

<details>
<summary><strong>Dev notes</strong></summary>

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

## Gotchas

- **`public_html/uploads/` ships in deploys.** The folder is in the git repo, so anything committed lives forever in history. Don't commit member PII you uploaded for testing — and don't `git clean` the server's `uploads/` folder, you'll wipe production media.
- **Server uploads aren't in git.** Production uploads exist on cPanel only. A fresh checkout has placeholder/dev media. Back up `uploads/` before destructive work.
- **File ownership.** `public_html/uploads/` must be writable by the PHP-FPM user (DEPLOY.md step 6). If uploads start failing with "Upload failed" after a deploy, check ownership and `chmod`. A wrong-owner subfolder breaks just that feature (e.g. only Wings uploads fail).
- **Delete file vs. delete row.** `deleteMedia()` removes both, but only if `file_path` passes the safe-path check. Legacy rows with only `path` rely on `normalizeUploadsPath()` to reconstruct it; a malformed `path` yields `blocked_unsafe_path` in the audit log and leaves the file on disk.
- **Shortcodes resolve at render time.** Delete a media row and every `[media:NNN]` referencing it renders as "Missing media". `referenceCounts()` shows DB references but does **not** scan page HTML for shortcodes — check before deleting.
- **No thumbnails, no resizes.** "Optimization" is in-place re-encoding only. A 6000-pixel phone photo stays 6000 pixels.
- **The library upload writes to `public_html/uploads/`** (flat), not `public_html/uploads/library/` despite the subfolder name.
- **`uploaded_by` vs. `uploaded_by_user_id`.** Both columns exist and get the same value — `uploaded_by` is the legacy FK to `users(id)`, `uploaded_by_user_id` is what newer code reads.

</details>

<!-- SCREENSHOT: Media library grid at /admin/index.php?page=media. Save as 25-media-library-grid.png. -->
<!-- ![Media library grid](../images/25-media-library-grid.png) -->

<!-- SCREENSHOT: Upload modal / form on the same page, showing title/tags/visibility. Save as 25-media-upload.png. -->
<!-- ![Upload form](../images/25-media-upload.png) -->

<!-- SCREENSHOT: Page builder content area with a [media:NNN] shortcode inserted, beside the rendered preview from /admin/page-builder/preview.php. Save as 25-media-shortcode.png. -->
<!-- ![Shortcode in page HTML](../images/25-media-shortcode.png) -->

## Related chapters

- [23 — Pages, navigation & menus](view.php?slug=23-pages-navigation) — where `[media:NNN]` shortcodes get embedded.
- [24 — AI page builder](view.php?slug=24-ai-page-builder) — inserts media references into generated page HTML.
- [26 — Events & RSVPs](view.php?slug=26-events-rsvps) — `events.attachment_url` is one of the references the library tracks.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — where `media.*` settings live and how `SettingsService` reads them.
- [33 — Deployment](view.php?slug=33-deployment) — why `uploads/` is in the repo, server file ownership, and why never to `git clean` on the server.
