# Encryption & secrets at rest

## For administrators

### What this is

A handful of very sensitive things — our **Stripe secret key**, our **SMTP password**, every admin's **2FA secret seed**, our **AI provider keys** — are stored in the database in a scrambled form. Even someone who got their hands on a full copy of the database (a backup, a stolen dump, a curious hosting tech) couldn't read them.

The scrambling depends on a special key called **`APP_KEY`**. It lives in a single configuration file on the server, *not* in the database. That separation is the whole point — the database holds the locked box, the server holds the key.

### Why this matters to you

If `APP_KEY` is lost or changed, every scrambled value becomes unreadable garbage. There is no recovery. The Stripe key, the webhook secret, the SMTP password, and every admin's 2FA seed would all have to be rotated and re-entered from scratch. So the rule is simple: **we do not lose or touch `APP_KEY`**.

### What's encrypted

- The **Stripe secret key** and the **Stripe webhook signing secret**
- The **SMTP password** (the password used to send our outbound emails)
- Every admin's **2FA TOTP secret** (the seed behind the 6-digit codes in their authenticator app)
- All **AI provider keys** (OpenAI, kie.ai, etc.)

### What's NOT encrypted

Regular operational data is stored as plain text — encryption is for secrets, not for everyday data.

- Member names, email addresses, phone numbers
- Order line items, refund amounts, product details
- Chapter info, ride records, event details
- Activity log entries

This is deliberate. Encrypting everything would make searching, reporting, and exports impossible.

### What admins should NEVER touch

- **The `APP_KEY` value** in the server's `.env` file. Don't change it, don't delete it, don't "tidy it up". If you've never heard of `.env`, you're already in the safe zone.
- The encrypted rows in the database directly. Anything with a column name ending in `_encrypted` should only be written through the admin forms, never edited by hand.

### What you might see

- **Viewing settings** — the Stripe secret key, SMTP password, and AI keys all show as masked dots (`••••••••`) once set. You can't read what's currently in there from the UI by design.
- **Entering a new value** — when you type in a new Stripe key (for example) and save, the site scrambles it before writing it to the database. The browser never receives the saved value back in plain text.
- **A "Configured — leave blank to keep" hint** — if you don't want to change a secret, just leave that field empty when saving the form. The existing value stays.

### When to involve a developer

- **Rotating Stripe keys** — generating a new pair in Stripe and swapping them in cleanly.
- **Rotating SMTP credentials** — same idea, with the email provider.
- **You need to read what's currently stored.** The UI deliberately won't show you.
- **Standing up a new server or staging copy.** New environments need their own `APP_KEY` set carefully.

### Good practice

