# Settings by section (reference)

## For administrators

### What this is

![The Settings Hub — sections in the left sidebar match the headings below](images/31-settings-hub.png)

The **Settings Hub** is the one place where you change anything that affects the whole site — the association's name, the contact email, what Stripe is doing, who's allowed into which admin page, what an event email looks like.

You get there from **Admin → Settings**. The landing page is a grid of cards, one per category. You'll only see the cards your role has permission to open.

This chapter walks through every section in plain English. You don't need to read it end to end — skim to the section you're about to open.

### A few things that apply everywhere

- **Almost every save asks for a 2FA code** (the "step-up" check). Payments is the exception — Stripe handles its own gating.
- **Changes are logged** to the audit log with who, what, when, and old/new value.
- **Secrets are encrypted at rest** — API keys, SMTP passwords, webhook secrets. You won't see the full value after saving.
- **Empty secret fields don't blank the saved value.** Re-saving with a blank Stripe key keeps the existing key — so you can't accidentally wipe one.

### Site Settings

{{link:/admin/settings/?section=site|Take me to Site Settings}}

Everyday brand and contact details for the public site.

- **Site name** — the words in the browser tab and the top of every email. Almost always "Australian Goldwing Association".
- **Tagline** — the subtitle that appears under the logo on the homepage.
- **Logo URL** and **Favicon URL** — full web addresses of the logo image and the little browser-tab icon. Upload via Media Library first, then paste the URL here.
- **Base URL** — the canonical address of the site (e.g. `https://goldwing.org.au`). Only change this if you've actually changed domain. Getting it wrong breaks the links in every email.
- **Timezone** — used by PHP for emails, reports, and date stamps. Almost always `Australia/Sydney`.
- **Contact email** and **Contact phone** — the public contact details shown in the footer and used as a fallback for test notifications.
- **Show navigation / Show footer** — kill switches for the public site's top nav and footer. Useful during a redesign; otherwise leave on.
- **Social links** — Facebook, Instagram, YouTube, TikTok URLs. Drive the icons in the footer.
- **Privacy URL / Terms URL** — the two legal page links that appear in the footer and at the bottom of emails.

### Store Settings

{{link:/admin/settings/?section=store|Take me to Store Settings}}

How the storefront behaves at checkout. Pricing of individual items lives on each product, not here.

- **Store name / slug** — what shoppers see, and what appears in the URL (`/store/`).
- **Members-only** — if on, only logged-in members can buy. If off, anyone can.
- **Shipping region** — `AU` or `INTL`. Drives which shipping options show.
- **GST enabled** — adds a GST line to orders.
- **Pass Stripe fees** — if on, the buyer pays the card processing fee on top of the item price. If off, the association absorbs it.
- **Flat shipping rate** — a single shipping price for every order. Leave off if you're using per-product shipping instead.
- **Free shipping threshold** — order total above which shipping is free. Off by default.
- **Pickup option** — let buyers choose pickup at checkout, with custom instructions.
- **Notification emails** — comma-separated list of staff to email when a new order comes in.
- **Email branding** — logo URL, footer text, and support email used in order-confirmation emails.

See [Chapter 27 — Store architecture](view.php?slug=27-store-architecture) and [Chapter 29 — Discounts, shipping & fees](view.php?slug=29-discounts-shipping) for the deeper "how it all fits" view.

### Payments (Stripe)

{{link:/admin/settings/?section=payments|Take me to Payments (Stripe)}}

The connection to Stripe — keys, what payment methods are on, receipts, invoice prefix. Treat this section with extra care: this is real money.

- **Test mode toggle** — when on, the site uses the test keys and no money moves. Turn off only when you're ready to charge real cards.
- **Test / Live publishable keys** — the public-facing keys (start with `pk_…`).
- **Test / Live secret keys** — the private keys (start with `sk_…`). These are encrypted.
- **Webhook signing secret** — used to verify that incoming Stripe notifications are actually from Stripe. Also encrypted.
- **Allow guest checkout** — let people buy without a Goldwing account.
- **Require shipping for physical items** — keeps the shipping form on physical products and hides it for digital-only carts.
- **Apple Pay / Google Pay / Buy now pay later** — the alternative payment buttons on the Stripe checkout.
- **Send receipts** — Stripe emails the buyer a receipt directly.
- **Invoice prefix** — text in front of the invoice number (e.g. `INV-1234`).
- **Generate PDF** — attach a PDF copy of the invoice to invoice emails.

