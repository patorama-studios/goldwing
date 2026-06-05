# Login rate limiting & lockout

## What this covers

How the site stops a brute-force attack against `/login.php`. Two independent counters run on every failed login — one per IP address, one per account (email) — and either one can trip a temporary lockout. This chapter covers the counters, the lockout window, what gets written to the database, how an admin unlocks a stuck member, the (minimal) locked-out UX, and how to tune everything from Settings Hub. The same numbers are reused by the password-reset throttle at `/member/reset_password.php`.

Note: don't confuse this with `/locked-out/` — that page is the "you don't have access to this section" screen used by role gating. Rate-limit lockout doesn't redirect anywhere; it just refuses the login.

## Why it exists

`login.php` is the front door to the admin console, and it's a public, unauthenticated endpoint. Without throttling, an attacker could spray leaked passwords against one known admin email, or try `Spring2025!` against every email in a breach corpus from one IP.

We picked **two counters instead of one** because they defend against different threats. An account-only limit lets an attacker keep guessing forever as long as they rotate emails. An IP-only limit lets them keep guessing as long as they rotate IPs (cheap on a botnet). Tripping either is enough to lock the next attempt out.

The progressive delay (the `usleep()` call in `AuthService::attemptLogin`) is a secondary defence: even before the hard lockout fires, each successive failure costs the attacker one more second of wall-clock time, capped at 5 seconds. Invisible to a member who mistyped once, brutal to a script trying 1,000 attempts per minute.

## How it works

All the logic lives in `app/Services/LoginRateLimiter.php`, called from `app/Services/AuthService.php::attemptLogin()`. The flow on every login POST is:

1. `LoginRateLimiter::check($email, null, $ip)` reads `login_attempts` for both the IP row (no email) and the account row (no IP) and returns `['locked' => bool, 'delay' => int]`.
2. If `locked`, `AuthService` short-circuits with `['status' => 'locked']` and `login.php` shows *"Too many attempts. Try again later."*. It also writes `security.login_locked` to the activity log.
3. If not locked, the request sleeps `$delay` seconds (0-5), then checks the password.
4. On success: `recordSuccess()` zeros both rows (`attempts_count = 0`, `locked_until = NULL`).
5. On failure: `recordFailure()` runs `applyAttempt()` twice — once for the account row, once for the IP row.

`applyAttempt()` is the heart of it:

```php
if ($last >= $windowStart) {           // still inside the window
    $attempts = (int) $row['attempts_count'] + 1;
} else {                                // window expired, start fresh
    $attempts = 1;
}
if ($attempts >= $maxAttempts) {
    $lockedUntil = $now->modify('+' . $lockoutMinutes . ' minutes');
}
```

So **N failed attempts within W minutes** sets `locked_until` to `now + L minutes` on that row. After the lockout passes, the next failure just rolls the window forward — there's no escalating ban.

Storage is one row per IP and one row per account in `login_attempts`:

| column | meaning |
|---|---|
| `email`, `user_id` | NULL on the IP row, populated on the account row |
| `ip_address` | always populated |
| `attempts_count` | running count inside the current window |
| `first_attempt_at` / `last_attempt_at` | window start / most recent failure |
| `locked_until` | NULL or future timestamp; lockout is over when `now > locked_until` |

Rows are reused forever — successful logins zero the counter, they don't delete the row. `SELECT email, ip_address, locked_until FROM login_attempts WHERE locked_until > NOW()` is the canonical "who's locked right now" query.

After a lockout fires, `AuthService` also dispatches a `failed_login` security alert via `SecurityAlertService::send()` to the alert email under Settings → Security Alerting. See [22 — Notifications & email](view.php?slug=22-notifications-email).

## Where to change it

Two places:

- **Admin → Settings Hub → Security → Login Security** (`/admin/settings/index.php`, the "Login Security" card around lines 1538-1559). Every knob below is editable here.
- **`app/Services/LoginRateLimiter.php`** if you need to change the *algorithm* (e.g. tier the lockout duration by attempt count, or expire stale rows).

To unlock a stuck user manually, an admin runs one SQL statement in cPanel → phpMyAdmin:

```sql
UPDATE login_attempts
   SET attempts_count = 0, locked_until = NULL
 WHERE email = 'someone@example.com' OR ip_address = '203.0.113.5';
```

