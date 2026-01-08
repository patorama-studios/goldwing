# Goldwing Calendar + Events

Production-ready calendar/events module for cPanel (PHP 8.1 + MySQL 8).

## Setup
1. Create a MySQL database and user.
2. Import the schema:
   - Run `calendar/sql/schema.sql` in your database (creates `calendar_*` tables).
3. Update configuration:
   - Edit `calendar/config/config.php` with your DB credentials, base URL, and Stripe keys.
4. Ensure writable paths:
   - `calendar/public/tickets` must be writable by PHP (created automatically on first webhook).
5. Upload the `calendar` folder to your cPanel site.

## Stripe Webhook
- Set webhook endpoint to: `https://your-domain.com/calendar/public/webhook_stripe.php`
- Add the signing secret to `config.php` (`stripe.webhook_secret`).

## Cron Jobs (cPanel)
- Weekly digest (run weekly):
  - `php /path/to/calendar/cron/weekly_digest.php`
- Reminders (run hourly):
  - `php /path/to/calendar/cron/reminders.php`

## Notes
- RSVP and ticket checkout require a logged-in member (`$_SESSION['user_id']`).
- Events are created via `calendar/public/admin_event_create.php`.
- Event management uses role checks (`SUPER_ADMIN`, `ADMIN`, `CHAPTER_LEADER`, `COMMITTEE`, `TREASURER`).
- Calendar data uses `calendar_*` tables to avoid clashing with any existing events table.
- Media thumbnails are selected from `media_library`.
