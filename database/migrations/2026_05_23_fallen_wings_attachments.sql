-- Fallen Wings: add image_url and pdf_url attachment columns
-- These were used by submit/edit handlers in public_html/member/index.php
-- and public_html/admin/index.php but never created. The missing columns
-- caused a PHP warning / 500 page when submitting a new fallen-wings entry.

ALTER TABLE fallen_wings
  ADD COLUMN image_url VARCHAR(255) NULL AFTER tribute,
  ADD COLUMN pdf_url   VARCHAR(255) NULL AFTER image_url;
