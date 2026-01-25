ALTER TABLE media
  ADD COLUMN file_name VARCHAR(255) NULL AFTER title,
  ADD COLUMN file_path VARCHAR(255) NULL AFTER path,
  ADD COLUMN file_type VARCHAR(100) NULL AFTER file_path,
  ADD COLUMN file_size INT NULL AFTER file_type,
  ADD COLUMN uploaded_by_user_id INT NULL AFTER uploaded_by,
  ADD COLUMN source_context VARCHAR(60) NULL AFTER uploaded_by_user_id,
  ADD COLUMN source_table VARCHAR(120) NULL AFTER source_context,
  ADD COLUMN source_record_id INT NULL AFTER source_table;

CREATE INDEX idx_media_file_path ON media (file_path);
CREATE INDEX idx_media_source_context ON media (source_context);
