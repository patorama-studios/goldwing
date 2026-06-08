-- Members can request to cancel their membership from the member dashboard.
-- This is a "do not renew" intent (not immediate termination) so they keep
-- access until their current paid period expires. Staff get notified via
-- activity log + email and can follow up before the period ends.

ALTER TABLE members
  ADD COLUMN do_not_renew TINYINT(1) NOT NULL DEFAULT 0 AFTER exclude_electronic,
  ADD COLUMN do_not_renew_at DATETIME NULL AFTER do_not_renew;
