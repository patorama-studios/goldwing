CREATE TABLE IF NOT EXISTS email_otp_codes (
  user_id INT PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  resend_count INT NOT NULL DEFAULT 0,
  resend_window_started_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_otp_trust (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_email_otp_trust_user (user_id),
  INDEX idx_email_otp_trust_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
