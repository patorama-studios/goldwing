-- Member-editable join_date with admin approval.
--
-- 1. Adds a dedicated `join_date` column to `members` so the actual joining
--    date can differ from `created_at` (which only records when the row was
--    inserted into the database — wrong for migrated members).
-- 2. Adds `member_profile_change_requests` for the notification-hub flow:
--    members request changes to selected profile fields and an admin
--    approves / denies them.

ALTER TABLE members
  ADD COLUMN join_date DATE NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS member_profile_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  field_name VARCHAR(64) NOT NULL,
  current_value TEXT NULL,
  requested_value TEXT NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  rejection_reason TEXT NULL,
  feedback_message TEXT NULL,
  requested_at DATETIME NOT NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  INDEX idx_mpcr_status_member (status, member_id),
  INDEX idx_mpcr_requested_at (requested_at),
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
