-- Admin Notification Hub: unified approval workflow
-- Adds approval status + feedback support to notices and calendar_events,
-- adds feedback_message column to existing approval tables,
-- and seeds the webmaster role.

-- 1. notices: add approval workflow
ALTER TABLE notices
  ADD COLUMN status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_pinned,
  ADD COLUMN feedback_message TEXT NULL AFTER status,
  ADD COLUMN reviewed_by INT NULL AFTER feedback_message,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD INDEX idx_notices_status (status);

-- 2. calendar_events: extend status enum to include approval states
ALTER TABLE calendar_events
  MODIFY COLUMN status ENUM('draft','pending','approved','rejected','published','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN feedback_message TEXT NULL,
  ADD COLUMN reviewed_by INT NULL,
  ADD COLUMN reviewed_at DATETIME NULL;

-- Note: existing 'published' calendar_events rows are intentionally left as-is.
-- The render code filters WHERE status = 'published', so the notification hub
-- treats 'published' as the approved/live state and continues using it on approval.

-- 3. add feedback_message to existing approval tables
ALTER TABLE fallen_wings
  ADD COLUMN feedback_message TEXT NULL;

ALTER TABLE chapter_change_requests
  ADD COLUMN feedback_message TEXT NULL;

ALTER TABLE member_of_year_nominations
  ADD COLUMN feedback_message TEXT NULL;

-- 4. seed webmaster role
INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
SELECT 'webmaster', 'webmaster', 'Webmaster (reviews and approves member submissions)', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'webmaster');
