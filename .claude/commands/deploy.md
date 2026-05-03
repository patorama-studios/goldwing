# Goldwing Deploy Skill

Deploy the current branch to the live site (draft.goldwing.org.au) via cPanel Git Version Control, debug any issues, and verify the deployment.

## Environment

- **Live site**: https://draft.goldwing.org.au
- **cPanel host**: https://goldwing.org.au:2083
- **cPanel user**: goldwing
- **Git repo path on server**: `/home2/goldwing/draft.goldwing.org.au/` (note: cPanel API may report `/home/goldwing/` — the real disk path is `/home2/`)
- **Deployment file**: `.cpanel.yml` (must exist in repo root)
- **Branch**: `main`

## Step 1 — Ensure .cpanel.yml exists and is correct

The file must be committed in the repo root:

```yaml
---
deployment:
  tasks:
    - export DEPLOYPATH=/home/goldwing/draft.goldwing.org.au/
    - /bin/true
```

If it's missing, create and commit it before proceeding.

## Step 2 — Push all commits to GitHub

```bash
git push origin main
```

Confirm the push succeeded and the remote is up to date.

## Step 3 — Trigger cPanel Git pull via UAPI

cPanel does NOT auto-deploy on push — you must trigger it manually via the UAPI.

### Get the repository clone URL (needed for deployment API)

```
GET https://goldwing.org.au:2083/execute/VersionControl/retrieve
  ?type=git
```

Extract the `repository_root` value from the response (needed as the `repository_root` parameter below).

### Trigger the deployment

```
POST https://goldwing.org.au:2083/execute/VersionControlDeployment/create
  repository_root=<value from above>
  branch=main
```

A `status: 1` response with `data: []` can be a silent failure — check the actual files on disk to confirm.

### Verify files were updated

Use the cPanel Fileman API to check the modified timestamp of a recently-changed file:

```
GET https://goldwing.org.au:2083/execute/Fileman/list_files
  ?dir=/home2/goldwing/draft.goldwing.org.au/
  &include_mime=0
```

Or fetch the live page and look for the expected change.

## Step 4 — Verify the live site

- Visit https://draft.goldwing.org.au and confirm the change is present
- If testing auth or member features, use the Chrome MCP tools to test the golden path
- Check browser console / network tab for errors

## Common Issues & Fixes

### `deployable: 0` / Deploy button greyed out in cPanel UI

- **Cause**: `.cpanel.yml` missing or not committed; or cPanel internal state issue
- **Fix**: Ensure `.cpanel.yml` is committed and pushed; try triggering `VersionControlDeployment/create` via API directly anyway — the button state and the API are independent

### cPanel API path discrepancy

- cPanel VersionControl API reports repo root as `/home/goldwing/...`
- Actual disk path is `/home2/goldwing/...`
- Always use `/home2/goldwing/` when calling Fileman API or writing server-side scripts

### Changes committed but not appearing on server

1. Check git log on server — cPanel may not have pulled the latest commit
2. Use Fileman API to upload a patch script directly as a workaround:
   - Upload to `/home2/goldwing/draft.goldwing.org.au/public_html/_patch.php`
   - Run via browser: `https://draft.goldwing.org.au/_patch.php`
   - **Delete the script immediately after** via Fileman API

### Uploading a temporary server-side script via Fileman

```
POST https://goldwing.org.au:2083/execute/Fileman/save_file_content
  dir=/home2/goldwing/draft.goldwing.org.au/public_html
  filename=_patch.php
  content=<php script content, URL-encoded>
```

Delete afterward:

```
POST https://goldwing.org.au:2083/execute/Fileman/delete_files
  files=[{"dir":"/home2/goldwing/draft.goldwing.org.au/public_html","file":"_patch.php"}]
```

## Authentication for cPanel API calls

All cPanel UAPI calls require HTTP Basic Auth:
- Username: `goldwing`
- Password: ask the user — never store credentials here

Pass as: `Authorization: Basic base64(goldwing:PASSWORD)`