Full picture: [Chapter 13 — Stripe integration overview](view.php?slug=13-stripe-overview) and [Chapter 18 — Invoices](view.php?slug=18-invoices).

### Notifications

{{link:/admin/settings/?section=notifications|Take me to Notifications}}

The catalogue of every email the system sends — subjects, bodies, recipients, on/off toggles.

The page is laid out as a compact **Sender Identity** card at the top (one-line From / Reply-to summary + an "Edit identity" dialog + the digest toggles), then a two-pane **Notification Templates** editor (left rail groups templates by category — Security, Payments, Store Orders, Admin — click one to edit it on the right with **Content / Recipients / Sender overrides** tabs). A **Send test** button lives inline next to each template's header.

The Content tab has an **Edit / Preview view toggle** at the top. The Edit view shows the **Quill rich-text editor** (auto-mounted by `/assets/js/goldwing-wysiwyg.js`) with a row of **clickable merge-tag chips** above it — only the tags relevant to the current template, drawn from each definition's `placeholders` array; clicking a chip inserts the tag at the Quill cursor. The Preview view shows a **branded email preview** in an iframe rendered through `EmailService::wrapHtml()` (the actual brand wrapper used at send time), with common merge tags substituted for sample values so the preview reads like a real email. The preview is fed by a small admin-only endpoint at `/api/admin/settings/notifications/preview` (POST with `key`, `subject`, `body`, CSRF) and re-renders when you toggle to Preview, debounced ~350ms while editing.

- **From name** and **From email** — what every system email is signed as. The address has to end in `@goldwing.org.au` or email providers will reject it. Edit via the **Edit identity** button.
- **Reply-to** — where replies actually land if it's different from the From address. Also in the Edit identity dialog.
- **Admin emails** — comma-separated list of who gets admin-only alerts (new orders, refund alerts, security alerts). Also in the Edit identity dialog.
- **Weekly digest** — turns on the weekly summary email to admins.
- **Event reminders** — turns on the automatic reminder cron that emails attendees before each event.
- **Per-event templates** — for each kind of notification (order paid, refund processed, welcome email, etc.), you can edit the subject and body, change the recipients, override the sender, or disable it entirely.

> The "In-app Notifications" card (categories + wrapper template) has been removed from the UI pending the bell-inbox feature being wired end-to-end. The underlying settings keys (`notifications.in_app_categories`, `notifications.template_basic`) still exist but are no longer editable from this page.

A handful of emails are **transactional** — receipts, refund notices, password resets — and ignore the opt-out list. They have to go out regardless. The rest respect each member's notification preferences.

Full picture: [Chapter 22 — Notifications & email](view.php?slug=22-notifications-email).

### Security & Authentication

{{link:/admin/settings/?section=security|Take me to Security & Authentication}}

The site's safety policies — password rules, 2FA policy, step-up windows, login lockouts, security alert subscriptions, the File Integrity Monitor. Probably the densest section in the Hub.

- **Force HTTPS** — automatically redirect insecure traffic. Should always be on in production.
- **Password minimum length** — enforced on signup and password change.
- **2FA mode** — `Required for all`, `Required for some roles`, or `Optional`. The default is "required for all".
- **Step-up window** — how long after a 2FA prompt before the next sensitive action asks again. Default is 10 minutes.
- **Login rate limits** — how many failed logins per IP and per account before the system slows the attacker down or locks the account.
- **Security alerts** — checkboxes for which kinds of events trigger an email to the alert recipient (failed logins, new admin device, refunds, role escalation, member exports, FIM changes, webhook failures).
- **File Integrity Monitor (FIM)** — a cron that hashes core files and alerts if any change unexpectedly. Indispensable if you ever suspect tampering.

