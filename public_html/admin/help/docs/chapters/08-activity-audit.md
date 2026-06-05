# Activity & audit log

## What this covers

The site keeps two append-only history tables — `activity_log` for *things admins did to members* (and security events the system noticed), and `audit_log` for *settings changes*. They look alike (actor, action, timestamp, IP, UA, JSON blob) but they're written by different services and answer different questions. This chapter explains which one to use, where each surfaces, and what the data looks like.

## Why it exists

- **Regulatory accountability.** The association handles members' personal details, payment metadata, and refunds. When somebody asks "who saw this record? who changed that price?" we need a timestamp and an actor.
- **Blame-free post-mortems.** When a refund goes to the wrong card or a role gets added by mistake, the log tells us *what* happened before we work out *why*.
- **RBAC verification.** [Chapter 07 — Roles & permissions](view.php?slug=07-roles-permissions) is only meaningful if we can prove the permission gates actually fired. `activity_log` is the receipt — step-up grants, role assignments, FIM baseline approvals, security-settings edits.
- **Detection.** A handful of entries double as triggers for `SecurityAlertService` — see below.

## How it works

### Two tables, two writers

| Concern | Table | Writer service | Read API |
|---|---|---|---|
| "An admin (or system) did X *to* a member or security subsystem" | `activity_log` | `App\Services\ActivityLogger` | `App\Services\ActivityRepository` + the security-log page |
| "Someone changed a setting / approved an application / edited a page" | `audit_log` | `App\Services\SettingsService` (auto), `App\Services\AuditService` (manual)\* | Direct `SELECT` on the settings audit view |

\* Name collision warning. `AuditService::log()` writes into `audit_logs` (plural, legacy schema in `database/schema.sql`) for older actions (chapter assignments, application approvals, notice CRUD, Page Builder saves). `SettingsService::writeAudit()` writes into `audit_log` (singular, modern schema from `database/settings_hub.sql`) with `entity_type` / `entity_id` / `diff_json` columns. The Settings audit UI reads only the singular `audit_log`.

### `activity_log` schema

From `database/members_module.sql`:

```
id              INT PK
actor_type      ENUM('admin','member','system')
actor_id        INT NULL   -> users.id
member_id       INT NULL   -> members.id (the *target*)
action          VARCHAR(100)   e.g. 'refund.processed', 'security.login_failed'
target_type     VARCHAR(50)  NULL   (added by 2025_01_20_security migration)
target_id       INT          NULL
metadata        JSON NULL
ip_address      VARCHAR(45) NULL
user_agent      VARCHAR(255) NULL
created_at      DATETIME
```

`ActivityLogger::log($actorType, $actorId, $memberId, $action, $metadata = [])` is the single entry point. The action string is a dotted namespace — `security.*`, `refund.*`, `email.*`, `membership.*`, `notification.*`, `admin_role.*` — so you can filter a whole subsystem with `LIKE`. Metadata is freeform JSON; conventions are visible by grepping `ActivityLogger::log` across `app/Services/`.

### `audit_log` schema

From `database/settings_hub.sql`:

```
id              INT PK
actor_user_id   INT NULL   -> users.id
action          VARCHAR(100)   e.g. 'settings.update'
entity_type     VARCHAR(50)    e.g. 'settings_global', 'security_settings'
entity_id       INT NULL
diff_json       JSON NULL      { "before": …, "after": … }
ip_address      VARCHAR(50)
user_agent      VARCHAR(255)
created_at      DATETIME
```

Every write through `SettingsService::setGlobal()` calls the private `writeAudit()`, which snapshots the JSON before and after the change into `diff_json`. You don't have to remember to log anything — the service does it. Only call `AuditService::log()` directly for things outside the settings hub (page edits, application approvals); for anything in `settings_global`, the audit row is automatic.

### Encrypted-secret safety

`SettingsService::encodeValue()` wraps encrypted values in an envelope: `{"encrypted":true,"value":"<ciphertext>"}`. The audit diff captures *the envelope*, not the plaintext — so a Stripe secret-key rotation produces a `diff_json` with two opaque ciphertexts, not the actual keys. See [Chapter 31 — Settings architecture](view.php?slug=31-settings-architecture). **Never bypass `SettingsService` to write a secret directly** — you'd leak the plaintext into the audit row.

### Relationship to `SecurityAlertService`

