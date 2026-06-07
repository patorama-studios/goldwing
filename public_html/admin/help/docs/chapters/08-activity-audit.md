# Activity & audit log

## For administrators

### What this is

Every sensitive thing the admin team does on this site is written down — who did it, when they did it, what they touched, from which device. They all land in one place: the **Audit Hub**. One screen, one filter bar, every type of event. **"Who did what, when, to whom?"** — start here.

You can't edit a log entry. You can't delete one either. That's the whole point — if it could be quietly changed, it wouldn't be evidence.

### Three sources, one timeline

The Audit Hub merges three underlying sources so you don't have to remember which page to open:

- **Settings** — settings changes. Someone bumped the membership price. Someone changed the Stripe key. Someone turned on maintenance mode. Shows the value before and the value after.
- **Admin** — admin actions outside the settings hub. Application approvals, chapter assignments, notice CRUD, Page Builder saves.
- **Activity** — security and member-touching events. Refunds, password resets, role assignments, failed logins, webhook outcomes, member exports/imports.

Each row is tagged with a coloured **Source** badge so you can see at a glance whether you're looking at a settings tweak, an admin action, or a security event.

### What you can use this for

- **Figure out what happened.** A member rings up saying "someone reset my password but it wasn't me." Open the Audit Hub, search their name, you'll see who, when, and from which IP.
- **Show an auditor.** During an audit you can filter to a date range and walk through every refund, every role change, every settings tweak — side by side.
- **Back up "I didn't do that" with evidence.** Every row records who was logged in. If a treasurer says "I didn't change the Stripe key," the hub will show whether they did or not.

### Who's allowed to view

Only roles with `admin.logs.view` can read the Audit Hub. Committee members and treasurers don't see the link in the side menu. This is deliberate — the logs include IP addresses, browser strings, and the names of admin staff. Keep the audience small.

### Where to find it

- **Audit Hub (everything site-wide)** — Admin → **Audit Hub** in the side menu, or via Settings → Audit Hub.
- **Per-member activity** — Admin → Members → click the member → **Activity** tab. (Same data, filtered to that one member.)

{{link:/admin/audit/|Take me to the Audit Hub}}
{{link:/admin/members/|Take me to Members}}

### How to search

The filter bar at the top of the Audit Hub lets you narrow by:

- **Search** — free-text across actor name, action, and payload. Type `refund` to see every refund-shaped row across all three sources.
- **Source** — Settings / Admin / Activity, or All. The cards at the very top are clickable shortcuts.
- **Action** — pick a specific action name from the dropdown.
- **Actor** — name or email contains.
- **From / To** — date range.

Results are sorted newest first. Page size is configurable; pagination at the bottom lets you walk further back than the visible page.

### Reading the Details column

Each row's **Details** column shows a friendly summary instead of raw JSON. Settings changes appear as `Field name: old → new`. Activity rows show their metadata (amount, target, reason, etc.) as plain labels. Need the underlying payload? Each row has a **Show raw** toggle that expands the pretty-printed JSON for the developer-flavoured view.

### What gets recorded

For every sensitive action — refunds, password resets, role changes, profile edits, exports, imports, settings tweaks, failed logins — the log captures:

- **Who** — the admin's user account (or "system" for automated actions).
- **When** — date and time, to the second.
- **What** — the action name (`refund.processed`, `member.password_reset`, etc.).
- **Why** — whatever reason text was typed at the time (e.g. the refund reason).
- **IP address** — the network address the action came from.
- **Browser** — the user agent string (e.g. "Chrome on macOS").

For settings changes, the Details column shows a **before/after diff** — the old value and the new value side by side. Click **Show raw** on a row to expand the underlying JSON.

### What's NOT in here

- **Members reading their own data.** A member opening their own profile to check their address is not logged. That would generate millions of useless rows.
- **Routine page views.** Admin staff opening a member's page to look at it isn't logged either — only when they change something.
- **Member-initiated changes.** A member changing their own email or paying for renewal goes into their personal account timeline (visible on their profile), not into the admin activity log.
- **Plaintext secrets.** When someone rotates the Stripe key, the audit log shows that the key changed, but the actual key value is masked. You can't recover a leaked key by reading the audit log — that's deliberate.

### What can go wrong

