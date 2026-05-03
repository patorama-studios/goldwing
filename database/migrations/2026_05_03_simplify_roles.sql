-- Simplify role system to four roles: admin (Webmaster), store_manager (Quartermaster),
-- chapter_leader (Area Rep), member. All other roles are removed.

-- Update descriptions for renamed roles
UPDATE roles
SET description = 'Webmaster - full system access',
    updated_at  = NOW()
WHERE name = 'admin';

UPDATE roles
SET description = 'Quartermaster - store and order management',
    updated_at  = NOW()
WHERE name = 'store_manager';

UPDATE roles
SET description = 'Area Representative',
    updated_at  = NOW()
WHERE name = 'chapter_leader';

-- Remove unused roles. ON DELETE CASCADE handles user_roles and role_permissions rows.
DELETE FROM roles
WHERE name IN ('committee', 'treasurer', 'membership_admin', 'store_admin', 'content_admin');

-- Re-seed Quartermaster permissions (store only, no member access)
INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
SELECT r.id, p.permission_key, 1, NOW(), NOW()
FROM roles r
JOIN (
  SELECT 'admin.store.view'              AS permission_key
  UNION ALL SELECT 'admin.products.manage'
  UNION ALL SELECT 'admin.orders.view'
  UNION ALL SELECT 'admin.orders.fulfil'
  UNION ALL SELECT 'admin.orders.refund_cancel'
  UNION ALL SELECT 'admin.order_fulfilment.manage'
) p ON r.name = 'store_manager'
ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW();

-- Ensure Area Rep has no stale permissions beyond what they should have
DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
WHERE r.name = 'chapter_leader'
  AND rp.permission_key NOT IN (
    'admin.dashboard.view',
    'admin.members.view',
    'admin.members.edit'
  );

INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
SELECT r.id, p.permission_key, 1, NOW(), NOW()
FROM roles r
JOIN (
  SELECT 'admin.dashboard.view' AS permission_key
  UNION ALL SELECT 'admin.members.view'
  UNION ALL SELECT 'admin.members.edit'
) p ON r.name = 'chapter_leader'
ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW();

-- Clean up page_role_access rows for removed roles so the access table stays tidy
DELETE FROM page_role_access
WHERE role IN ('committee', 'treasurer', 'membership_admin', 'store_admin', 'content_admin');
