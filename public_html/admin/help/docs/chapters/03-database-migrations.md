# Database & migrations

## For administrators

This chapter is for developers. As an admin you don't touch the database directly — you only need to know the bits that affect day-to-day operations.

### What this is

The **database** is where the site stores everything: members, orders, settings, sessions, audit logs, page content. It's a single MySQL database that sits on cPanel alongside the site files. Every time a member logs in, a product is bought, a setting is changed, or an admin clicks a button, something gets read from or written to this database.

You will almost never need to look at it. The admin interface is the right tool for everything you'd ever want to do — viewing members, editing orders, refunding payments, changing settings. The database is what sits behind that interface.

### What you might run into

- **Backups happen automatically.** cPanel runs a full backup once a day (files plus database). You don't need to do anything to make this happen.
- **"A member's data looks wrong."** Sometimes a member writes in to say their renewal date, chapter, or payment history is off. The developer can investigate the database and either confirm or fix it. Don't try to fix it yourself.
- **"Something in admin is broken."** Occasionally the cause is database-level (a table needs updating, a setting got wiped). The error message in admin will usually be enough for the developer to act on — screenshot it and pass it along.

### Where backups live

cPanel → **Files** → **Backup**. You'll see a list of recent backups. Each one bundles the files and the database into a single download.

Keep at least a week's worth on hand somewhere safe (your laptop, a cloud drive). cPanel rotates old backups out, so don't rely on them being there forever.

### What you should NEVER do

- **Don't open phpMyAdmin and edit a member row directly.** Even a tiny typo can break login, billing, or membership state. Use the admin interface — it does the right thing.
- **Don't delete from a table to "fix" something.** A row in the database is often referenced from several other places. Deleting it can leave orphaned data that causes real problems later.
- **Don't share database credentials.** The DB password is in a config file the developer manages. It should never be in email, chat, or a shared document.

### What you can safely do

- **Download a backup** from cPanel → Files → Backup. This is read-only — you're just saving a copy.
- **Ask the developer to restore a backup** if something has gone seriously wrong. Restores affect everyone, so they're done with care and usually outside peak hours.

### Good practice

- Make sure cPanel backups are actually running — log in once a month and check the backup list has recent dates on it.
- Occasionally download a backup and confirm the file isn't zero bytes. A backup you've never tested isn't a backup.
- If you're about to do something big in admin (bulk import, mass delete, anything irreversible), ask the developer to take a fresh backup first.

### Who to ask if data looks wrong

Your developer. Give them the member name (or order number, or setting page), what you expected to see, and what's actually showing. They can compare against the database and fix or explain it.

---

<details>
<summary><strong>Dev notes</strong></summary>

## What this covers

How data is stored, how the schema gets into a fresh database, and how schema changes get from a dev laptop to the live server. One MySQL database holds everything — members, orders, settings, sessions, audit logs, AI conversations — and "migrations" are plain `.sql` files run by hand.

## Why it exists

Same reasoning as the no-framework call in [Chapter 01](view.php?slug=01-system-overview):

- **cPanel ships with MySQL.** Anything else means a second hosting account or a managed DB bill we don't have budget for.
- **One database, not many.** Splitting members, store, and content into separate DBs would mean cross-DB joins and three sets of credentials. A single 43-table schema is easier to back up and restore.
- **No migrations runner.** No `artisan migrate`, no `rake db:migrate`. Each schema change is a dated `.sql` file in `database/migrations/`, run by hand once per environment. The trade-off is drift risk — see Gotchas.
- **Sessions in the DB, not on disk.** cPanel's PHP-FPM pool is load-balanced across workers, so disk-based sessions can land on a different worker than the one that wrote them. MySQL-backed sessions mean any worker can read any session, and "log everyone out" is one `DELETE FROM sessions`.

## How it works

### Connection wiring

