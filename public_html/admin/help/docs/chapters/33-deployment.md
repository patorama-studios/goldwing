# Deployment

## What this covers

How code gets from Pat's laptop to `draft.goldwing.org.au`: the GitHub repo, the cPanel Git Version Control integration, the `.cpanel.yml` hook, pre-push impact checks, and the manual "Deploy HEAD Commit" step. Plus the things that *will* break the site — chiefly FTP and `git clean` on the server.

## Why it works this way

cPanel ships a built-in Git integration that can clone a remote repo into any account-owned directory, pull new commits on demand, and run a `.cpanel.yml` deploy hook after each pull. We use it because:

- **Atomic-ish deploys.** A single "Update from Remote" + "Deploy HEAD Commit" moves the whole working tree to a known SHA. No half-uploaded files.
- **Git is the source of truth.** Every deployed change has a commit. You can `git log` on the server to answer "what's actually running right now?".
- **No build pipeline.** The site is plain PHP 8 with Tailwind via CDN (see [Chapter 01 — System overview](view.php?slug=01-system-overview)). There's nothing to compile or bundle. The repo *is* the deploy artefact.
- **One person, one button.** Pat triggers deploys from the cPanel UI. No CI account, no SSH keys to rotate, no GitHub Actions bill.

The trade-off is that FTP — the other obvious way to push files to cPanel — is actively dangerous here. FTP writes straight to the working tree without telling git. The next `git pull` from cPanel sees the FTP'd files as local modifications, refuses to fast-forward, and the deploy silently breaks. FTP is forbidden, even though `scripts/ftp_upload_changed.py` and `scripts/ftp_auto_upload.py` still exist in the repo — they're legacy and must not be run.

## How it works

### The pieces

- **Repo:** `github.com/patorama-studios/goldwing`, branch `main`. The only branch that gets deployed.
- **Server clone:** `/home/goldwing/draft.goldwing.org.au/` on the cPanel account. This directory *is* the live working tree — `public_html/` underneath it is what Apache serves. No `dist/`, no rsync target.
- **`.cpanel.yml`:** the deploy hook cPanel runs after a successful pull. Intentionally minimal:

  ```yaml
  ---
  deployment:
    tasks:
      - export DEPLOYPATH=/home/goldwing/draft.goldwing.org.au/
      - /bin/true
  ```

  No `cp`, no `chmod`, no build step. Files land in the right place by virtue of the repo layout matching the deploy path.

### The standing flow ("push live")

1. **Edit files locally.** Run things in MAMP at `localhost` to confirm they work.
2. **Run the two impact checks.** Both are wired into the `tour-impact-check` and `doc-sync-check` skills and run automatically when Pat says "push live":

   ```bash
   ./scripts/check_tour_impact.sh
   ./scripts/check_doc_impact.sh
   ```

   The first compares your diff against `config/tour-manifest.json` and lists any guided tours whose selectors or wording you may have invalidated (see [Chapter 36 — Tours system](view.php?slug=36-tours-system)). The second compares against `_toc.json` and lists doc chapters that watch the files you touched. Exit code `2` is informational, not blocking — but you should note affected tours/chapters in the commit message and re-run them after deploy.
3. **Stage *only* the session's files.** Always `git add <path>` per file. Never `git add -A` or `git add .` — that picks up stray local artefacts (`.env.local` edits, IDE config, test fixtures, the various `Current AGA Members.xlsx` files that float around the repo root) and ships them.
4. **Commit in Conventional Commits style** with a Claude trailer:

   ```
   feat(store): add discount stacking guard

   Co-Authored-By: Claude
   ```

   Prefixes in use: `feat:`, `fix:`, `refactor:`, `docs:`, `chore:`. Scope in parens is optional but helpful (`feat(tours): …`). Body should answer *why*, not just *what*.
