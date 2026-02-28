-- Migration: Add View Permissions for Sales Discounts and Shipping
-- Date: 2026-02-27

-- 1. Insert the new permissions
INSERT INTO permissions (`key`, `name`, `module`, `description`) 
VALUES 
('sales.orders.discount.view', 'View Order Discount Info', 'sales', 'Permission to see discount percentages, amounts, and free sample counts in sales orders.'),
('sales.orders.shipping.view', 'View Order Shipping Info', 'sales', 'Permission to see shipping types and costs in sales orders.')
ON DUPLICATE KEY UPDATE 
    name = VALUES(name), 
    description = VALUES(description);

-- 2. Assign these permissions to the 'admin' role
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, `id` 
FROM `permissions` 
WHERE `key` IN ('sales.orders.discount.view', 'sales.orders.shipping.view')
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);
