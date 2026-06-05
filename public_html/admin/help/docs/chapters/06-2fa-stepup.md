# 2FA, step-up & trusted devices

## For administrators

### What 2FA is

**2FA** stands for **two-factor authentication**. It's the second layer on top of your password — a short code (usually 6 digits) that proves it's really you, not someone who's just guessed or stolen your password.

The code comes from an app on your phone — **Google Authenticator**, **Authy**, **Microsoft Authenticator**, or **1Password** all work. The app shows a new code every 30 seconds. You type the current code into the site when it asks for it.

The reasoning is simple: a password can be phished, leaked, or guessed; an attacker won't *also* have your phone. Even if they have one, they don't have both.

If you don't have an authenticator app handy, the site can also email you a code instead. Same idea, slower delivery.

### What "step-up" means

You log in once in the morning. Hours later — still logged in — you go to issue a refund. Before the refund actually happens, the site re-prompts you for a fresh 2FA code. **That re-prompt is step-up.**

It exists because being "logged in" for hours is not the same as "definitely still the same human at the keyboard". For money-moving actions (refunds, exports of member data, security settings), we want fresh proof that it's really you — proof that's only a few minutes old.

By default, step-up lasts **10 minutes**. Once you complete it, you can do as many gated actions as you like in that window without re-prompting. After it expires, the next sensitive action asks again.

### What "trusted device" means

When you sign in from your own office laptop, you can tick **"Trust this device for 30 days"**. The site remembers that browser and won't ask you for a 2FA code on it again for the next 30 days.

It's the trade-off between safety and friction. Asking for a 2FA code every single time you open the site is annoying; never asking is unsafe. Trusting *your* laptop for 30 days while still demanding 2FA from any unknown browser is a sensible middle.

