-- Migration 038 — Re-sync membership_periods.status for members an admin
-- manually activated before the MembershipStatusService funnel landed.
--
-- The admin "Change status → Active" button used to write only members.status,
-- and the renewal-date editor used to write only membership_periods.end_date.
-- Neither touched membership_periods.status, so a member fixed up by an admin
-- could end up with members.status='ACTIVE', a future end_date, but
-- membership_periods.status='LAPSED' (left over from the expire cron). The
-- dashboard lockdown reader preferred the period status, so those members
-- still saw the "renew now" lockdown.
--
-- Conservative fix: only re-sync rows where members.status is ACTIVE, the
-- period's end_date is today or later, and the period is still LAPSED.
-- Authoritative version is in public_html/admin/admin/run-migration.php
-- (Migration 038); this file mirrors it for the database/migrations/ archive
-- and writes no activity_log rows.

UPDATE membership_periods mp
JOIN members m ON m.id = mp.member_id
SET mp.status = 'ACTIVE'
WHERE mp.status = 'LAPSED'
  AND mp.end_date >= CURDATE()
  AND UPPER(m.status) = 'ACTIVE';
