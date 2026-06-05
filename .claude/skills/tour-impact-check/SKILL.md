---
name: tour-impact-check
description: Check whether any Goldwing tours need re-verification after a code change. Run BEFORE committing or pushing if any tour-watched file has been modified. Use when committing PHP, JS, or template changes to the Goldwing site — especially under `public_html/`, `app/Views/`, or `public_html/assets/js/tours/`. Also run before creating a PR. The check compares staged/unstaged/diff files against `config/tour-manifest.json` and lists tours whose `watched_files` overlap with the change. If any tours are flagged, mention them in the commit/PR description and re-run the affected tour via the admin Tour Validator before deploying.
---

# Tour impact check

Goldwing has a guided-tour system (Driver.js) defined in `config/tour-manifest.json`. Each tour declares a `watched_files` list — files that, if changed, may invalidate the tour's selectors or step text. Whenever code on this site changes, this skill checks whether any tour is affected and flags it for re-verification.

## When to run it

ALWAYS run this skill before:
- Creating a git commit that touches site code
- Opening a PR
- Pushing to `origin/main`

Skip only if the change is purely docs/notes, infra-only (CI config, `.gitignore`, etc), or entirely outside the tour scope.

## How to run

There are two modes — use the one that fits the moment:

**1) Vs. main (default, what you want before a PR):**
```bash
./scripts/check_tour_impact.sh
```
Diffs current HEAD against `origin/main` and reports affected tours.

**2) Specific files (what you want before a commit, or to check a focused change):**
```bash
git diff --name-only --cached | php scripts/check_tour_impact.php
# or staged + unstaged:
git diff --name-only HEAD | php scripts/check_tour_impact.php
# or explicit:
php scripts/check_tour_impact.php public_html/member/index.php
```

## Interpreting the output

- **`✓ No tours affected`** — nothing to do. Continue.
- **`⚠ N tour(s) may be affected`** — for each listed tour:
  1. Note its `slug` in your commit message / PR description (one line: `Affected tours: <slug-1>, <slug-2>`).
  2. After deploy, open `/admin/help/validator.php`, click "Test now" on each affected tour, and walk through it as the configured role to confirm steps still match the page. If a step is wrong, hit "Wrong / confusing" and add a note — that flags the tour as failing and emails the support address.
  3. Also re-run the linter for those tours: `php scripts/lint_tours.php <slug>` — this catches selector drift without needing a human walkthrough.

The script's exit code is `2` when tours are affected (informational, NOT a blocking failure). You can still commit/push; you're just being told the tour may need a refresh.

## Examples of changes that almost always invalidate tours

- Renaming or removing a `data-tour="..."` attribute (definitely breaks the matching tour).
- Changing the wording on a button or label referenced in a tour step's description (steps say "click Save" — if it's now "Publish", the tour misleads users).
- Restructuring a form so the visual order doesn't match the tour order.
- Adding a new step in a multi-page flow (the tour skips the new step silently).
- Moving an admin page to a new URL (the tour's `page_url` and `page_match` need updating).

## What to do if you ADD a new tour-able feature

Add a new entry to `config/tour-manifest.json`:
- pick a slug like `member-foo` or `admin-foo`
- list every `data-tour="..."` selector you placed
- list every file the tour depends on under `watched_files`
- create the steps file at `public_html/assets/js/tours/tours/<slug>.js`

Then run this skill once to confirm the impact check sees the new tour, and run the linter to confirm selectors resolve.
