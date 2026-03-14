-- Add sales.orders.delete permission
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) 
VALUES ('sales', 'Orders - Delete', 'sales.orders.delete', 'Permission to delete sales orders and their items')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Grant to admin role
SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'admin');
SET @perm_id = (SELECT `id` FROM `permissions` WHERE `key` = 'sales.orders.delete');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (@admin_role_id, @perm_id)
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
