START TRANSACTION;

-- Permission to delete portal order requests (separate from review/pricing access).
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'sales', 'Portal Orders - Delete', 'sales.portal_orders.delete', 'Delete customer portal order requests that have not been converted to a real order'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'sales.portal_orders.delete');

-- Grant to admin role
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` = 'sales.portal_orders.delete'
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = @admin_role_id AND rp.permission_id = p.id
  );

-- Grant to any role that already holds sales.portal_orders.manage
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
FROM role_permissions rp
JOIN permissions manage_p ON manage_p.id = rp.permission_id AND manage_p.`key` = 'sales.portal_orders.manage'
JOIN permissions p ON p.`key` = 'sales.portal_orders.delete'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions existing
    WHERE existing.role_id = rp.role_id AND existing.permission_id = p.id
);

COMMIT;
