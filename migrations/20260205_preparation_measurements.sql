-- Create table to store preparation measurements for mixing step
-- Allows storing standard measurements (pH, TDS, Temperature, Humidity) plus custom fields

CREATE TABLE IF NOT EXISTS `manufacturing_preparation_measurements` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `manufacturing_order_id` int(11) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_value` varchar(255),
  `field_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `fk_prep_order_id` (`manufacturing_order_id`),
  FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
