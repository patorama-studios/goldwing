-- Member portal page visibility (Role Builder → "Member Portal Pages").
-- Seed every EXISTING role with view access to every gateable member page, so
-- current members keep seeing everything until an admin deliberately un-ticks a
-- page. Insert-only: never overwrites an admin's later customisation, and roles
-- created after this migration start blank (no member.page.* rows) on purpose.
INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
SELECT r.id, p.permission_key, 1, NOW(), NOW()
FROM roles r
JOIN (
  SELECT 'member.page.wings' AS permission_key
  UNION ALL SELECT 'member.page.calendar'
  UNION ALL SELECT 'member.page.notices'
  UNION ALL SELECT 'member.page.fallen-wings'
  UNION ALL SELECT 'member.page.member-of-the-year'
  UNION ALL SELECT 'member.page.awards'
  UNION ALL SELECT 'member.page.directory'
  UNION ALL SELECT 'member.page.committee'
  UNION ALL SELECT 'member.page.dealers'
  UNION ALL SELECT 'member.page.store'
) p
ON DUPLICATE KEY UPDATE updated_at = role_permissions.updated_at;