- **The logs grow forever.** Nothing trims them. After a few years they'll be the biggest tables in the database. That's fine for now, but flag it to your developer if searches start feeling slow.
- **An action you expected to see isn't there.** Either the table wasn't set up properly on this database (rare, but possible on a fresh install), or that particular action isn't wired to log yet. Ask your developer.
- **The log shows the action but not the detail you need.** The "before/after" diff in the audit log is generally good, but for encrypted values (like Stripe secret keys) you'll only see "value changed" — not what to. That's the safety feature, not a bug.

### Good practice

- **Review the Audit Hub monthly.** Pop in once a month, filter by the Settings source, and skim recent changes. Anything unexpected — a price bump nobody mentioned, a payment key rotated outside a known window — is worth a quick "hey, was this you?" Slack message.
- **ALWAYS type a reason when prompted.** Refund reasons, password-reset reasons, role-change reasons — they all land in the log. A reason of "see ticket #842" is infinitely better than blank, even if the ticket lives elsewhere.
- **Never delete log rows.** There's no UI to do it, and there shouldn't be. If somebody ever asks you to "clean up" a log entry, that's a flag — say no.
- **Keep the Audit Hub audience small.** Don't screenshare it on a public call. It includes staff IPs and sometimes member identifiers.

### Who to ask if you can't find something

- **A specific action isn't appearing in the log** — flag it to your developer; they'll check whether that path is wired up.
- **You need a bulk export for an auditor** — your developer can pull a CSV straight from the database faster than you can scroll. There's no built-in export button.
- **The diff in an audit row looks garbled** — that's the encrypted-envelope safety. Your developer can confirm what category of value changed without ever seeing the plaintext.

---

<details>
<summary><strong>Dev notes</strong></summary>

### What this covers

The site keeps two append-only history tables — `activity_log` for *things admins did to members* (and security events the system noticed), and `audit_log` for *settings changes*. They look alike (actor, action, timestamp, IP, UA, JSON blob) but they're written by different services and answer different questions. This chapter explains which one to use, where each surfaces, and what the data looks like.

### Why it exists

- **Regulatory accountability.** The association handles members' personal details, payment metadata, and refunds. When somebody asks "who saw this record? who changed that price?" we need a timestamp and an actor.
- **Blame-free post-mortems.** When a refund goes to the wrong card or a role gets added by mistake, the log tells us *what* happened before we work out *why*.
- **RBAC verification.** [Chapter 07 — Roles & permissions](view.php?slug=07-roles-permissions) is only meaningful if we can prove the permission gates actually fired. `activity_log` is the receipt — step-up grants, role assignments, FIM baseline approvals, security-settings edits.
- **Detection.** A handful of entries double as triggers for `SecurityAlertService` — see below.

### How it works

#### Two tables, two writers

| Concern | Table | Writer service | Read API |
|---|---|---|---|
| "An admin (or system) did X *to* a member or security subsystem" | `activity_log` | `App\Services\ActivityLogger` | `App\Services\ActivityRepository` + the security-log page |
| "Someone changed a setting / approved an application / edited a page" | `audit_log` | `App\Services\SettingsService` (auto), `App\Services\AuditService` (manual)\* | Direct `SELECT` on the settings audit view |

\* Name collision warning. `AuditService::log()` writes into `audit_logs` (plural, legacy schema in `database/schema.sql`) for older actions (chapter assignments, application approvals, notice CRUD, Page Builder saves). `SettingsService::writeAudit()` writes into `audit_log` (singular, modern schema from `database/settings_hub.sql`) with `entity_type` / `entity_id` / `diff_json` columns. The Settings audit UI reads only the singular `audit_log`.

#### `activity_log` schema

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

#### `audit_log` schema

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

#### Encrypted-secret safety

`SettingsService::encodeValue()` wraps encrypted values in an envelope: `{"encrypted":true,"value":"<ciphertext>"}`. The audit diff captures *the envelope*, not the plaintext — so a Stripe secret-key rotation produces a `diff_json` with two opaque ciphertexts, not the actual keys. See [Chapter 31 — Settings architecture](view.php?slug=31-settings-architecture). **Never bypass `SettingsService` to write a secret directly** — you'd leak the plaintext into the audit row.

#### Relationship to `SecurityAlertService`

`activity_log` is the durable record; `SecurityAlertService` is the optional email pager that fires off the back of certain entries. The alert fires *in addition to* the log row, gated by the corresponding `alerts.*` flag in `security_settings`. If the alert email is blank, the row still goes into `activity_log`; you just don't get the email. See [Chapter 22 — Notifications & email](view.php?slug=22-notifications-email).

