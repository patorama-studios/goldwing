# Settings architecture

## What this covers

How site configuration is stored, read, written, audited, and gated by permission. This is the foundational chapter for the Settings Hub — it covers the two database tables, the `SettingsService` API, the audit trail, the seed-on-first-visit pattern, the legacy migration that pulled the old `store_settings` and `settings_payments` rows into the new model, and the per-section role gates. The actual list of keys per section (what each toggle does) lives in [Chapter 32 — Settings by section](view.php?slug=32-settings-by-section).

## Why it exists

Before the Settings Hub there were a dozen one-off tables (`store_settings`, `settings_payments`, hard-coded values in `config/app.php`). Every new toggle meant a new `ALTER TABLE`, a new form handler, a new audit hook. That doesn't scale.

The new model is two tables and one service class. Adding a setting is "pick a category, pick a key, store any JSON value." No schema migration, no service class per feature:

- **One write path** — `SettingsService::setGlobal()` — every change audited the same way.
- **One read path** — `SettingsService::getGlobal()` — request-scoped cache so a page can read fifty keys without fifty queries.
- **Schemaless values** — anything `json_encode`-able works: scalars, arrays, the membership pricing matrix, the notification catalog, feature flag maps.
- **Permission per section** — sidebar filters itself, form handlers re-check; a `store_manager` can edit store settings but not Stripe keys.

## How it works

### The two tables

Both are created by `database/settings_hub.sql`. The shapes:

```sql
settings_global (id, category, key_name, value_json, updated_by_user_id, updated_at)
  UNIQUE (category, key_name)

settings_user   (id, user_id, key_name, value_json, updated_at)
  UNIQUE (user_id, key_name)

audit_log       (id, actor_user_id, action, entity_type, entity_id,
                 diff_json, ip_address, user_agent, created_at)
```

- **`settings_global`** is site-wide. The key is two parts joined by `.` — `category` (e.g. `site`, `payments`, `security`) and `key_name` (e.g. `timezone`, `stripe.secret_key`, `force_https`). `(category, key_name)` is unique.
- **`settings_user`** is per-user preferences (email opt-outs, dashboard layout). No category — the key is flat.
- **`audit_log`** is the diff trail. Every `setGlobal`/`setUser` writes a row with the before/after JSON. (Separate from the older `audit_logs` plural — see Gotchas.)

### Why JSON values

Storing everything as `value_json` means no migration when a setting changes shape. A boolean is `true`. A list of admin emails is `["a@x", "b@y"]`. The membership pricing matrix is a nested object with currency + rows + tier prices. The notification catalog is an object keyed by event code. All of it round-trips through `json_encode` / `json_decode`.

The trade-off is no SQL-level type checking. `SettingsService::getGlobal($key, $default)` mitigates that — pass the right shape as the default and PHP lands somewhere sensible if the row is missing or malformed.

### Reading

```php
$tz = SettingsService::getGlobal('site.timezone', 'Australia/Sydney');
$forceHttps = SettingsService::getGlobal('security.force_https', false);
$prefs = SettingsService::getUser($userId, 'dashboard_layout', []);
```

The first `getGlobal()` call loads the entire `settings_global` table into a static cache (`SettingsService::$globalCache`), keyed by category → key_name → row. Every subsequent call in the same request is an array lookup — that's why `app/bootstrap.php` can read `site.timezone`, `security.force_https`, and `advanced.maintenance_mode` on every request without thinking about it.

`getUser()` does *not* batch — one prepared query per call. For a whole category at once: `SettingsService::getGlobalCategory('payments')` returns the array for that category.

### Writing

```php
SettingsService::setGlobal($actorUserId, 'site.timezone', 'Australia/Brisbane');
SettingsService::setGlobal($actorUserId, 'payments.stripe.secret_key', $sk, ['encrypt' => true]);
SettingsService::setUser($userId, 'dashboard_layout', ['cards' => [...]]);
```

`setGlobal()` UPSERTs by `(category, key_name)`: updates if the encoded value differs, inserts if missing, no-ops if identical. Every actual change writes an `audit_log` row via `SettingsService::writeAudit()` and invalidates the static cache.

