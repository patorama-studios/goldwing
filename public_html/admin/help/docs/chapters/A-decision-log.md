# A — Decision log

## What this is

A short record of the architectural calls that shaped this codebase, and the reasoning behind each one. Every chapter in this documentation answers *what* and *how* — this appendix answers *why*. Read it when you're tempted to rip something out, when you're onboarding and the stack looks weird, or when a decision needs to be revisited because the original constraint has changed.

For the narrative version — "here's the runtime, here's where the code lives" — start with [Chapter 01 — System overview](view.php?slug=01-system-overview). This appendix is the dry reference behind that narrative.

## Conventions

- Each decision is numbered and dated by status, not by recency.
- Four blocks per entry: **Context** (the constraint we were under), **Decision** (what we picked), **Consequences** (what we have to live with), **Status** (`active`, `superseded`, `under review`).
- Entries are *append-only* in spirit — if a decision changes, mark the old one superseded and add a new one. Don't rewrite history.
- An entry is short on purpose. If it grows past four bullets per block, it should be a chapter, not a log entry.

## The decisions

### 1. PHP 8 + MySQL on cPanel, no framework

**Context.** The Australian Goldwing Association already pays for cPanel + MySQL hosting and has no budget for a second hosting account. The team is one developer plus a rotating cast of volunteer admins. A Laravel/Rails/Node stack would add an ops story and an upgrade treadmill nobody has time for.

**Decision.** Plain PHP 8.3+ on cPanel's bundled FPM workers, MySQL as the only datastore, and a tiny in-house service layer under `app/Services/` instead of a framework. The pieces a framework would give us (routing, templating, ORM) are small enough to write directly.

**Consequences.** No router — every `*.php` file under `public_html/` *is* a route. No migrations runner — schema changes are hand-run SQL files. No service container — services are static classes. Trivial deploy in exchange. Documented in [Chapter 01](view.php?slug=01-system-overview) and [Chapter 03](view.php?slug=03-database-migrations).

**Status.** Active.

### 2. Single repository, monolith

**Context.** One developer, one site, no separate front-end SPA, no microservices. Splitting `app/` and `public_html/` into separate repos (or breaking out store / members / payments) would mean coordinating multiple deploys for a single feature.

**Decision.** Everything in one git repo: `app/` (logic), `public_html/` (web root), `config/`, `database/`, `cron/`, `scripts/`. One push, one deploy, one history.

**Consequences.** Easy local clone, easy "what changed in the last week" review, no service-discovery overhead. The repo is now ~70 services and ~40 admin pages, which is fine at this size — if it grows to a second product, revisit.

**Status.** Active.

### 3. Sessions in MySQL, not on disk

**Context.** cPanel's PHP-FPM pool is load-balanced across workers, so a session file written by worker A may not be visible to worker B on the next request. Disk-based sessions also can't be invalidated en masse without an SSH and a `find … -delete`.

**Decision.** `App\Services\DbSessionHandler` registered in `bootstrap.php` before `session_start()`. Reads and writes go to the `sessions` table; `gc()` deletes expired rows.

**Consequences.** Every request does one extra `INSERT … ON DUPLICATE KEY UPDATE`. In exchange, "log everyone out" is one `DELETE FROM sessions`, sessions survive worker swaps, and we get `ip_address` / `user_agent` / `last_activity_at` columns for free. Detail in [Chapter 03](view.php?slug=03-database-migrations) and [Chapter 05](view.php?slug=05-authentication).

**Status.** Active.

### 4. Settings in two JSON tables, not a column per setting

**Context.** Before the Settings Hub there were a dozen one-off tables (`store_settings`, `settings_payments`) and hard-coded values in `config/app.php`. Every new toggle meant an `ALTER TABLE`, a form handler, an audit hook. That didn't scale.

**Decision.** Two tables — `settings_global (category, key_name, value_json)` and `settings_user (user_id, key_name, value_json)` — backing one `SettingsService`. Values are arbitrary JSON.

**Consequences.** Adding a setting is "pick a category, pick a key, store any JSON." No schema migration, no per-feature service, one audited write path. The trade-off is no SQL-level type checking — typos in keys silently return `null`. See [Chapter 31](view.php?slug=31-settings-architecture).

**Status.** Active.

### 5. Tailwind via CDN, no build step