### Where to view it

- **Audit Hub (site-wide, all three sources)** — `/admin/audit/`. Requires `admin.logs.view`. Backed by `App\Services\AuditHubService`, which UNIONs `audit_log`, `audit_logs`, and `activity_log` at read time and projects them into a single normalized row shape (`source`, `created_at`, `actor_*`, `action`, `target_*`, `details_text`, `metadata_json`, `ip_address`). Filters: free-text search, source, action, actor (name/email contains), date range. Paginated.
- **Per-member activity** — `/admin/members/view.php?id=<id>` → Activity tab. Backed by `ActivityRepository::listByMember()`. Filter by actor type, action substring, date range.
- **Legacy URLs** — `/admin/security/activity_log.php`, `/admin/settings/index.php?section=audit`, and `/admin/index.php?page=audit` are now 302 redirects to `/admin/audit/` with the appropriate `source` and filter pre-selected. The redirect handlers preserve the most common filter params (action, date range, search). The old per-page views in `admin/index.php` and the `'audit'` branch in `settings/index.php` have been removed.

There is **no CSV export** in the Audit Hub. For bulk pulls, run a `SELECT … INTO OUTFILE` from MySQL.

### About `AuditHubService`

The service is read-only and defensive: it inspects which of the three tables exist (`SHOW TABLES`) and which optional columns exist on `activity_log` (`target_type`, `target_id`) before composing the UNION, so a partially-migrated database still renders. The `friendlyMetadata()` helper handles two payload shapes:

1. **Settings diffs** with `{before, after}` keys — emits one `Field name: old → new` line per changed field.
2. **Activity-style metadata** — flattens one level into `Key: value` pairs, prettifying snake_case keys and converting booleans / nulls to human strings.

The raw JSON is always available behind a `<details>` toggle on the row.

The three writer services (`AuditService`, `ActivityLogger`, `SettingsService::writeAudit`) are unchanged — this is purely a UI consolidation.

### Settings

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

### Gotchas

- **No rotation.** Neither table has a TTL, partitioning, or scheduled trim — they grow forever. The `idx_activity_created` index keeps date-range queries cheap, but expect these to become the largest tables in the database within a few years. The fix when it bites is a cron that archives rows older than N months to a cold table — never a `DELETE`, because we want immutability.
- **`ActivityLogger` silently no-ops on failure.** The `try/catch` in `ActivityLogger::log()` only writes to `error_log` if the INSERT throws. If the `activity_log` table is missing (e.g. a fresh database that hasn't run `2025_01_20_security.sql`), every call is dropped without an admin-visible error. Confirm the table exists after any migration.
- **The tables look identical at a glance.** When debugging, `grep`-ing for "audit" or "activity" can mislead. `SettingsService` → `audit_log`. `ActivityLogger` → `activity_log`. `AuditService::log()` → `audit_logs` (plural, legacy). Three tables, one concept.
- **Audit Hub is paginated, but legacy URLs aren't.** The per-member Activity tab still uses a 100-row `LIMIT`, not pagination — narrow the date filter for dense windows.
- **Member-export and member-import alerts are fired from the page, not from a service.** See `public_html/admin/members/export.php` and `import.php` for the call sites.

</details>

<!-- SCREENSHOT: /admin/audit/ showing the stats cards, filter bar and a table of recent rows across all three sources. Capture on goldwing.org.au as admin. Save as 08-audit-hub.png. -->
<!-- ![Audit Hub](../images/08-audit-hub.png) -->

<!-- SCREENSHOT: /admin/audit/?source=settings with a row's "Show raw" expanded to demonstrate the friendly diff vs raw JSON view. Save as 08-audit-hub-diff.png. -->
<!-- ![Audit Hub — settings diff](../images/08-audit-hub-diff.png) -->

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — what writes the `security.login_*` rows.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — `security.otp_*` and `security.admin_new_device` rows.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what `admin_role.updated` and `security.role_escalation` mean.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the rest of the security posture.
- [11 — File integrity monitoring](view.php?slug=11-file-integrity) — the other big `SecurityAlertService` consumer.
- [17 — Refunds](view.php?slug=17-refunds) — refund-related activity entries and the `refund_created` alert.
- [20 — Members admin console](view.php?slug=20-members-admin) — the per-member Activity tab.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `SettingsService` stamps `audit_log` and handles encrypted envelopes.
