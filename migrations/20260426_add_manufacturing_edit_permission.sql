-- Migration to add edit permission for manufacturing orders
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
VALUES ('manufacturing', 'Manufacturing - Edit Order', 'manufacturing.edit', 'Permission to edit manufacturing orders')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Grant to admin role
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin');
SET @perm_id := (SELECT `id` FROM `permissions` WHERE `key` = 'manufacturing.edit');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, @perm_id
WHERE @admin_role_id IS NOT NULL AND @perm_id IS NOT NULL
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
