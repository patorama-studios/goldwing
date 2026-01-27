ALTER TABLE roles
  ADD COLUMN slug VARCHAR(80) NULL,
  ADD COLUMN description TEXT NULL,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN created_at DATETIME NULL,
  ADD COLUMN updated_at DATETIME NULL;

UPDATE roles
SET slug = COALESCE(slug, name),
    is_system = 1,
    is_active = 1,
    created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, NOW());

ALTER TABLE roles
  MODIFY slug VARCHAR(80) NOT NULL,
  ADD UNIQUE KEY uniq_roles_slug (slug);

ALTER TABLE user_roles
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  permission_key VARCHAR(191) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_role_permission (role_id, permission_key),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
SELECT * FROM (
  SELECT 'membership_admin' AS name, 'membership_admin' AS slug, 'Membership admin role' AS description, 1 AS is_system, 1 AS is_active, NOW() AS created_at, NOW() AS updated_at
  UNION ALL
  SELECT 'store_admin', 'store_admin', 'Store admin role', 1, 1, NOW(), NOW()
  UNION ALL
  SELECT 'content_admin', 'content_admin', 'Content admin role', 1, 1, NOW(), NOW()
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.slug = seed.slug);

UPDATE roles
SET is_system = 1,
    is_active = 1,
    description = CASE
      WHEN name = 'admin' THEN 'Admin (full access)'
      WHEN name = 'committee' THEN 'Committee admin'
      WHEN name = 'treasurer' THEN 'Treasurer admin'
      WHEN name = 'chapter_leader' THEN 'Chapter leader admin'
      WHEN name = 'store_manager' THEN 'Store manager admin'
      ELSE description
    END,
    updated_at = NOW()
WHERE name IN ('admin', 'committee', 'treasurer', 'chapter_leader', 'store_manager');

