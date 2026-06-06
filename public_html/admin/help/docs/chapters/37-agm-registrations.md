# AGM Registrations

## For administrators

### What this is

The **Annual General Meeting registration system**. Every year a different chapter hosts a 2-3 day AGM with dinners, breakfasts, merchandise, and a registration tier (member / non-member, full / patch-only, early-bird / late). This module is how attendees register and pay, and how the host chapter manages the event.

It is intentionally separate from the membership renewal store: AGM money flows through a **second Stripe account** so the host chapter's takings don't mix with the national association's membership and merchandise revenue.

### What you can do

- **Set up an AGM event** for a given year — title, dates, venue, deadlines, contact details.
- **Author the public landing page** in a rich-text editor with images, headings, lists.
- **Configure the product catalogue** — registration tiers, merchandise, meals (with sub-choices like the five Friday-dinner pasta options).
- **Add custom form fields** — anything specific to this AGM (e.g. "Sunday social ride interest", "Raffle ticket quantity").
- **Set early-bird vs late pricing** — pricing tier auto-switches at the cutoff datetime you configure.
- **Open and close registration** — registration only accepts submissions between the open and close datetimes.
- **View, filter, and export submissions** — full list with payment status, attendee names, bikes, dietary, plus CSV download for the host chapter's catering planner.
- **Manually mark bank-transfer registrations as paid** once you've confirmed the bank deposit.
- **Refund a registration** to the attendee via the AGM Stripe account.
- **Archive past events** — they become read-only history, available in the Archive tab.
- **Clone products and fields** from a previous AGM so the new host chapter only has to tweak prices.

### Who's allowed

The AGM module has its own permission set so the host-chapter coordinator can be given access without seeing membership or store finances:

- `admin.agm.view` — see events and submissions.
- `admin.agm.manage` — edit events, products, fields; mark registrations paid; cancel.
- `admin.agm.refund` — issue a Stripe refund.
- `admin.agm.settings` — configure the AGM Stripe keys.

The pre-seeded **`agm_manager`** role bundles all four. Assign it from Settings → Accounts &amp; Roles to whoever's running the host chapter's registration desk.

### Where to find it

Admin sidebar → **Content → AGM**. Eight tabs:

1. **Dashboard** — counts, revenue, recent submissions.
2. **Event Setup** — create / edit / publish / archive events.
3. **Content** — WYSIWYG landing-page content.
4. **Products &amp; Pricing** — registration tiers, merch, meals.
5. **Form Fields** — extra per-event questions.
6. **Submissions** — list, filter, view detail, mark paid, refund, cancel, export CSV.
7. **Archive** — past events with their registration history.
8. **Settings** — AGM Stripe keys (test &amp; live, publishable &amp; secret, webhook signing secret).

The public-facing pages are at `/agm/` (landing) and `/agm/register.php` (form).

### How to set up a new AGM (step by step)

1. **Settings tab** — paste the AGM Stripe account's test or live keys. Note the webhook URL shown on the page (`/api/stripe_webhook_agm.php`) and add it as an endpoint in the AGM Stripe dashboard with the four events listed there. Paste the webhook signing secret back into Settings.
2. **Event Setup → + New event** — fill in year, slug (`perth-2026`), title, dates, venue, registration open/close, and the late-fee cutoff. Leave status as `draft` while you finish setup.
3. **Content tab** — paste or write the landing-page body. Upload a cover image via `/admin/media` and paste its path.
4. **Products &amp; Pricing tab** — add each product with early-bird and late prices. For products like "Friday dinner" with multiple meal choices, set "Requires a choice" and list each option on its own line. Use the **Clone products &amp; fields from** dropdown to start from last year's catalogue.
5. **Form Fields tab** — add any one-off questions just for this AGM.
6. **Event Setup tab → Publish &amp; set current** — flips status to `published` and marks it as the active AGM. Only one event is "current" at a time. The public `/agm/` page now shows it.
7. Test a registration with a Stripe test card (`4242 4242 4242 4242`). Confirm the registration appears in **Submissions** with `paid` status.

### Pricing tier behaviour

The system computes `early` vs `late` server-side from the **Late fee starts at** datetime on the event. Attendees can't game this — the price tier is fixed at the moment they submit. The Submissions table shows which tier each registration paid.

### Bank transfer fallback

If the event allows bank transfer, attendees can pick that as their payment method. The registration is saved with status `awaiting_bank_transfer` and the bank-transfer instructions you wrote on the event are shown on the success page. When the deposit lands, open the registration in Submissions and click **Mark paid** — that triggers the confirmation email.

### Where data lives

Per-member history surfaces in two places:

- **Member area → My Orders** — the member sees their AGM registrations alongside membership orders and store orders.
- **Admin → Members → View member** — the **AGM registrations** card under the Orders tab.

The "View →" link from the admin member card jumps straight to the registration detail in `/admin/agm/?tab=submissions&view=…`.

### Tips and gotchas

- **Publishing makes it current.** Hitting **Publish &amp; set current** automatically un-currents whatever was previously current. Use draft status if you're setting up next year's event while this year's is still active.
- **Product deletes don't break old registrations.** Each registration item snapshots the product name and price at the moment of submission, so deleting a product later only affects the form going forward.
- **One Stripe account per system.** Membership/store payments still flow through the existing Stripe account; AGM payments flow through the second one. Confirm in Stripe that the right account is receiving the money before going live.
- **CSV export honours the filter** on the Submissions tab — set status / tier / search first, then click Export CSV to get exactly what's on screen.

---

## For developers

### Architecture

Six tables, three services, one webhook endpoint.

**Tables** (defined in `database/agm_module.sql`):

