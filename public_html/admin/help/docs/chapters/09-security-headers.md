# Security headers & policies

## What this covers

Every HTTP response the site sends carries a stack of security headers — Content-Security-Policy, X-Frame-Options, HSTS, Referrer-Policy, Permissions-Policy, X-Content-Type-Options. This chapter explains what each one says, why it's set the way it is, and which knobs are in code versus the Settings Hub. It also covers the two services that decide *who* needs 2FA and *how aggressive* the lockout policy is: `SecurityPolicyService` and `SecuritySettingsService`.

If you're trying to add a new third-party script (e.g. a new analytics CDN), this is the chapter — you'll be editing `SecurityHeadersService` directly, because CSP source lists live in PHP, not in the database.

## Why it exists

Browsers will execute almost anything an HTML page asks them to unless we tell them otherwise. The headers here are the "tell them otherwise" — they're the difference between an XSS bug being an inconvenience and an XSS bug exfiltrating session cookies.

A few deliberate choices shaped the current implementation:

- **CSP source lists are in PHP, not in the DB.** Letting an admin add an arbitrary script source through a form is one CSRF away from someone whitelisting their own attacker-controlled CDN. The CSP is code-reviewed and deployed; that's the gate.
- **2FA enforcement and lockout thresholds *are* in the DB.** These are operational tuning knobs (lock people out faster after a brute-force spike, exempt one admin during a recovery) and need to change without a deploy.
- **One central place applies all headers.** `SecurityHeadersService::apply()` runs from `app/bootstrap.php` so every PHP entrypoint gets the same treatment automatically — there's no "this admin page forgot its CSP" failure mode.

## How it works

### The headers themselves

