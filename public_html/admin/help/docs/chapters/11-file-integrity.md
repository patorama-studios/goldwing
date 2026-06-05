# File integrity monitoring

## What this covers

How the site notices when a PHP file changes on disk without us putting it there. The FIM (File Integrity Monitoring) service: what gets hashed, where the baseline is stored, how the cron diff works, who gets emailed, what to do when an alert lands.

## Why it exists

Shared cPanel hosting has several ways a file can change on disk without going through git: a stale FTP session, a cPanel "File Manager" save, a compromised SFTP password, or — worst case — an attacker dropping a backdoor under `/app/Services/`. None of those show up in the activity log (`ActivityLogger` only sees things that go through our PHP).

FIM is the "sealed envelope" check: SHA-256 every file in a known set, store the map in the DB after a human approves it, re-hash on a schedule, shout if anything changed. It's the only mechanism that catches *out-of-band* file edits — everything else assumes the attacker is using our admin UI.

Trade-off: noisy after a real deploy, because every deploy is by definition an out-of-band file change. The workflow accepts that — an admin re-approves after each deploy. See Gotchas.

## How it works

Three pieces: a service, a cron job, and a tiny database row.

**1. The service: `app/Services/FileIntegrityService.php`**

Four public static methods:

- `computeBaseline($root, $paths, $excludes)` — walks each `$paths` entry under `$root` with `RecursiveDirectoryIterator`, skips prefix-matched `$excludes`, and returns a sorted `[relativePath => sha256]` map via `hash_file('sha256', …)`.
- `loadBaseline()` — reads the JSON blob from `file_integrity_baseline.baseline_json` (single row, `id = 1`).
- `saveBaseline($baseline, $approvedByUserId)` — writes the JSON back, stamps `approved_by_user_id` + `approved_at = NOW()`, clears `last_scan_report_json`, sets `last_scan_status = 'OK'`.
- `scan($root, $paths, $excludes)` — recomputes the current map and diffs it. Returns `['added' => [...], 'modified' => [...], 'deleted' => [...]]`. Throws `RuntimeException('Baseline not set.')` if baseline JSON is empty.

The diff is pure hash comparison: same path + different SHA-256 = "modified"; missing from baseline = "added"; missing from current = "deleted". No timestamp or permission checks.

**2. The cron: `cron/fim_scan.php`**

Runs the pipeline on a schedule:

1. Bail if `fim_enabled` is off.
2. Call `FileIntegrityService::scan()` with the configured paths.
3. If anything changed: `recordScanResult('CHANGES_DETECTED', $changes)`, fire `SecurityAlertService::send('fim_changes', …)` with an ADDED/MODIFIED/DELETED list in the body, log `security.fim_changes_detected`.
4. Otherwise: `recordScanResult('OK')`.
5. On any `Throwable`: `recordScanResult('ERROR', …)` and send a "scan error" alert.

Cron command: `php /home/<cpanel-user>/public_html/cron/fim_scan.php`. Hourly or nightly — see [34 — Cron jobs](view.php?slug=34-cron-jobs).

**3. The storage: `file_integrity_baseline`**

Single-row table (`id = 1`). Columns: `baseline_json` (LONGTEXT, the approved `{path: sha256}` map), `approved_by_user_id`, `approved_at`, `last_scan_at`, `last_scan_status` (`OK` | `CHANGES_DETECTED` | `ERROR`), `last_scan_report_json` (most recent diff or error message).

**4. Alerts**

`SecurityAlertService::send('fim_changes', …)` checks `alerts.fim_changes` in `security_settings`, then emails the configured recipient via `EmailService`. If `alert_email` is blank it falls back to `site.contact_email`; if both are blank, no email goes out (the diff still sits in `last_scan_report_json`).

There is no in-app alert inbox or per-alert approve/dismiss flow. The only ways to clear a change are (a) re-approve the baseline, which overwrites the diff, or (b) wait for the next scan to come back clean on its own.

## Where to change it

Everything lives at **Admin → Settings → Security & Authentication** (`/admin/settings/index.php?section=security`).

The "File Integrity Monitoring" card:

