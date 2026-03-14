-- Add finance.payments.delete permission
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) 
VALUES ('finance', 'Payments - Delete', 'finance.payments.delete', 'Permission to delete order payments and reverse financial impact')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Grant to admin role
SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'admin');
SET @perm_id_pay = (SELECT `id` FROM `permissions` WHERE `key` = 'finance.payments.delete');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (@admin_role_id, @perm_id_pay)
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