**Context.** cPanel has no Node toolchain and we don't want to maintain one. Every admin change shouldn't require a CSS rebuild on a developer's laptop. The site has limited custom styling — Tailwind utility classes cover almost everything.

**Decision.** Load Tailwind directly from `https://cdn.tailwindcss.com?plugins=forms,typography`. No `package.json`, no `tailwind.config.js`, no build artifact.

**Consequences.** Edits are instant: change a class in a `.php` file, refresh, done. We can't use Tailwind plugins that require config-file changes. Offline (or if the CDN is degraded) admin pages render unstyled. If we ever need a custom plugin, the path is to vendor a single compiled CSS file under `public_html/assets/`.

**Status.** Active.

### 6. AI page builder hard-locked to kie.ai

**Context.** The page builder needs predictable per-call cost, a curated model list, and one vendor relationship to manage. Supporting OpenAI + Anthropic + kie.ai + Gemini in parallel would multiply the surface area of billing, prompt drift, and key management.

**Decision.** `AiService` only loads the kie.ai provider adapter. `KIE_API_KEY` is the env fallback; the per-user UI key is stored encrypted in `ai_provider_keys`. The adapter folder is structured for multiple providers, but only one is wired in.

**Consequences.** Switching vendors is a code change plus a new adapter, not a settings toggle. One vendor to negotiate with, one set of model names to track. Documented in [Chapter 24](view.php?slug=24-ai-page-builder) and called out in [DEPLOY.md](../../../../DEPLOY.md).

**Status.** Active.

### 7. Stripe as the sole payments provider

**Context.** Need AU card support, refunds, webhooks, a usable test mode, and balance reporting. Considered Eway and PayPal — Eway needed a hosted page per product type; PayPal was clunky for renewal invoices.

**Decision.** Stripe via the official `stripe/stripe-php` SDK, vendored at `app/ThirdParty/stripe-php/`. One Stripe account drives both memberships and store orders; line items are distinguished by `metadata` (`member_id` vs `order_id`). Test vs live is the `sk_test_…` / `sk_live_…` key prefix.

**Consequences.** One dashboard, one webhook endpoint, one set of keys. Concentrated risk — a Stripe account suspension takes both products offline simultaneously. Acceptable for a single-association site. See [Chapter 13](view.php?slug=13-stripe-overview).

**Status.** Active.

### 8. No Stripe Subscriptions — single-charge sessions

**Context.** Association memberships renew annually, not monthly, and renewal isn't automatic — members may change tier, lapse, or be removed. Stripe Subscriptions would add a billing object we'd have to keep in sync with our own membership state machine.

**Decision.** Every payment is a one-shot Checkout Session. Renewals are driven by `cron/send_renewal_reminders.php`, which emails members a fresh payment link 60/30/14/7 days before expiry.

**Consequences.** Simpler than Subscriptions — no proration, no failed-payment dunning code to write. The cost is that renewal is a manual click for the member, and lapsed members have to be chased by email rather than auto-charged. See [Chapter 19](view.php?slug=19-membership-lifecycle).

**Status.** Active.

### 9. Custom 2FA + step-up, not an off-the-shelf library