-- Seed default permissions for system admin roles.
INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
SELECT r.id, p.permission_key, p.allowed, NOW(), NOW()
FROM roles r
JOIN (
  UNION ALL SELECT 'admin', 'admin.dashboard.view', 1
  UNION ALL SELECT 'admin', 'admin.users.view', 1
  UNION ALL SELECT 'admin', 'admin.users.create', 1
  UNION ALL SELECT 'admin', 'admin.users.edit', 1
  UNION ALL SELECT 'admin', 'admin.users.disable', 1
  UNION ALL SELECT 'admin', 'admin.roles.view', 1
  UNION ALL SELECT 'admin', 'admin.roles.manage', 1
  UNION ALL SELECT 'admin', 'admin.members.view', 1
  UNION ALL SELECT 'admin', 'admin.members.edit', 1
  UNION ALL SELECT 'admin', 'admin.members.renew', 1
  UNION ALL SELECT 'admin', 'admin.members.manual_payment', 1
  UNION ALL SELECT 'admin', 'admin.members.import_export', 1
  UNION ALL SELECT 'admin', 'admin.membership_types.manage', 1
  UNION ALL SELECT 'admin', 'admin.payments.view', 1
  UNION ALL SELECT 'admin', 'admin.payments.refund', 1
  UNION ALL SELECT 'admin', 'admin.store.view', 1
  UNION ALL SELECT 'admin', 'admin.products.manage', 1
  UNION ALL SELECT 'admin', 'admin.orders.view', 1
  UNION ALL SELECT 'admin', 'admin.orders.fulfil', 1
  UNION ALL SELECT 'admin', 'admin.orders.refund_cancel', 1
  UNION ALL SELECT 'admin', 'admin.order_fulfilment.manage', 1
  UNION ALL SELECT 'admin', 'admin.pages.view', 1
  UNION ALL SELECT 'admin', 'admin.pages.edit', 1
  UNION ALL SELECT 'admin', 'admin.pages.publish', 1
  UNION ALL SELECT 'admin', 'admin.media_library.manage', 1
  UNION ALL SELECT 'admin', 'admin.wings_magazine.manage', 1
  UNION ALL SELECT 'admin', 'admin.calendar.view', 1
  UNION ALL SELECT 'admin', 'admin.calendar.manage', 1
  UNION ALL SELECT 'admin', 'admin.events.manage', 1
  UNION ALL SELECT 'admin', 'admin.member_of_year.view', 1
  UNION ALL SELECT 'admin', 'admin.member_of_year.manage', 1
  UNION ALL SELECT 'admin', 'admin.forms.export', 1
  UNION ALL SELECT 'admin', 'admin.ai_page_builder.access', 1
  UNION ALL SELECT 'admin', 'admin.ai_page_builder.edit', 1
  UNION ALL SELECT 'admin', 'admin.ai_page_builder.publish', 1
  UNION ALL SELECT 'admin', 'admin.header_footer_builder.access', 1
  UNION ALL SELECT 'admin', 'admin.settings.general.manage', 1
  UNION ALL SELECT 'admin', 'admin.logs.view', 1
  UNION ALL SELECT 'admin', 'admin.integrations.manage', 1

  UNION ALL SELECT 'membership_admin', 'admin.members.view', 1
  UNION ALL SELECT 'membership_admin', 'admin.members.edit', 1
  UNION ALL SELECT 'membership_admin', 'admin.members.renew', 1
  UNION ALL SELECT 'membership_admin', 'admin.members.manual_payment', 1
  UNION ALL SELECT 'membership_admin', 'admin.members.import_export', 1
  UNION ALL SELECT 'membership_admin', 'admin.membership_types.manage', 1
  UNION ALL SELECT 'membership_admin', 'admin.payments.view', 1
  UNION ALL SELECT 'membership_admin', 'admin.payments.refund', 1

  UNION ALL SELECT 'store_admin', 'admin.store.view', 1
  UNION ALL SELECT 'store_admin', 'admin.products.manage', 1
  UNION ALL SELECT 'store_admin', 'admin.orders.view', 1
  UNION ALL SELECT 'store_admin', 'admin.orders.fulfil', 1
  UNION ALL SELECT 'store_admin', 'admin.orders.refund_cancel', 1
  UNION ALL SELECT 'store_admin', 'admin.order_fulfilment.manage', 1

  UNION ALL SELECT 'content_admin', 'admin.pages.view', 1
  UNION ALL SELECT 'content_admin', 'admin.pages.edit', 1
  UNION ALL SELECT 'content_admin', 'admin.pages.publish', 1
  UNION ALL SELECT 'content_admin', 'admin.media_library.manage', 1
  UNION ALL SELECT 'content_admin', 'admin.wings_magazine.manage', 1
  UNION ALL SELECT 'content_admin', 'admin.ai_page_builder.access', 1
  UNION ALL SELECT 'content_admin', 'admin.ai_page_builder.edit', 1
  UNION ALL SELECT 'content_admin', 'admin.ai_page_builder.publish', 1

  UNION ALL SELECT 'committee', 'admin.dashboard.view', 1
  UNION ALL SELECT 'committee', 'admin.members.view', 1
  UNION ALL SELECT 'committee', 'admin.members.edit', 1
  UNION ALL SELECT 'committee', 'admin.members.renew', 1
  UNION ALL SELECT 'committee', 'admin.members.manual_payment', 1
  UNION ALL SELECT 'committee', 'admin.members.import_export', 1
  UNION ALL SELECT 'committee', 'admin.membership_types.manage', 1
  UNION ALL SELECT 'committee', 'admin.payments.view', 1
  UNION ALL SELECT 'committee', 'admin.payments.refund', 1
  UNION ALL SELECT 'committee', 'admin.events.manage', 1
  UNION ALL SELECT 'committee', 'admin.calendar.view', 1
  UNION ALL SELECT 'committee', 'admin.pages.view', 1

  UNION ALL SELECT 'treasurer', 'admin.dashboard.view', 1
  UNION ALL SELECT 'treasurer', 'admin.members.view', 1
  UNION ALL SELECT 'treasurer', 'admin.members.edit', 1
  UNION ALL SELECT 'treasurer', 'admin.payments.view', 1
  UNION ALL SELECT 'treasurer', 'admin.payments.refund', 1

  UNION ALL SELECT 'chapter_leader', 'admin.dashboard.view', 1
  UNION ALL SELECT 'chapter_leader', 'admin.members.view', 1
  UNION ALL SELECT 'chapter_leader', 'admin.members.edit', 1

  UNION ALL SELECT 'store_manager', 'admin.store.view', 1
  UNION ALL SELECT 'store_manager', 'admin.products.manage', 1
  UNION ALL SELECT 'store_manager', 'admin.orders.view', 1
  UNION ALL SELECT 'store_manager', 'admin.orders.fulfil', 1
  UNION ALL SELECT 'store_manager', 'admin.order_fulfilment.manage', 1
) p ON p.role_name = r.name
ON DUPLICATE KEY UPDATE
  allowed = VALUES(allowed),
  updated_at = NOW();
