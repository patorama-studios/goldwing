CREATE TABLE IF NOT EXISTS settings_global (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(50) NOT NULL,
  key_name VARCHAR(100) NOT NULL,
  value_json JSON NOT NULL,
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_settings_global (category, key_name),
  FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings_user (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  key_name VARCHAR(100) NOT NULL,
  value_json JSON NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_settings_user (user_id, key_name),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT NULL,
  diff_json JSON NULL,
  ip_address VARCHAR(50) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
