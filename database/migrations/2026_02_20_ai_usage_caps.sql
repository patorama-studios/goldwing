CREATE TABLE IF NOT EXISTS ai_usage_monthly (
  id INT AUTO_INCREMENT PRIMARY KEY,
  month_key VARCHAR(7) NOT NULL,
  provider VARCHAR(40) NOT NULL,
  total_usd_cents INT NOT NULL DEFAULT 0,
  total_tokens INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_usage_month_provider (month_key, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
