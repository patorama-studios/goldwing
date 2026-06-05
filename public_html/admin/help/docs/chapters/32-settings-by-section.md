# Settings by section (reference)

## What this covers

A section-by-section catalogue of every page in the Settings Hub: what it controls, who can change it, and the exact `settings_global` keys it reads and writes. This is a reference chapter — keep it open in another tab when you're hunting for "what's the key name for X?" or "which permission gates the Payments tab?". For the *how* of settings (storage, caching, encryption, audit, defaults) see [Chapter 31 — Settings architecture](view.php?slug=31-settings-architecture).

## Why it exists

When something needs to change site-wide — a contact email, the Stripe mode, the password policy, a feature flag — there's exactly one place to do it: the Settings Hub at `/admin/settings/`. Landing on that URL shows a card-grid index of every settings category the current admin can access (defined by `$hubGroups` in `public_html/admin/settings/index.php`). The Hub has thirteen sections and 100+ keys, and the keys themselves are namespaced strings like `payments.stripe.use_test_mode` that you can't grep for without knowing the name. This chapter is the map. Every section here corresponds to one card on the Hub index and one entry in the `$sections` array of `public_html/admin/settings/index.php`.

## How it works (briefly)

All sections except the three standalone pages (`ai.php`, `roles.php`, `access-control.php`) are rendered by the same dispatcher: `public_html/admin/settings/index.php` reads `?section=<key>` (or `hub`, the default when no section is given). For `hub` it renders the card-grid index, filtering each card by its declared permission so admins only see categories they can actually open. For a real section it looks the key up in `$sections`, runs the per-section permission check via `current_admin_can()`, then renders the matching form. Saves go through `SettingsService::setGlobal($userId, $key, $value)` — which JSON-encodes the value, stamps `audit_log`, and (for sensitive keys) encrypts via `CryptoService`. Reads go through `SettingsService::getGlobal($key, $default)`.

Defaults come from three places:

- `SettingsService::ensureDefaults()` — seeded on every Hub load.
- `SecuritySettingsService::defaults()` — a separate `security_settings` table mirror.
- Per-form fallbacks — the third argument to `getGlobal()` calls in each form.

The full mechanics — JSON storage, caching, encryption flags, audit — are in [Chapter 31](view.php?slug=31-settings-architecture).

## Where to change it

| Section | URL | Permission |
|---|---|---|
| Hub landing | `/admin/settings/index.php` | Any of the section permissions below (per-card filter) |
| Site Settings | `/admin/settings/index.php?section=site` | `admin.settings.general.manage` |
| Store Settings | `/admin/settings/index.php?section=store` | `admin.store.view` |
| Payments (Stripe) | `/admin/settings/index.php?section=payments` | `admin.payments.view` |
| Notifications | `/admin/settings/index.php?section=notifications` | `admin.settings.general.manage` |
| Accounts & Roles | `/admin/settings/index.php?section=accounts` | `admin.users.view` |
| Security & Authentication | `/admin/settings/index.php?section=security` | `admin.settings.general.manage` |
| Integrations | `/admin/settings/index.php?section=integrations` | `admin.integrations.manage` |
| Media & Files | `/admin/settings/index.php?section=media` | `admin.media_library.manage` |
| Events | `/admin/settings/index.php?section=events` | `admin.events.manage` |
| AI Settings | `/admin/settings/ai.php` | `admin.settings.general.manage` |
| Membership Settings | `/admin/settings/index.php?section=membership_pricing` | `admin.membership_types.manage` |
| Audit Log | `/admin/settings/index.php?section=audit` | `admin.logs.view` |
| Advanced / Developer | `/admin/settings/index.php?section=advanced` | `admin.settings.general.manage` |
| Admin Role Builder | `/admin/settings/roles.php` | `admin.roles.view` (view) / `admin.roles.manage` (edit) |
| Access Control | `/admin/settings/access-control.php` | `admin.roles.manage` |

The permission map lives in `$hubGroups` at the top of `public_html/admin/settings/index.php` (each card declares its `permission` key) and is enforced in the dispatcher's `can_access_section()` check on form save. The sidebar entry for Settings (`app/Views/partials/backend_admin_sidebar.php`) is gated by `$settingsPermissions` — visible to any admin with at least one of the section permissions. Step-up authentication ([Ch 06](view.php?slug=06-2fa-stepup)) is required for every save *except* the Payments section (which uses Stripe's own gating).

