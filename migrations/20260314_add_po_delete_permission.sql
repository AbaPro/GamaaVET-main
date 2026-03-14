-- Add purchases.orders.delete permission
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) 
VALUES ('purchases', 'PO - Delete', 'purchases.orders.delete', 'Permission to delete purchase orders and their items')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Grant to admin role
SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'admin');
SET @perm_id = (SELECT `id` FROM `permissions` WHERE `key` = 'purchases.orders.delete');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (@admin_role_id, @perm_id)
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