Full picture: [Chapter 06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup), [Chapter 09 — Security headers & policies](view.php?slug=09-security-headers), [Chapter 11 — File Integrity Monitor](view.php?slug=11-file-integrity), [Chapter 12 — Rate limit & lockout](view.php?slug=12-rate-limit-lockout).

### Integrations

{{link:/admin/settings/?section=integrations|Take me to Integrations}}

Where you wire in outside services — outbound email, video embeds, Zoom, future MYOB sync.

- **Email provider** — `PHP mail()` (default, rarely used), `SMTP` (any provider with credentials), or `Resend` (the API service we use in production).
- **Resend API key** — encrypted. Generated in the Resend dashboard.
- **SMTP host / port / user / password / encryption** — if you're using SMTP. The password is encrypted.
- **YouTube / Vimeo embeds** — whether the page builder lets editors embed videos from each.
- **Default Zoom URL** — a meeting URL pre-filled when an admin creates a new online event.
- **MYOB sync** — off by default; behind a feature flag in Advanced.

For SMTP-specific guidance see [Chapter 22 — Notifications & email](view.php?slug=22-notifications-email).

### Media & Files

{{link:/admin/settings/?section=media|Take me to Media & Files}}

The upload rules for the Media Library.

- **Allowed types** — which file extensions are permitted at upload. Default is the common image and PDF set.
- **Max upload size (MB)** — per-file cap. Default 10 MB.
- **Storage limit (MB)** — a soft warning threshold for total media storage.
- **Image optimisation** — auto-compress images on upload to save space and load faster.
- **Privacy default** — whether new uploads default to public, member-only, or admin-only.

Full picture: [Chapter 25 — Media library](view.php?slug=25-media-library).

### Events

{{link:/admin/settings/?section=events|Take me to Events Settings}}

Defaults that get applied when an admin creates a new event. None of these change *existing* events.

- **RSVP default** — whether the RSVP toggle is pre-ticked on new events.
- **Visibility default** — public, member, or admin.
- **Public ticketing** — whether non-members can buy event tickets (off by default).
- **Event timezone** — display timezone for event listings. Usually matches the site timezone.
- **Include map / Zoom link** — whether the event form includes the Google Maps and Zoom URL fields by default.

Full picture: [Chapter 26 — Events & RSVPs](view.php?slug=26-events-rsvps).

### AI Settings

{{link:/admin/settings/ai.php|Take me to AI Settings}}

The page builder's connection to AI — model, monthly spend cap, the guardrails prompt.

- **Provider / model** — currently fixed to kie.ai with a Claude model.
- **Image generation enabled** — whether the builder can ask the AI for images (off by default — image generation costs more).
- **Monthly cap (USD)** — a soft spend cap; once hit, the system blocks new AI calls until next month.
- **Per-token / per-image cost basis** — used to estimate the running monthly spend.
- **Guardrails** — free-text safety rules injected into every prompt.
- **Builder master prompt** — the system prompt prepended to every AI page-builder request.

The actual API key is stored separately (encrypted) and isn't visible in this form. Full picture: [Chapter 24 — AI page builder](view.php?slug=24-ai-page-builder).

### Developer Access

{{link:/admin/settings/developer-access.php|Take me to Developer Access}}

The handover lockout for the outside developer's admin login. Grant a timed access window (default one week), revoke it early, or switch the whole lockout on or off. Grants email the developer automatically, and every change is logged. Full instructions in [Appendix D — Handover & emergency runbook](view.php?slug=D-handover-runbook).

### Membership Settings

{{link:/admin/settings/?section=membership_pricing|Take me to Membership Settings}}

The pricing matrix and member number formatting. The pricing matrix is the grid of dollar amounts for each combination of magazine type, membership type, and term — see [Chapter 14 — Membership pricing matrix](view.php?slug=14-membership-pricing) for the full picture.

Other settings here:

- **Manual migration link** — when on, the join form shows a link for existing members to claim their account from the old system.

