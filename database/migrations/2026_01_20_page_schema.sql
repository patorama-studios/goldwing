ALTER TABLE pages
  ADD COLUMN schema_json MEDIUMTEXT NULL AFTER html_content;