## Settings

### Site

What it controls: the public brand identity — name, logo, contact details, social links, legal page URLs, the timezone PHP runs in.

| Key | Type | Default | What it does |
|---|---|---|---|
| `site.name` | string | "Australian Goldwing Association" | Site title, used in `<title>` and emails. Required. |
| `site.tagline` | string | "" | Shown under the logo on the public homepage. |
| `site.logo_url` | string | "" | Absolute URL of the logo image. |
| `site.favicon_url` | string | "" | Absolute URL of the favicon. |
| `site.timezone` | string | "Australia/Sydney" | `date_default_timezone_set()` value applied in `bootstrap.php`. |
| `site.base_url` | string | "" | Canonical base URL for absolute links in emails. Validated by `BaseUrlService`. |
| `site.contact_email` | string | "" | Public contact address. Fallback for test notifications. |
| `site.contact_phone` | string | "" | Public contact phone. |
| `site.show_nav` | bool | true | Toggles the public navigation bar. |
| `site.show_footer` | bool | true | Toggles the public footer. |
| `site.social_links` | object | `{facebook,instagram,youtube,tiktok}` | Map of social network URLs. |
| `site.legal_urls` | object | `{privacy,terms}` | Privacy and terms-of-service URLs. |

### Store

What it controls: storefront identity, fee passthrough, shipping, pickup, order-confirmation email branding. Deep dive: [Ch 27 — Store architecture](view.php?slug=27-store-architecture), [Ch 29 — Discounts, shipping & fees](view.php?slug=29-discounts-shipping).

| Key | Type | Default | What it does |
|---|---|---|---|
| `store.name` | string | (from legacy store row) | Store display name. |
| `store.slug` | string | "store" | URL slug for `/store/`. |
| `store.members_only` | bool | true | Restricts purchasing to logged-in members. |
| `store.shipping_region` | enum | "AU" | `"AU"` or `"INTL"`. |
| `store.gst_enabled` | bool | true | Adds GST line to orders. |
| `store.pass_stripe_fees` | bool | true | Adds the Stripe processing fee to the buyer's total. |
| `store.stripe_fee_percent` | float | 0 | Percent component of the passed fee. |
| `store.stripe_fee_fixed` | float | 0 | Fixed-cents component of the passed fee. |
| `store.shipping_flat_enabled` | bool | false | Charge a flat shipping rate. |
| `store.shipping_flat_rate` | float\|null | null | Flat-rate amount (dollars). |
| `store.shipping_free_enabled` | bool | false | Free shipping above threshold. |
| `store.shipping_free_threshold` | float\|null | null | Order subtotal for free shipping. |
| `store.pickup_enabled` | bool | false | Offer pickup option at checkout. |
| `store.pickup_instructions` | string | "" | Shown when buyer picks "pickup". |
| `store.notification_emails` | string | "" | Comma list of staff addresses for order alerts. |
| `store.email_logo_url` | string | "" | Logo for order-confirmation emails. |
| `store.email_footer_text` | string | "" | Footer line for order emails. |
| `store.support_email` | string | "" | Customer support address shown in emails. |
| `store.order_paid_status` | string | "paid" | The order status set by the Stripe webhook on successful payment. |

### Payments (Stripe)

What it controls: which Stripe environment is live, the API keys (encrypted), checkout features, receipts, invoice numbering, membership pricing toggles. Deep dive: [Ch 13 — Stripe overview](view.php?slug=13-stripe-overview), [Ch 18 — Invoices](view.php?slug=18-invoices). Saved via `StripeSettingsService::saveAdminSettings()`.

