-- Migration to add region and direct sale fields
ALTER TABLE `users` ADD COLUMN `region` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `customers` ADD COLUMN `region` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `customers` ADD COLUMN `direct_sale` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `inventories` ADD COLUMN `region` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `inventories` ADD COLUMN `direct_sale` VARCHAR(20) DEFAULT NULL;

-- Add permissions for region access
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES 
('regions', 'Access Curva', 'region.curva', 'Allow user to login to Curva region'),
('regions', 'Access Primer', 'region.primer', 'Allow user to login to Primer region');
