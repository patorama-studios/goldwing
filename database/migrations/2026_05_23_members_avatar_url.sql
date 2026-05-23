-- Add avatar_url directly to members so legacy members without a linked
-- user account can still have a profile photo. Existing avatars stored in
-- settings_user (keyed by user_id) remain valid as a fallback — see the
-- view.php / directory display, which prefers members.avatar_url and falls
-- back to settings_user when empty.

ALTER TABLE members
  ADD COLUMN avatar_url VARCHAR(512) NULL AFTER notes;
