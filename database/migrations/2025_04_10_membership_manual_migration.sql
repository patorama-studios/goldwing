-- Manual membership migration + admin overrides

CREATE TABLE IF NOT EXISTS membership_migration_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  disabled_at DATETIME NULL,
  created_by INT NULL,
  sent_at DATETIME NULL,
  send_count INT NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_membership_migration_token (token_hash),
  INDEX idx_membership_migration_member (member_id),
  INDEX idx_membership_migration_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS manual_migration_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER updated_at,
  ADD COLUMN IF NOT EXISTS manual_migration_disabled_at DATETIME NULL AFTER manual_migration_disabled,
  ADD COLUMN IF NOT EXISTS manual_migration_disabled_by INT NULL AFTER manual_migration_disabled_at,
  ADD CONSTRAINT fk_members_manual_migration_disabled_by FOREIGN KEY (manual_migration_disabled_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(40) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS order_source VARCHAR(60) NULL AFTER payment_method,
  ADD COLUMN IF NOT EXISTS order_reference VARCHAR(120) NULL AFTER order_source,
  ADD COLUMN IF NOT EXISTS internal_notes TEXT NULL AFTER order_reference;
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER internal_notes,
  ADD CONSTRAINT fk_payments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
