# Configuration & environment

## What this covers

Everything that tells the site *how* to run rather than *what* to do: the `.env` files, the static arrays under `config/`, and the runtime-editable Settings Hub. Four layers, loaded in a specific order. This chapter explains which layer to use for what, and what overrides what.

## Why it exists

Four layers instead of one because each kind of setting wants to live somewhere different:

- **Secrets** (DB password, Stripe key, `APP_KEY`) must not be in git → `.env`.
- **Per-machine overrides** (local MAMP vs cPanel DB) must not stomp on each other → `.env.local`.
- **Code-shaped defaults** (the `auth.google` array shape, allowed AI models) want to be PHP → `config/*.php`.
- **Runtime-editable knobs** (timezone, force HTTPS, Stripe keys, maintenance mode) want a UI → `settings_global` table.

The trade-off is that "where is this value set?" sometimes has two answers. Precedence below resolves it.

## How it works

### Load order (every request)

1. `app/bootstrap.php` calls `Env::load('.env')`, then `Env::load('.env.local')`. **`.env.local` overrides `.env`** — the second load `putenv()`s the same keys.
2. `config/app.php` is required. Each entry that wants an env value uses `getenv('FOO') ?: 'default'`.
3. `config/database.php` does the same when `Database::connection()` is first called.
4. Session starts, then `SettingsService::getGlobal('site.timezone', …)` and `getGlobal('security.force_https', …)` are read from the DB.

Effective precedence:

```
.env.local  >  .env  >  config/*.php default  >  SettingsService default
                                  ↓
                  (settings_global may override this at read time)
```

That last line matters: services like `StripeSettingsService` and `EmailService` read `settings_global` first and fall back to `config/app.php` only when the DB row is empty. **For Stripe and SMTP, `settings_global` wins over env** once an admin has saved a value in the UI.

### The env vars that matter

`Env::load()` (`app/Services/Env.php`) is a tiny parser: `KEY=value`, `#` comments, optional quotes, no interpolation. Values are written to both `$_ENV` and `putenv()` so `getenv()` works everywhere.