- **Don't email yourself Stripe keys** or paste them into Slack. Type them straight into the admin form from the Stripe dashboard.
- **Rotate Stripe keys annually** as a hygiene step, with a developer's help.
- **If `APP_KEY` ever changes unexpectedly** — flag it immediately. Symptoms: SMTP suddenly failing, admins asked to re-enrol in 2FA, "Stripe key not configured" errors after no settings change. Every encrypted secret has to be rotated.
- **Keep a copy of `APP_KEY` somewhere safe** (1Password, sealed envelope in the treasurer's safe) so a server rebuild doesn't lock us out.

### Who to ask if you're stuck

- **"Is encryption working right now?"** — your developer can check in seconds.
- **"I think I broke `APP_KEY`"** — stop, don't make more changes, call your developer.
- **"I need to rotate the Stripe keys"** — Treasurer (who holds the Stripe login) plus your developer, together.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

How the site keeps the secrets it has to remember — Stripe API keys, the Stripe webhook signing secret, SMTP passwords, 2FA TOTP seeds, AI provider keys, the email-preferences unsubscribe token — out of plain sight in the database. It covers the env var everything pivots on (`APP_KEY`), the two encryption services (`CryptoService` and `EncryptionService`), which fields each one wraps, what happens if `APP_KEY` is lost or changed, and how to spot-check that encryption is on.

### Why it exists

A cPanel-managed MySQL database is shared infrastructure. The cPanel operator, backup tooling, anyone who steals a `mysqldump`, and any bug that renders a row to the page all see column values in the clear unless we encrypt before insert. A stolen snapshot should not ship working Stripe live keys, SMTP credentials, or TOTP seeds for every admin.

Why two services? `CryptoService` is the original — AES-256-CBC, no auth tag — wired into the oldest call sites (Stripe payment settings, the `settings_global` encrypted wrapper, the email-preferences token, the legacy TOTP fallback). `EncryptionService` is newer — AES-256-GCM with an auth tag — added when we needed tamper-evident storage for AI provider keys and fresh 2FA secrets. Both read the same `APP_KEY`.

### How it works

#### The key

Everything hinges on `APP_KEY`, set in `.env` (or `.env.local`) on each environment. `app/bootstrap.php` loads it via `App\Services\Env::load()` (`putenv` + `$_ENV`), and `config/app.php` exposes it as `config('app_key')` (`'app_key' => getenv('APP_KEY') ?: ''`). Both services pull from `config('app_key')` first and fall back to `Env::get('APP_KEY')`. If the value is missing, `CryptoService` silently returns `null`/`''`; `EncryptionService::encrypt` throws `RuntimeException`.

#### CryptoService — AES-256-CBC

`app/Services/CryptoService.php`, the legacy primitive:

- Cipher: `AES-256-CBC`.
- Key: `hash('sha256', APP_KEY, true)` — 32 bytes.
- IV: 16 random bytes per encrypt, prepended to ciphertext. Output `base64(iv . ciphertext)`.
- No auth tag — a corrupted payload decrypts to garbage rather than failing loudly.

#### EncryptionService — AES-256-GCM

`app/Services/EncryptionService.php`, the newer primitive:

- Cipher: `aes-256-gcm`.
- Key: if `APP_KEY` is a 64-char hex string, `hex2bin()` it; otherwise use raw. Truncated to 32 bytes.
- IV: 12 random bytes. Tag: 16-byte GCM auth tag in the payload. Output `base64(iv . tag . ciphertext)`.
- `EncryptionService::isReady()` returns true only when a usable key is configured — call sites use this to refuse to save secrets when the key is missing (see `public_html/admin/settings/ai.php`).

#### What gets encrypted, and with which service

| Secret | Stored in | Service |
|---|---|---|
| Stripe secret key (`payments.stripe.secret_key`) | `settings_payments.secret_key_encrypted` | CryptoService |
| Stripe webhook signing secret (`payments.stripe.webhook_secret`) | `settings_payments.webhook_secret_encrypted` | CryptoService |
| SMTP password (`integrations.smtp_password`) | `settings_global` row, `{"encrypted": true, "value": "…"}` wrapper | CryptoService (via `SettingsService::setGlobal(..., ['encrypt' => true])`) |
| Email-preferences unsubscribe tokens | URL payload (never stored — round-tripped through the URL) | CryptoService |
| 2FA TOTP seeds | `users_2fa.totp_secret_encrypted` | EncryptionService preferred, CryptoService fallback |
| AI provider keys (OpenAI, kie.ai, etc.) | `ai_provider_keys.api_key_encrypted` | EncryptionService (refuses to save if not ready) |

The TwoFactorService dual path is in `app/Services/TwoFactorService.php`:

```php
private static function encryptSecret(string $secret): string {
    if (EncryptionService::isReady()) return EncryptionService::encrypt($secret);
    return CryptoService::encrypt($secret) ?? '';
}
```

Decrypt mirrors it. An `APP_KEY` stripped of its hex format (or never set) downgrades new 2FA storage to the older cipher rather than losing data.

#### The `settings_global` encrypted wrapper

`SettingsService::encodeValue()` / `decodeValue()` wrap any setting marked `['encrypt' => true]` as `{ "encrypted": true, "value": "<base64 ciphertext from CryptoService>" }`. That's the mechanism behind `integrations.smtp_password` — the row in `settings_global` carries the flag, and reads round-trip through `CryptoService::decrypt()`.

### Where to change it

- **Set or rotate `APP_KEY`** — edit `.env` on the server (cPanel File Manager or SSH). Never commit `.env`. A safe value is 64 hex chars (`openssl rand -hex 32`).
- **Add a new encrypted setting** — `SettingsService::setGlobal($actor, 'category.key', $plaintext, ['encrypt' => true])`. Reads through `SettingsService::getGlobal()` decrypt transparently.
- **Add a new encrypted column** — store `EncryptionService::encrypt($plain)`, read with `EncryptionService::decrypt($row['col'])`. Guard writes with `EncryptionService::isReady()` so the UI refuses to save when misconfigured.
- **Picking a service** — new code prefers `EncryptionService` (GCM, tamper-evident). Use `CryptoService` only when writing into a column an existing CBC reader will load.

### Settings

No settings page of its own. It touches:

- `APP_KEY` — env var in `.env` / `.env.local`. The only secret the DB can't store its own decrypt key for.
- `payments.stripe.secret_key`, `payments.stripe.webhook_secret` — see [Chapter 13](view.php?slug=13-stripe-overview).
- `integrations.smtp_password` — `/admin/settings/index.php` under Integrations.
- AI provider keys — `/admin/settings/ai.php`. Save is disabled when `EncryptionService::isReady()` is false.

### Gotchas

- **Lose `APP_KEY`, lose every secret.** No recovery. Stripe keys, webhook secret, SMTP password, every TOTP seed become unrecoverable ciphertext. Back up `.env` somewhere safe (1Password) the moment it's set.
- **Changing `APP_KEY` does NOT re-encrypt existing rows.** A new key means old ciphertext decrypts to garbage. To rotate: with the old key in place, dump plaintexts (Stripe via `PaymentSettingsService`, SMTP and AI keys via their admin forms); rotate `APP_KEY`; re-enter each secret. 2FA seeds can't be dumped — every admin re-enrolls.
- **`CryptoService::decrypt()` returns `''` on failure**, not `null`. "Empty Stripe secret key" and "wrong APP_KEY" look identical from the calling code. Check `EncryptionService::isReady()` or look at the underlying `*_encrypted` column.
- **GCM rejects tampered payloads; CBC does not.** A bit-flipped CryptoService payload silently decrypts to junk; EncryptionService returns `null`. Empty decrypts on `users_2fa.totp_secret_encrypted` usually mean the row was written under a different `APP_KEY`.
- **`.env` must stay outside the web root.** It lives at the project root, above `public_html/` — moving it inside would void the entire scheme.
- **`APP_KEY=` with no value silently disables encryption.** `Env::load()` strips quotes but doesn't validate length. Verify with `php -r "require 'app/bootstrap.php'; var_dump(App\Services\EncryptionService::isReady());"`.

</details>

<!-- SCREENSHOT: AI settings page at /admin/settings/ai.php showing the "encryption not ready" warning when APP_KEY is unset. Capture on a throwaway env with APP_KEY removed. Save to public_html/admin/help/images/10-ai-encryption-warning.png and uncomment below. -->
<!-- ![AI encryption-not-ready warning](../images/10-ai-encryption-warning.png) -->

<!-- SCREENSHOT: The Integrations section of /admin/settings/index.php with the SMTP password field showing the green "Configured - leave blank to keep" placeholder (proves the masked-secret read works). Save as 10-smtp-masked.png. -->
<!-- ![SMTP masked secret](../images/10-smtp-masked.png) -->

## Related chapters

- [04 — Configuration & environment](view.php?slug=04-configuration) — how `.env` is loaded and where `config('app_key')` comes from.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — the biggest consumer of `EncryptionService`.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the other half of Part 3 — Security.
- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — writer of the encrypted `*_encrypted` columns on `settings_payments`.
- [22 — Notifications & email](view.php?slug=22-notifications-email) — `EmailService` reads `integrations.smtp_password` through the encrypted wrapper.