The optional `['encrypt' => true]` flag wraps the payload in `{ "encrypted": true, "value": "<ciphertext>" }`. `getGlobal()` recognises that shape and transparently decrypts via `CryptoService::decrypt()` — see [Chapter 10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets).

### The audit row

Every write produces a row like:

```
actor_user_id: 1
action:        settings.update   (or settings.create)
entity_type:   settings_global   (or settings_user)
entity_id:     <row id>
diff_json:     { "before": <decoded JSON or null>, "after": <decoded JSON> }
ip_address:    request IP
user_agent:    request UA
created_at:    NOW()
```

For encrypted keys the diff still records the *encrypted envelope* — not the plaintext — so an audit dump never leaks a live Stripe secret.

### Seed-on-first-visit

The Settings Hub landing page (`public_html/admin/settings/index.php`) calls, in this order:

```php
SettingsService::migrateLegacy((int) $user['id']);
SettingsService::ensureDefaults((int) $user['id']);
```

`migrateLegacy()` reads the old `store_settings` and `settings_payments` rows (and a couple of `config/app.php` values) and writes them into `settings_global` *only if the target row doesn't already exist* — safe to run every visit. The Stripe secret + webhook secret get the `encrypt` flag on the way through. Cutover from the old tables happens the first time anyone opens the hub after deploying `settings_hub.sql`.

`ensureDefaults()` walks the `SettingsService::defaults()` array (~80 keys across `site`, `store`, `payments`, `notifications`, `accounts`, `security`, `integrations`, `media`, `events`, `membership`, `advanced`) and inserts any missing key.

### The Settings Hub UI

`/admin/settings/index.php` switches on `?section=<name>`. The sidebar (`app/Views/partials/backend_admin_sidebar.php`, the `$settingsChildren` array) defines the section list and the permission required to see each entry:

| Section | URL | Permission |
|---|---|---|
| Settings Hub (landing) | `/admin/settings/index.php` | `admin.settings.general.manage` |
| Site | `?section=site` | `admin.settings.general.manage` |
| Store | `?section=store` | `admin.store.view` |
| Payments (Stripe) | `?section=payments` | `admin.payments.view` |
| Notifications | `?section=notifications` | `admin.settings.general.manage` |
| Accounts & Roles | `?section=accounts` | `admin.users.view` |
| Security & Authentication | `?section=security` | `admin.settings.general.manage` |
| Integrations | `?section=integrations` | `admin.integrations.manage` |
| Media & Files | `?section=media` | `admin.media_library.manage` |
| Events | `?section=events` | `admin.events.manage` |
| AI Settings | `/admin/settings/ai.php` | `admin.settings.general.manage` |
| Membership Pricing | `?section=membership_pricing` | `admin.membership_types.manage` |
| Audit Log | `?section=audit` | `admin.logs.view` |
| Advanced / Developer | `?section=advanced` | `admin.settings.general.manage` |
| Admin Role Builder | `/admin/settings/roles.php` | `admin.roles.view` |
| Access Control | `/admin/settings/access-control.php` | `admin.roles.manage` |

Permissions are checked twice: the sidebar hides entries the user can't see (`current_admin_can($perm)`), and each save handler re-checks before writing. See [Chapter 07 — Roles & permissions](view.php?slug=07-roles-permissions) for how the permission strings resolve.

### Encrypted vs plain

Most settings are stored plain — they're booleans, URLs, email addresses, JSON config. Only true secrets get the `encrypt` flag:

- `payments.stripe.secret_key`
- `payments.stripe.webhook_secret`
- `payments.stripe.test_secret_key`, `payments.stripe.live_secret_key` (when mode-split is on)
- `integrations.smtp_password`
- `integrations.resend_api_key`
- AI provider API keys (managed via `AiProviderKeyService`, same envelope)

Everything else — including `payments.stripe.publishable_key` — is plaintext, because publishable keys are designed to ship to the browser anyway.

### Adding a new setting key

Three steps, no SQL:

