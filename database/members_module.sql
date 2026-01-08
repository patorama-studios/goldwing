-- Members module schema updates
SET FOREIGN_KEY_CHECKS = 0;

-- Membership types lookup table
CREATE TABLE IF NOT EXISTS membership_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  billing_period ENUM('annual','one_off') NOT NULL DEFAULT 'annual',
  price_cents INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_membership_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed membership types
INSERT INTO membership_types (name, billing_period, price_cents, is_active, created_at)
VALUES
  ('Full', 'annual', 0, 1, NOW()),
  ('Associate', 'annual', 0, 1, NOW()),
  ('Life', 'one_off', 0, 1, NOW())
ON DUPLICATE KEY UPDATE
  billing_period = VALUES(billing_period),
  price_cents = VALUES(price_cents),
  is_active = VALUES(is_active),
  updated_at = NOW();

-- Chapters enhancements (state + active flag)
SET @chapter_state_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chapters' AND COLUMN_NAME = 'state');
SET @chapter_active_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chapters' AND COLUMN_NAME = 'is_active');
SET @chapter_sort_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chapters' AND COLUMN_NAME = 'sort_order');

SET @chapter_state_sql = IF(@chapter_state_exists = 0, 'ALTER TABLE chapters ADD COLUMN state VARCHAR(150) NULL AFTER region', 'SELECT 1');
PREPARE stmt FROM @chapter_state_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @chapter_active_sql = IF(@chapter_active_exists = 0, 'ALTER TABLE chapters ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @chapter_active_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @chapter_sort_sql = IF(@chapter_sort_exists = 0, 'ALTER TABLE chapters ADD COLUMN sort_order INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @chapter_sort_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE chapters SET state = region WHERE state IS NULL;
UPDATE chapters SET sort_order = id WHERE sort_order = 0;

-- Seed key chapters for Australian states/groups
INSERT INTO chapters (name, state, is_active)
SELECT 'Central Coast Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Central Coast Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Central West Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Central West Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Coffs Coast Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Coffs Coast Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'New England Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'New England Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Northwest Chapter B', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Northwest Chapter B');

INSERT INTO chapters (name, state, is_active)
SELECT 'Riverina Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Riverina Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Sydney Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Sydney Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Brisbane Chapter', 'Queensland', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Brisbane Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Fraser Coast Chapter', 'Queensland', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Fraser Coast Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'South Australian Chapter', 'South Australia', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'South Australian Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Tasmania Chapter', 'Tasmania', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Tasmania Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Perth Chapter', 'Western Australia', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Perth Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'West Coast Wings Chapter', 'Western Australia', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'West Coast Wings Chapter');

-- Members table extensions
ALTER TABLE members
  ADD COLUMN IF NOT EXISTS member_number VARCHAR(120) NULL AFTER member_number_suffix,
  ADD COLUMN IF NOT EXISTS suburb VARCHAR(120) NULL AFTER address_line2,
  ADD COLUMN IF NOT EXISTS membership_type_id INT NULL AFTER chapter_id,
  ADD COLUMN IF NOT EXISTS directory_pref_a_collect_motorcycle TINYINT(1) NOT NULL DEFAULT 0 AFTER country,
  ADD COLUMN IF NOT EXISTS directory_pref_b_accept_calls TINYINT(1) NOT NULL DEFAULT 0 AFTER directory_pref_a_collect_motorcycle,
  ADD COLUMN IF NOT EXISTS directory_pref_c_bed_or_tent TINYINT(1) NOT NULL DEFAULT 0 AFTER directory_pref_b_accept_calls,
  ADD COLUMN IF NOT EXISTS directory_pref_d_tools_or_workshop TINYINT(1) NOT NULL DEFAULT 0 AFTER directory_pref_c_bed_or_tent,
  ADD COLUMN IF NOT EXISTS directory_pref_e_exclude_member_directory TINYINT(1) NOT NULL DEFAULT 0 AFTER directory_pref_d_tools_or_workshop,
  ADD COLUMN IF NOT EXISTS directory_pref_f_exclude_electronic_directory TINYINT(1) NOT NULL DEFAULT 0 AFTER directory_pref_e_exclude_member_directory,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER directory_pref_f_exclude_electronic_directory;

-- Normalize neighbourhood data
UPDATE members SET suburb = city WHERE suburb IS NULL;

