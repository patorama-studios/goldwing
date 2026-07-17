-- Event PDF attachment: admins can attach a downloadable PDF (flyer,
-- itinerary, entry form) to a calendar event. Applied on prod via
-- /admin/run-migration.php (Migration 046).
ALTER TABLE calendar_events ADD COLUMN attachment_path VARCHAR(500) NULL AFTER media_id;
ALTER TABLE calendar_events ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path;