`app/Services/SecurityHeadersService.php` is one static method, `apply()`, called from `app/bootstrap.php` line 49 — right after the session is started and before any page logic. It bails out early if `headers_sent()` is true (so an accidental `echo` before bootstrap doesn't crash the request).

The headers it sets:

| Header | Value | Purpose |
|---|---|---|
| `Content-Security-Policy` | composed below | What the page is allowed to load and execute |
| `X-Frame-Options` | `DENY` or `SAMEORIGIN` | Legacy framing protection (CSP `frame-ancestors` is the modern one; we set both for old browsers) |
| `X-Content-Type-Options` | `nosniff` | Stops browsers guessing MIME types — protects against polyglot uploads |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Leak the path within Goldwing, leak only the origin to outside sites |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` | Tells the browser no page on this site may use these APIs (Stripe Elements has its own postMessage path and doesn't need the Payment Request API) |
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains; preload` | Only set when the request is already HTTPS; locks browsers into HTTPS for 2 years |

### The CSP, broken down

The CSP is built up from variables for readability. The current directives:

```
default-src 'self';
img-src 'self' data: https:;
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com [+ jsdelivr on framed pages];
script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net
           https://cdnjs.cloudflare.com https://maps.googleapis.com https://maps.gstatic.com
           https://js.stripe.com https://m.stripe.network;
frame-src 'self' https://js.stripe.com https://m.stripe.network;
font-src 'self' data: https://fonts.gstatic.com;
connect-src 'self' https://api.stripe.com https://m.stripe.network
            https://maps.googleapis.com https://maps.gstatic.com
            https://cdnjs.cloudflare.com https://cdn.jsdelivr.net;
worker-src blob: https://cdnjs.cloudflare.com;
frame-ancestors 'none' | 'self';
```

`'unsafe-inline'` is in `script-src` and `style-src` because Tailwind's CDN injects styles inline and a lot of admin pages use inline `onclick` handlers. Removing it is a long-term cleanup task, not a quick toggle.

`frame-ancestors` is the per-page override: two paths are allowed to be iframed — `/calendar/events_public.php` (so chapters can embed the events calendar on their own sites) and `/admin/page-builder/preview.php` (so the page builder can render previews in an iframe). Everything else gets `'none'`. The toggle is the in-line `$allowFraming` check at the top of `apply()`.

### `SecurityPolicyService` — who needs 2FA

`app/Services/SecurityPolicyService.php` answers one question per request: *does this user have to have 2FA enabled?* It's called from `require_login()` in `bootstrap.php` and from the admin user-management screens.

The decision flow in `computeTwoFaRequirement(array $user)`:

1. If the global `enable_2fa` flag is off, or `twofa_mode` is `DISABLED` → return `DISABLED`.
2. If mode is `REQUIRED_FOR_ALL` → required.
3. If mode is `REQUIRED_FOR_ROLES` → required only if the user's roles intersect `twofa_required_roles`.
4. Per-user override (from `user_security_overrides.twofa_override`): `REQUIRED` forces it on, `EXEMPT` forces it off, `DEFAULT` leaves the role-based answer alone.
5. Return `REQUIRED` or `OPTIONAL`.

If the result is `REQUIRED` and the user hasn't enrolled yet, `require_login()` bounces them to `/member/2fa_enroll.php` — unless they're inside the grace window (`twofa_grace_days`) or have email OTP enabled as a fallback. See [Chapter 06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup).

### `SecuritySettingsService` — the cached row

All of the operational security knobs live in **one row** of the `security_settings` table (`id = 1`). `SecuritySettingsService::get()` loads it once per request (`self::$cache`), falls back to `defaults()` if the table is missing (defensive — avoids breaking the site during a half-applied migration), and seeds a fresh row if the table exists but is empty.

`SecuritySettingsService::update($actorUserId, $payload)` writes the whole row atomically, logs `security.settings_updated` to the activity log, and clears the cache.

## Where to change it

| You want to… | Edit |
|---|---|
| Add a new whitelisted script CDN | `app/Services/SecurityHeadersService.php` (`$cdnSources`, `$scriptSrc`, or a new variable) → deploy |
| Allow a new page to be iframed | Add its path to the `$allowFraming` array in `SecurityHeadersService::apply()` |
| Change the Permissions-Policy list | Edit the literal in `SecurityHeadersService::apply()` |
| Force HTTPS | Settings Hub → **Security** → "Force HTTPS" toggle (`security.force_https`) |
| Set the password minimum length | Settings Hub → **Security** → "Password minimum length" (`security.password_min_length`) |
| Tweak 2FA mode, lockout, alerts, FIM paths | Settings Hub → **Security** → 2FA / Login lockout / Alerts / File integrity sections (all backed by `security_settings`) |
| Force one user into / out of 2FA | Admin user edit screen → 2FA override (`user_security_overrides.twofa_override`) |

## Settings

Two storage backends — two flavours of setting.

**`settings_global` keys (read via `SettingsService::getGlobal`)**

| Key | Default | Allowed values |
|---|---|---|
| `security.force_https` | `false` | boolean — when true, bootstrap 301-redirects any HTTP request to HTTPS |
| `security.password_min_length` | `12` (UI), `8` (`PasswordPolicyService` fallback) | integer ≥ 1 — minimum password length enforced by `PasswordPolicyService` |

**`security_settings` row (read via `SecuritySettingsService::get`)**

| Key | Default | Allowed values |
|---|---|---|
| `enable_2fa` | `true` | boolean — global 2FA kill switch |
| `twofa_mode` | `REQUIRED_FOR_ALL` | `DISABLED`, `OPTIONAL`, `REQUIRED_FOR_ALL`, `REQUIRED_FOR_ROLES` |
| `twofa_required_roles` | `[]` | array of role names — only used when mode is `REQUIRED_FOR_ROLES` |
| `twofa_grace_days` | `0` | integer days — new accounts skip the 2FA wall for this long |
| `stepup_enabled` | `true` | boolean — whether sensitive actions require re-verification |
| `stepup_window_minutes` | `10` | integer minutes — how long a step-up stays valid |
| `login_ip_max_attempts` | `10` | integer — failed logins per IP before throttling |
| `login_ip_window_minutes` | `10` | integer minutes — sliding window for the IP counter |
| `login_account_max_attempts` | `5` | integer — failed logins per account before lockout |
| `login_account_window_minutes` | `15` | integer minutes — sliding window for the account counter |
| `login_lockout_minutes` | `30` | integer minutes — how long an account stays locked |
| `login_progressive_delay` | `true` | boolean — add increasing delay after each failed attempt |
| `alert_email` | `""` | email address — recipient for security alert emails |
| `alerts.failed_login` | `true` | boolean — email on failed-login bursts |
| `alerts.new_admin_device` | `true` | boolean — email when an admin signs in from a new device |
| `alerts.refund_created` | `true` | boolean — email on every refund |
| `alerts.role_escalation` | `true` | boolean — email when a user gains the `admin` role |
| `alerts.member_export` | `true` | boolean — email on member-data exports |
| `alerts.fim_changes` | `true` | boolean — email when FIM detects file changes |
| `alerts.webhook_failure` | `true` | boolean — email when Stripe webhooks fail repeatedly |
| `fim_enabled` | `true` | boolean — turn the file integrity scanner on/off |
| `fim_paths` | `["/app", "/admin", "/config"]` | array of paths (relative to repo root) to watch |
| `fim_exclude_paths` | `["/uploads", "/cache"]` | array of paths to skip |
| `webhook_alerts_enabled` | `true` | boolean — alert on webhook failure bursts |
| `webhook_alert_threshold` | `3` | integer — failed webhooks needed to trigger the alert |
| `webhook_alert_window_minutes` | `10` | integer minutes — sliding window for the webhook counter |

**`user_security_overrides` (per-user, set via `SecurityPolicyService::setTwoFaOverride`)**

| Column | Allowed values |
|---|---|
| `twofa_override` | `DEFAULT`, `REQUIRED`, `EXEMPT` |


<!-- SCREENSHOT: Browser devtools Network tab on draft.goldwing.org.au showing the response headers (CSP, X-Frame-Options, HSTS, Permissions-Policy) on /admin/index.php. Save to public_html/admin/help/images/09-response-headers.png. -->
<!-- ![Response headers in devtools](../images/09-response-headers.png) -->

<!-- SCREENSHOT: /admin/settings/index.php scrolled to the Security section showing the 2FA mode, lockout knobs, alerts checkboxes, and Force HTTPS toggle. Save as 09-security-settings.png. -->
<!-- ![Security settings panel](../images/09-security-settings.png) -->

## Gotchas

- **`'unsafe-inline'` is still in `script-src`.** That weakens the CSP — XSS that injects inline `<script>` will run. Moving the admin UI to nonce-based scripts is on the cleanup list; until then, the rest of the stack (CSRF tokens, input escaping via `e()`, the `frame-ancestors` lock) is the real defence.
- **Adding a CDN means editing PHP and deploying.** There is no admin UI for CSP source lists, and there shouldn't be. If you need jsdelivr for a non-framed page, add it to `$cdnSources` in `SecurityHeadersService` and ship.
- **HSTS only sets on HTTPS requests.** The `$_SERVER['HTTPS']` check is intentional — sending HSTS over plain HTTP is ignored by browsers anyway, but the check avoids surprising local-dev behaviour where HSTS would pin `localhost` to HTTPS.
- **`X-Frame-Options` and CSP `frame-ancestors` are both set.** Modern browsers use `frame-ancestors`; old ones use `XFO`. Keep both in sync if you add a new framed-allowed path — there are two places to update.
- **`SecuritySettingsService::$cache` is request-scoped.** It only lasts for one PHP request. If a CLI script (cron) calls `update()` then `get()`, it'll see the new values. If a long-running daemon ever appears, that cache would need flushing.
- **The 2FA grace period is from account creation, not from "now".** Setting `twofa_grace_days = 30` does *not* give existing users 30 more days — `AuthService::withinGracePeriod()` measures against `users.created_at`.
- **`twofa_required_roles_json` and `fim_paths_json` are JSON columns.** Querying them from SQL needs `JSON_CONTAINS` or `JSON_EXTRACT`; don't try `LIKE '%admin%'` matches in reports.

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — `AuthService`, password policy, where these headers stop being theoretical
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — the user-facing side of the 2FA policy this chapter computes
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — how the Stripe key and other secrets are protected at rest
- [11 — File integrity monitoring](view.php?slug=11-file-integrity) — what the `fim_*` settings here actually drive
- [12 — Login rate limiting & lockout](view.php?slug=12-rate-limit-lockout) — the runtime that reads the `login_*` knobs
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `settings_global` vs dedicated tables (like `security_settings`) coexist