| Key | Type | Default | What it does |
|---|---|---|---|
| `payments.stripe.use_test_mode` | bool | true | Switches between test and live key sets. |
| `payments.stripe.test_publishable_key` | string | "" | Test-mode publishable key (pk_test_…). |
| `payments.stripe.test_secret_key` | string (encrypted) | "" | Test-mode secret key (sk_test_…). |
| `payments.stripe.live_publishable_key` | string | "" | Live-mode publishable key (pk_live_…). |
| `payments.stripe.live_secret_key` | string (encrypted) | "" | Live-mode secret key (sk_live_…). |
| `payments.stripe.webhook_secret` | string (encrypted) | "" | Webhook signing secret (whsec_…). |
| `payments.stripe.allow_guest_checkout` | bool | true | Allow checkout without an account. |
| `payments.stripe.require_shipping_for_physical` | bool | true | Force shipping form on physical SKUs. |
| `payments.stripe.digital_only_minimal` | bool | true | Skip shipping when cart is all digital. |
| `payments.stripe.enable_apple_pay` | bool | true | Show Apple Pay button. |
| `payments.stripe.enable_google_pay` | bool | true | Show Google Pay button. |
| `payments.stripe.enable_bnpl` | bool | false | Afterpay / Zip / etc. |
| `payments.stripe.send_receipts` | bool | true | Email Stripe receipts on success. |
| `payments.stripe.save_invoice_refs` | bool | true | Persist Stripe invoice IDs to our orders. |
| `payments.stripe.customer_portal_enabled` | bool | false | Enable Stripe's hosted customer portal. |
| `payments.stripe.checkout_enabled` | bool | true | Master switch for the storefront checkout. |
| `payments.stripe.invoice_prefix` | string | "INV" | Prefix used by `InvoiceService`. |
| `payments.stripe.invoice_email_template` | string | "" | Override template for invoice emails. |
| `payments.stripe.generate_pdf` | bool | true | Attach the FPDF-generated PDF to invoice emails. |
| `payments.stripe.mode` | enum (legacy) | "test" | Legacy mirror of `use_test_mode`; superseded but still written. |
| `payments.membership_prices` | object | (matrix) | Map of `{magazine_type → membership_type → period → cents}`. See `MembershipPricingService`. |
| `payments.membership_default_term` | enum | "12M" | Default selected term on the join form (`12M` or `24M`). |
| `payments.membership_allow_both_types` | bool | true | Allow both digital + print membership types. |
| `payments.bank_transfer_instructions` | string | "" | Shown to buyers who pick "Bank transfer" (where supported). |

Secret keys are auto-encrypted at rest via the `encrypt: true` flag on `setGlobal()` — see [Ch 10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets).

### Notifications

What it controls: the global From/Reply-To, admin recipient list, the in-app notification catalogue (per-event subject/body/recipients). Deep dive: [Ch 22 — Notifications & email](view.php?slug=22-notifications-email).

| Key | Type | Default | What it does |
|---|---|---|---|
| `notifications.from_name` | string | (from `config/app.php`) | Default sender name. |
| `notifications.from_email` | string | (from `config/app.php`) | Default sender address. Must end with `@goldwing.org.au`. |
| `notifications.reply_to` | string | "" | Default Reply-To header. |
| `notifications.admin_emails` | string | "" | Comma-or-newline list of admin recipients used by `NotificationService::getAdminEmails()`. |
| `notifications.weekly_digest_enabled` | bool | false | Enable the weekly admin digest cron. |
| `notifications.event_reminders_enabled` | bool | true | Enable the event-reminder cron. |
| `notifications.in_app_categories` | array | [] | Categories shown in the in-app notification bell. |
| `notifications.template_basic` | string | "" | Base HTML template wrapped around per-event bodies. |
| `notifications.catalog` | object | (defaults from `NotificationService::definitions()`) | Per-event overrides: `{enabled, recipient_mode, custom_recipients, subject, body, from_name, from_email, reply_to}`. |

### Accounts & Roles

What it controls: how new account requests are handled and how role changes get audited. The bulk of role/permission work happens on the two standalone pages — Admin Role Builder and Access Control — see below. Deep dive: [Ch 07 — Roles & permissions](view.php?slug=07-roles-permissions).

| Key | Type | Default | What it does |
|---|---|---|---|
| `accounts.user_approval_required` | bool | false | New signups need admin approval before activation. |
| `accounts.membership_status_visibility` | enum | "member" | Who can see another user's membership status (`public`, `member`, `admin`). |
| `accounts.audit_role_changes` | bool | true | Log every role assignment to `audit_log`. |

Gated behind the `accounts.roles` feature flag (Advanced section).

### Security & Authentication

