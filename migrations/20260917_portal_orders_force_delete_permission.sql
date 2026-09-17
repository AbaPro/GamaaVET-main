START TRANSACTION;

-- Permission to force-delete portal order requests, including ones already
-- approved (but not yet converted to a real order). Admin-only by default —
-- unlike sales.portal_orders.delete, this is not auto-granted to every role
-- that holds sales.portal_orders.manage.
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'sales', 'Portal Orders - Force Delete', 'sales.portal_orders.force_delete', 'Delete approved (not yet converted) customer portal order requests'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'sales.portal_orders.force_delete');

-- Grant to admin role only
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` = 'sales.portal_orders.force_delete'
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = @admin_role_id AND rp.permission_id = p.id
  );

COMMIT;
