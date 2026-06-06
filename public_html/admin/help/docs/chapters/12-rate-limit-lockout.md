# Login rate limiting & lockout

## For administrators

### What this is

If someone (or a bot) keeps failing the login form, we lock them out for a while. After too many wrong passwords in a short window, the site simply refuses the next attempt and shows *"Too many attempts. Try again later."* — no countdown, no email, just a door that won't open.

We track two separate things on every failed login: the **IP address** the attempt came from, and the **email** the attempt was made against. Either one can trigger a lockout on its own.

### What it does for you

- **Stops password-guessing attacks.** Someone trying `Spring2025!` against every email they've ever seen gets cut off after a handful of tries.
- **Protects individual member accounts.** Even if an attacker rotates IPs, hammering one email locks that email out.
- **Slows attackers down even before the lockout fires.** Each failed attempt adds a small delay (up to 5 seconds) — invisible to a real member who mistyped once, painful for a script trying thousands per minute.

### Who's allowed to change settings

**Admin only.** Anyone else won't see the Login Security card in Settings Hub.

### Where to find it in admin

![Security & Authentication — login rate limit settings](images/09-security-settings.png)

Admin → Settings → Security & Authentication → **Login Security** card.

{{link:/admin/settings/?section=security|Take me to Security & Authentication}}

The Security Log (Admin → Security Log) is where you go to *see* lockouts happening — search for an email or look for `security.login_locked` and `security.login_failed` entries.

{{link:/admin/security/activity_log.php|Take me to the Security Log}}

### The settings, explained plainly

There are six knobs. The defaults are sensible — don't change them without a reason.

#### Max attempts per IP (default: 10)

An **IP address** is the rough "street address" of whatever internet connection a person is using. Every device on your home wifi shares one. A whole office on the same router shares one. Mobile networks sometimes share one across thousands of phones.

We track failed logins by IP because most attackers come from a single computer or server — limiting per-IP is one of the cheapest defences. `10` failed attempts inside the IP window will lock that IP. Set this to `0` to turn the IP counter off entirely (you probably never want to).

#### IP window minutes (default: 10)

The sliding window in which those 10 attempts count. After 10 minutes of no failures, the counter resets to zero. So "10 failures in 10 minutes" is the trigger; 10 failures spread over an hour is not.

#### Max attempts per account (default: 5)

The same idea but applied to a specific email address. `5` failed logins against `someone@example.com` inside the account window locks that account regardless of which IP they came from. This is the one that defends against a botnet attack on a known admin email.

#### Account window minutes (default: 15)

The sliding window for the account counter. Same logic as the IP window, longer fuse.

#### Lockout duration (default: 30 minutes)

Once either counter trips, how long the lockout lasts. After the timer is up, the next attempt is allowed; if it fails too, the window simply rolls forward — there's no escalating "second offence" ban.

#### Progressive delay (default: ON)

When this is on, each failed attempt makes the site pause for one extra second before checking the password (capped at 5 seconds). A real person who mistyped once won't notice. A script trying to guess passwords at speed grinds to a crawl.

### "A member says they can't log in" — diagnosis flow

This is the support call you'll get. Work through these steps in order.

{{link:/admin/security/activity_log.php|Take me to the Security Log}}
{{link:/admin/members/|Take me to the Members list}}

1. **Ask them when they last tried.** If it was less than 30 minutes ago and they've been bashing the password in repeatedly, they're almost certainly in a lockout. Even if not, the timing helps the Security Log search.

2. **Check whether they're locked out.** Admin → Security Log. Search for their email. Look for recent `security.login_locked` or a string of `security.login_failed` entries. If you see a lockout in the last 30 minutes, that's the answer.

3. **If locked out, two options:**
   - **Wait it out.** The lockout is 30 minutes by default. Tell them to try again after that. This is the right answer 90% of the time.
   - **Clear the lockout manually.** There is *no admin button* for this — it has to be done with a SQL statement in phpMyAdmin. **Flag this to your developer** unless you're comfortable in phpMyAdmin yourself (the dev notes below have the exact query).

