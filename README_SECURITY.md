# Security System Overview

This project includes a production-ready security layer with 2FA, step-up auth, rate limiting, activity logging, webhook monitoring, and file integrity monitoring.

## Setup Checklist

1) Run database migration:
- `database/migrations/2025_01_20_security.sql`

2) Ensure `APP_KEY` is set in `.env` (required for 2FA secret encryption).

3) Configure SMTP (Settings Hub > Integrations):
- `integrations.smtp_host`
- `integrations.smtp_port`
- `integrations.smtp_user`
- `integrations.smtp_password` (stored encrypted)
- `integrations.smtp_encryption` (`tls`, `ssl`, or `none`)

4) Review Security & Authentication settings:
- 2FA policy, grace period, step-up window
- Login rate limits
- Security alert email + alert toggles
- File integrity monitoring paths
- Webhook failure thresholds

## Cron Jobs (cPanel)

File Integrity Monitoring scan (recommended hourly or nightly):
- Command: `php /home/<cpanel-user>/public_html/cron/fim_scan.php`

## 2FA Enrollment Flow

- Users without 2FA are redirected to `/member/2fa_enroll.php` when required.
- Login flow prompts `/member/2fa_verify.php` for TOTP or recovery codes.
- Admins can force/exempt/reset 2FA from the Members screen.

## Step-up Authentication

Sensitive actions (refunds, exports, settings changes, profile edits, baseline approval) require step-up:
- Prompt page: `/stepup.php`
- Validity window: configured in Security & Authentication settings.

## Stripe Webhook Monitoring

- Webhook signature verification via Stripe SDK.
- Idempotency enforced using `webhook_events`.
- Failure alerts configurable in Security & Authentication settings.

## SSO Placeholders

Stub endpoints are available:
- `/auth/google.php`
- `/auth/apple.php`

Set OAuth config in `config/app.php` or environment variables:
- `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, `GOOGLE_OAUTH_REDIRECT_URI`
- `APPLE_OAUTH_CLIENT_ID`, `APPLE_OAUTH_TEAM_ID`, `APPLE_OAUTH_KEY_ID`, `APPLE_OAUTH_PRIVATE_KEY_PATH`, `APPLE_OAUTH_REDIRECT_URI`
