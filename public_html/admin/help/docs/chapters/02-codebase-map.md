# Codebase map

## For administrators

This chapter is mostly for developers — admins rarely need to know about it. What you might want to know is that the site is made up of hundreds of files and folders, and this chapter is the **map**. If a developer ever asks you to "send me that file" or "check whether such-and-such exists," this is where you'd look to find it.

### What this is

A written-down list of every folder in the website's code, what's inside each one, and what it does. Think of it like the index at the front of a big reference book — not something you read cover to cover, but something you flip to when you need to find one specific thing.

The site isn't built out of one giant file. It's made up of many small pieces — each piece does one job (handling logins, sending emails, processing orders, etc.). The map tells you which folder holds which piece.

### What you might use this for

You'll almost never open this chapter on your own. But there are a few moments when it comes in handy:

- **A developer asks you to find a file.** They might say "have a look at `RefundService.php`" — this chapter tells you which folder to look in.
- **You're reviewing a developer's work.** If they've listed the files they changed, this chapter helps you sanity-check those files sit in areas you'd expect.
- **You're writing a brief to a developer.** The folder names from this map are the right vocabulary to describe roughly where a problem is.

### Where the files live

The whole site lives in a single **git repository** at `/home2/goldwing` on the cPanel server — everything (admin pages, member pages, database scripts, cron jobs, configuration) ships together as one unit.

### Areas you might recognise

You'll see these folder names come up in conversations with developers. You don't edit them — you just need to know roughly what's where:

- **`public_html/admin/`** — every admin page you log into. The Settings Hub, the Members list, the Store, the Activity Log, all of it.
- **`public_html/member/`** — the logged-in member area: the dashboard, the read-Wings page, 2FA enrolment, the notifications page.
- **`public_html/store/`** — the public-facing shop pages: catalogue, product pages, cart, checkout.
- **`app/storage/logs/`** — runtime log files. If a developer says "send me the log," they usually mean a file in here.
- **`cron/`** — the scheduled jobs that run automatically every day (renewal reminders, expiring lapsed memberships, the file-integrity scan). You don't run these yourself — the server runs them on a timer.
- **`config/`** — settings files. The database connection details and the AI provider key live here. **Never edit these from the admin panel side** — they're maintained by developers only.
- **`database/`** — `.sql` files that set up or update the database. Developers apply these when they roll out new features.

### What admins should never touch

Don't directly edit:

- Any **`.php` file** anywhere in the codebase. The admin pages are built out of PHP files, but you change their behaviour through Settings, not by editing the file. Editing PHP directly will almost certainly break the site.
- Any **`.sql` file** in `database/`. These rewrite the database. Running the wrong one can wipe data.
- **The database** itself (via phpMyAdmin on cPanel or otherwise). Everything you need to change lives behind a button in the admin panel — if you can't find a button, ask a developer rather than editing the database directly.

If you're not sure whether something is safe to touch, the safe assumption is **no**.

### Who to ask if a developer mentions a file

If a developer mentions a specific file by name and you don't know what they mean, **ask them to walk you through what they need**. A short "can you tell me what that file does and what you need from me?" is always a better answer than guessing. Developers expect to explain their own jargon — it doesn't make you look uninformed; it makes the handover clean.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

Every folder in the repo, what's inside, and which chapter goes deeper. The "where does X live?" reference. [Chapter 01](view.php?slug=01-system-overview) explains *why* the layout is shaped this way — this is the field map.

### Why it exists

No router, no scaffolding generator, no IDE convention telling you where new code belongs. A file's *path* is the convention. If you can't find something in 30 seconds you'll either duplicate it or wire it up wrong — so we keep the map written down.

### How it works

#### Top-level layout

```
Goldwing Website/
├── app/              ← PHP outside the web root (services, views, vendor)
├── public_html/      ← cPanel doc root (every PHP file = a URL)
├── config/           ← static config, DB credentials, tour manifest
├── database/         ← schema, seeds, module installers, migrations/
├── cron/             ← scheduled jobs
├── scripts/          ← tooling (impact checks, importers)
├── calendar/         ← standalone events module
├── includes/         ← legacy shared helpers
├── DEPLOY.md         ← deployment runbook
└── .cpanel.yml       ← cPanel "Update from Remote" hook
```

#### `app/`

