ALTER TABLE pages
  ADD COLUMN draft_html MEDIUMTEXT NULL AFTER html_content,
  ADD COLUMN live_html MEDIUMTEXT NULL AFTER draft_html,
  ADD COLUMN access_level VARCHAR(60) NOT NULL DEFAULT 'public' AFTER live_html;

UPDATE pages
SET draft_html = COALESCE(draft_html, html_content),
    live_html = COALESCE(live_html, html_content),
    access_level = CASE
      WHEN visibility = 'member' THEN 'role:member'
      WHEN visibility = 'admin' THEN 'role:admin'
      ELSE 'public'
    END;

ALTER TABLE page_versions
  ADD COLUMN version_number INT NULL AFTER change_summary,
  ADD COLUMN version_label VARCHAR(150) NULL AFTER version_number,
  ADD COLUMN html_snapshot MEDIUMTEXT NULL AFTER version_label,
  ADD COLUMN published_by_user_id INT NULL AFTER html_snapshot,
  ADD COLUMN published_at DATETIME NULL AFTER published_by_user_id;

CREATE TABLE page_chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_id INT NOT NULL,
  user_id INT NULL,
  role ENUM('user','assistant','system') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  selected_element_id VARCHAR(80) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (page_id) REFERENCES pages(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
