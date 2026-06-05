---
name: doc-sync-check
description: Check whether any Admin System Documentation chapters need updating after a code change. Run BEFORE committing or pushing whenever a file declared in any chapter's `watched_files` has been modified. Use when committing PHP, JS, SQL, or config changes to the Goldwing site — especially under `app/Services/`, `public_html/admin/`, `database/`, `config/`, `cron/`, or `scripts/`. The check compares staged/unstaged/diff files against `public_html/admin/help/docs/_toc.json` and lists chapters whose `watched_files` overlap with the change. If any chapters are flagged, either update the affected chapter under `public_html/admin/help/docs/chapters/` or note in the commit message why the docs are still accurate. Also run alongside `tour-impact-check` before creating a PR or pushing live.
---

# Documentation impact check

Goldwing has an admin-only system documentation set (the "System Docs" section under `/admin/help/docs/`). Each chapter in `public_html/admin/help/docs/_toc.json` declares a `watched_files` list — files that, if changed, may have made the chapter stale. Whenever code changes, this skill checks which chapters are at risk and prompts an update.

## When to run it

ALWAYS run this skill — together with `tour-impact-check` — before:

- Creating a git commit that touches site code
- Opening a PR
- Pushing to `origin/main` (i.e. anytime Pat says "push live")

Skip only if the change is purely infra (`.gitignore`, hooks, CI config) or entirely outside the documented scope.

## How to run

Two modes — pick the one that fits:

**1) Vs. main + uncommitted (default, what you want before a push):**
```bash
./scripts/check_doc_impact.sh
```
Diffs current HEAD against `origin/main`, plus uncommitted and untracked files, and reports affected chapters.

**2) Targeted (a specific commit, a specific file, or just staged changes):**
```bash
git diff --name-only --cached  | php scripts/check_doc_impact.php
git diff --name-only HEAD      | php scripts/check_doc_impact.php
php scripts/check_doc_impact.php app/Services/RefundService.php
```

## Interpreting the output

- **`✓ No documentation chapters affected`** — nothing to do.
- **`📚 N documentation chapter(s) may need an update`** — for each listed chapter:
  1. Open the chapter file at `public_html/admin/help/docs/chapters/<slug>.md`.
  2. Read the actual code change. Decide whether any of these chapter sections need an edit:
     - **How it works** (logic or flow changed)
     - **Where to change it** (UI path moved, env var renamed)
     - **Settings** (new/renamed/removed settings key)
     - **Gotchas** (a known issue was fixed, or a new one introduced)
  3. Edit the markdown. Keep the chapter's structure (What / Why / How / Where / Settings / Screenshots / Gotchas / Related).
  4. If the change really doesn't affect the docs (e.g. internal refactor with identical behaviour), note that in the commit message:
     `docs: verified <slug> still accurate after <change>`
  5. Re-stage the chapter file alongside the code change so they ship together.
  6. If you ADDED a brand-new subsystem that no chapter covers, add a new entry to `_toc.json` and create the chapter file.

The script exits with code `2` when chapters are affected. This is **informational only** — it does not block the push. The point is to make the impact visible so docs and code stay in sync.

## How to update the watched_files for a chapter

If you add new files that belong to an existing chapter (e.g. a new `app/Services/FooService.php` that's really part of the Stripe story), open `public_html/admin/help/docs/_toc.json`, find the right chapter, and add the path to its `watched_files` array. Use:

- An exact file path (`app/Services/FooService.php`), or
- A directory prefix ending in `/` (`app/Services/AiProviders/`).

## What this skill is NOT

- It does not validate that the documentation prose is correct — only that a code change touched a file the docs declared interest in.
- It does not auto-rewrite chapters. Updating prose is a Claude task triggered by this skill's output, not a script.
- It does not block the commit/push. Use it as a checklist, not a gate.

## Composes with

- `tour-impact-check` — same shape, different target (`config/tour-manifest.json`). Run both together.
- The "push live" deploy workflow — Pat expects both checks to run before he's told the SHA to deploy.
