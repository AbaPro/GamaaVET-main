-- Create locations table
CREATE TABLE IF NOT EXISTS `locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(100) NOT NULL,
  `address` varchar(255),
  `description` text,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add location_id to inventories if it doesn't exist
ALTER TABLE `inventories` ADD COLUMN `location_id` int(11) DEFAULT NULL;

-- Add foreign key constraint if it doesn't exist
ALTER TABLE `inventories` ADD CONSTRAINT `fk_inventories_locations` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`);

-- Remove location varchar column from inventories if it exists
ALTER TABLE `inventories` DROP COLUMN IF EXISTS `location`;

-- Add location_id to manufacturing_orders for tracking which location the order is for
ALTER TABLE `manufacturing_orders` ADD COLUMN `location_id` int(11) DEFAULT NULL;

-- Add foreign key constraint for manufacturing_orders
ALTER TABLE `manufacturing_orders` ADD CONSTRAINT `fk_manufacturing_orders_locations` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`);

-- Insert sample locations if table is empty
INSERT IGNORE INTO `locations` (`id`, `name`, `address`, `description`, `is_active`) VALUES
(1, 'Main Warehouse', '123 Main Street', 'Primary storage location', 1),
(2, 'Branch Office', '456 Branch Ave', 'Secondary location', 1),
(3, 'Manufacturing Plant', '789 Industrial Rd', 'Production facility', 1);