- **Enable file integrity monitoring** → `fim_enabled`
- **Directories to monitor** → `fim_paths` (newline/comma separated, each leading-slash relative to project root)
- **Exclude paths** → `fim_exclude_paths` (prefix match on the relative path)
- **Approve baseline** button → calls `require_stepup()`, then `FileIntegrityService::computeBaseline()` + `saveBaseline()`, and logs `security.fim_baseline_approved`.

Alert recipient and the FIM-email toggle are in the same form under "Security alerts": `alert_email` and the "File integrity changes" checkbox (maps to `alerts.fim_changes`).

No admin "scan now" button — SSH in and run the cron file by hand if you need an off-schedule scan.

## Settings

All keys are columns on `security_settings` (id = 1), surfaced by `SecuritySettingsService::get()`:

| Key | Type | Default | Notes |
|---|---|---|---|
| `fim_enabled` | bool | `true` | Master switch. When off, the cron exits immediately. |
| `fim_paths` | string[] | `['/app', '/admin', '/config']` | Walked recursively for directories, hashed directly for files. |
| `fim_exclude_paths` | string[] | `['/uploads', '/cache']` | Prefix match on the relative path. |
| `alerts.fim_changes` | bool | `true` | Gate on `SecurityAlertService::send('fim_changes', …)`. |
| `alert_email` | string | `''` | Recipient. Falls back to `site.contact_email`. |

The baseline itself lives in `file_integrity_baseline.baseline_json`, not in `settings_global`.

## Screenshots

<!-- SCREENSHOT: The "File Integrity Monitoring" card at /admin/settings/index.php?section=security, showing the enable toggle, the paths/excludes textareas with the defaults visible, and the "Approve baseline" button. Save as 11-fim-card.png. -->
<!-- ![FIM settings card](../images/11-fim-card.png) -->

<!-- SCREENSHOT: An example "Security alert: file integrity changes" email in an inbox, showing the ADDED/MODIFIED/DELETED list in the body. Save as 11-fim-alert-email.png. -->
<!-- ![FIM alert email](../images/11-fim-alert-email.png) -->

## Gotchas

- **A fresh install fails loudly.** With an empty baseline, `scan()` throws `RuntimeException('Baseline not set.')`, the cron emails the error, and `last_scan_status` becomes `ERROR`. Fix by clicking "Approve baseline" once.
- **Deploys *will* trigger an alert.** Any `git pull` changes hashes under `/app` and `/admin`. Normal workflow: see the alert, sanity-check the file list against the deploy's `git log`, re-approve. If a deploy lands and *no* alert arrives, FIM is disabled or the cron isn't running.
- **`CHANGES_DETECTED` does not reset anything.** The next cron run compares against the same baseline and finds the same diff. The email keeps firing every scan until someone re-approves.
- **Exclusions are prefix matches, not globs.** `/uploads` excludes `/uploads/anything/deep`. You cannot exclude `*.log` — put the logs in a directory and exclude that. Excludes need a leading `/`.
- **`/uploads` and `/cache` are excluded by default for a reason** — members upload images and the cache churns. Hashing those would alert on every member action.
- **A "deleted" file is just as suspicious as an "added" one.** An attacker removing `SecurityHeadersService.php` to disable headers is a real attack pattern.
- **Approving the baseline requires step-up** (`require_stepup()` in `admin/settings/index.php` before `saveBaseline()`) — see [06 — 2FA, step-up & trusted devices](view.php?slug=06-2fa-stepup).

## Related chapters

- [08 — Activity & audit log](view.php?slug=08-activity-audit) — where `security.fim_changes_detected` and `security.fim_baseline_approved` end up.
- [09 — Security headers & policies](view.php?slug=09-security-headers) — the other half of "passive site hardening".
- [10 — Encryption & secrets at rest](view.php?slug=10-encryption-secrets) — protects the secrets FIM protects the *code* around.
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — schedule, command, and the full list of `cron/*.php` files.
- [35 — Logs & troubleshooting](view.php?slug=35-logs-troubleshooting) — where to look when the FIM cron silently stops emailing.
