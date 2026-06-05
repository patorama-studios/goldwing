# Authentication & sessions

## What this covers

How someone proves they are a Goldwing user and stays proved for the rest of their visit: the login form, the password check, what gets written into the session, where sessions live, how they end, and the password-reset path. 2FA is touched on but lives in its own chapter ([06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup)).

## Why it exists

The site needs three things from auth and we wrote them ourselves rather than bolt on a library:

- **One login for two audiences** — members and admins both come through `/login.php`. Splitting them would mean two forms and two recovery flows to keep in sync.
- **Sessions that survive cPanel** — PHP-FPM is load-balanced, and file-backed sessions gave us occasional "I just logged in and now I'm not" reports. Sessions in MySQL fixed that and gave a bonus: an admin can log everyone out by truncating one table.
- **An identifier that matches how people think of themselves** — older members remember their member number ("AGA-1234"), newer members remember an email. The field accepts either.

## How it works

### The login flow

`/login.php` posts back to itself with `identifier` + `password` + a CSRF token. Inside `AuthService::attemptLogin()`:

1. CSRF is verified by the page, then `LoginRateLimiter::check()` runs — locked/throttled returns `locked` (see [Chapter 12](view.php?slug=12-rate-limit-lockout)).
2. `findUserByIdentifier()` decides email vs member number and looks up the `users` row. Associate members are walked up to the household's primary account.
3. `password_verify($password, $user['password_hash'])` — passwords are hashed with PHP's native `password_hash()` (`PASSWORD_DEFAULT`, currently bcrypt). On failure the rate limiter records the attempt and a generic `invalid` status comes back.
4. `is_active = 0` is rejected; roles are loaded from `user_roles` joined to `roles`; the 2FA branch runs (see Chapter 06). If 2FA is not required, `completeLogin()` runs straight away.

`completeLogin()` regenerates the session ID (defeats fixation), writes the user blob into `$_SESSION['user']` (`id`, `email`, `name`, `member_id`, `roles`), records the login in `user_logins`, fires a `security.login_success` activity entry, and records the device via `TrustedDeviceService`. A new admin device additionally pings `SecurityAlertService`. `/login.php` then redirects to `/admin/index.php` for admin/area_rep/store_manager, or `/member/index.php` for everyone else.

### Email or member ID

`AuthService::findUserByIdentifier()` branches on `FILTER_VALIDATE_EMAIL` — emails hit `users.email`, anything else is parsed by `MembershipService::parseMemberNumberString` and resolved through `members` (including the associate → full-member walk-up) back to a `users` row.

### The `users` table

Essentials (full schema in [Chapter 03](view.php?slug=03-database-migrations)): `id`, `email` (unique), `password_hash` (bcrypt), `member_id` (FK to `members.id`, null for staff-only accounts), `name`, `is_active`, plus the 2FA columns covered in Chapter 06. Role membership lives in `user_roles` — see [Chapter 07](view.php?slug=07-roles-permissions).

### `require_login()` and `current_user()`

Both defined in `app/bootstrap.php`. Every gated page calls `require_login()` near the top:

```php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$user = current_user();
```

`current_user()` returns `$_SESSION['user']` (or `null`). `require_login()` redirects to `/login.php` if no user, and additionally enforces the 2FA-required branch: policy says 2FA required + user has none + grace closed + email-OTP off → bounce to `/member/2fa_enroll.php`.

### Session lifecycle

Sessions are PHP sessions, but the save handler is swapped to `App\Services\DbSessionHandler` inside bootstrap. Every session is a row in the `sessions` table (`id`, `user_id`, `data`, `ip_address`, `user_agent`, `last_activity_at`, `created_at`, `expires_at`). `expires_at` is `NOW() + session.gc_maxlifetime`, refreshed on every write. GC simply runs `DELETE FROM sessions WHERE expires_at < NOW()`.

Cookie params (set in bootstrap before `session_start`): name `goldwing_session`, `httponly` true, `samesite` `Strict`, `secure` auto-true on HTTPS, lifetime 0 (browser session).

### Logout

`/logout.php` calls `AuthService::logout()` which clears the step-up grant, empties `$_SESSION`, and runs `session_destroy()` — which in turn runs `DbSessionHandler::destroy()` and deletes the row.

### Password reset

