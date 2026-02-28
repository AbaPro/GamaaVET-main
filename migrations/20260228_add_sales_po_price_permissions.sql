-- Migration: Add View Permissions for Sales and PO Prices
-- Date: 2026-02-28

-- 1. Insert the new permissions
INSERT INTO permissions (`key`, `name`, `module`, `description`) 
VALUES 
('sales.orders.price.view', 'View Order Price Info', 'sales', 'Permission to see prices, amounts, and totals in sales orders and quotations.'),
('purchases.po.price.view', 'View PO Price Info', 'purchases', 'Permission to see prices, costs, amounts, and totals in purchase orders.')
ON DUPLICATE KEY UPDATE 
    name = VALUES(name), 
    description = VALUES(description);

-- 2. Assign these permissions to the 'admin' role
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, `id` 
FROM `permissions` 
WHERE `key` IN ('sales.orders.price.view', 'purchases.po.price.view')
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);
