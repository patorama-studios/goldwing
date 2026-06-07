-- Add an optional abbreviation for each chapter (e.g. FCC for Fraser Coast Chapter).
-- Displayed as "(ABBR) Chapter Name" wherever a chapter label appears.
-- Admins populate via /admin/settings/index.php?section=membership_pricing.

ALTER TABLE chapters
  ADD COLUMN abbreviation VARCHAR(16) NULL AFTER name;