What it controls: HTTPS enforcement, password length, 2FA policy, step-up window, login rate limits and lockout, security alert subscriptions, File Integrity Monitoring, webhook failure alerts. Persisted in `security_settings` (a single-row mirror) plus two `settings_global` keys. Deep dives: [Ch 06](view.php?slug=06-2fa-stepup), [Ch 09](view.php?slug=09-security-headers), [Ch 11](view.php?slug=11-file-integrity), [Ch 12](view.php?slug=12-rate-limit-lockout).

| Key | Type | Default | What it does |
|---|---|---|---|
| `security.force_https` | bool | false | `bootstrap.php` redirects HTTP → HTTPS when on. |
| `security.password_min_length` | int | 12 | Enforced by `PasswordPolicyService`. |
| `enable_2fa` (security_settings) | bool | true | Master switch for TOTP / email OTP. |
| `twofa_mode` | enum | "REQUIRED_FOR_ALL" | Or `OPTIONAL`, `REQUIRED_FOR_ROLES`. |
| `twofa_required_roles` | array | [] | Roles forced to enrol when mode is `REQUIRED_FOR_ROLES`. |
| `twofa_grace_days` | int | 0 | Days a new user can defer enrolment. |
| `stepup_enabled` | bool | true | Require step-up re-auth for sensitive saves. |
| `stepup_window_minutes` | int | 10 | How long a step-up is honoured. |
| `login_ip_max_attempts` | int | 10 | Failed-login cap per IP. |
| `login_ip_window_minutes` | int | 10 | Rolling window for the IP cap. |
| `login_account_max_attempts` | int | 5 | Failed-login cap per account. |
| `login_account_window_minutes` | int | 15 | Rolling window for the account cap. |
| `login_lockout_minutes` | int | 30 | Lockout duration after cap hit. |
| `login_progressive_delay` | bool | true | Slow each retry instead of hard-block. |
| `alert_email` | string | "" | Where security alerts get sent. |
| `alerts` | object | (all true) | Per-event toggles: `failed_login`, `new_admin_device`, `refund_created`, `role_escalation`, `member_export`, `fim_changes`, `webhook_failure`. |
| `fim_enabled` | bool | true | Run File Integrity Monitor cron. |
| `fim_paths` | array | `["/app","/admin","/config"]` | Paths to hash. |
| `fim_exclude_paths` | array | `["/uploads","/cache"]` | Sub-paths to skip. |
| `webhook_alerts_enabled` | bool | true | Alert on repeated webhook failures. |
| `webhook_alert_threshold` | int | 3 | Failures before alerting. |
| `webhook_alert_window_minutes` | int | 10 | Window for the threshold. |

### Integrations

What it controls: outbound email provider (Resend / SMTP / `mail()`), embed toggles, default Zoom link, optional MYOB sync.

| Key | Type | Default | What it does |
|---|---|---|---|
| `integrations.email_provider` | enum | "php_mail" | `php_mail`, `smtp`, `resend`. |
| `integrations.resend_api_key` | string (encrypted) | "" | Resend API key. |
| `integrations.smtp_host` | string | "" | SMTP server hostname. |
| `integrations.smtp_port` | int | 587 | SMTP port. |
| `integrations.smtp_user` | string | "" | SMTP username. |
| `integrations.smtp_password` | string (encrypted) | "" | SMTP password. |
| `integrations.smtp_encryption` | enum | "tls" | `tls`, `ssl`, `none`. |
| `integrations.youtube_embeds_enabled` | bool | true | Allow YouTube embeds in the page builder. |
| `integrations.vimeo_embeds_enabled` | bool | true | Allow Vimeo embeds. |
| `integrations.zoom_default_url` | string | "" | Pre-fill for event Zoom links. |
| `integrations.myob_enabled` | bool | false | Toggle MYOB sync (behind `integrations.myob` flag). |

### Media & Files

What it controls: upload limits, allowed types, image optimisation, default privacy, optional folder taxonomy. Deep dive: [Ch 25 — Media library](view.php?slug=25-media-library).

