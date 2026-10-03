-- Migration 054 — life_members.member_id (Oct 2026).
-- Links a roll entry to its member record so the Life Members page can list
-- every ACTIVE member flagged as life (member_type LIFE or is_life_member = 1)
-- that is NOT already on the roll, without showing anyone twice.
-- Entries for deceased / honorary people with no member record stay NULL.
-- The auto-link of the seeded rows (match on surname + first-name prefix, only
-- when exactly one member matches) lives in public_html/admin/run-migration.php
-- — there is no SQL-only equivalent.
ALTER TABLE life_members
  ADD COLUMN member_id INT NULL AFTER full_name,
  ADD INDEX idx_life_members_member (member_id);
