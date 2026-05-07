-- Add is_historic flag to members table.
-- Historic = member owns a motorcycle 25+ years old.
-- This is legally significant: the member requires proof of AGA club membership
-- to qualify for state-based historic vehicle registration concessions.

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS is_historic TINYINT(1) NOT NULL DEFAULT 0
    AFTER notes;

-- Index for filtering historic members in the admin member list
ALTER TABLE members
  ADD INDEX IF NOT EXISTS idx_members_historic (is_historic);