`App\Services\Database::connection()` is a static singleton returning one PDO per request. It reads `config/database.php` (which reads `.env`), builds a `charset=utf8mb4` DSN, and sets `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, and `EMULATE_PREPARES=false` so types come back native and every failed query throws. Services call it directly; page-level code uses the global `db()` helper from `app/bootstrap.php`.

### Session storage

`App\Services\DbSessionHandler` implements `SessionHandlerInterface` and is registered in `bootstrap.php` before `session_start()`. Reads pull `data` from `sessions` where `expires_at > NOW()`. Writes are one `INSERT … ON DUPLICATE KEY UPDATE` that also stamps `ip_address`, `user_agent`, and `last_activity_at`. `gc()` deletes expired rows. TTL comes from `session.gc_maxlifetime`. This is why "Force logout all users" is a one-line query — see [Chapter 05](view.php?slug=05-authentication).

### The SQL files

Everything lives under `database/`. The big ones, in import order:

| File | What it does |
|---|---|
| `schema.sql` | The 43-table base schema (members, users, roles, chapters, sessions, pages, audit_log, etc.). `utf8mb4`, InnoDB throughout. ~550 lines. |
| `seeds.sql` | Minimum data to log in: the role list, three demo chapters, an admin user. |
| `settings_hub.sql` | `settings_global` + `settings_user` tables that power the [Settings Hub](view.php?slug=31-settings-architecture). |
| `members_module.sql` | Membership types, member vehicles, refunds, activity logging. |
| `store_module.sql` | All `store_*` tables: products, variants, categories, carts, orders, discounts, shipments, tickets. |
| `payments_module.sql` | Stripe-side tables: `orders`, `order_items`, `invoices`, `refunds`, `webhook_events`, `payment_channels`. |
| `about_pages.sql` | Idempotent `INSERT … ON DUPLICATE KEY UPDATE` for the public About pages. |
| `media_import.sql` | Bulk-loads the existing media library into the `media` table (~4,100 lines). |

`schema.sql` is too long to read cover to cover — `grep -n "CREATE TABLE" database/schema.sql` gives you a clickable index.

### The `migrations/` subdirectory

Anything that changes an already-deployed schema goes in `database/migrations/` as a dated file (e.g. `2025_01_20_security.sql`, `2026_06_05_tours.sql`). Every migration uses `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE … ADD COLUMN IF NOT EXISTS` (MySQL 8.0+), or `INFORMATION_SCHEMA` guards so running the same file twice is a no-op — which matters because nothing tracks which migrations have run.

### The "latest" snapshots

Four rolled-up files at the top level:

- **`latest_full.sql`** — schema + seeds + modules + migrations + about pages + media, concatenated. Stand up a brand-new DB in one shot.
- **`latest_full_clean.sql`** — same content but `DROP TABLE IF EXISTS` first. Use to reset draft to a known state.
- **`latest_patched.sql` / `patched.sql`** — older recovery snapshots, kept for audit.

Snapshots are *output*, not source of truth — don't edit them.

### Importing on a fresh database

Via phpMyAdmin's import tab or `mysql` CLI, in order: `schema.sql` → `seeds.sql` → `settings_hub.sql` → `members_module.sql` → `store_module.sql` → `payments_module.sql` → `about_pages.sql` → `media_import.sql` → each `migrations/*.sql` by date. Or just `mysql -u user -p dbname < database/latest_full.sql`.

### Backups

cPanel takes a daily full backup (files + DB); restores go through cPanel → Backups. For an on-demand dump, SSH in and run `mysqldump --single-transaction --quick --routines dbname > ~/backups/dbname-$(date +%F).sql`, or use phpMyAdmin → Export → Quick → SQL. **Always dump before a destructive migration** (column drop, large `UPDATE`, anything irreversible).

## Where to change it

- **New table/column for a feature:** dated file under `database/migrations/` with `IF NOT EXISTS` guards. Run on local, then draft, then live.
- **Canonical schema:** also update `database/schema.sql` so fresh installs get the column. Migrations and `schema.sql` must stay in sync.
- **Seed data for a new lookup:** `database/seeds.sql` if needed for a fresh install to be usable; otherwise inline in the migration.
- **Connection settings:** `config/database.php`, which reads `.env` — see [Chapter 04](view.php?slug=04-configuration).

## Settings

No admin-UI settings here. DB credentials live in `.env` / `config/database.php` — see [Chapter 04](view.php?slug=04-configuration). Settings *stored* in the DB are covered in [Chapter 31](view.php?slug=31-settings-architecture), which uses the `settings_global` table created by `settings_hub.sql`.

## Gotchas

- **No migration tracking.** Nothing remembers which files have run on which environment. Forget one and you get "unknown column" errors. Keep a checklist when deploying schema changes; check `INFORMATION_SCHEMA.COLUMNS` if you suspect drift.
- **`store_module.sql` and `payments_module.sql` start with `DROP TABLE IF EXISTS`.** Fine on a fresh install. **Never** re-run them against a populated DB — you'll wipe live orders. Use the migrations folder for changes to existing tables.
- **`schema.sql` and `migrations/` can drift.** When you add a column, edit both — the migration *and* the `CREATE TABLE` in `schema.sql` — or future fresh installs will be missing the column.
- **`utf8mb4` everywhere.** Never introduce `utf8` (3-byte, breaks emoji). All `CREATE TABLE` statements and the PDO DSN already use it. Mojibake → check the connection charset first.
- **Sessions table grows fast.** `DbSessionHandler::gc()` runs probabilistically. If `sessions` ever crosses a million rows, run a manual `DELETE FROM sessions WHERE expires_at < NOW()`.
- **Don't edit `latest_*.sql` by hand** — edits will vanish next time someone regenerates the snapshot.

</details>

<!-- SCREENSHOT: phpMyAdmin showing the goldwing database with the table list visible (sessions, members, settings_global, store_products, audit_log). Capture from cPanel → phpMyAdmin while logged into the draft account. Save as 03-phpmyadmin-tables.png and uncomment below. -->
<!-- ![phpMyAdmin table list](../images/03-phpmyadmin-tables.png) -->

<!-- SCREENSHOT: phpMyAdmin's Import tab with a migration file ready to upload. Save as 03-phpmyadmin-import.png. -->
<!-- ![phpMyAdmin import](../images/03-phpmyadmin-import.png) -->

## Related chapters

- [02 — Codebase map](view.php?slug=02-codebase-map) — where the SQL files sit relative to everything else.
- [04 — Configuration & environment](view.php?slug=04-configuration) — DB credentials in `.env` and `config/database.php`.
- [31 — Settings architecture](view.php?slug=31-settings-architecture) — the `settings_global` table created here, how it's read and written.
- [33 — Deployment](view.php?slug=33-deployment) — the "push live" flow and where running a migration fits into it.
