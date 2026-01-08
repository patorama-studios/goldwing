-- Security system schema updates

CREATE TABLE IF NOT EXISTS security_settings (
  id INT PRIMARY KEY,
  enable_2fa TINYINT(1) NOT NULL DEFAULT 1,
  twofa_mode ENUM('REQUIRED_FOR_ALL','REQUIRED_FOR_ROLES','OPTIONAL_FOR_ALL','DISABLED') NOT NULL DEFAULT 'REQUIRED_FOR_ALL',
  twofa_required_roles_json TEXT NULL,
  twofa_grace_days INT NOT NULL DEFAULT 0,
  stepup_enabled TINYINT(1) NOT NULL DEFAULT 1,
  stepup_window_minutes INT NOT NULL DEFAULT 10,
  login_ip_max_attempts INT NOT NULL DEFAULT 10,
  login_ip_window_minutes INT NOT NULL DEFAULT 10,
  login_account_max_attempts INT NOT NULL DEFAULT 5,
  login_account_window_minutes INT NOT NULL DEFAULT 15,
  login_lockout_minutes INT NOT NULL DEFAULT 30,
  login_progressive_delay TINYINT(1) NOT NULL DEFAULT 1,
  alert_email VARCHAR(255) NULL,
  alerts_json TEXT NULL,
  fim_enabled TINYINT(1) NOT NULL DEFAULT 1,
  fim_paths_json TEXT NULL,
  fim_exclude_paths_json TEXT NULL,
  webhook_alerts_enabled TINYINT(1) NOT NULL DEFAULT 1,
  webhook_alert_threshold INT NOT NULL DEFAULT 3,
  webhook_alert_window_minutes INT NOT NULL DEFAULT 10,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO security_settings (
  id,
  enable_2fa,
  twofa_mode,
  twofa_required_roles_json,
  twofa_grace_days,
  stepup_enabled,
  stepup_window_minutes,
  login_ip_max_attempts,
  login_ip_window_minutes,
  login_account_max_attempts,
  login_account_window_minutes,
  login_lockout_minutes,
  login_progressive_delay,
  alert_email,
  alerts_json,
  fim_enabled,
  fim_paths_json,
  fim_exclude_paths_json,
  webhook_alerts_enabled,
  webhook_alert_threshold,
  webhook_alert_window_minutes,
  updated_at
) VALUES (
  1,
  1,
  'REQUIRED_FOR_ALL',
  '[]',
  0,
  1,
  10,
  10,
  10,
  5,
  15,
  30,
  1,
  NULL,
  '{"failed_login":true,"new_admin_device":true,"refund_created":true,"role_escalation":true,"member_export":true,"fim_changes":true,"webhook_failure":true}',
  1,
  '["/app","/admin","/config"]',
  '["/uploads","/cache"]',
  1,
  3,
  10,
  NOW()
) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);

CREATE TABLE IF NOT EXISTS user_2fa (
  user_id INT PRIMARY KEY,
  totp_secret_encrypted TEXT NULL,
  enabled_at DATETIME NULL,
  last_verified_at DATETIME NULL,
  recovery_codes_json TEXT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_security_overrides (
  user_id INT PRIMARY KEY,
  twofa_override ENUM('DEFAULT','REQUIRED','EXEMPT') NOT NULL DEFAULT 'DEFAULT',
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) PRIMARY KEY,
  user_id INT NULL,
  data MEDIUMTEXT NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_activity_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_sessions_user (user_id),
  INDEX idx_sessions_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotent column updates for login_attempts
SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'user_id'),
  'SELECT 1',
  'ALTER TABLE login_attempts ADD COLUMN user_id INT NULL AFTER email'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'attempts_count'),
  'SELECT 1',
  'ALTER TABLE login_attempts ADD COLUMN attempts_count INT NOT NULL DEFAULT 0'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'first_attempt_at'),
  'SELECT 1',
  'ALTER TABLE login_attempts ADD COLUMN first_attempt_at DATETIME NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'last_attempt_at'),
  'SELECT 1',
  'ALTER TABLE login_attempts ADD COLUMN last_attempt_at DATETIME NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'locked_until'),
  'SELECT 1',
  'ALTER TABLE login_attempts ADD COLUMN locked_until DATETIME NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'updated_at'),
  'SELECT 1',
  'ALTER TABLE login_attempts ADD COLUMN updated_at DATETIME NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE login_attempts
  MODIFY COLUMN email VARCHAR(255) NULL,
  MODIFY COLUMN ip_address VARCHAR(45) NULL;

-- Idempotent column updates for password_resets
SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'token_hash'),
  'SELECT 1',
  'ALTER TABLE password_resets ADD COLUMN token_hash VARCHAR(255) NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'used_at'),
  'SELECT 1',
  'ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'ip_address'),
  'SELECT 1',
  'ALTER TABLE password_resets ADD COLUMN ip_address VARCHAR(45) NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'token'),
  'ALTER TABLE password_resets DROP COLUMN token',
  'SELECT 1'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS stepup_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  issued_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_stepup_user (user_id),
  INDEX idx_stepup_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trusted_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  device_fingerprint_hash VARCHAR(255) NOT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  label VARCHAR(255) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_trusted_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS file_integrity_baseline (
  id INT PRIMARY KEY,
  baseline_json LONGTEXT NULL,
  approved_by_user_id INT NULL,
  approved_at DATETIME NULL,
  last_scan_at DATETIME NULL,
  last_scan_status ENUM('OK','CHANGES_DETECTED','ERROR') NULL,
  last_scan_report_json LONGTEXT NULL,
  FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO file_integrity_baseline (id, baseline_json, approved_by_user_id, approved_at)
VALUES (1, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE id = id;

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

-- Idempotent column updates for activity_log
SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'target_type'),
  'SELECT 1',
  'ALTER TABLE activity_log ADD COLUMN target_type VARCHAR(50) NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'target_id'),
  'SELECT 1',
  'ALTER TABLE activity_log ADD COLUMN target_id INT NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'user_id'),
  'SELECT 1',
  'ALTER TABLE activity_log ADD COLUMN user_id INT NULL'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND INDEX_NAME = 'idx_activity_target'),
  'SELECT 1',
  'ALTER TABLE activity_log ADD INDEX idx_activity_target (target_type, target_id)'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @stmt = (SELECT IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND INDEX_NAME = 'idx_activity_user'),
  'SELECT 1',
  'ALTER TABLE activity_log ADD INDEX idx_activity_user (user_id)'
));
PREPARE stmt FROM @stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;
