-- Migration: Add View Permissions for Manufacturing Components and Formulas
-- Date: 2026-02-28

-- 1. Insert the new permissions
INSERT INTO permissions (`key`, `name`, `module`, `description`) 
VALUES 
('manufacturing.component.name.view', 'View Component Name', 'manufacturing', 'Permission to see specific component names and SKUs in formulas and orders.'),
('manufacturing.formula.view_all', 'View Entire Formula', 'manufacturing', 'Permission to see the entire formula components list inside manufacturing orders.')
ON DUPLICATE KEY UPDATE 
    name = VALUES(name), 
    description = VALUES(description);

-- 2. Assign these permissions to the 'admin' role
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, `id` 
FROM `permissions` 
WHERE `key` IN ('manufacturing.component.name.view', 'manufacturing.formula.view_all')
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);
