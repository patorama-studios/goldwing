# Australian Goldwing Association Deployment Guide

## 1) Upload files
- Upload the entire project, preserving the folder structure.
- The `/public_html` folder contents must be placed into your cPanel `public_html`.
- Keep `/app`, `/config`, `/database`, `/cron` outside web root (recommended). If you must place them inside web root, protect them with cPanel directory privacy rules.

## 2) Create MySQL database and user
- In cPanel, create a MySQL database and user.
- Grant the user **ALL PRIVILEGES** on the new database.

## 3) Import schema and seed data
- In phpMyAdmin, import `database/schema.sql`.
- Then import `database/seeds.sql`.
- Optional: import `database/about_pages.sql` to update the About section without reloading all seed data.

## 4) Configure app
- Edit `config/database.php` with your database host, name, username, and password.
- Edit `config/app.php`:
  - `base_url` (e.g., `https://yourdomain.com`)
  - `email.from` and `email.from_name`
  - `stripe.secret_key` and `stripe.webhook_secret`
  - `stripe.membership_prices` with your Stripe price IDs
  - `ai.default_model` to pick the default model (default: `claude-sonnet-4-6`)
- The AI page builder is hard-locked to kie.ai. Configure the API key either via:
  - Admin → AI Settings → kie.ai API Key (stored encrypted in DB), OR
  - `KIE_API_KEY` environment variable (fallback)
  - `AI_DEFAULT_MODEL` env var (optional model override)

## 5) Stripe webhook
- In Stripe, add a webhook endpoint:
  - URL: `https://yourdomain.com/api/stripe_webhook.php`
  - Events: `checkout.session.completed`
- Copy the webhook signing secret into `config/app.php`.

## 6) Set permissions
- Ensure `/public_html/uploads` is writable by the web server user.

## 7) Cron jobs
Add the following cPanel cron jobs:
- `0 6 * * * /usr/bin/php /path/to/cron/send_renewal_reminders.php`
- `5 0 * * * /usr/bin/php /path/to/cron/expire_memberships.php`
- Optional daily summary:
  - `15 6 * * * /usr/bin/php /path/to/cron/daily_summary_admin.php`

## 8) Admin login
- Login at `/login.php` using the seeded admin account:
  - Email: `admin@goldwing.local`
  - Password: `Admin123!`
- Update the password immediately from the password reset page.
