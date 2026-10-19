-- Monthly Wings magazine email (Oct 2026). One wings_sends row per send of an
-- issue; one wings_send_recipients row per inbox on the reviewed list, with the
-- personal link token that opens the issue without a portal login.
-- status: queued | sent | failed | removed (unticked on review) | opted_out.
-- Applied on prod via /admin/run-migration.php (Migration 056) — keep in sync.
CREATE TABLE IF NOT EXISTS wings_sends (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  subject VARCHAR(200) NOT NULL,
  message TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  INDEX idx_wings_sends_issue (issue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wings_send_recipients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  send_id INT UNSIGNED NOT NULL,
  member_id INT NULL,
  email VARCHAR(255) NOT NULL,
  greeting VARCHAR(255) NOT NULL DEFAULT '',
  token CHAR(32) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'queued',
  sent_at DATETIME NULL,
  opened_at DATETIME NULL,
  UNIQUE KEY uq_wings_recipient_token (token),
  INDEX idx_wings_recipient_send_status (send_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