| Key | Type | Default | What it does |
|---|---|---|---|
| `media.allowed_types` | array | (image/PDF set) | MIME-suffix list permitted at upload. |
| `media.max_upload_mb` | float | 10 | Per-file upload cap. |
| `media.storage_limit_mb` | float | 5120 | Soft warning threshold for total storage. |
| `media.image_optimization_enabled` | bool | true | Auto-compress uploaded images. |
| `media.privacy_default` | enum | "member" | Default visibility of new uploads (`public`, `member`, `admin`). |
| `media.folder_taxonomy` | array | [] | Folder labels (behind `media.folder_taxonomy` flag). |

### Events

What it controls: defaults applied to new events. Deep dive: [Ch 26 — Events & RSVPs](view.php?slug=26-events-rsvps).

| Key | Type | Default | What it does |
|---|---|---|---|
| `events.rsvp_default_enabled` | bool | true | RSVP toggle pre-checked on new events. |
| `events.visibility_default` | enum | "member" | `public`, `member`, `admin`. |
| `events.public_ticketing_enabled` | bool | false | Allow non-members to buy event tickets. |
| `events.timezone` | string | "Australia/Sydney" | Display timezone for events. |
| `events.include_map_link` | bool | true | Render a Google Maps link from the address. |
| `events.include_zoom_link` | bool | true | Show the Zoom URL field on the event form. |

### AI Settings (standalone)

What it controls: the kie.ai-backed page builder model, monthly spend cap, per-token / per-image cost basis, system guardrails, master prompt. Saved by `public_html/admin/settings/ai.php`. Deep dive: [Ch 24 — AI page builder](view.php?slug=24-ai-page-builder).

| Key | Type | Default | What it does |
|---|---|---|---|
| `ai.provider` | string | "kie" | Provider key — currently fixed to `kie`. |
| `ai.model` | string | "claude-sonnet-4-6" | Model identifier passed to kie.ai. |
| `ai.image_generation_enabled` | bool | false | Allow image generation calls. |
| `ai.monthly_cap_usd` | float | 50 | Soft cap; blocks calls when exceeded. |
| `ai.token_cost_usd` | float | 0.01 | Per-1k-token cost basis for spend tracking. |
| `ai.image_cost_usd` | float | 0.04 | Per-image cost basis. |
| `ai.guardrails` | string | "" | Free-text safety rules injected into prompts. |
| `ai.builder_master_prompt` | string | "" | The system prompt prepended to every builder call. |
| `ai.template_header_html` | string | "" | Header HTML wrapper for AI-generated pages. |
| `ai.template_footer_html` | string | "" | Footer wrapper for AI-generated pages. |

The actual API key is stored separately in `ai_provider_keys` (encrypted) via `AiProviderKeyService`, not in `settings_global`.

### Membership Settings

What it controls: the pricing matrix (magazine type × membership type × period), member-number formatting, manual migration link, chapters CRUD. Saved via `MembershipPricingService::updateMembershipPricing()`. Deep dive: [Ch 14 — Membership pricing matrix](view.php?slug=14-membership-pricing), [Ch 21 — Chapters & area reps](view.php?slug=21-chapters-area-reps).

| Key | Type | Default | What it does |
|---|---|---|---|
| `membership.pricing_matrix` | object | (seeded matrix) | `{magazine_type → membership_type → period → cents}`. |
| `membership.member_number_start` | int | 1000 | First member number issued. |
| `membership.associate_suffix_start` | int | 1 | First associate suffix. |
| `membership.member_number_format_full` | string | "{base}" | Format template — must contain `{base}` or `{base_padded}`. |
| `membership.member_number_format_associate` | string | "{base}.{suffix}" | Must contain `{base}` and `{suffix}` placeholders. |
| `membership.member_number_base_padding` | int (0–12) | 0 | Zero-pad width for `{base_padded}`. |
| `membership.member_number_suffix_padding` | int (0–12) | 0 | Zero-pad width for `{suffix_padded}`. |
| `membership.manual_migration_enabled` | bool | false | Show the manual migration link on join form. |
| `membership.manual_migration_expiry_days` | int (1–60) | 14 | Migration link lifetime. |

Chapter rows are written directly into the `chapters` table, not into `settings_global`.

### Audit Log

Read-only — no settings keys of its own. Renders the global audit log filtered to settings changes; the data comes from `audit_log` populated by `AuditService::log()` and `SettingsService::setGlobal()`. Deep dive: [Ch 08 — Activity & audit log](view.php?slug=08-activity-audit).

### Advanced / Developer

