-- Capture members' Date of Birth. The apply form has always collected DOB
-- (dob_day/month/year) but silently discarded it — there was no column and the
-- POST handler never read the fields. This adds the column so DOB is stored on
-- new applications and editable in the member + admin profile.
--
-- Applied on prod via /admin/run-migration.php (Migration 045). Nullable:
-- existing members have no stored DOB until it's entered manually.

ALTER TABLE members
  ADD COLUMN date_of_birth DATE NULL AFTER phone;
