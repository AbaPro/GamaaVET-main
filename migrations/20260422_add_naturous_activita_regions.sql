-- Migration: Add Naturous and Activita regions, accounts, and permissions
-- Date: 2026-04-22
-- Purpose: Expand direct-sale brands with Naturous and Activita while renaming
--          Curva -> CureVet and Primer -> PremiumVet (display only).

-- 1. New region permissions for Naturous and Activita
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
('regions', 'Access Naturous', 'region.naturous', 'Allow user to login to Naturous region'),
('regions', 'Access Activita', 'region.activita', 'Allow user to login to Activita region')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Grant new region permissions to admin role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`slug` = 'admin'
  AND p.`key` IN ('region.naturous', 'region.activita')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- 2. New accounts for Naturous and Activita
INSERT INTO `accounts` (`name`, `slug`) VALUES
('Naturous', 'naturous'),
('Activita', 'activita')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Update existing account display names (Curva -> CureVet, Primer -> PremiumVet)
UPDATE `accounts` SET `name` = 'CureVet' WHERE `slug` = 'curva';
UPDATE `accounts` SET `name` = 'PremiumVet' WHERE `slug` = 'primer';
