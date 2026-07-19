# C — Technical specifications

## For administrators

### What this is

A factual reference for "what is the website actually made of": the technology, the hosting, every outside service it depends on, and the scheduled jobs that run in the background. You don't need to understand it — it exists so that any future developer or agency can be handed this chapter (or the manual it's part of) and know exactly what they're taking on.

### The short version

| Question | Answer |
| --- | --- |
| What kind of website is it? | Custom-built PHP website (no WordPress, no page-builder platform) |
| Where does it run? | The association's cPanel shared hosting account (goldwing.org.au) |
| Where is the code kept? | A private GitHub repository, deployed to the server through cPanel's Git tool |
| Where is the data kept? | A MySQL database on the same hosting account |
| Who takes the card payments? | Stripe, paying out to the association's bank account |
| What sends the emails? | The association's SMTP mailbox (configured in Settings → Integrations) |
| What does it cost to run? | Hosting + domain renewal + Stripe's per-transaction fees (+ small AI usage if the AI page builder is used) |

### Outside services the site depends on

| Service | What it does | Where it's managed |
| --- | --- | --- |
| **Stripe** | Card payments, invoices, refunds, payouts to the bank | dashboard.stripe.com (association account) |
| **Email (SMTP)** | Sends all site email — receipts, reminders, alerts | Settings → Integrations + the mailbox provider |
| **Google Maps** | Address autocomplete on signup and profile forms | Google Cloud Console (API key) |
| **kie.ai** | Powers the AI page builder (optional) | kie.ai account (API key in Settings → AI) |
| **GitHub** | Stores the website's code | github.com (private repository) |
| **Domain & DNS** | The goldwing.org.au name and its DNS records | The domain registrar account |

Everything else — members, pages, store, calendar, documents — is the site's own code and database. Nothing is rented from a website-builder platform that could disappear or raise its prices.

<details>
<summary>Dev notes</summary>

### Stack

- **PHP 8.3+** on cPanel shared hosting. The site owner has no SSH — everything ships via cPanel's Git deploy (see [Chapter 33 — Deployment](view.php?slug=33-deployment)).
- **No framework, no Composer, no npm.** Filesystem routing: each `.php` file under `public_html/` is a route. Every request starts with `app/bootstrap.php` (env loading, autoloader for `App\`, DB-backed sessions, security headers, access control).
- **Service layer:** ~80 classes in `app/Services/` hold the business logic; `app/Views/partials/` holds the layout shells; `includes/` has six procedural helper files (access control, admin permissions, store helpers…).
- **Vendored libraries** (committed to the repo, not package-managed): `stripe-php` and FPDF in `app/ThirdParty/`; Driver.js (tours), Quill and TinyMCE under `public_html/assets/`; Tailwind via CDN in the admin.
- **MySQL via PDO**, ~43 tables. Sessions live in the `sessions` table (`DbSessionHandler`). Schema changes ship as numbered blocks in `public_html/admin/run-migration.php` (run from the browser) mirrored as dated files in `database/migrations/` — see [Chapter 3 — Database & migrations](view.php?slug=03-database-migrations).

### Environment contract (`.env`)

`.env` sits at the hosting account root (NOT inside `public_html/`) and is loaded by `app/Services/Env.php`. A commented template is committed as `.env.example`. Keys:

| Key | Purpose |
| --- | --- |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_CHARSET` | MySQL connection |
| `APP_KEY` | Master encryption key — decrypts Stripe/SMTP secrets stored in the database. **Losing it means re-entering every stored secret.** |
| `APP_BASE_URL` | Canonical site URL |
| `GOOGLE_MAPS_API_KEY` | Address autocomplete |
| `KIE_API_KEY` / `AI_DEFAULT_MODEL` | AI page builder |
| `GOOGLE_OAUTH_*` / `APPLE_OAUTH_*` | Social login (configured but optional) |

Most live secrets (Stripe keys, SMTP password) are stored **encrypted in the database** (`settings_global`, via `CryptoService`) and edited through the Settings hub — not in `.env`. `.env` holds the DB connection plus the `APP_KEY` that unlocks the rest. See [Chapter 10 — Encryption & secrets](view.php?slug=10-encryption-secrets).

### Cron jobs

Four scripts in `cron/`, scheduled through cPanel → Cron Jobs ([Chapter 34 — Cron jobs](view.php?slug=34-cron-jobs)): `expire_memberships.php`, `send_renewal_reminders.php`, `expire_pending_orders.php`, `fim_scan.php`.

### Known flags for a future developer

- `config/database.php` historically carried hardcoded production DB fallbacks. Rotate the DB password at handover, keep credentials only in `.env`, then remove the fallbacks — the ordered procedure is in `HANDOVER.md` at the repo root.
- There is no automated database backup job — backups are manual through cPanel (routine in [Appendix D](view.php?slug=D-handover-runbook)). An automated offsite backup is a worthwhile future addition.
- SMS sending is log-only (`sms_log` table) — no live SMS gateway is wired.
- Customer invoices are generated with FPDF; the committee manual PDF is generated separately (`admin/help/docs/manual.php`).
- Read [Chapter 19 — Membership lifecycle](view.php?slug=19-membership-lifecycle) (status casing, months-based terms) and [Chapter 14 — Pricing matrix](view.php?slug=14-membership-pricing) before touching member or pricing data — both have conventions that aren't guessable.

### New agency quick start

1. Get GitHub access and clone the repository; copy `.env.example` → `.env` with local DB credentials.
2. Import `database/latest_full_clean.sql` (demo data only — production data stays on the server).
3. Serve `public_html/` with PHP 8.3+ (`php -S localhost:8080` from `public_html/` covers most pages).
4. Read Chapters 1–4, then [Chapter 33 — Deployment](view.php?slug=33-deployment), before shipping anything.
5. Production access = cPanel (committee-held) + an admin login gated by the developer-access window ([Appendix D](view.php?slug=D-handover-runbook)).

</details>

## Related chapters

- [01 — System overview & architecture](view.php?slug=01-system-overview) — the same picture in narrative form.
- [04 — Configuration & environment](view.php?slug=04-configuration) — how `.env` and settings interact.
- [33 — Deployment](view.php?slug=33-deployment) — how code reaches the server.
- [D — Handover & emergency runbook](view.php?slug=D-handover-runbook) — who holds what, and what to do when things break.
