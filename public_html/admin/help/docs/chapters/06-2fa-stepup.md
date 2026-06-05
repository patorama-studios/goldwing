# 2FA, step-up & trusted devices

## What this covers

The three layers on top of the password login: TOTP two-factor (with email OTP as a fallback), the step-up gate for sensitive admin actions, and the trusted-device cookie that lets a member skip the second factor on a recognised browser. Services: `TwoFactorService`, `TotpService`, `EmailOtpService`, `StepUpService`, `TrustedDeviceService`. Pages: `/member/2fa_enroll.php`, `/member/2fa_verify.php`, `/stepup.php`. [Chapter 05 — Authentication](view.php?slug=05-authentication) covers the password login — this chapter starts where the password check ends.

## Why it exists

A password alone is one phish from a takeover, and once an attacker has the session cookie they can do anything the legitimate user can — refund themselves money, export the member list, hand out admin roles. Defence in depth, three layers:

- **Login-time 2FA** — a stolen password isn't enough.
- **Step-up** — a hijacked session isn't enough for the dangerous actions, because they re-prompt for password + OTP and the proof expires in minutes.
- **Trusted devices** — the usability counter-weight. A 30-day cookie on the member's own browser keeps the friction proportional to the risk.

Custom-built rather than off-the-shelf because the stack is plain PHP — see [Appendix A — Decision log](view.php?slug=A-decision-log).

## How it works

### TOTP 2FA — enrollment, verification, recovery

Two services. `TotpService` is pure crypto — base32 secrets, otpauth URLs, 6-digit codes per RFC 6238 (HMAC-SHA1, 30s period, ±1-step tolerance). `TwoFactorService` owns the `user_2fa` row and the recovery codes.

**Enrollment** is at `/member/2fa_enroll.php`. A member either opts in or `require_login()` redirects them here because `SecurityPolicyService` says they're required and unenrolled (see [Ch 09](view.php?slug=09-security-headers) for the `twofa_mode` decision flow).

1. `beginEnrollment($userId)` generates a 20-byte base32 secret, encrypts it, writes it to `user_2fa` with `enabled_at = NULL`. Any existing row is reset so abandoned half-enrollments are overwritten cleanly.
2. The page renders a QR (via `api.qrserver.com`) of `otpauth://totp/<issuer>:<email>?secret=<base32>&issuer=<issuer>&period=30&digits=6`, plus the raw secret for manual entry.
3. The member scans, then types the current 6-digit code.
4. `verifyAndEnable()` calls `TotpService::verifyCode()`. On success the row gets `enabled_at = NOW()` and eight one-time **recovery codes** (8 uppercase hex chars) are generated, `password_hash`'d, and stored as JSON in `recovery_codes_json`. The plaintext codes are shown to the member **once** — their only chance to save them.
5. If the user was mid-login (`$_SESSION['auth_pending']` set), `AuthService::completeTwoFactorLogin()` finalises the session.

**Verification at login** is at `/member/2fa_verify.php`. After the password passes, `AuthService` parks the partial login in `$_SESSION['auth_pending']` and redirects here. The page accepts either a 6-digit code (`verifyCode`) or a recovery code (`verifyRecoveryCode`). Recovery codes are consumed one-shot.

**Secrets at rest** are encrypted. `TwoFactorService::encryptSecret()` prefers `EncryptionService::encrypt()` (AES-256-GCM, key in `.env`) and falls back to the legacy `CryptoService` — see [Chapter 10](view.php?slug=10-encryption-secrets).

**Admin overrides** are on the Members admin screen — see [Chapter 20](view.php?slug=20-members-admin). `user_security_overrides.twofa_override` is `DEFAULT`, `REQUIRED` (force on), or `EXEMPT` (force off — break-glass only). "Reset 2FA" calls `TwoFactorService::reset()`, nulling secret, `enabled_at`, and recovery codes in one statement.

**The grace period.** `twofa_grace_days` (default `0`) is measured against `users.created_at` by `AuthService::withinGracePeriod()` — once it closes, the next login redirects to enrollment.

### Email OTP — the alternative second factor

`EmailOtpService` issues a 6-digit code by email when a member doesn't have an authenticator app.

- `issueCode()` generates the code, `password_hash`'s it, stores hash + 10-minute expiry in `email_otp_codes`, dispatches the `security_email_otp` notification.
- Rate-limited: 60-second resend cooldown, max 5 resends per rolling hour, max 5 wrong-code attempts before the row is locked.
- Verified through the same `/member/2fa_verify.php` — when `$_SESSION['auth_pending']['purpose']` is `email_otp` the page switches into email-OTP mode and shows a "Trust this device for 30 days" checkbox.

### Step-up — re-verification for sensitive actions

`StepUpService` proves "the human is still at the keyboard" for actions where session-stealing isn't an acceptable risk. Invoked via the `require_stepup()` helper in `app/bootstrap.php`:

```php
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/...');
```

If the session has no valid step-up token, the helper stashes the URL in `$_SESSION['stepup_redirect']` and redirects to `/stepup.php`. That page asks for password + OTP, calls `AuthService::verifyPassword()` + `TwoFactorService::verifyCode()` / `verifyRecoveryCode()`, and on success calls `StepUpService::issue()` to insert a row into `stepup_tokens` with `expires_at = NOW() + stepup_window_minutes`. The token ID is held on the session as `$_SESSION['stepup_token_id']`. The user bounces back to where they were.

