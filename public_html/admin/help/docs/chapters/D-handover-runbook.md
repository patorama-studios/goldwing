# D — Handover & emergency runbook

## For administrators

### What this is

The chapter to open when something needs the developer, when something breaks, or when the committee changes hands. It covers how to let the developer in (and how they're kept out), the backup routine, emergency triage, and what to hand a future webmaster or agency.

### How developer access works after handover

The website was built by an outside developer (Patorama Studios). After handover the club owns everything — and the developer's admin login is **locked by default**. Letting them in is a deliberate, logged decision by the webmaster:

1. Open {{link:/admin/settings/developer-access.php|Settings → Developer Access}}.
2. Choose a window length (one week is the default) and click **Grant access**.
3. The developer is emailed automatically and signs in with their own password and 2FA — no passwords change hands.
4. When the window ends — or you click **Revoke access now** — they are locked out again automatically, and any session they still have open is ended on their next click.

Every grant, revoke and blocked login attempt appears in the history panel on that page and in the [audit hub](view.php?slug=08-activity-audit).

> **Rule of thumb:** grant a window when you've agreed work with the developer; never leave one open "just in case".

### Monthly backup routine (10 minutes)

The site does not back itself up off the server — the webmaster keeps copies:

1. **Database:** cPanel → **Backup** → download a **MySQL Database Backup**. Store it off the server (committee drive, or a USB held by the secretary).
2. **Member list:** Admin → Members → **Export CSV** — a human-readable fallback of the data that matters most.
3. Do both **before** any big change: imports, deploys you're unsure about, committee handover.
4. Keep at least the last three copies. Once a year, ask the developer or hosting support to confirm a backup actually restores.

Also ask the hosting provider whether automatic server backups (e.g. JetBackup) are included in the plan, and how far back they go.

### If the site is down

1. Don't panic, and don't click anything in cPanel's Git screen.
2. Check whether it's just you — try your phone on mobile data.
3. Check the hosting provider's status page or support — most outages are hosting-level, not the website itself.
4. Was something just deployed? If yes, tell the developer what and when — do not redeploy or reset anything yourself.
5. Grant a developer access window and send them: what you saw, when it started, and screenshots.

### If payments look wrong

Money truth lives in **Stripe**, not the website. Log into dashboard.stripe.com first: is the payment there? Then use the reconcile tool ([Chapter 16 — Webhooks & idempotency](view.php?slug=16-webhooks-idempotency)) and [Chapter 17 — Refunds](view.php?slug=17-refunds). If Stripe and the site still disagree after a day, grant the developer a window.

### If you're locked out of admin

- **One admin locked out:** another admin resets their password from the Members console ([Chapter 20 — Members admin](view.php?slug=20-members-admin)).
- **The only webmaster is unavailable and the developer is locked out:** use the break-glass below.

### Break-glass: unlocking the developer without a webmaster

For a genuine emergency where nobody with admin access is available. Whoever holds the **cPanel** login can do this:

1. cPanel → **phpMyAdmin** → select the site database.
2. Open the `settings_global` table and find the row where `category` = `security` and `key_name` = `dev_access_lockout_enabled`.
3. Change its `value_json` from `true` to `false` and save.
4. The developer can now log in normally. **After the emergency**, switch the lockout back on from {{link:/admin/settings/developer-access.php|Settings → Developer Access}}.

This is the only by-hand database edit this manual will ever ask for — it works because the lockout's master switch lives in that settings row.

### Handing over to a new webmaster

1. Create their admin login first (Members console → grant the admin role) and have them enroll 2FA.
2. Walk them through: deploys ([Chapter 33 — Deployment](view.php?slug=33-deployment)), this runbook, Developer Access, and the backup routine.
3. Transfer the account credentials they now hold — cPanel/hosting, domain registrar, Stripe, the site mailbox — via the committee password manager, never by email.
4. Update the security alert email in Settings → Security so alerts reach the new webmaster.
5. Deactivate the outgoing webmaster's admin account if they are leaving the role entirely.

### Onboarding a future developer or agency

Hand them: (1) access to the GitHub repository, (2) the latest **committee manual PDF** (or this doc system), (3) `HANDOVER.md` from the repository root, (4) a granted developer-access window — or set their email as the gated account in Developer Access, and (5) the current `.env` values via the password manager. A competent PHP developer needs nothing else to take over.

<details>
<summary>Dev notes</summary>

### The developer-access mechanism

- Service: `app/Services/DeveloperAccessService.php`. Settings keys (in `settings_global`): `security.dev_access_lockout_enabled` (bool master switch), `security.dev_access_email` (the gated account), `security.dev_access_expires_at` (ISO datetime; empty = locked).
- Enforcement points: (1) `AuthService::attemptLogin()` returns `['status' => 'dev_locked']` straight after the `is_active` check; (2) `AuthService::completeTwoFactorLogin()` re-checks before establishing the session; (3) `app/bootstrap.php` ends a *live* session whose window has expired or been revoked — redirect to `/login.php?dev_locked=1`, or a JSON 401 under `/api/`.
- UI: `public_html/admin/settings/developer-access.php` — permission `admin.settings.general.manage`, step-up required on POST, and the gated account cannot change its own access while the lockout is on.
- Activity actions: `security.dev_access_granted | revoked | lockout_enabled | lockout_disabled | login_denied | session_ended | email_changed` — all in `activity_log`, surfaced in the page's history panel and the audit hub.
- Granting emails the developer via `EmailService::send()`.

### Handover artefacts

- `HANDOVER.md` (repo root) — the full transfer procedure and credential checklist.
- `.env.example` — the environment contract for a fresh setup.
- Manual PDF — `admin/help/docs/manual.php` in-admin ("Save as PDF"), or `php scripts/build_manual.php` + headless Chrome for a CLI build.
- Outstanding at the time of writing: rotate the production DB password and strip the hardcoded fallbacks from `config/database.php` once the server `.env` is confirmed — ordered steps in `HANDOVER.md`.

</details>

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — where the developer-lockout check sits in the login flow.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where dev-access events are recorded.
- [33 — Deployment](view.php?slug=33-deployment) — the cPanel deploy steps referenced above.
- [C — Technical specifications](view.php?slug=C-technical-specifications) — the full stack and service inventory.