5. **`git push origin main`.** That's the last thing Pat does locally. After this, the session stops and Pat takes over in cPanel.
6. **In cPanel:** Git Version Control → find the `draft.goldwing.org.au` repo → **Update from Remote** (pulls commits) → **Deploy HEAD Commit** (runs `.cpanel.yml`). The UI shows the SHA before and after.
7. **Verify.** Log in to `/admin/`, glance at the dashboard, click into the section the change touched. If a tour was flagged, run it from `/admin/help/validator.php` → "Test now".

### Database changes

Migrations are **not** auto-run. The site has no migration runner — each `database/*.sql` file is applied by hand via phpMyAdmin (see [Chapter 03 — Database & migrations](view.php?slug=03-database-migrations)). After a deploy that includes a new SQL file: open phpMyAdmin, select the goldwing database, import the file, re-check the affected admin page. Forgetting this is the most common cause of "I deployed and now the page errors with `Unknown column`" — there is no schema check on boot.

## Where to change it

The deploy *process* is documented here. The deploy *config* is two things:

- **`.cpanel.yml`** at the repo root — edit if you need a post-deploy task (currently none).
- **cPanel → Git Version Control** for the repo URL, branch, and deploy path. Pat is the only one who should touch this.

For the legacy server bootstrap (initial setup, MySQL user creation, Stripe webhook URL), see `DEPLOY.md` at the repo root.

## Settings

This chapter has no settings of its own. Deploy behaviour is hard-coded in `.cpanel.yml` and the cPanel UI.

## Screenshots

<!-- SCREENSHOT: cPanel Git Version Control page for the draft.goldwing.org.au repo, showing the "Update from Remote" and "Deploy HEAD Commit" buttons and the current/remote SHAs. Save as public_html/admin/help/images/33-cpanel-git-deploy.png and uncomment the line below. -->
<!-- ![cPanel Git deploy](../images/33-cpanel-git-deploy.png) -->

## Gotchas

- **NEVER use FTP.** The legacy `scripts/ftp_upload_changed.py` and `scripts/ftp_auto_upload.py` exist but must not be run. They write files outside git's awareness, leave the cPanel working tree dirty, and break the next `Update from Remote`. If you've already done it, `git status` on the server and reconcile by hand with `git checkout -- <path>` per file (never blanket).
- **NEVER `git clean` on the server.** The live working tree holds untracked-but-required files: `.htaccess`, `.user.ini`, `.well-known/` (Let's Encrypt), `public_html/uploads/` (user-uploaded media — see [Chapter 25 — Media library](view.php?slug=25-media-library)), runtime caches. `git clean -fd` erases all of them.
- **NEVER `git reset --hard` on the server without inventory.** Local config (`.env`, `config/database.php`) is not in git and will vanish.
- **`public_html/uploads/` IS in the deploy path.** If you accidentally commit a user-uploaded file locally, it ships. Keep `public_html/uploads/` out of `git add` unless you intend to seed media.
- **cPanel sometimes silently fails the Update from Remote.** Verify the displayed SHA matches what you pushed (`git rev-parse origin/main` locally). If it doesn't, re-trigger — usually a transient SSH hiccup.
- **Migrations don't auto-run.** If a deploy added a SQL file under `database/`, apply it via phpMyAdmin or the page will error.
- **Server timezone vs Australia/Sydney.** cPanel cron times use the server TZ (typically UTC), but PHP code uses `Australia/Sydney` from `site.timezone`. Schedule cron in server time and watch DST boundaries. See [Chapter 34 — Cron jobs](view.php?slug=34-cron-jobs).
- **No staging vs live split yet.** `draft.goldwing.org.au` is both staging and effectively live until production cut-over. Treat every push as a production push.

## Related chapters

- [02 — Codebase map](view.php?slug=02-codebase-map) — what's in each folder you're deploying.
- [03 — Database & migrations](view.php?slug=03-database-migrations) — the manual-migrations rule explained.
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — server timezone, scheduled tasks deployed alongside the code.
- [35 — Logs & troubleshooting](view.php?slug=35-logs-troubleshooting) — where to look when a deploy goes wrong.
- [36 — Tours system](view.php?slug=36-tours-system) — the tour-impact-check counterpart, what to do when a tour is flagged.
