-- Migration: Add Delivery step table
-- Date: 2026-02-05

CREATE TABLE IF NOT EXISTS `manufacturing_delivery_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `manufacturing_order_id` int(11) NOT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `recipient_phone` varchar(50) DEFAULT NULL,
  `delivery_datetime` datetime DEFAULT NULL,
  `customer_notes` text DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `manufacturing_order_id` (`manufacturing_order_id`),
  CONSTRAINT `manufacturing_delivery_info_ibfk_1` FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
