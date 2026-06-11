-- Informational log of member self-service profile edits, surfaced in the
-- admin Notification Hub as the 'profile_update' type. No approval flow:
-- PENDING simply means unread; archiving marks the entry as read.
CREATE TABLE IF NOT EXISTS member_profile_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'member',
  -- JSON array of {field, label, old, new} for each changed field.
  changes TEXT NOT NULL,
  -- 1 when the change touches email, phone, or postal address — the fields
  -- that matter most before a CSV export / external mail-out.
  has_contact_change TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('PENDING','ARCHIVED') NOT NULL DEFAULT 'PENDING',
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_mpu_status_created (status, created_at),
  INDEX idx_mpu_member_created (member_id, created_at),
  CONSTRAINT fk_mpu_member FOREIGN KEY (member_id) REFERENCES members(id),
  CONSTRAINT fk_mpu_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