`app/bootstrap.php` is the single entry point — every request `require_once`s it first. `app/Services/` holds 69 service classes (grouped below). `app/Views/partials/` holds shared HTML — `backend_head.php`, `backend_footer.php`, `backend_admin_sidebar.php`, `backend_member_sidebar.php`, `help_button.php`, `feedback_widget.php`, `committee_cards.php`, `membership_content.php`. `app/ThirdParty/stripe-php/` and `app/ThirdParty/fpdf/` are vendored libs (no Composer on cPanel). `app/storage/logs/` holds runtime logs (FIM, debug). `app/cache/` holds transient assets. `app/AI/`, `Auth/`, `Controllers/`, `Models/` are empty reserved namespace folders.

#### `app/Services/` — the 68 services, grouped

Autoloader maps `App\Services\Foo` to `app/Services/Foo.php`. Looking for "how does X work?" — start here.

**Auth & Security**

| File | Job |
|---|---|
| `AuthService.php` | Password + social login, signup. |
| `PasswordPolicyService.php` | Complexity, reuse, min-length. |
| `TwoFactorService.php`, `TotpService.php` | TOTP enrolment + verify. |
| `EmailOtpService.php` | Email OTP fallback. |
| `StepUpService.php` | Re-auth gate before sensitive actions. |
| `TrustedDeviceService.php` | Remember-device tokens. |
| `LoginRateLimiter.php` | Failed-attempt throttling. |
| `Csrf.php` | Token generation + `verify()`. |
| `SecurityHeadersService.php` | CSP, HSTS, X-Frame-Options. |
| `SecurityPolicyService.php` | Role/permission resolution. |
| `SecuritySettingsService.php` | `security.*` settings I/O. |
| `SecurityAlertService.php` | Admin emails when FIM fires. |
| `CryptoService.php`, `EncryptionService.php` | App-key encryption at rest. |
| `FileIntegrityService.php` | Hash + verify critical files. |

**Payments & Finance**

| File | Job |
|---|---|
| `StripeService.php` | Stripe SDK wrapper. |
| `StripeSettingsService.php` | Encrypted keys + price-ID mapping. |
| `PaymentSettingsService.php` | Fees + currency. |
| `PaymentWebhookService.php` | Idempotent webhook dispatch. |
| `OrderService.php`, `OrderRepository.php` | Store orders. |
| `MembershipOrderService.php` | Memberships-as-orders glue. |
| `RefundService.php` | Stripe refund flow + audit. |
| `InvoiceService.php`, `PdfInvoiceService.php` | Invoice records + FPDF render. |

**Members & Memberships**

| File | Job |
|---|---|
| `MemberRepository.php` | Member CRUD + queries. |
| `MembershipService.php` | Period lifecycle. |
| `MembershipStatusService.php` | Single funnel for admin status / renewal-date edits — keeps `members.status` and `membership_periods.status`/`end_date` in sync. |
| `MembershipPricingService.php` | Printed vs PDF × period matrix. |
| `MembershipMigrationService.php` | Bulk imports + chapter moves. |
| `AdminMemberAccess.php` | Per-admin scope. |
| `ChapterRepository.php` | Chapters + area-rep mapping. |
| `CommitteeService.php` | National + chapter committee cards. |
| `VehicleRepository.php` | Member bikes. |

**Content & Pages**

| File | Job |
|---|---|
| `PageService.php` | Public CMS-page CRUD. |
| `PageSchemaService.php` | Block schema (hero, columns, gallery…). |
| `PageBuilderService.php` | Drag-and-drop orchestration. |
| `AiPageBuilderService.php`, `PageAiRevisionService.php` | AI prompt → diff + history. |
| `NavigationService.php` | Menu locations + nested items. |
| `MediaService.php` | Uploads, thumbnails, library. |
| `DomSnapshotService.php`, `UnifiedDiffService.php` | Snapshot + diff for AI editor. |
| `EventService.php`, `EventRsvpRepository.php` | Public events + RSVPs. |
| `DownloadLogRepository.php` | Wings PDF download tracking. |

**Notifications & Comms** — `NotificationService.php` (what gets sent, to whom), `NotificationPreferenceService.php` (opt-outs), `EmailService.php` (templated render + send), `SmtpMailer.php` (raw transport), `EmailPreferencesTokenService.php` (one-click unsubscribe), `SmsService.php` (placeholder), `NoticeService.php` (on-site banners).

**AI** — `AiService.php` (frontline calls), `AiProviderKeyService.php` (encrypted keys), and `AiProviders/` holding `AiProviderInterface.php`, `AiProviderFactory.php`, and `KieAiProvider.php` (the only real provider today).

**Settings, Activity, Infra**

