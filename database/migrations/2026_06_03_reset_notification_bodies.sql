-- Migration: 2026_06_03_reset_notification_bodies
-- Remove the saved 'body' key from each notification template in the catalog.
-- Without a saved body, getCatalogSettings() falls back to the clean code defaults
-- in NotificationService::definitions(), eliminating raw byte-escape sequences
-- (\xe2\x80\x94, \xf0\x9f...) that were stored when the admin UI saved
-- browser-rendered innerHTML. All other template settings (enabled, subject,
-- from_name, from_email, reply_to, recipient_mode, custom_recipients) are preserved.

UPDATE site_settings
SET value = JSON_REMOVE(
    value,
    '$.member_set_password.body',
    '$.member_password_reset_admin.body',
    '$.member_password_reset_self.body',
    '$.security_email_otp.body',
    '$.member_welcome.body',
    '$.membership_approved.body',
    '$.membership_rejected.body',
    '$.membership_expired.body',
    '$.membership_expiring_soon.body',
    '$.payment_receipt.body',
    '$.password_changed.body'
)
WHERE category = 'notifications'
  AND name = 'catalog';