-- Populate directory preferences from legacy flags (if present)
UPDATE members SET
  directory_pref_a_collect_motorcycle = assist_ute,
  directory_pref_b_accept_calls = assist_phone,
  directory_pref_c_bed_or_tent = assist_bed,
  directory_pref_d_tools_or_workshop = assist_tools,
  directory_pref_e_exclude_member_directory = exclude_printed,
  directory_pref_f_exclude_electronic_directory = exclude_electronic;

-- Store combined member number for quick display
UPDATE members SET member_number = CASE WHEN member_number_suffix > 0 THEN CONCAT(member_number_base, '.', member_number_suffix) ELSE CAST(member_number_base AS CHAR) END WHERE member_number_base IS NOT NULL;

-- Relax status column before the enum change
ALTER TABLE members
  MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending';

UPDATE members SET status = 'pending' WHERE status = 'PENDING';
UPDATE members SET status = 'active' WHERE status = 'ACTIVE';
UPDATE members SET status = 'expired' WHERE status = 'LAPSED';
UPDATE members SET status = 'cancelled' WHERE status = 'INACTIVE';

ALTER TABLE members
  MODIFY COLUMN status ENUM('pending','active','expired','cancelled','suspended') NOT NULL DEFAULT 'pending';

-- Reference the new membership types
ALTER TABLE members
  ADD INDEX idx_members_email (email),
  ADD INDEX idx_members_chapter (chapter_id),
  ADD INDEX idx_members_membership_type (membership_type_id),
  ADD INDEX idx_members_status (status),
  ADD INDEX idx_members_last_name (last_name),
  ADD UNIQUE KEY uniq_members_email (email);

ALTER TABLE members
  ADD CONSTRAINT fk_members_membership_type FOREIGN KEY (membership_type_id) REFERENCES membership_types(id);

-- Map existing member records to seeded membership types
UPDATE members m
JOIN membership_types mt ON mt.name = 'Full'
SET m.membership_type_id = mt.id
WHERE m.member_type = 'FULL';

UPDATE members m
JOIN membership_types mt ON mt.name = 'Associate'
SET m.membership_type_id = mt.id
WHERE m.member_type = 'ASSOCIATE';

UPDATE members m
JOIN membership_types mt ON mt.name = 'Life'
SET m.membership_type_id = mt.id
WHERE m.member_type = 'LIFE';

-- Member authentication store
CREATE TABLE IF NOT EXISTS member_auth (
  member_id INT PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  password_reset_token VARCHAR(64) NULL,
  password_reset_expires_at DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  failed_login_count INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vehicles per member
CREATE TABLE IF NOT EXISTS member_vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  vehicle_type ENUM('bike','trike','sidecar','trailer') NOT NULL,
  make VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  year_from YEAR(4) NULL,
  year_to YEAR(4) NULL,
  year_exact YEAR(4) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_member_vehicles_member (member_id),
  INDEX idx_member_vehicles_type (vehicle_type),
  INDEX idx_member_vehicles_make (make),
  INDEX idx_member_vehicles_model (model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refunds table linking to store orders
CREATE TABLE IF NOT EXISTS store_refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  member_id INT NOT NULL,
  amount_cents INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  stripe_refund_id VARCHAR(120) NULL,
  status ENUM('requested','processed','failed') NOT NULL DEFAULT 'requested',
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_store_refunds_member (member_id),
  INDEX idx_store_refunds_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Event RSVP tracking
CREATE TABLE IF NOT EXISTS event_rsvps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  member_id INT NOT NULL,
  status ENUM('going','interested','not_going','cancelled') NOT NULL DEFAULT 'interested',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_event_rsvps_member (member_id),
  INDEX idx_event_rsvps_event (event_id),
  INDEX idx_event_rsvps_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Download logging
CREATE TABLE IF NOT EXISTS downloads_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  file_path VARCHAR(255) NULL,
  action ENUM('download') NOT NULL DEFAULT 'download',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_downloads_member (member_id),
  INDEX idx_downloads_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unified activity log
CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('admin','member','system') NOT NULL,
  actor_id INT NULL,
  member_id INT NULL,
  action VARCHAR(100) NOT NULL,
  metadata JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  INDEX idx_activity_member (member_id),
  INDEX idx_activity_created (created_at),
  INDEX idx_activity_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