**Context.** Off-the-shelf 2FA libraries assume a framework and a single enforcement model. We need a configurable grace period (so new members aren't blocked at signup), role-based enforcement (`twofa_mode = required_for_admins`), recovery codes, email-OTP fallback, trusted-device cookies, and a separate step-up gate for sensitive actions.

**Decision.** Four small services owned in-house: `TotpService` (RFC 6238 crypto), `TwoFactorService` (enrollment + verification + recovery codes), `EmailOtpService` (email fallback), `StepUpService` (sensitive-action re-verification), `TrustedDeviceService` (30-day cookie).

**Consequences.** More code to own, but it fits the rest of the stack — same `db()`, same `SettingsService`, same audit log. Documented in [Chapter 06](view.php?slug=06-2fa-stepup).

**Status.** Active.

### 10. Two encryption services side by side

**Context.** Some secrets need broad-envelope encryption (Stripe keys, SMTP password, the unsubscribe token round-trip). Others need tamper-evident storage with an auth tag (AI provider keys, fresh 2FA seeds — a corrupted payload should fail loudly, not decrypt to garbage).

**Decision.** Keep both. `CryptoService` is AES-256-CBC, no auth tag — wired into the older call sites (Stripe settings, `settings_global` encrypted wrapper, email-prefs token, legacy TOTP fallback). `EncryptionService` is AES-256-GCM with auth tag — for new code, refuses to save when the key is missing.

**Consequences.** Split-brain: developers must pick the right service per call site (rule of thumb: new code picks `EncryptionService`). Both read the same `APP_KEY`, so they share fate — rotating or losing the key breaks both in lockstep. See [Chapter 10](view.php?slug=10-encryption-secrets).

**Status.** Active.

### 11. Tours powered by Driver.js + a manifest

**Context.** The site has grown past the point where new members or volunteer admins can discover features by clicking around. External docs (Notion, PDFs) went stale within a week. Tooltips only help once you've found the button.

**Decision.** Adopt Driver.js as the in-page tour engine. Declare every tour in `config/tour-manifest.json` (audience, roles, page match, steps file, watched files). Per-step wording lives in the database so a non-developer admin can fix awkward phrasing without a deploy. Add an admin Tour Validator and a `tour-impact-check` skill to flag tours that may have broken when watched files change.

**Consequences.** Tour wording can drift from UI reality if a `data-tour=""` attribute is renamed without re-running the validator — that's exactly what the impact-check skill catches. See [Chapter 36](view.php?slug=36-tours-system).

**Status.** Active.

### 12. Admin Documentation: markdown files + minimal renderer

**Context.** Same problem the tours solve, scaled up: admins need a reference for "why does this work this way?" that doesn't live in someone's head. Building an in-app CMS for docs would be over-engineering; using Notion would put the docs behind another login and out of sync.

**Decision.** Plain markdown under `public_html/admin/help/docs/chapters/` plus a minimal renderer at `/admin/help/docs/view.php?slug=…`. TOC declared in `_toc.json`. Each chapter declares `watched_files` so a `doc-sync-check` skill can flag stale chapters after a code change. Admin-only — no public exposure.

**Consequences.** Edits ship with the code (versioned, reviewable, diffable) — but that also means edits need a deploy. Not editable in the admin UI. The system you're reading right now.

**Status.** Active (new).

### 13. `activity_log` and `audit_log` are separate tables

**Context.** Two different concerns: *what members and admins did to other members* (password resets, refunds, vehicle CRUD, profile edits) vs *what changed in site configuration* (a settings toggle, a roles edit, a baseline approval). Mashing them into one table means every consumer has to filter by `entity_type` and the indexes get muddled.

**Decision.** Two tables, two services. `ActivityLogger` writes `activity_log` (the per-member action trail). `AuditService` writes `audit_log` (the settings/diff trail). Each has its own admin viewer.

**Consequences.** Admins need to know which view to check ("did Stripe key change?" → audit log; "who refunded the order?" → activity log). Some IP/UA capture is duplicated between the two writers. Acceptable trade for clearer indexes and queries. See [Chapter 08](view.php?slug=08-activity-audit).

**Status.** Active.

### 14. Forbidden: FTP for code deploys, destructive git on the server

**Context.** cPanel's git integration is the source of truth: a push to `origin/main` plus a "Update from Remote" / "Deploy HEAD Commit" click is the deploy. Mixing FTP uploads in — or running `git clean` / `git reset --hard` on the server — corrupts the working tree, drops uncommitted server-side files, and makes the next deploy diverge. Lesson learned the hard way on 2026-06-05.

**Decision.** Deploys go exclusively through cPanel's git pull from `origin/main`. FTP scripts (`scripts/ftp_upload.py`, `ftp_upload2.py`, etc.) are kept on disk for true emergencies only — they are never the normal path. Destructive git commands on the server are forbidden.

**Consequences.** Developers must remember the discipline. The reward is a clean, reproducible deploy history and a working tree that always matches a commit hash. See [Chapter 33](view.php?slug=33-deployment).

**Status.** Active.

## How to add a new entry

1. Pick the next number (decisions are append-only — never renumber).
2. Write the four blocks. Aim for two to four sentences each. If you can't fit it, the decision probably needs its own chapter and this entry should just summarise + link to it.
3. Set **Status** to `active` for new decisions, `superseded` when replacing an older one (and link the replacement), or `under review` if the team is currently debating it.
4. If the decision touches code paths declared in `_toc.json` `watched_files`, mention it in the affected chapter too — this appendix is the *why*, the chapter is the *how*.
5. Commit it. Like every other doc edit, it ships on the next deploy.