`StepUpService::isValid()` also pins the token to the issuing IP and User-Agent — a stolen session used from a different IP can't ride a still-fresh step-up.

Current step-up consumers (`grep require_stepup`): refunds (`admin/store/order_view.php` — see [Chapter 17](view.php?slug=17-refunds)); member exports / imports / bulk actions (`admin/members/export.php`, `import.php`, `actions.php`); security settings & FIM baseline approval (`admin/settings/index.php`).

If `stepup_enabled` is `false`, `isValid()` short-circuits to `true` and the gate disappears — useful during setup, hostile in production.

### Trusted devices

`TrustedDeviceService` fingerprints a browser by hashing `IP | User-Agent | Accept-Language` and records first-seen/last-seen in `trusted_devices`. It's the *ledger* — the actual skip-2FA cookie is set by `EmailOtpService::trustDevice()` (token `email_otp_trust`, hashed in `email_otp_trust`, 30-day expiry, HttpOnly, Secure on HTTPS, SameSite=Lax).

## Where to change it

- **Admin → Settings → Security & Authentication** — global knobs: 2FA mode, required roles, grace days, step-up enabled, step-up window. Backed by the `security_settings` row.
- **Admin → Members → (member) → 2FA controls** — per-user override and "Reset 2FA". See [Chapter 20](view.php?slug=20-members-admin).
- **`/member/2fa_enroll.php`** — QR provider or enrollment copy.
- **`/stepup.php`** — the step-up prompt.
- **Code-side, to gate a new sensitive action** — `require_stepup($_SERVER['REQUEST_URI'] ?? '/some/fallback');` at the top of the page, after `require_role()`.

## Settings

In the `security_settings` row (id = 1), via `SecuritySettingsService::get()`:

| Key | Default | Purpose |
|---|---|---|
| `enable_2fa` | `true` | Global kill switch |
| `twofa_mode` | `REQUIRED_FOR_ALL` | `DISABLED` / `OPTIONAL` / `REQUIRED_FOR_ALL` / `REQUIRED_FOR_ROLES` |
| `twofa_required_roles` | `[]` | Roles forced when mode is `REQUIRED_FOR_ROLES` |
| `twofa_grace_days` | `0` | Days from `users.created_at` before 2FA is forced |
| `stepup_enabled` | `true` | Whether `require_stepup()` actually gates |
| `stepup_window_minutes` | `10` | How long a step-up token stays valid |

Per-user, in `user_security_overrides.twofa_override`: `DEFAULT` / `REQUIRED` / `EXEMPT`.

Email-OTP rate-limit knobs (`MAX_ATTEMPTS`, `RESEND_DELAY_SECONDS`, `EXPIRY_MINUTES`, `TRUST_DAYS`) are PHP constants in `EmailOtpService` — change in code and redeploy.

## Screenshots

<!-- SCREENSHOT: /member/2fa_enroll.php showing the QR code, the secret text below it, and the "Authenticator code" input. Save to public_html/admin/help/images/06-2fa-enroll.png. -->
<!-- ![2FA enrollment QR](../images/06-2fa-enroll.png) -->

<!-- SCREENSHOT: /stepup.php — the "Confirm your identity" prompt with password + code inputs. Save as 06-stepup-prompt.png. -->
<!-- ![Step-up prompt](../images/06-stepup-prompt.png) -->

<!-- SCREENSHOT: /member/2fa_verify.php in email-OTP mode (purpose=email_otp) showing the "Trust this device for 30 days" checkbox. Save as 06-trusted-device.png. -->
<!-- ![Trust this device toggle](../images/06-trusted-device.png) -->

## Gotchas

- **Lost authenticator + lost recovery codes = admin reset.** No self-service path back in. Admin clicks "Reset 2FA"; user re-enrols on next login. There is intentionally no "email me a bypass".
- **The trusted-device cookie is separate from the session.** Clearing site cookies drops the session but the server-side `email_otp_trust` row persists; if the cookie was synced or restored, trust resumes. To revoke everywhere, `DELETE` the user's rows from `email_otp_trust`.
- **Step-up is per-session, not per-action.** Once you step up, the token is good for `stepup_window_minutes` for *every* gated action — not "one refund per step-up". The IP/UA pinning is the brake.
- **`stepup_tokens` rows don't get cleaned up.** Expired tokens are simply rejected by `expires_at > NOW()`; the table grows monotonically. A purge is a manual `DELETE` or a new cron.
- **TOTP needs accurate server time.** The ±1 window absorbs ~30s of skew; more and every code looks wrong. Symptom: fresh codes "always" fail — check `date` on the server.
- **`TrustedDeviceService` records, it doesn't gate.** It's a ledger of seen browsers; the skip-2FA cookie is `EmailOtpService::trustDevice()`.
- **`require_stepup()` is a no-op when `stepup_enabled` is off.** Toggling that flag silently removes the gate from every refund / export / settings page. Don't flip it for "convenience" in production.

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — the password login that hands off to 2FA
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what `REQUIRED_FOR_ROLES` matches against
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where `security.2fa_enabled`, `security.otp_*` events land
- [09 — Security headers & policies](view.php?slug=09-security-headers) — `SecurityPolicyService` and the rest of `security_settings`
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — `EncryptionService` vs `CryptoService` for the TOTP secret
- [17 — Refunds](view.php?slug=17-refunds) — a step-up consumer
- [20 — Members admin console](view.php?slug=20-members-admin) — per-user 2FA force / exempt / reset
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `security_settings` fits alongside `settings_global`
