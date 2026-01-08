-- Member portal enhancements: notices + fallen wings
ALTER TABLE notices
  ADD COLUMN category ENUM('notice','advert','announcement') NOT NULL DEFAULT 'notice' AFTER content,
  ADD COLUMN audience_scope ENUM('all','state','chapter') NOT NULL DEFAULT 'all' AFTER visibility,
  ADD COLUMN audience_state VARCHAR(100) NULL AFTER audience_scope,
  ADD COLUMN audience_chapter_id INT NULL AFTER audience_state,
  ADD COLUMN attachment_url VARCHAR(255) NULL AFTER audience_chapter_id,
  ADD COLUMN attachment_type ENUM('image','pdf') NULL AFTER attachment_url,
  ADD COLUMN published_at DATETIME NULL AFTER attachment_type,
  ADD CONSTRAINT fk_notices_audience_chapter FOREIGN KEY (audience_chapter_id) REFERENCES chapters(id);

CREATE TABLE IF NOT EXISTS fallen_wings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  year_of_passing INT NOT NULL,
  tribute TEXT NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  submitted_by INT NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