**Do not** tick trusted device on a shared computer (library, kiosk, a friend's laptop). The cookie stays on that machine.

### Who's allowed to enable or manage 2FA settings

- **Members** can enrol themselves in 2FA from their own profile, and can disable it again from there.
- **Admins** can reset another member's 2FA (used when someone loses their phone) and can change the site-wide rules (e.g. "all admins must have 2FA on").
- **Only Admins** can change site-wide security settings.

### Where to find it

Three different places, depending on what you're doing:

1. **Enrolling yourself** — log in, click your name in the top right → **Profile** → **Two-factor authentication**.
2. **Helping a member** — Admin → **Members** → click the member → **2FA controls** panel (reset, force on, force off).
3. **Site-wide rules** — Admin → **Settings** → **Security & Authentication**.

### How to enrol yourself in 2FA (step by step)

{{tour:member-2fa}}

1. Install an authenticator app on your phone if you don't have one (Google Authenticator and Authy are both free).
2. Go to **Profile → Two-factor authentication**.
3. Click **Enable 2FA**. You'll see a QR code and a row of letters/numbers (the same secret, in two formats).
4. Open the authenticator app, tap **+** or **Add account**, and **scan the QR code**. (Or type the letters/numbers in by hand — same result.)
5. The app now shows a 6-digit code that changes every 30 seconds. Type the current code into the site.
6. The site shows you **eight recovery codes** — long strings like `8KFR-2QXP`. **This is the only time you'll ever see them.** Save them somewhere safe: print them, write them in a notebook, or paste them into a password manager. Do **not** leave them in an email or on the desktop.
7. Click **Done**. From now on, every login asks for the 6-digit code after your password.

The recovery codes are your safety net if you lose your phone. Each one works once.

### How to help a member who's lost their phone

This is the most common 2FA support call. The fix is fast:

1. Go to Admin → **Members** → search for the member → click their row.
2. Scroll to the **2FA controls** panel.
3. Click **Reset 2FA**.
4. Tell the member to log in normally. After the password they'll be sent straight to the enrolment page to set up 2FA again with their new phone.

If the member still has their **recovery codes** but lost the phone, they don't need you at all — they can use a recovery code on the login page and re-enrol themselves. Reset is only when both phone *and* recovery codes are gone.

Every reset is logged with your name, the time, and the member involved.

### How to force 2FA for admins

If you want everyone with an admin role to be required to have 2FA on (recommended), go to Admin → **Settings** → **Security & Authentication**, find **2FA mode**, and set it to one of:

- **Optional** — anyone can enable it, no one's forced.
- **Required for everyone** — every member must have 2FA on, no exceptions.
- **Required for certain roles** — pick the roles below; only those people are forced.

For most clubs, **Required for certain roles** with Admin, Treasurer, and Committee Member ticked is the right setting. Ordinary members aren't forced (it would generate too many support calls), but anyone with the keys to the kingdom is.

There's also a **grace period** (days) — when a member is forced to enable 2FA, they get this many days from when their account was created before the forcing kicks in. Default is **0** (instant).

### What can go wrong

- **Lost phone + lost recovery codes** — the only way back in is for an admin to **Reset 2FA**. There is no "email me a bypass" — that would defeat the point. If *every* admin loses their phone and codes at once, you're in trouble; this is why admins always print their recovery codes.
- **Member says "the code never works"** — almost always the phone's clock is slightly wrong. The 6-digit code is time-based, so a phone running 2 minutes fast or slow will generate codes the server doesn't accept. Tell them to enable automatic time on their phone (Settings → Date & Time → Set automatically) and try again.
- **Step-up window too short** — admins complain they're re-prompted constantly. Bump the **Step-up window** in Settings → Security up from 10 minutes to 15 or 20. Don't go above 30 — at that point you've defeated the purpose.
- **Step-up window too long** — set to several hours, step-up stops protecting anything. Keep it between **5 and 15 minutes**.
- **Trusted-device cookie won't go away** — clearing site cookies in the browser usually clears it, but if the member synced the cookie to other browsers it may come back. To wipe it server-side, ask your developer to remove that user's row from `email_otp_trust`.

### What gets recorded

- Every **enrolment** (when a member turns 2FA on) — logged with their name and the time.
- Every **reset** (admin clearing someone's 2FA) — logged with both names: which admin, which member.
- Every **failed code** — logged. A burst of failures is a useful signal.
- Every **trusted-device** issued, and from which browser.

Search the activity log for `2fa` or `otp` to see all of it. See [Chapter 08 — Activity & audit log](view.php?slug=08-activity-audit).

### Good practice

- **All admins on 2FA, no exceptions.** An admin password without 2FA is the single biggest risk in the system.
- **Print the recovery codes.** Don't store them only in a password manager that lives behind the same password you're protecting. Print them, fold them, put them in the club safe with the bank statements.
- **Step-up window between 5 and 15 minutes.** Long enough not to annoy admins doing a batch of refunds in one sitting, short enough that a walked-away laptop isn't a free pass.
- **Don't trust shared computers.** Trusted-device is for the laptop only you use.
- **Audit "Reset 2FA" entries quarterly.** A spike in resets can mean phishing — someone's locking real members out and trying to fish for a "helpful" admin who'll reset them to a phone the attacker controls. Verify identity before resetting.

### Who to ask if you're stuck

- **A member can't get past the 2FA prompt** — first try resetting their 2FA. If they still can't get in, the password might also be wrong; reset that too.
- **The site is rejecting every code, even brand-new ones** — almost certainly the **server's** clock is drifting (not the member's phone). Ask your developer to check.
- **You want to change the step-up rules, or which actions require step-up** — that's a code change, not a setting. Talk to your developer.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

The three layers on top of the password login: TOTP two-factor (with email OTP as a fallback), the step-up gate for sensitive admin actions, and the trusted-device cookie that lets a member skip the second factor on a recognised browser. Services: `TwoFactorService`, `TotpService`, `EmailOtpService`, `StepUpService`, `TrustedDeviceService`. Pages: `/member/2fa_enroll.php`, `/member/2fa_verify.php`, `/stepup.php`. [Chapter 05 — Authentication](view.php?slug=05-authentication) covers the password login — this chapter starts where the password check ends.

### Why it exists

A password alone is one phish from a takeover, and once an attacker has the session cookie they can do anything the legitimate user can — refund themselves money, export the member list, hand out admin roles. Defence in depth, three layers:

- **Login-time 2FA** — a stolen password isn't enough.
- **Step-up** — a hijacked session isn't enough for the dangerous actions, because they re-prompt for password + OTP and the proof expires in minutes.
- **Trusted devices** — the usability counter-weight. A 30-day cookie on the member's own browser keeps the friction proportional to the risk.

Custom-built rather than off-the-shelf because the stack is plain PHP — see [Appendix A — Decision log](view.php?slug=A-decision-log).

### How it works

#### TOTP 2FA — enrollment, verification, recovery

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

#### Email OTP — the alternative second factor

`EmailOtpService` issues a 6-digit code by email when a member doesn't have an authenticator app.

- `issueCode()` generates the code, `password_hash`'s it, stores hash + 10-minute expiry in `email_otp_codes`, dispatches the `security_email_otp` notification.
- Rate-limited: 60-second resend cooldown, max 5 resends per rolling hour, max 5 wrong-code attempts before the row is locked.
- Verified through the same `/member/2fa_verify.php` — when `$_SESSION['auth_pending']['purpose']` is `email_otp` the page switches into email-OTP mode and shows a "Trust this device for 30 days" checkbox.

#### Step-up — re-verification for sensitive actions

`StepUpService` proves "the human is still at the keyboard" for actions where session-stealing isn't an acceptable risk. Invoked via the `require_stepup()` helper in `app/bootstrap.php`:

```php
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/...');
```

If the session has no valid step-up token, the helper stashes the URL in `$_SESSION['stepup_redirect']` and redirects to `/stepup.php`. That page asks for password + OTP, calls `AuthService::verifyPassword()` + `TwoFactorService::verifyCode()` / `verifyRecoveryCode()`, and on success calls `StepUpService::issue()` to insert a row into `stepup_tokens` with `expires_at = NOW() + stepup_window_minutes`. The token ID is held on the session as `$_SESSION['stepup_token_id']`. The user bounces back to where they were.

`StepUpService::isValid()` also pins the token to the issuing IP and User-Agent — a stolen session used from a different IP can't ride a still-fresh step-up.

Current step-up consumers (`grep require_stepup`): refunds (`admin/store/order_view.php` — see [Chapter 17](view.php?slug=17-refunds)); member exports / imports / bulk actions (`admin/members/export.php`, `import.php`, `actions.php`); security settings & FIM baseline approval (`admin/settings/index.php`).

If `stepup_enabled` is `false`, `isValid()` short-circuits to `true` and the gate disappears — useful during setup, hostile in production.

#### Trusted devices

`TrustedDeviceService` fingerprints a browser by hashing `IP | User-Agent | Accept-Language` and records first-seen/last-seen in `trusted_devices`. It's the *ledger* — the actual skip-2FA cookie is set by `EmailOtpService::trustDevice()` (token `email_otp_trust`, hashed in `email_otp_trust`, 30-day expiry, HttpOnly, Secure on HTTPS, SameSite=Lax).

### Where to change it

- **Admin → Settings → Security & Authentication** — global knobs: 2FA mode, required roles, grace days, step-up enabled, step-up window. Backed by the `security_settings` row.
- **Admin → Members → (member) → 2FA controls** — per-user override and "Reset 2FA". See [Chapter 20](view.php?slug=20-members-admin).
- **`/member/2fa_enroll.php`** — QR provider or enrollment copy.
- **`/stepup.php`** — the step-up prompt.
- **Code-side, to gate a new sensitive action** — `require_stepup($_SERVER['REQUEST_URI'] ?? '/some/fallback');` at the top of the page, after `require_role()`.

### Settings

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

### Gotchas

- **Lost authenticator + lost recovery codes = admin reset.** No self-service path back in. Admin clicks "Reset 2FA"; user re-enrols on next login. There is intentionally no "email me a bypass".
- **The trusted-device cookie is separate from the session.** Clearing site cookies drops the session but the server-side `email_otp_trust` row persists; if the cookie was synced or restored, trust resumes. To revoke everywhere, `DELETE` the user's rows from `email_otp_trust`.
- **Step-up is per-session, not per-action.** Once you step up, the token is good for `stepup_window_minutes` for *every* gated action — not "one refund per step-up". The IP/UA pinning is the brake.
- **`stepup_tokens` rows don't get cleaned up.** Expired tokens are simply rejected by `expires_at > NOW()`; the table grows monotonically. A purge is a manual `DELETE` or a new cron.
- **TOTP needs accurate server time.** The ±1 window absorbs ~30s of skew; more and every code looks wrong. Symptom: fresh codes "always" fail — check `date` on the server.
- **`TrustedDeviceService` records, it doesn't gate.** It's a ledger of seen browsers; the skip-2FA cookie is `EmailOtpService::trustDevice()`.
- **`require_stepup()` is a no-op when `stepup_enabled` is off.** Toggling that flag silently removes the gate from every refund / export / settings page. Don't flip it for "convenience" in production.

</details>

<!-- SCREENSHOT: /member/2fa_enroll.php showing the QR code, the secret text below it, and the "Authenticator code" input. Save to public_html/admin/help/images/06-2fa-enroll.png. -->
<!-- ![2FA enrollment QR](../images/06-2fa-enroll.png) -->

<!-- SCREENSHOT: /stepup.php — the "Confirm your identity" prompt with password + code inputs. Save as 06-stepup-prompt.png. -->
<!-- ![Step-up prompt](../images/06-stepup-prompt.png) -->

<!-- SCREENSHOT: /member/2fa_verify.php in email-OTP mode (purpose=email_otp) showing the "Trust this device for 30 days" checkbox. Save as 06-trusted-device.png. -->
<!-- ![Trust this device toggle](../images/06-trusted-device.png) -->

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — the password login that hands off to 2FA
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what `REQUIRED_FOR_ROLES` matches against
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where `security.2fa_enabled`, `security.otp_*` events land
- [09 — Security headers & policies](view.php?slug=09-security-headers) — `SecurityPolicyService` and the rest of `security_settings`
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — `EncryptionService` vs `CryptoService` for the TOTP secret
- [17 — Refunds](view.php?slug=17-refunds) — a step-up consumer
- [20 — Members admin console](view.php?slug=20-members-admin) — per-user 2FA force / exempt / reset
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `security_settings` fits alongside `settings_global`
