# Activity & audit log

## For administrators

### What this is

Every sensitive thing the admin team does on this site is written down — who did it, when they did it, what they touched, from which device. Two separate logs do this work, sitting side by side. Between them they answer one question: **"Who did what, when, to whom?"**

You can't edit a log entry. You can't delete one either. That's the whole point — if it could be quietly changed, it wouldn't be evidence.

### The two logs in plain English

- **Activity Log** — admin actions that affect a **member**. A refund got issued, a password got reset, a profile got edited, a vehicle was added to someone's garage, someone's account got locked. One row per action, with the member it happened to.
- **Audit Log** — **settings** changes. Someone bumped the membership price. Someone changed the Stripe key. Someone turned on maintenance mode. Someone edited the welcome email template. The audit log shows the value before and the value after, side by side.

Different logs because they answer different questions. The activity log is "what happened to Jane Smith's account?" The audit log is "who changed the membership fee from $80 to $90?"

### What you can use this for

- **Figure out what happened.** A member rings up saying "someone reset my password but it wasn't me." Open the activity log, search their name, you'll see who, when, and from which IP.
- **Show an auditor.** During an audit you can hand over a date-filtered export proving every refund, every role change, every settings tweak.
- **Back up "I didn't do that" with evidence.** Both logs record who was logged in. If a treasurer says "I didn't change the Stripe key," the audit log will show whether they did or not.

### Who's allowed to view

Only **Admin** can read either log. Committee members and treasurers don't see the security log links in the side menu. This is deliberate — the logs include IP addresses, browser strings, and the names of admin staff. Keep the audience small.

### Where to find them

- **Activity Log (site-wide)** — Admin → Security Log.
- **Activity Log (one member only)** — Admin → Members → click the member → **Activity** tab.
- **Audit Log (settings changes)** — Admin → Settings → **Audit Log** tab.

Three doors, three views, but the activity log views are reading the same underlying table — just with different filters.

{{link:/admin/security/activity_log.php|Take me to the Security Log}}
{{link:/admin/members/|Take me to Members}}
{{link:/admin/settings/?section=audit|Take me to the Audit Log}}

### How to search the activity log

{{link:/admin/security/activity_log.php|Take me to the Security Log}}

The filter bar at the top of Admin → Security Log lets you narrow by:

- **Member** — type a name or ID to see everything done to that one person.
- **Action type** — e.g. `refund.processed`, `security.password_reset`, `admin_role.updated`. You can type just `refund` to see every refund-shaped row.
- **Date range** — start and end date. Default is the last week.
- **IP address** — useful if you're chasing a suspicious login.

Results are sorted newest first and capped at 200 rows. If you've got a busy week, narrow the date range rather than expecting to scroll past 200.

### What gets recorded

For every sensitive action — refunds, password resets, role changes, profile edits, exports, imports, settings tweaks, failed logins — the log captures:

- **Who** — the admin's user account (or "system" for automated actions).
- **When** — date and time, to the second.
- **What** — the action name (`refund.processed`, `member.password_reset`, etc.).
- **Why** — whatever reason text was typed at the time (e.g. the refund reason).
- **IP address** — the network address the action came from.
- **Browser** — the user agent string (e.g. "Chrome on macOS").

For settings changes, the audit log also shows a **before/after diff** — the old value and the new value side by side. Click "View" on a row to expand it.

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

- **Review the audit log monthly.** Pop in once a month and skim recent settings changes. Anything unexpected — a price bump nobody mentioned, a payment key rotated outside a known window — is worth a quick "hey, was this you?" Slack message.
- **ALWAYS type a reason when prompted.** Refund reasons, password-reset reasons, role-change reasons — they all land in the log. A reason of "see ticket #842" is infinitely better than blank, even if the ticket lives elsewhere.
- **Never delete log rows.** There's no UI to do it, and there shouldn't be. If somebody ever asks you to "clean up" a log entry, that's a flag — say no.
- **Keep the activity log audience small.** Don't screenshare the security log on a public call. It includes staff IPs and sometimes member identifiers.

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

- **Per-member activity** — `/admin/members/view.php?id=<id>` → Activity tab. Backed by `ActivityRepository::listByMember()`. Filter by actor type, action substring, date range.
- **Site-wide security activity** — `/admin/security/activity_log.php`. Requires `admin.logs.view`. Filter by user ID, action, IP, target type, date range. Most recent 200 rows.
- **Settings audit** — `/admin/settings/index.php?section=audit`. Filter by action and actor name. Most recent 100 rows. Each row has a collapsible "View" with the pretty-printed `diff_json`.

There is **no CSV export** on any of the three views. For bulk pulls, run a `SELECT … INTO OUTFILE` from MySQL.

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
- **Hard row caps.** `activity_log` views show 200 rows max; the settings audit shows 100. The cap is a `LIMIT`, not pagination — narrow the date filter if your window is denser.
- **Member-export and member-import alerts are fired from the page, not from a service.** See `public_html/admin/members/export.php` and `import.php` for the call sites.

</details>

<!-- SCREENSHOT: /admin/security/activity_log.php showing the filter bar and a table of recent rows. Capture on draft.goldwing.org.au as admin. Save as 08-activity-log.png. -->
<!-- ![Activity log](../images/08-activity-log.png) -->

<!-- SCREENSHOT: /admin/settings/index.php?section=audit showing the audit table with a diff "View" expanded. Save as 08-audit-log.png. -->
<!-- ![Audit log](../images/08-audit-log.png) -->

## Related chapters

- [05 — Authentication & sessions](view.php?slug=05-authentication) — what writes the `security.login_*` rows.
- [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup) — `security.otp_*` and `security.admin_new_device` rows.
- [07 — Roles & permissions](view.php?slug=07-roles-permissions) — what `admin_role.updated` and `security.role_escalation` mean.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the rest of the security posture.
- [11 — File integrity monitoring](view.php?slug=11-file-integrity) — the other big `SecurityAlertService` consumer.
- [17 — Refunds](view.php?slug=17-refunds) — refund-related activity entries and the `refund_created` alert.
- [20 — Members admin console](view.php?slug=20-members-admin) — the per-member Activity tab.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — how `SettingsService` stamps `audit_log` and handles encrypted envelopes.