What it controls: maintenance mode, password-reset rate-limit bypass, the feature-flag panel that gates experimental sections elsewhere in the Hub. Also surfaces the system log tail and the last 50 email-log rows.

| Key | Type | Default | What it does |
|---|---|---|---|
| `advanced.maintenance_mode` | bool | false | Non-admin requests get a 503 maintenance page. Enforced in `bootstrap.php`. |
| `advanced.disable_password_reset_rate_limit` | bool | false | Bypass the password-reset throttle (debug only). |
| `advanced.feature_flags` | object | (all false) | Map of `{security.two_factor, payments.secondary_stripe, integrations.myob, accounts.roles, media.folder_taxonomy}` → bool. Read by `SettingsService::isFeatureEnabled()`. |

### Admin Role Builder (standalone)

What it controls: the per-role permission grid for *admin*-tier roles. Permissions come from the `admin_permission_registry()` helper; assignments are written to `role_permissions`. Permission required: `admin.roles.view` to open, `admin.roles.manage` to save. Deep dive: [Ch 07 — Roles & permissions](view.php?slug=07-roles-permissions).

Owns no `settings_global` keys — its source of truth is the `roles` and `role_permissions` tables.

### Access Control (standalone)

What it controls: which page each role can access in the admin sidebar. Reads `pages_registry`, joins `page_role_access`, writes back per-role flags. Permission required: `admin.roles.manage`. Deep dive: [Ch 07 — Roles & permissions](view.php?slug=07-roles-permissions).

Owns no `settings_global` keys — its source of truth is the `page_role_access` table.


<!-- SCREENSHOT: The Settings Hub landing page at /admin/settings/index.php on draft.goldwing.org.au, showing the sidebar with all sections expanded. Save to public_html/admin/help/images/32-hub-landing.png. -->
<!-- ![Settings Hub landing](../images/32-hub-landing.png) -->

<!-- SCREENSHOT: The Payments tab at /admin/settings/index.php?section=payments, showing the test/live key toggle and the encrypted key fields. Save as 32-payments-tab.png. -->
<!-- ![Payments tab](../images/32-payments-tab.png) -->

## Gotchas

- **`payments.stripe.mode` is legacy** — the source of truth is `payments.stripe.use_test_mode`. The `mode` key is still mirrored for older code paths; don't write it directly.
- **Encrypted keys never round-trip through the form** — `integrations.smtp_password`, `integrations.resend_api_key`, and the Stripe secret keys only get written when the form value is non-empty. Submitting an empty field leaves the stored value untouched. This is deliberate so a re-save of the form doesn't blank out keys you never re-entered.
- **Two timezone settings exist.** `site.timezone` runs `bootstrap.php`; `events.timezone` is for event display only. They usually agree — but nothing forces them to.
- **`security.password_min_length` lives in `settings_global` but the rest of the security panel lives in `security_settings`.** That's because the table predates the Hub and was kept for performance — `LoginRateLimiter` and `TwoFactorService` read directly from it without going through `SettingsService`.
- **Feature flags hide UI, not behaviour.** Toggling off `accounts.roles` only hides the Accounts form; existing role assignments still apply.
- **Notification "From" must end in `@goldwing.org.au`** — both the global default and per-event overrides are validated by `is_goldwing_sender()` in the dispatcher. Otherwise SPF/DKIM signing fails.
- **The Advanced section can break the site.** Maintenance mode blocks non-admin requests *immediately* — make sure you're on an admin session before flipping it.
- **`ai.template_*` keys aren't in the AI form.** They're written elsewhere (page builder template manager) but read by `AiPageBuilderService`. Grepping `ai.template_header_html` from the AI page will turn up nothing.

## Related chapters

- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `settings_global` actually works.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what each `admin.*` permission grants.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — why most saves prompt for step-up.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — what `security.force_https` actually does.
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — how the `encrypt: true` flag works.
- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — the Payments section in context.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — how the pricing matrix is read.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — how the notification catalogue is dispatched.
- [24 — AI page builder](view.php?slug=24-ai-page-builder) — how the `ai.*` keys feed the builder.
- [25 — Media library](view.php?slug=25-media-library) — how upload limits get enforced.
- [26 — Events & RSVPs](view.php?slug=26-events-rsvps) — where the event defaults apply.