| File | Job |
|---|---|
| `SettingsService.php` | Settings Hub: `getGlobal/setGlobal`, JSON values. |
| `AuditService.php` | Stamps changes into `audit_logs` (admin actions). |
| `ActivityLogger.php`, `ActivityRepository.php` | Security & member-touching events → `activity_log`. |
| `AuditHubService.php` | Read-only UNION of `audit_log` / `audit_logs` / `activity_log` for the unified Audit Hub. |
| `LogViewerService.php` | Tails the PHP system log for the Advanced settings panel. |
| `Database.php`, `DbSessionHandler.php`, `Env.php` | DB connection, MySQL sessions, `.env` loader. |
| `BaseUrlService.php` | Resolves `https://…` across CLI + web. |
| `Validator.php` | Tiny input-validation helpers. |
| `PendingRequestsService.php` | Unified "needs admin action" queue. |
| `TourService.php` | UI walkthrough metadata. |

#### `public_html/`

Top-level: `index.php`, `login.php`, `logout.php`, `apply.php`, `become-a-member.php`, `checkout.php`, `email_preferences.php`, `stepup.php`, plus a few one-shot migration shims.

| Folder | Purpose |
|---|---|
| `admin/` | Admin console — breakdown below. |
| `member/` | Logged-in members: dashboard, `2fa_enroll.php`, `2fa_verify.php`, `read_wings.php`, `download_wings.php`, `notifications.php`, `reset_password.php`, `help.php`. |
| `store/` | Storefront: `catalog.php`, `product.php`, `cart.php`, `checkout.php`, `orders.php`. |
| `api/` | Server-to-server: `stripe_webhook.php`, `ai_settings.php`, `feedback.php`, `pages.php`. |
| `auth/` | OAuth callbacks: `google.php`, `apple.php`. |
| `assets/` | `styles.css`, `navigation.js`, `js/` (admin-members-list, address-autocomplete, password-strength, `goldwing-wysiwyg.js` — shared Quill + emoji-picker initialiser for `textarea[data-wysiwyg]`, `tours/`), `css/`, `img/`. |
| `uploads/` | User media: `library/`, `members/`, `avatars/`, `bikes/`, `notices/`, `store/`, `wings/`, `about/`. `.htaccess` blocks PHP here. |

#### `public_html/admin/`

| Subfolder | What it does |
|---|---|
| `index.php` | Dashboard — section-switcher by `?section=`. |
| `navigation.php` | Menu builder UI. |
| `ai_editor.php` | AI page-builder entry. |
| `members/` | List/view/actions, `add.php` (add-member wizard), import, export, `merge_suburbs.php`, `backfill_member_baseline.php`. |
| `store/` | Products, `product_form.php`, categories, tags, discounts, orders, low-stock, import, merge, settings. |
| `settings/` | Settings Hub (`index.php` by `?section=`), `roles.php`, `access-control.php`, `ai.php`, `*-save.php` handlers. |
| `security/` | `activity_log.php` (302 redirect into the Audit Hub). |
| `audit/` | Unified Audit Hub — settings diffs, admin actions, and security events in one timeline. |
| `page-builder/` | `index.php` (UI), `api.php` (JSON), `preview.php`. |
| `pages/` | `builder.php`, `editor.php` — shims into page-builder. |
| `requests/` | Pending-requests queue: `index.php`, `view.php`, `actions.php`. |
| `applications/` | `view.php` for new-member applications. |
| `member-of-the-year/` | Nomination admin. |
| `help/` | This documentation viewer, tour validator/editor, help API endpoints. |

#### `config/`

- `app.php` — base URL, mail-from, default AI model, env wiring.
- `database.php` — DB host/name/user/pass; per-env, never committed.
- `member_of_year.php` — voting window + categories.
- `tour-manifest.json` — every UI walkthrough; powers [Chapter 36](view.php?slug=36-tours-system) + impact-check.
- `tour-steps-seed.json` — initial step bodies for the tour seeder.

#### `database/`

| File | Contains |
|---|---|
| `schema.sql` | Canonical table definitions — run on a fresh DB. |
| `seeds.sql` | Default settings, seed admin, default roles. |
| `members_module.sql` | Vehicles, refunds, activity log. |
| `store_module.sql` | Products, orders, categories. |
| `payments_module.sql` | Payments tables (legacy install path). |
| `settings_hub.sql` | `settings_global`, `settings_user`, `audit_log`. |
| `about_pages.sql` | Re-seed About pages only. |
| `media_import.sql` | One-off media library seed. |
| `latest_full*.sql`, `latest_patched.sql`, `patched.sql` | Live-server dumps for local bootstrap. |
| `migrations/2025_*.sql … 2026_*.sql` | Date-ordered incremental migrations; run by hand per env. See [Chapter 03](view.php?slug=03-database-migrations). |