- `agm_events` — one row per AGM year. Includes `is_current`, `status` (`draft|published|closed|archived`), `stripe_account_key` (always `'agm'`), and the WYSIWYG `description_html`.
- `agm_products` — registration / merchandise / meal / custom items. `early_price` always present; `late_price` optional. `choices_json` holds an array for products like Friday dinner.
- `agm_form_fields` — per-event custom questions. Baseline fields (name, address, bikes, emergency contacts, dietary) are hard-coded in `register.php`; this table is for extras only.
- `agm_registrations` — one row per submission. Attendees inline (`attendee1_*`, `attendee2_*`), emergency contacts inline. Pricing tier captured at submission time. Payment method + status track the lifecycle.
- `agm_registration_items` — line items with `name_snapshot`, `unit_price`, `pricing_tier_snapshot` — frozen at submission so historical orders survive product edits.
- `agm_registration_motorcycles` — 0–2 bikes per registration with trike/sidecar/trailer flags.

The `'agm'` row in `payment_channels` is seeded by `payments_module.sql`; the AGM module re-asserts it and adds the matching `settings_payments` row so AGM invoice numbering is independent.

### Services

- **`App\Services\AgmEventService`** — CRUD on events, products, form fields. `getCurrentEvent()`, `setCurrentEvent()`, `archiveEvent()`, `cloneFromPrevious()`.
- **`App\Services\AgmRegistrationService`** — `computePricingTier(event)`, `isRegistrationOpen(event)`, `createRegistration(event, payload, items, motorcycles, context)`, lifecycle transitions (`markPaid`, `markRefunded`, `markCancelled`), and the confirmation-email dispatch (`agm_registration_confirmation` notification template).
- **`App\Services\AgmWebhookService`** — handles `checkout.session.completed`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded` for the AGM channel. Looks up the registration by `metadata.agm_registration_id` (set when we create the Checkout Session) or falls back to the Stripe session / payment intent ID stored on the registration.

### Secondary Stripe account

`App\Services\StripeSettingsService` and `App\Services\StripeService` were refactored to accept an optional `?string $accountKey = null` (defaults to `StripeSettingsService::ACCOUNT_PRIMARY === 'primary'`). Existing membership/store call sites pass nothing and behave identically; AGM call sites pass `StripeSettingsService::ACCOUNT_AGM === 'agm'`.

Account-to-settings-prefix mapping inside `StripeSettingsService`:

- `'primary'` → `payments.stripe.*` settings, `STRIPE_*` env, `payment_channels.code = 'primary'`.
- `'agm'` → `payments.agm_stripe.*` settings, `STRIPE_AGM_*` env, `payment_channels.code = 'agm'`.

Admin-side, `saveAgmAdminSettings()` is the AGM equivalent of `saveAdminSettings()` and namespaces what it writes.

### Webhook endpoint

`/public_html/api/stripe_webhook_agm.php` is a parallel of `stripe_webhook.php` but uses the AGM account's webhook secret and dispatches to `AgmWebhookService::handleEvent($event)`. Webhook event recording / idempotency / failure-alert plumbing is shared via `PaymentWebhookService::recordEvent()` and `markProcessed()`.

In Stripe, configure the endpoint URL the Settings tab shows and subscribe it to:

- `checkout.session.completed`
- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `charge.refunded`

### Public pages

- `/agm/index.php` — landing page; renders `agm_events.description_html` of the current event.
- `/agm/register.php` — form (GET) + submit handler (POST). Validates against current event status, recomputes pricing tier server-side, snapshots items, creates Stripe Checkout Session via `StripeService::createCheckoutSessionWithLineItems(..., StripeSettingsService::ACCOUNT_AGM)`, redirects to `session.url`.
- `/agm/return.php` — success / cancel / bank-transfer landing page. The success path is purely informational; payment-status changes happen via the webhook so we don't trust the return URL.

### WYSIWYG

The AGM Content tab loads TinyMCE from `/assets/vendor/tinymce/tinymce.min.js`. If the bundle isn't there, the editor gracefully falls back to a plain `<textarea>` and shows a warning. Run `scripts/install_tinymce.sh` on the server to drop the TinyMCE Community (GPL) bundle into place.

`tinymce.init` uses `license_key: 'gpl'` to suppress the upgrade banner — this is the supported open-source path under the GPL licence.

### Files to know

- `database/agm_module.sql` — schema migration.
- `database/seeds_agm_perth_2026.sql` — Perth 2026 event seed from the v5 PDF.
- `app/Services/AgmEventService.php`, `app/Services/AgmRegistrationService.php`, `app/Services/AgmWebhookService.php`.
- `public_html/admin/agm/` — admin section (router `index.php`, POST handler `actions.php`, eight tab partials under `tabs/`).
- `public_html/agm/` — public registration flow (`index.php`, `register.php`, `return.php`).
- `public_html/api/stripe_webhook_agm.php` — AGM Stripe webhook endpoint.
- `includes/admin_permissions.php` — `admin.agm.*` permissions and the `agm_manager` role.
- `app/Views/partials/backend_admin_sidebar.php` — sidebar AGM group.

### Related

- [Chapter 13 — Stripe integration overview](view.php?slug=13-stripe-overview) — describes the multi-account `accountKey` mechanism.
- [Chapter 16 — Webhooks &amp; idempotency](view.php?slug=16-webhooks-idempotency) — covers the shared webhook plumbing reused by `stripe_webhook_agm.php`.
- [Chapter 17 — Refunds](view.php?slug=17-refunds) — refund mechanics (AGM refunds use the same `StripeService::createRefund()` with `accountKey='agm'`).