4. **If not locked out, check for 2FA issues.** They may be entering the right password but failing on the second-factor code. See [Chapter 06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) for how to reset their 2FA.

5. **If still failing, reset their password.** They may genuinely have forgotten it. See [Chapter 20 — Member self-service & password reset](view.php?slug=20-member-self-service) — you can trigger a reset link from their member profile.

### What can go wrong

- **A legit member gets locked out repeatedly.** Usually a typo, a saved-but-wrong password in a browser/password manager, or they're confusing their member account with another login. Walk them through clearing the saved password and trying once carefully.
- **A whole office or shared wifi gets locked out.** Multiple members on the same IP all failing once each can add up to the IP limit. Annoying but rare; wait it out or unlock the IP manually.
- **The lockout is too aggressive.** If you keep getting legit-member lockouts, the limits may be too tight for how your members actually log in. Talk to your developer before changing them — these defaults are deliberately conservative.
- **An admin locks themselves out.** It happens. Same fix as any member — wait it out, or another admin clears the row manually. If *every* admin is locked out at once, you need developer (or hosting) access to run the SQL directly.
- **Turning a counter off entirely.** Setting max attempts to `0` *disables* that counter — it doesn't mean "lock immediately". Setting both to `0` turns the whole rate limiter off. Don't do that.

### What gets recorded

- **Every failed login attempt** writes to the `login_attempts` table (per IP and per email).
- **Every lockout that fires** writes `security.login_locked` to the activity log and dispatches a `failed_login` security alert email to the address configured under Settings → Security Alerting.
- **Every successful login** resets that user's counters to zero.

If you ever need to ask "was this account being attacked last week?", the Security Log has the answer.

### Good practice

- **Don't lower the limits without a reason.** The defaults (10 per IP / 5 per account / 30-min lockout) are tuned to block attacks while almost never catching a real member. Tightening them produces more support calls; loosening them weakens the defence.
- **If you keep getting legit lockouts, look at the cause first.** Are members sharing a device with a wrong saved password? Are they typing email + password from a club roster that has typos in it? Fix the cause before changing the numbers.
- **Watch the security alert emails.** A spike of `failed_login` alerts against admin emails is a real attack signal — change passwords, enable 2FA on every admin, and tell your developer.
- **Remember password reset is throttled by the same numbers.** Tightening login tightens the reset form too. See Chapter 20.

### Who to ask if you're stuck

- **Clearing a lockout manually** — your developer. It's a one-line SQL statement but it has to be run in phpMyAdmin and there's no admin button for it.
- **A suspected attack in progress** — your developer, immediately. The security alert emails should already be firing; they may want to change settings or block an IP at the hosting level.
- **Settings questions** — same developer; the defaults are good and changing them has knock-on effects.

---

<details>
<summary><strong>Dev notes</strong></summary>

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

</details>

<!-- SCREENSHOT: Settings Hub → Security tab, scrolled to the "Login Security" card. Capture all six fields with defaults visible. Save to public_html/admin/help/images/12-login-security-card.png and uncomment the line below. -->
<!-- ![Login Security settings card](../images/12-login-security-card.png) -->

<!-- SCREENSHOT: /login.php showing the "Too many attempts. Try again later." error after a lockout. Save as 12-login-locked-error.png. -->
<!-- ![Login form showing the lockout error](../images/12-login-locked-error.png) -->

<!-- SCREENSHOT: phpMyAdmin showing a SELECT on login_attempts with one IP row and one account row, locked_until populated. Save as 12-login-attempts-table.png. -->
<!-- ![login_attempts table with a live lockout](../images/12-login-attempts-table.png) -->

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — what calls `AuthService::attemptLogin()` and the rest of the login flow.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — second factor that runs after a successful password check.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where `security.login_locked` and `security.login_failed` show up.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the full `security_settings` table and other knobs on the same admin card.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — how the `failed_login` security alert gets delivered.
