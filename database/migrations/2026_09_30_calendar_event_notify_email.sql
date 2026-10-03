-- Per-event RSVP notification address: when set, an email goes to it each
-- time a member RSVPs. Applied on prod via /admin/run-migration.php (Migration 052).
ALTER TABLE calendar_events ADD COLUMN notify_email VARCHAR(255) NULL AFTER rsvp_enabled;
