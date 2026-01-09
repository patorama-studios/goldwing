-- Add bike colour field for member_bikes

ALTER TABLE member_bikes
  ADD COLUMN IF NOT EXISTS colour VARCHAR(100) NULL AFTER model;
