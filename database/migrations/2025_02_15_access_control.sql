-- Page access control registry

CREATE TABLE IF NOT EXISTS pages_registry (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_key VARCHAR(120) NOT NULL UNIQUE,
  label VARCHAR(160) NOT NULL,
  path_pattern VARCHAR(220) NOT NULL,
  match_type ENUM('exact','prefix') NOT NULL DEFAULT 'exact',
  nav_group VARCHAR(120) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS page_role_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_id INT NOT NULL,
  role VARCHAR(60) NOT NULL,
  can_access TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_page_role (page_id, role),
  INDEX idx_page_role (role),
  FOREIGN KEY (page_id) REFERENCES pages_registry(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
