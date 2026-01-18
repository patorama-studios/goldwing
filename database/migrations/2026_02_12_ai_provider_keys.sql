CREATE TABLE IF NOT EXISTS ai_provider_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) NOT NULL UNIQUE,
  api_key_encrypted TEXT NOT NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