> **Member numbers are now assigned manually.** When an admin approves an application they type the member number directly in the approval dialog. The old auto-sequencing UI (member number start / format / padding fields) has been removed from this page; the underlying settings keys still exist in the database for legacy display formatters but are no longer written by the UI.

Chapter management (adding, renaming, archiving local chapters) is on the same page and writes to the `chapters` table directly. See [Chapter 21 — Chapters & area reps](view.php?slug=21-chapters-area-reps).

### Audit Hub

{{link:/admin/audit/|Take me to the Audit Hub}}

Read-only. Folded out of Settings into its own page at **/admin/audit/**. Shows every settings change, every admin action, and every security event in one timeline — who, what, old value, new value, when. Pick the **Settings** source to scope to settings changes only. No fields to edit. Full picture: [Chapter 08 — Activity & audit log](view.php?slug=08-activity-audit) and [Chapter 31 — Settings architecture](view.php?slug=31-settings-architecture).

### Advanced / Developer

{{link:/admin/settings/?section=advanced|Take me to Advanced / Developer}}

**Handle with care.** Two toggles here can break the public site, and a third controls which experimental features are even visible.

- **Maintenance mode** — when on, anyone who isn't logged in as an admin gets a "be right back" page. Use during deploys or emergency fixes. Don't flip this on without checking you're definitely logged in as an admin first, or you'll lock yourself out of the front end.
- **Disable password-reset rate limit** — bypasses the throttle on password reset requests. Debug-only — leave off in production.
- **Feature flags** — toggles for experimental sections (two-factor experiments, secondary Stripe account, MYOB sync, media folder taxonomy). These hide UI, not behaviour.

This section also surfaces the system log tail and recent email-log rows, which are handy for diagnosing problems.

### Admin Role Builder

{{link:/admin/settings/roles.php|Take me to the Admin Role Builder}}

The grid where you tick which permissions each admin role has. Open it, pick a role from the dropdown, tick or untick permissions, save. Permission required to view: `admin.roles.view`. Permission required to save: `admin.roles.manage`.

This is where you'd give your new Treasurer the refund permission, or let a Newsletter Editor publish blog posts. Full picture: [Chapter 07 — Roles & permissions](view.php?slug=07-roles-permissions).

### Access Control

{{link:/admin/settings/access-control.php|Take me to Access Control}}

The sister page to the Role Builder — instead of "which permissions does this role have", this is "which admin pages does this role see in the sidebar". Useful for hiding sections an admin shouldn't even know exist, rather than just blocking them at the form level. Full picture: [Chapter 07 — Roles & permissions](view.php?slug=07-roles-permissions).

**No longer surfaced as a Hub card** — the defaults are sensible for the current role set, so the matrix UI was hidden to keep the Hub tidy. The underlying URL-to-role gate is still live (enforced on every request by `enforce_page_access()` in `bootstrap.php`); navigate directly to the URL above if you need to tweak it.

### Who to ask if you're stuck

- **A section is missing from your Hub** — your role doesn't include its permission. Ask an admin to update the Role Builder.
- **A change didn't take effect** — try clearing your browser cache. If still nothing, check the audit log to confirm the save actually went through.
- **You broke something** — the audit log shows the previous value. Open the same setting and put it back. If it was a feature flag, toggle it off again.
- **You can't find the right key** — read the dev notes below; every key is listed there with its full name.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

A section-by-section catalogue of every page in the Settings Hub: what it controls, who can change it, and the exact `settings_global` keys it reads and writes. This is a reference chapter — keep it open in another tab when you're hunting for "what's the key name for X?" or "which permission gates the Payments tab?". For the *how* of settings (storage, caching, encryption, audit, defaults) see [Chapter 31 — Settings architecture](view.php?slug=31-settings-architecture).

### Why it exists

When something needs to change site-wide — a contact email, the Stripe mode, the password policy, a feature flag — there's exactly one place to do it: the Settings Hub at `/admin/settings/`. Landing on that URL shows a card-grid index of every settings category the current admin can access (defined by `$hubGroups` in `public_html/admin/settings/index.php`). The Hub has thirteen sections and 100+ keys, and the keys themselves are namespaced strings like `payments.stripe.use_test_mode` that you can't grep for without knowing the name. This chapter is the map. Every section here corresponds to one card on the Hub index and one entry in the `$sections` array of `public_html/admin/settings/index.php`.

### How it works (briefly)

All sections except the standalone pages (`ai.php`, `roles.php`, `access-control.php`, `committee-roles.php`) are rendered by the same dispatcher: `public_html/admin/settings/index.php` reads `?section=<key>` (or `hub`, the default when no section is given). For `hub` it renders the card-grid index, filtering each card by its declared permission so admins only see categories they can actually open. For a real section it looks the key up in `$sections`, runs the per-section permission check via `current_admin_can()`, then renders the matching form. Saves go through `SettingsService::setGlobal($userId, $key, $value)` — which JSON-encodes the value, stamps `audit_log`, and (for sensitive keys) encrypts via `CryptoService`. Reads go through `SettingsService::getGlobal($key, $default)`.

Defaults come from three places:

- `SettingsService::ensureDefaults()` — seeded on every Hub load.
- `SecuritySettingsService::defaults()` — a separate `security_settings` table mirror.
- Per-form fallbacks — the third argument to `getGlobal()` calls in each form.

The full mechanics — JSON storage, caching, encryption flags, audit — are in [Chapter 31](view.php?slug=31-settings-architecture).

### Where to change it

| Section | URL | Permission |
|---|---|---|
| Hub landing | `/admin/settings/index.php` | Any of the section permissions below (per-card filter) |
| Site Settings | `/admin/settings/index.php?section=site` | `admin.settings.general.manage` |
| Store Settings | `/admin/settings/index.php?section=store` | `admin.store.view` |
| Payments (Stripe) | `/admin/settings/index.php?section=payments` | `admin.payments.view` |
| Notifications | `/admin/settings/index.php?section=notifications` | `admin.settings.general.manage` |
| Security & Authentication | `/admin/settings/index.php?section=security` | `admin.settings.general.manage` |
| Integrations | `/admin/settings/index.php?section=integrations` | `admin.integrations.manage` |
| Media & Files | `/admin/settings/index.php?section=media` | `admin.media_library.manage` |
| Events | `/admin/settings/index.php?section=events` | `admin.events.manage` |
| AI Settings | `/admin/settings/ai.php` | `admin.settings.general.manage` |
| Membership Settings | `/admin/settings/index.php?section=membership_pricing` | `admin.membership_types.manage` |
| Audit Hub | `/admin/audit/` (folded out of settings) | `admin.logs.view` |
| Advanced / Developer | `/admin/settings/index.php?section=advanced` | `admin.settings.general.manage` |
| Admin Role Builder | `/admin/settings/roles.php` | `admin.roles.view` (view) / `admin.roles.manage` (edit) |
| Access Control (hidden from Hub, direct URL only) | `/admin/settings/access-control.php` | `admin.roles.manage` |
| Committee & Leadership Roles | `/admin/settings/committee-roles.php` | `admin.members.view` |

The permission map lives in `$hubGroups` at the top of `public_html/admin/settings/index.php` (each card declares its `permission` key) and is enforced in the dispatcher's `can_access_section()` check on form save. The sidebar entry for Settings (`app/Views/partials/backend_admin_sidebar.php`) is gated by `$settingsPermissions` — visible to any admin with at least one of the section permissions. Step-up authentication ([Ch 06](view.php?slug=06-2fa-stepup)) is required for every save *except* the Payments section (which uses Stripe's own gating).

### Settings

#### Site

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

#### Store

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

#### Payments (Stripe)

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
| `payments.stripe.invoice_prefix` | string | "MEM" | Prefix used by `InvoiceService` for membership invoices. |
| `payments.stripe.invoice_prefix_store` | string | "STORE" | Prefix stamped on Stripe Invoices created by `StoreInvoiceService`. Lives in metadata + description so order type is filterable in Stripe dashboard. |
| `payments.stripe.invoice_email_template` | string | "" | Override template for invoice emails. |
| `payments.stripe.generate_pdf` | bool | true | Attach the FPDF-generated PDF to invoice emails. |
| `payments.stripe.mode` | enum (legacy) | "test" | Legacy mirror of `use_test_mode`; superseded but still written. |
| `payments.membership_prices` | object | (matrix) | Map of `{magazine_type → membership_type → period → cents}`. See `MembershipPricingService`. |
| `payments.membership_default_term` | enum | "12M" | Default selected term on the join form (`12M` or `24M`). |
| `payments.membership_allow_both_types` | bool | true | Allow both digital + print membership types. |
| `payments.bank_transfer_instructions` | string | "" | Shown to buyers who pick "Bank transfer" (where supported). |

Secret keys are auto-encrypted at rest via the `encrypt: true` flag on `setGlobal()` — see [Ch 10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets).

#### Notifications

What it controls: the global From/Reply-To, admin recipient list, the in-app notification catalogue (per-event subject/body/recipients). Deep dive: [Ch 22 — Notifications & email](view.php?slug=22-notifications-email).

| Key | Type | Default | What it does |
|---|---|---|---|
| `notifications.from_name` | string | (from `config/app.php`) | Default sender name. |
| `notifications.from_email` | string | (from `config/app.php`) | Default sender address. Must end with `@goldwing.org.au`. |
| `notifications.reply_to` | string | "" | Default Reply-To header. |
| `notifications.admin_emails` | string | "" | Comma-or-newline list of admin recipients used by `NotificationService::getAdminEmails()`. |
| `notifications.weekly_digest_enabled` | bool | false | Enable the weekly admin digest cron. |
| `notifications.event_reminders_enabled` | bool | true | Enable the event-reminder cron. |
| `notifications.in_app_categories` | array | [] | Categories shown in the in-app notification bell. Persisted only — the bell-inbox feature is not yet wired, so the UI for this key is currently hidden. |
| `notifications.template_basic` | string | "" | Base HTML template wrapped around per-event bodies. Persisted only — UI currently hidden (see above). |
| `notifications.catalog` | object | (defaults from `NotificationService::definitions()`) | Per-event overrides: `{enabled, recipient_mode, custom_recipients, subject, body, from_name, from_email, reply_to}`. |

#### Security & Authentication

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

#### Integrations

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

#### Media & Files

What it controls: upload limits, allowed types, image optimisation, default privacy, optional folder taxonomy. Deep dive: [Ch 25 — Media library](view.php?slug=25-media-library).

| Key | Type | Default | What it does |
|---|---|---|---|
| `media.allowed_types` | array | (image/PDF set) | MIME-suffix list permitted at upload. |
| `media.max_upload_mb` | float | 10 | Per-file upload cap. |
| `media.storage_limit_mb` | float | 5120 | Soft warning threshold for total storage. |
| `media.image_optimization_enabled` | bool | true | Auto-compress uploaded images. |
| `media.privacy_default` | enum | "member" | Default visibility of new uploads (`public`, `member`, `admin`). |
| `media.folder_taxonomy` | array | [] | Folder labels (behind `media.folder_taxonomy` flag). |

#### Events

What it controls: defaults applied to new events. Deep dive: [Ch 26 — Events & RSVPs](view.php?slug=26-events-rsvps).

| Key | Type | Default | What it does |
|---|---|---|---|
| `events.rsvp_default_enabled` | bool | true | RSVP toggle pre-checked on new events. |
| `events.visibility_default` | enum | "member" | `public`, `member`, `admin`. |
| `events.public_ticketing_enabled` | bool | false | Allow non-members to buy event tickets. |
| `events.timezone` | string | "Australia/Sydney" | Display timezone for events. |
| `events.include_map_link` | bool | true | Render a Google Maps link from the address. |
| `events.include_zoom_link` | bool | true | Show the Zoom URL field on the event form. |

#### AI Settings (standalone)

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

#### Membership Settings

What it controls: the membership year anchor + expiry, admin-defined renewal periods + their prices, the **new-member joining matrix** (explicit per-cell prices by term × join window) + the one-off joining fee, the pro-rata fallback engine + per-cell annual base prices, manual migration link, Associate→Full upgrade pricing, chapters CRUD. Saved via `MembershipPricingService::updateConfig()`. Deep dive: [Ch 14 — Membership pricing](view.php?slug=14-membership-pricing), [Ch 21 — Chapters & area reps](view.php?slug=21-chapters-area-reps).

**Member numbers** — no longer auto-sequenced; an admin types the number in the approval dialog. The `membership.member_number_*` keys below remain in `settings_global` for legacy display code but the Settings UI no longer exposes or writes them.

| Key | Type | Default | What it does |
|---|---|---|---|
| `membership.pricing.config` | object | (seeded config) | Single JSON blob with `anchor_month`/`anchor_day`/`expiry_month`/`expiry_day`, `prorata_enabled`, `prorata_rounding`, `renewal_periods` list (`{id,label,duration_months,sort_order,active}`), `renewal_prices` matrix (`{magazine→type→period_id→cents}`), `prorata_annual_prices` (`{magazine→type→cents}`), plus `joining_enabled` (bool), `joining_fee_cents` (int), and the `joining_prices` matrix (`{magazine→type→period_id→window→cents}`, window ∈ `FULL`/`DEC`/`APR`). |
| `membership.pricing_matrix` | object | (legacy seed) | Legacy 24-row matrix `{magazine_type → membership_type → period → cents}`. Read once on first migration and then inert — `getConfig()` migrates it into `membership.pricing.config`. Kept for safety so older installs don't lose data on the first load. |
| `membership.member_number_start` | int | 1000 | First member number issued. |
| `membership.associate_suffix_start` | int | 1 | First associate suffix. |
| `membership.member_number_format_full` | string | "{base}" | Format template — must contain `{base}` or `{base_padded}`. |
| `membership.member_number_format_associate` | string | "{base}.{suffix}" | Must contain `{base}` and `{suffix}` placeholders. |
| `membership.member_number_base_padding` | int (0–12) | 0 | Zero-pad width for `{base_padded}`. |
| `membership.member_number_suffix_padding` | int (0–12) | 0 | Zero-pad width for `{suffix_padded}`. |
| `membership.manual_migration_enabled` | bool | false | Show the manual migration link on join form. |
| `membership.manual_migration_expiry_days` | int (1–60) | 14 | Migration link lifetime. |
| `membership.upgrade_mode` | string ("standard"\|"custom") | "standard" | How an Associate is charged when upgrading to Full from their profile. "standard" reads the 1-year FULL price from the matrix (PRINTED or PDF based on `wings_preference`). "custom" uses a flat fee. See `MembershipUpgradeService::getUpgradePriceCents()`. |
| `membership.upgrade_custom_fee_cents` | int (cents) | 0 | Flat fee charged on upgrade when `upgrade_mode` is "custom". Ignored otherwise. |

Chapter rows are written directly into the `chapters` table, not into `settings_global`.

#### Audit Hub

Read-only — no settings keys of its own. Lives at `/admin/audit/` (no longer part of the settings dispatcher). Renders a unified view across `audit_log` (settings diffs), `audit_logs` (admin actions), and `activity_log` (security events) via `App\Services\AuditHubService`. Deep dive: [Ch 08 — Activity & audit log](view.php?slug=08-activity-audit).

#### Advanced / Developer

What it controls: maintenance mode, password-reset rate-limit bypass, the feature-flag panel that gates experimental sections elsewhere in the Hub. Also surfaces the system log tail and the last 50 email-log rows.

| Key | Type | Default | What it does |
|---|---|---|---|
| `advanced.maintenance_mode` | bool | false | Non-admin requests get a 503 maintenance page. Enforced in `bootstrap.php`. |
| `advanced.disable_password_reset_rate_limit` | bool | false | Bypass the password-reset throttle (debug only). |
| `advanced.feature_flags` | object | (all false) | Map of `{security.two_factor, payments.secondary_stripe, integrations.myob, media.folder_taxonomy}` → bool. Read by `SettingsService::isFeatureEnabled()`. |

#### Admin Role Builder (standalone)

What it controls: the per-role permission grid for *admin*-tier roles. Permissions come from the `admin_permission_registry()` helper; assignments are written to `role_permissions`. Permission required: `admin.roles.view` to open, `admin.roles.manage` to save. Deep dive: [Ch 07 — Roles & permissions](view.php?slug=07-roles-permissions).

Owns no `settings_global` keys — its source of truth is the `roles` and `role_permissions` tables.

#### Access Control (standalone, hidden from Hub)

What it controls: which page each role can access in the admin sidebar, and the URL-to-role-bucket gate enforced on every request by `enforce_page_access()` in `bootstrap.php`. Reads `pages_registry`, joins `page_role_access`, writes back per-role flags. Permission required: `admin.roles.manage`. Deep dive: [Ch 07 — Roles & permissions](view.php?slug=07-roles-permissions).

The card was removed from the Hub because the defaults from `access_control_default_registry()` cover all current role buckets (public/member/area_rep/store_manager/admin) and are almost never customised. The page still lives at `/admin/settings/access-control.php` for direct access if the matrix ever needs editing — and removing the file would orphan the `page_role_access` table that the runtime middleware reads.

Owns no `settings_global` keys — its source of truth is the `page_role_access` table.

#### Committee & Leadership Roles (standalone)

What it controls: which member currently holds each National or Chapter Rep role. Role-centric UI — pick the role, search a member by name or member number, click to assign. Per-assignment privacy toggle and remove button live on the same row. Permission required: `admin.members.view`. Reads/writes the `committee_roles` and `member_committee_assignments` tables via `CommitteeService`; `syncAssignments()` mirrors state back onto the legacy `members.is_committee` / `is_area_rep` / `committee_role` columns. Deep dive: [Ch 21 — Chapters & area reps](view.php?slug=21-chapters-area-reps).

Owns no `settings_global` keys — its sources of truth are `committee_roles` (catalog) and `member_committee_assignments` (who holds what).

### Gotchas

- **`payments.stripe.mode` is legacy** — the source of truth is `payments.stripe.use_test_mode`. The `mode` key is still mirrored for older code paths; don't write it directly.
- **Encrypted keys never round-trip through the form** — `integrations.smtp_password`, `integrations.resend_api_key`, and the Stripe secret keys only get written when the form value is non-empty. Submitting an empty field leaves the stored value untouched. This is deliberate so a re-save of the form doesn't blank out keys you never re-entered.
- **Two timezone settings exist.** `site.timezone` runs `bootstrap.php`; `events.timezone` is for event display only. They usually agree — but nothing forces them to.
- **`security.password_min_length` lives in `settings_global` but the rest of the security panel lives in `security_settings`.** That's because the table predates the Hub and was kept for performance — `LoginRateLimiter` and `TwoFactorService` read directly from it without going through `SettingsService`.
- **Notification "From" must end in `@goldwing.org.au`** — both the global default and per-event overrides are validated by `is_goldwing_sender()` in the dispatcher. Otherwise SPF/DKIM signing fails.
- **The Advanced section can break the site.** Maintenance mode blocks non-admin requests *immediately* — make sure you're on an admin session before flipping it.
- **`ai.template_*` keys aren't in the AI form.** They're written elsewhere (page builder template manager) but read by `AiPageBuilderService`. Grepping `ai.template_header_html` from the AI page will turn up nothing.

</details>

<!-- SCREENSHOT: The Settings Hub landing page at /admin/settings/index.php on goldwing.org.au, showing the sidebar with all sections expanded. Save to public_html/admin/help/images/32-hub-landing.png. -->
<!-- ![Settings Hub landing](../images/32-hub-landing.png) -->

<!-- SCREENSHOT: The Payments tab at /admin/settings/index.php?section=payments, showing the test/live key toggle and the encrypted key fields. Save as 32-payments-tab.png. -->
<!-- ![Payments tab](../images/32-payments-tab.png) -->

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