| Variable | Used by | Required? | Notes |
|---|---|---|---|
| `APP_KEY` | `CryptoService` (Ch 10) | **Yes** | 32-byte base64 secret. Rotating it breaks every value already encrypted — see Gotchas. |
| `APP_BASE_URL` | `config('base_url')`, email links | Yes | e.g. `https://draft.goldwing.org.au`. Trailing slash stripped. |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` `DB_CHARSET` | `config/database.php` | Effectively yes | Hard-coded production fallbacks exist — always set these in `.env.local`. |
| `KIE_API_KEY` | AI page builder (Ch 24) | For AI features | Feeds `config('ai.api_key')` and `config('ai.providers.kie.api_key')`. |
| `AI_DEFAULT_MODEL` | AI page builder | No | Defaults to `claude-sonnet-4-6`. |
| `GOOGLE_OAUTH_CLIENT_ID` `GOOGLE_OAUTH_CLIENT_SECRET` `GOOGLE_OAUTH_REDIRECT_URI` | `config('auth.google')` | No | Placeholders until Google SSO ships. |
| `APPLE_OAUTH_CLIENT_ID` `APPLE_OAUTH_TEAM_ID` `APPLE_OAUTH_KEY_ID` `APPLE_OAUTH_PRIVATE_KEY_PATH` `APPLE_OAUTH_REDIRECT_URI` | `config('auth.apple')` | No | Same. |
| `STRIPE_*` | `StripeSettingsService` (only when `settings_global` is empty) | No | First-deploy fallback before keys are saved in the UI. |
| `GOOGLE_MAPS_API_KEY` | `backend_head.php` + public header | If maps are used | Read directly via `getenv()`, not `config()`. |

### `config/app.php`

Static return array. Keys in current use:

- `app_key` — from `APP_KEY`.
- `app_name` — hard-coded "Australian Goldwing Association".
- `base_url` — from `APP_BASE_URL`.
- `env` — hard-coded `'production'`. Always. See Gotchas.
- `session.{name,secure,httponly,samesite}` — cookie params. **`samesite` is overridden in bootstrap to `Strict`**.
- `email.{from,from_name}` — default `From:` headers. Most code reads SMTP from `settings_global` instead.
- `stripe.{secret_key,webhook_secret,membership_prices.*}` — empty defaults; `StripeSettingsService` reads `settings_global` first.
- `ai.{default_provider,default_model,provider,api_key,model,providers.kie.*}` — wired to `KIE_API_KEY` / `AI_DEFAULT_MODEL`.
- `auth.{google,apple}.*` — OAuth credentials, all env-driven.

Reads use `config('a.b.c', $default)`, which dot-walks the array. `config()` `require`s the file on every call (Ch 01).

### `config/database.php`

Pulls all six DB env vars with fallbacks. Those fallbacks happen to be the real live credentials (legacy — predates `.env`). Always populate `.env.local` on a fresh local checkout.

### `config/tour-manifest.json` and `config/member_of_year.php`

Two narrow-purpose configs that don't run through `config()`:

- `config/tour-manifest.json` — declares every UI tour and its watched files. Loaded by `TourService`. See [Ch 36 — Tours system](view.php?slug=36-tours-system).
- `config/member_of_year.php` — copy and labels for the Member of the Year nomination form. Edit and redeploy.

### The Settings Hub

Anything an admin should change without a deploy lives in `settings_global` (site-wide) or `settings_user` (per-user). `SettingsService::getGlobal('category.key', $default)` reads, `::setGlobal()` writes and audits. Full model and key catalogue in [Ch 31](view.php?slug=31-settings-architecture) and [Ch 32](view.php?slug=32-settings-by-section).

## Where to change it

- **Secrets or per-environment values** → `.env.local` (local) or the server's `.env` (live). Never commit either.
- **Code-shaped defaults that should ship in git** → `config/app.php`, then redeploy.
- **Anything an admin needs to flip on production** → add a `settings_global` entry and surface it under `/admin/settings/`.
- **DB credentials** → `.env.local` locally, server's `.env` on live. The fallbacks in `config/database.php` are legacy.

## Settings

This chapter doesn't own user-facing settings. The full catalogue of `settings_global` keys (categories, defaults, which page edits them) is in [Ch 32 — Settings by section](view.php?slug=32-settings-by-section).


<!-- SCREENSHOT: Redacted view of the server's .env file (key names only). Save to public_html/admin/help/images/04-env-file.png. -->
<!-- ![Server .env keys](../images/04-env-file.png) -->

<!-- SCREENSHOT: /admin/settings/ landing page showing categories that override env defaults. Save as 04-settings-hub.png. -->
<!-- ![Settings Hub landing](../images/04-settings-hub.png) -->

## Gotchas

- **Never commit `.env` or `.env.local`.** Both are in `.gitignore`. If you ever do, rotate every secret and re-encrypt anything that was under the old `APP_KEY` (Ch 10).
- **Rotating `APP_KEY` breaks encrypted secrets at rest.** `CryptoService` derives its key from `APP_KEY`; Stripe keys and OAuth secrets saved via the UI become unreadable. Follow the [Ch 10](view.php?slug=10-encryption-secrets) procedure — never rotate cold.
- **`config('env')` always returns `'production'`.** There is no real environment switch. Don't write `if (config('env') === 'local')`. Key off `APP_BASE_URL` or the hostname if you need one.
- **`session.samesite` in `config/app.php` is vestigial.** Bootstrap hard-codes `Strict` after loading the config, so the config value never wins. Edit `app/bootstrap.php` instead.
- **`config/database.php` has real production credentials as fallbacks.** Forgetting `DB_*` in `.env.local` silently connects to the live DB. Always populate `.env.local` on a fresh checkout.
- **`settings_global` beats env for Stripe and SMTP.** If "I updated `STRIPE_SECRET_KEY` and nothing changed", check `/admin/settings/payments` — the DB row is winning.
- **`config()` re-reads the file on every call.** Cache it locally inside hot loops.

## Related chapters

- [01 — System overview & architecture](view.php?slug=01-system-overview) — where bootstrap sits in the request lifecycle.
- [03 — Database & migrations](view.php?slug=03-database-migrations) — what `DB_*` points at.
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — what `APP_KEY` does and how to rotate it.
- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — how `STRIPE_*` interacts with `settings_global`.
- [24 — AI page builder](view.php?slug=24-ai-page-builder) — what `KIE_API_KEY` and `AI_DEFAULT_MODEL` drive.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — the full Settings Hub model and key catalogue.