#### `cron/`

| Script | Schedule (`DEPLOY.md`) | Job |
|---|---|---|
| `send_renewal_reminders.php` | `0 6 * * *` | Emails members whose membership is about to lapse. |
| `expire_memberships.php` | `5 0 * * *` | Marks past-end-date periods as expired. |
| `daily_summary_admin.php` | `15 6 * * *` (optional) | Daily activity digest to first seeded admin. |
| `fim_scan.php` | Hourly (configurable) | File integrity scan; alerts on hash drift. |

#### `scripts/`

Impact-check pair (`check_doc_impact.php` / `.sh` and `check_tour_impact.php` / `.sh`) — read `_toc.json` and `config/tour-manifest.json` respectively, flag chapters/tours that need updating after a code change. `lint_tours.php` validates tour JSON. Importers: `import_store_catalogue.php` (idempotent SKU-keyed, reads `scripts/data/*.json`), `import_store_products_from_woocommerce.php`, `import_wings_from_live.php`. Media hygiene one-offs: `cleanup_media_references.php`, `cleanup_upload_duplicates.php`, `update_media_paths_from_duplicates.php`. `tests/critical_paths.php` is a smoke runner. `data/` holds source XLSX/JSON/CSV. `ftp_*.py` are legacy — **don't use** ([Chapter 33](view.php?slug=33-deployment)).

#### `calendar/` and `includes/`

`calendar/` is a semi-standalone events module with its own `sql/schema.sql`, `config/`, `cron/` (`weekly_digest.php`, `reminders.php`), and `lib/auth.php`. Writes to `calendar_*` tables to avoid clashing. See [Chapter 26](view.php?slug=26-events-rsvps).

`includes/` is legacy shared PHP pre-dating the autoloader: `access_control.php`, `admin_permissions.php`, `date_helpers.php`, `store_catalogue_import.php`, `store_helpers.php`. New code goes into `app/Services/`, not here.

### Where to change it

- **New service:** drop `app/Services/FooService.php`, namespace `App\Services`, class `FooService`. Autoloader picks it up.
- **New admin page:** `public_html/admin/<section>/<page>.php`, standard bootstrap + `require_role(['admin'])`, include the partials, add a sidebar link in `app/Views/partials/backend_admin_sidebar.php`.
- **New cron job:** drop under `cron/`, add the cPanel cron line, document in [Chapter 34](view.php?slug=34-cron-jobs).
- **New migration:** `database/migrations/YYYY_MM_DD_what.sql`; run by hand per env.

### Settings

None — pure orientation. Every other chapter declares its own.

### Gotchas (technical)

- **`public_html/admin/index.php` is ~260 KB** — a section-switcher by `?section=`. Resist adding sections; new admin pages get their own file under a subfolder.
- **`includes/access_control.php` and `App\Services\SecurityPolicyService` overlap.** Newer code uses the service; older admin pages still pull `includes/`. Follow what the file you're editing does — don't mix.
- **The `calendar/` module has its own bootstrap, config, sessions, and cron** — it doesn't go through `app/bootstrap.php`. Global auth changes need mirroring in `calendar/lib/auth.php`.
- **`app/AI/`, `Auth/`, `Controllers/`, `Models/` are empty.** Services live in `app/Services/`; the others are reserved placeholders.
- **`public_html/uploads.zip` is a 1.2 GB legacy backup** in repo history. Gitignored going forward but still there — don't clone fresh from a slow connection.
- **`.htaccess` rules matter.** `uploads/.htaccess` blocks PHP; `admin/page-builder/.htaccess` and `admin/pages/.htaccess` add rewrites. Check those when you move admin pages.

</details>

<!-- SCREENSHOT: Repo open in VS Code with top-level folders collapsed. Save as ../images/02-repo-tree.png. -->
<!-- ![Repo tree](../images/02-repo-tree.png) -->

<!-- SCREENSHOT: Listing of public_html/admin/ in Finder. Save as ../images/02-admin-folders.png. -->
<!-- ![admin/ folder contents](../images/02-admin-folders.png) -->

## Related chapters

- [01 — System overview & architecture](view.php?slug=01-system-overview) — why the layout looks like this.
- [03 — Database & migrations](view.php?slug=03-database-migrations) — the SQL files in detail.
- [04 — Configuration & environment](view.php?slug=04-configuration) — `config/` and `.env` deep dive.
- [33 — Deployment](view.php?slug=33-deployment) — how the repo reaches the server.
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — full cron operations chapter.
- [36 — Tours system](view.php?slug=36-tours-system) — `config/tour-manifest.json` and impact-check.
