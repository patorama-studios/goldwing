# Goldwing Website — Handover Procedure & Checklist

**Who this is for:** the outgoing developer (Patorama Studios) and the incoming committee webmaster.
**Companions:** the *Committee Manual PDF* (generated from Admin → Help → System Documentation → Print manual), and in the manual itself **Appendix C — Technical specifications** and **Appendix D — Handover & emergency runbook**.

The goal: after handover day the **committee owns every account and credential**, the developer's admin login is **locked by default** (unlockable only through a webmaster-granted access window), and a future agency could take over from the documents alone.

---

## 1. What's being handed over

| # | Asset | What it is | Currently held by | Transfers to | Done |
|---|-------|-----------|-------------------|--------------|:---:|
| 1 | Domain registrar login | Owns `goldwing.org.au` + DNS | *(fill in)* | Webmaster | ☐ |
| 2 | Hosting / cPanel login | The server: files, database, email routing, deploys, backups | *(fill in)* | Webmaster | ☐ |
| 3 | GitHub repository | The website's source code | Patorama Studios | Club-owned GitHub org (webmaster admin) | ☐ |
| 4 | Stripe account | Card payments, refunds, payouts to the club bank account | *(fill in — confirm owner email)* | Committee (treasurer + webmaster as admins) | ☐ |
| 5 | Site mailbox / SMTP login | The mailbox the site sends from | *(fill in)* | Webmaster | ☐ |
| 6 | Google Cloud project | Google Maps API key (address autocomplete) | *(fill in)* | Webmaster | ☐ |
| 7 | kie.ai account | AI page-builder credits (optional service) | *(fill in)* | Webmaster (or close it) | ☐ |
| 8 | Server `.env` values + `APP_KEY` | DB credentials + master encryption key | Developer | Committee password manager | ☐ |
| 9 | Webmaster admin login on the site | Day-to-day admin access | — | Webmaster (their own account, own 2FA) | ☐ |
| 10 | Committee Manual PDF | The full documentation snapshot | — | Committee drive | ☐ |

**Credential rule:** every password moves via the **committee password manager** (e.g. a free Bitwarden Organization or 1Password Families) — never by email or printout. Each account gets 2FA on a committee-held phone, with recovery codes stored in the vault.

---

## 2. Before handover day (developer prep)

- [ ] **Rotate the production database password** — in this exact order, during a quiet hour:
  1. cPanel → MySQL Databases → change the DB user's password.
  2. Immediately update `DB_PASS` in the server's `.env` (cPanel → File Manager, account root).
  3. Load the site and log in — confirm everything works.
  4. In a **follow-up deploy**, remove the old hardcoded fallback credentials from `config/database.php` (replace the `?:` fallbacks with empty strings). ⚠️ Do step 4 only after steps 1–3 are verified — the fallbacks are what the site uses if `.env` is missing, so removing them first would take the site down.
- [ ] Diff the server `.env` against `.env.example` — every key present and correct.
- [ ] Create the webmaster's **own admin account** on the site (Members console → admin role) — the developer's login is never shared.
- [ ] Take a **full backup**: cPanel full account backup + database backup → committee drive.
- [ ] Generate the **Committee Manual PDF** and put it in the committee drive.
- [ ] Set up the **password manager vault** and load rows 1–8 of the inventory table.
- [ ] **Stripe:** confirm the account owner email is a committee address; developer demoted to team member (or removed). Confirm bank payout details are the club's.
- [ ] **GitHub:** transfer the repository to a club-owned organisation (Settings → Danger Zone → Transfer), keep the developer as an outside collaborator. Update the cPanel Git remote URL afterwards and run one test deploy.
- [ ] Confirm the four **cron jobs** are scheduled in cPanel (manual: Chapter 34).
- [ ] Point the **security alert email** (Admin → Settings → Security) at the webmaster.

---

## 3. Handover day (60–90 minutes, developer + webmaster together)

1. **Manual walk-through (10 min).** Open the PDF, show the 10 parts, the "For administrators" vs "Dev notes" halves, and Appendix D (the runbook they'll actually use).
2. **Accounts ceremony (rows 1–8).** For each: log in → change the password to a vault-generated one → store in the vault → enable/verify 2FA on the committee phone → tick the table.
3. **Website roles.** Webmaster logs in with their own account + 2FA. Deactivate any stale admin accounts in the Members console.
4. **Enable the developer lockout — and test it end-to-end (5 min):**
   1. Webmaster: Admin → Settings → **Developer Access** → *Enable handover lockout*.
   2. Developer tries to log in → refused with "Developer access is currently locked".
   3. Webmaster clicks **Grant access** (1 day) → developer receives the email, logs in fine.
   4. Webmaster clicks **Revoke access now** → developer's next click ends their session.
   5. Check the history panel shows all four events. *The lockout is now proven, not assumed.*
5. **Deploy dry run.** Webmaster performs one real deploy themselves (cPanel → Git Version Control → Update from Remote → Deploy HEAD Commit) with the developer watching. Manual: Chapter 33.
6. **Backup dry run.** Webmaster downloads a database backup and the members CSV per the Appendix D routine.
7. **Sign-off.** Both parties confirm the inventory table is fully ticked; agree the support arrangement below; grant the developer a settling-in window (2 weeks suggested), after which the default state is locked.

---

## 4. After handover

- **Requesting work:** committee agrees scope with the developer → webmaster grants an access window (1 week default) → developer confirms by reply when done → window expires or is revoked. Never leave a window open "just in case".
- **Support expectations:** *(fill in — response time, hourly rate / retainer, preferred contact)*
- **Quarterly webmaster routine:** database backup + members CSV (Appendix D), review admin accounts, glance at Stripe payouts, check domain/hosting renewal dates.
- **Outstanding technical follow-ups** (developer, inside a granted window):
  - [ ] Remove the hardcoded DB fallbacks from `config/database.php` (step 4 of §2, if not already done).
  - [ ] Consider an automated offsite database backup (currently a manual routine).

---

## 5. If a new agency takes over later

Hand them: GitHub access (transfer or collaborator), the latest Manual PDF, this file, the `.env` values via the vault, and either a granted access window or their own gated email in **Settings → Developer Access**. The site is plain PHP 8 + MySQL with no proprietary platform lock-in — any competent PHP developer can run it from these documents. New-agency quick start: manual **Appendix C**, "Dev notes".

---

## 6. Handover record

| Date | Action | Done by |
|------|--------|---------|
| | DB password rotated, server `.env` updated | |
| | Vault created; all credentials transferred + 2FA moved | |
| | GitHub repository transferred; deploy re-tested | |
| | Stripe ownership confirmed to committee | |
| | Handover lockout enabled and tested end-to-end | |
| | Settling-in window granted (ends: ______ ) | |
| | `config/database.php` fallbacks removed | |