There is no admin UI for unlocking — see Gotchas. The user can also just wait `login_lockout_minutes` and try again.

## Settings

All settings live in the `security_settings` table (single row, id=1), wrapped by `App\Services\SecuritySettingsService::get()`. They are *not* in `settings_global` — this is a legacy single-row config table. See [09 — Security headers & policies](view.php?slug=09-security-headers) for the full table.

| key | default | meaning |
|---|---|---|
| `login_ip_max_attempts` | `10` | Failed logins from one IP within the IP window before the IP is locked. `0` disables the IP counter. If both IP and account max are `0`, the entire rate limiter is bypassed. |
| `login_ip_window_minutes` | `10` | Sliding window length for the IP counter. |
| `login_account_max_attempts` | `5` | Failed logins against one email within the account window before the account is locked. `0` disables the account counter. |
| `login_account_window_minutes` | `15` | Sliding window length for the account counter. |
| `login_lockout_minutes` | `30` | How long `locked_until` is set into the future once a counter trips. Minimum applied at write time is `1`. |
| `login_progressive_delay` | `true` | When on, each failed attempt sleeps `min(5, attempts_count)` seconds *before* the password check. |

One related global setting lives in `settings_global`: `advanced.disable_password_reset_rate_limit` (boolean) bypasses *just* the password-reset throttle — useful when bulk-issuing reset links during a migration.


<!-- SCREENSHOT: Settings Hub → Security tab, scrolled to the "Login Security" card. Capture all six fields with defaults visible. Save to public_html/admin/help/images/12-login-security-card.png and uncomment the line below. -->
<!-- ![Login Security settings card](../images/12-login-security-card.png) -->

<!-- SCREENSHOT: /login.php showing the "Too many attempts. Try again later." error after a lockout. Save as 12-login-locked-error.png. -->
<!-- ![Login form showing the lockout error](../images/12-login-locked-error.png) -->

<!-- SCREENSHOT: phpMyAdmin showing a SELECT on login_attempts with one IP row and one account row, locked_until populated. Save as 12-login-attempts-table.png. -->
<!-- ![login_attempts table with a live lockout](../images/12-login-attempts-table.png) -->

## Gotchas

- **The locked-out user gets no helpful UI.** `login.php` shows *"Too many attempts. Try again later."* — no countdown, no email. If a real member is locked they will phone the secretary. The activity-log entry and the security alert email are how *we* find out it happened.
- **`/locked-out/` is a different page.** `public_html/locked-out/index.php` is the "Access Restricted" screen for role denial, set via `$_SESSION['locked_out']`. Nothing to do with rate limiting. Easy to conflate because of the name.
- **Rows are never deleted, only zeroed.** If you want to prune `login_attempts`, write a cron — there is none today. The table stays small in practice (one row per IP).
- **The IP row has `email` and `user_id` NULL on purpose.** `getIpRow()` selects `WHERE ip_address = :ip AND email IS NULL AND user_id IS NULL`. Don't add NOT NULL to either column without rewriting the limiter.
- **Setting `max_attempts` to 0 turns the *whole* limiter off** for that scope. If both IP and account are `0`, `check()` returns early and nothing — counters, progressive delay, lockouts — runs. That's the kill switch.
- **Progressive delay sleeps the PHP-FPM worker.** Five seconds is fine on a quiet site; if you ever see worker starvation under attack, drop the cap in `LoginRateLimiter::check()` from `min(5, $attempts)` to `min(2, $attempts)`.
- **Password reset reuses these numbers.** `login_ip_max_attempts` / `login_ip_window_minutes` also throttle `/member/reset_password.php` against the `password_resets` table. Tightening login tightens reset.
- **NAT eats the IP counter.** Members behind a shared office or carrier NAT all look like one IP. Ten members fat-fingering passwords can lock that whole IP for `login_lockout_minutes`. The account counter is unaffected.
- **No admin "unlock" button exists.** Unlocking is a manual `UPDATE login_attempts ... SET locked_until = NULL` in phpMyAdmin. A small unlock UI under `/admin/security/` would be a worthwhile follow-up.

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — what calls `AuthService::attemptLogin()` and the rest of the login flow.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — second factor that runs after a successful password check.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where `security.login_locked` and `security.login_failed` show up.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the full `security_settings` table and other knobs on the same admin card.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — how the `failed_login` security alert gets delivered.