1. Pick a `category.key_name`. Reuse an existing category if it fits; only invent a new one if you're adding a whole subsystem.
2. Read it where you need it: `SettingsService::getGlobal('mycat.my_key', $sensibleDefault)`.
3. Write it from a form handler: `SettingsService::setGlobal((int) $user['id'], 'mycat.my_key', $value);` — add `['encrypt' => true]` if it's a secret.

Optionally add it to `SettingsService::defaults()` so `ensureDefaults()` seeds it on the next admin visit. If the form lives in the Settings Hub, also wire it into the relevant section template under `public_html/admin/settings/` and (if it's a new section) the `$settingsChildren` array.

## Where to change it

- `app/Services/SettingsService.php` — the entire API. Get/set, defaults, legacy migration, encrypt flag, audit hook.
- `database/settings_hub.sql` — the table definitions. Edit only to add indexes; don't add typed columns (that's what `value_json` exists to avoid).
- `app/Views/partials/backend_admin_sidebar.php` — the `$settingsChildren` array (per-section permissions and labels).
- `public_html/admin/settings/index.php` — the section dispatcher, the seed-on-visit calls, the form save handlers.
- `app/Services/CryptoService.php` — how the `encrypt: true` flag actually encrypts (see [Chapter 10](view.php?slug=10-encryption-secrets)).

## Settings

This chapter doesn't define settings — it defines how settings *work*. For the actual list of every key in `settings_global`, what each one does, and where in the UI it's edited, see [Chapter 32 — Settings by section](view.php?slug=32-settings-by-section).


<!-- SCREENSHOT: The Settings Hub landing page at /admin/settings/index.php showing the section tiles. Capture on draft.goldwing.org.au logged in as a full admin so all sections are visible. Save to public_html/admin/help/images/31-settings-hub-landing.png and uncomment below. -->
<!-- ![Settings Hub landing](../images/31-settings-hub-landing.png) -->

<!-- SCREENSHOT: The Audit section at /admin/settings/index.php?section=audit, with the diff view expanded on a single settings.update row so the before/after JSON is visible. Save as 31-settings-audit-diff.png. -->
<!-- ![Settings audit diff](../images/31-settings-audit-diff.png) -->

## Gotchas

- **`audit_log` vs `audit_logs` are two different tables.** `SettingsService::writeAudit()` writes to `audit_log` (the diff-based one in `settings_hub.sql`). The older `AuditService::log()` writes to `audit_logs` with a free-text `details` column. New code should prefer the structured one. See [Chapter 08](view.php?slug=08-activity-audit).
- **The static cache is per-request.** Two PHP-FPM workers won't see each other's writes until each worker's next request reloads. Rarely a problem, but don't assume freshness across workers.
- **`getGlobal()` returns `$default` if `json_decode` fails.** A malformed `value_json` row is silently treated as missing. If a feature flag looks "off," check the row for garbled JSON.
- **`splitKey()` defaults the category to `general` if you forget the dot.** `getGlobal('something')` looks up `general.something`. Always namespace your keys.
- **`migrateLegacy()` never overwrites.** It only fills missing rows. If the new row is wrong, edit it in the Hub — don't try to re-import.
- **Adding to `defaults()` doesn't backfill immediately.** A user has to open the Settings Hub once. If a cron needs a new default before any admin logs in post-deploy, set it in migration SQL.
- **The `encrypt` flag is per-write, not per-key.** Calling `setGlobal()` on a secret without `['encrypt' => true]` writes plaintext. Always use the Settings Hub form rather than ad-hoc SQL.

## Related chapters

- [32 — Settings by section](view.php?slug=32-settings-by-section) — every key in every section, what it controls, what it defaults to.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — how `current_admin_can('admin.settings.general.manage')` resolves.
- [08 — Activity & audit log](view.php?slug=08-activity-audit) — the two audit tables and when to use which.
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — how `['encrypt' => true]` actually protects secret values.
- [13 — Stripe integration overview](view.php?slug=13-stripe-overview) — the biggest consumer of encrypted settings.
- [14 — Membership pricing matrix](view.php?slug=14-membership-pricing) — the canonical example of a complex JSON value living in `settings_global`.
