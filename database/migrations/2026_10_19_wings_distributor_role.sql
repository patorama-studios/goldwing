-- Wings Distributor: a ready-made Role Builder role that runs the monthly Wings
-- email (Admin → Wings + Email to members) and nothing else in the admin area.
-- Applied on prod via /admin/run-migration.php (Migration 057) — keep in sync.
INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
SELECT 'wings_distributor', 'wings_distributor', 'Sends each new Wings issue to members: the Wings page and Email to members, nothing else.', 0, 1, NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'wings_distributor');

INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
SELECT id, 'admin.wings_magazine.manage', 1, NOW(), NOW() FROM roles WHERE name = 'wings_distributor'
ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW();