`/member/reset_password.php` collects an email, issues a single-use hashed token with an expiry, and emails a link to `/member/reset_password_confirm.php?token=…`. The confirm page validates the token, runs the new password through `PasswordPolicyService::validate()`, and writes the new `password_hash`. The `LoginRateLimiter` throttles requests by IP so the endpoint can't be used to enumerate emails.

### Password policy

`PasswordPolicyService::validate($password)` returns an array of error strings. Today it enforces minimum length from `security.password_min_length` (default 8, floored at 8 even if the setting is lower) and a short common-passwords denylist (`password`, `password123`, `goldwing123`, etc.). New rules go here, not in the calling pages.

### SSO stubs

`public_html/auth/google.php` and `public_html/auth/apple.php` exist but each just returns HTTP 501 with a "not configured" message — placeholders for a future OAuth implementation, nothing wired up.

### Grace period

When 2FA is first turned on globally, `AuthService::withinGracePeriod()` gives existing users a window (in days, from `security.twofa_grace_days`) to enrol without being locked out. Inside the window, login completes normally and `$_SESSION['twofa_enroll_required']` nudges them to the enrolment screen. After it closes, users without 2FA hit the hard `2fa_enroll` block. Full detail in Chapter 06.

### Impersonation

An admin can "act as" a member from `/admin/members/actions.php`. It writes `$_SESSION['impersonation']` with the impersonating admin's user row and the target member's id; `impersonation_context()` exposes it and `is_impersonating()` is a convenience check. While impersonating, `require_login()` skips the 2FA-enrol redirect (the admin already proved themselves). Every start and stop is written to `audit_log`.

## Where to change it

- **The login flow** — `app/Services/AuthService.php` and `public_html/login.php`.
- **Session blob shape** — `AuthService::completeLogin()`.
- **Session cookie params / handler** — `app/bootstrap.php` and `app/Services/DbSessionHandler.php`.
- **Password rules** — `app/Services/PasswordPolicyService.php`.
- **Helpers (`require_login`, `current_user`, `impersonation_context`)** — `app/bootstrap.php`.
- **Reset flow** — `public_html/member/reset_password.php` + `reset_password_confirm.php`.

## Settings

- `security.password_min_length` — integer, default 8 (floor 8). Edit at `/admin/settings/security.php`.
- `security.twofa_grace_days`, `security.twofa_required_roles`, `security.email_otp_enabled` — owned by 2FA but consumed in the login branch. See [Chapter 06](view.php?slug=06-2fa-stepup).
- `security.force_https` — bootstrap 301s to HTTPS before the session cookie is sent. See [Chapter 09](view.php?slug=09-security-headers).

## Screenshots

<!-- SCREENSHOT: The /login.php page on draft.goldwing.org.au showing the "Email or Member ID" field, password field, and Forgot password link. Save to public_html/admin/help/images/05-login-page.png. -->
<!-- ![Login page](../images/05-login-page.png) -->

<!-- SCREENSHOT: The member portal landing at /member/index.php immediately after login. Save as 05-member-portal.png. -->
<!-- ![Member portal](../images/05-member-portal.png) -->

## Gotchas

- **Sessions are DB-backed.** `DELETE FROM sessions;` on the live DB logs every user out instantly. Useful in an emergency; brutal otherwise.
- **Generic error message.** A bad email and a bad password both come back as `Login failed` — by design, so the form can't be used to enumerate accounts.
- **SSO is stubbed.** `/auth/google.php` and `/auth/apple.php` return 501. Don't surface them in the UI until they actually work.
- **Impersonation is powerful.** An admin can become any member without their password. Every start/stop is in `audit_log` ([Chapter 08](view.php?slug=08-activity-audit)).
- **Two SameSite values.** Bootstrap forces `Strict`; `config/app.php` says `Lax`. The cookie param wins — the config entry is vestigial.
- **Associate members share a login.** Logging in with an associate's member number resolves to the household's primary user account; audit entries therefore credit the primary member.
- **`password_min_length` has a hard floor of 8.** Setting it lower in the admin won't help — `PasswordPolicyService` clamps it.

## Related chapters

- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — what happens after the password check when 2FA is on.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what `$user['roles']` lets each user do.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where login successes, failures, and impersonation events land.
- [12 — Login rate limiting & lockout](view.php?slug=12-rate-limit-lockout) — the throttle that runs before `password_verify`.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — how reset-password and security-alert emails go out.