`activity_log` is the durable record; `SecurityAlertService` is the optional email pager that fires off the back of certain entries. The alert fires *in addition to* the log row, gated by the corresponding `alerts.*` flag in `security_settings`. If the alert email is blank, the row still goes into `activity_log`; you just don't get the email. See [Chapter 22 — Notifications & email](view.php?slug=22-notifications-email).

## Where to view it

- **Per-member activity** — `/admin/members/view.php?id=<id>` → Activity tab. Backed by `ActivityRepository::listByMember()`. Filter by actor type, action substring, date range.
- **Site-wide security activity** — `/admin/security/activity_log.php`. Requires `admin.logs.view`. Filter by user ID, action, IP, target type, date range. Most recent 200 rows.
- **Settings audit** — `/admin/settings/index.php?section=audit`. Filter by action and actor name. Most recent 100 rows. Each row has a collapsible "View" with the pretty-printed `diff_json`.

There is **no CSV export** on any of the three views. For bulk pulls, run a `SELECT … INTO OUTFILE` from MySQL.

## Settings

This chapter doesn't define its own settings, but it consumes the alert toggles in `security_settings`. Managed at `/admin/settings/index.php?section=security-alerting`:

| Key | Default | Effect |
|---|---|---|
| `alert_email` | `''` | Recipient for `SecurityAlertService::send()`. Falls back to `site.contact_email` if blank. |
| `alerts.failed_login` | `true` | Repeated-failed-login alert from `AuthService`. |
| `alerts.new_admin_device` | `true` | First login from an unrecognised admin device. |
| `alerts.role_escalation` | `true` | Webhook role-assignment alert. |
| `alerts.refund_created` | `true` | Refund alert from `RefundService`. |
| `alerts.webhook_failure` | `true` | Stripe webhook failure threshold. |
| `alerts.member_export` / `alerts.member_import` | `true` | Member CSV export and import. |
| `alerts.fim_changes` | `true` | FIM scan diffs — see [Chapter 11 — File integrity monitoring](view.php?slug=11-file-integrity). |


<!-- SCREENSHOT: /admin/security/activity_log.php showing the filter bar and a table of recent rows. Capture on draft.goldwing.org.au as admin. Save as 08-activity-log.png. -->
<!-- ![Activity log](../images/08-activity-log.png) -->

<!-- SCREENSHOT: /admin/settings/index.php?section=audit showing the audit table with a diff "View" expanded. Save as 08-audit-log.png. -->
<!-- ![Audit log](../images/08-audit-log.png) -->

## Gotchas

- **No rotation.** Neither table has a TTL, partitioning, or scheduled trim — they grow forever. The `idx_activity_created` index keeps date-range queries cheap, but expect these to become the largest tables in the database within a few years. The fix when it bites is a cron that archives rows older than N months to a cold table — never a `DELETE`, because we want immutability.
- **`ActivityLogger` silently no-ops on failure.** The `try/catch` in `ActivityLogger::log()` only writes to `error_log` if the INSERT throws. If the `activity_log` table is missing (e.g. a fresh database that hasn't run `2025_01_20_security.sql`), every call is dropped without an admin-visible error. Confirm the table exists after any migration.
- **The tables look identical at a glance.** When debugging, `grep`-ing for "audit" or "activity" can mislead. `SettingsService` → `audit_log`. `ActivityLogger` → `activity_log`. `AuditService::log()` → `audit_logs` (plural, legacy). Three tables, one concept.
- **Hard row caps.** `activity_log` views show 200 rows max; the settings audit shows 100. The cap is a `LIMIT`, not pagination — narrow the date filter if your window is denser.
- **Member-export and member-import alerts are fired from the page, not from a service.** See `public_html/admin/members/export.php` and `import.php` for the call sites.

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — what writes the `security.login_*` rows.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — `security.otp_*` and `security.admin_new_device` rows.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what `admin_role.updated` and `security.role_escalation` mean.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the rest of the security posture.
- [11 — File integrity monitoring](view.php?slug=11-file-integrity) — the other big `SecurityAlertService` consumer.
- [17 — Refunds](view.php?slug=17-refunds) — refund-related activity entries and the `refund_created` alert.
- [20 — Members admin console](view.php?slug=20-members-admin) — the per-member Activity tab.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `SettingsService` stamps `audit_log` and handles encrypted envelopes.
