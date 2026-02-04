-- Create table to track sourcing components with notes and quantity validation
CREATE TABLE IF NOT EXISTS `manufacturing_sourcing_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `manufacturing_order_id` int(11) NOT NULL,
  `formula_component_index` int(11) NOT NULL,
  `product_id` int(11),
  `component_name` varchar(255) NOT NULL,
  `required_quantity` decimal(10, 2) NOT NULL,
  `unit` varchar(50),
  `available_quantity` decimal(10, 2) DEFAULT 0,
  `notes` text,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `fk_order_id` (`manufacturing_order_id`),
  KEY `fk_product_id` (`product_id`),
  FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
