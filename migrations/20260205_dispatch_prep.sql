-- Migration: Add Dispatch Prep checklist table
-- Date: 2026-02-05

CREATE TABLE IF NOT EXISTS `manufacturing_dispatch_checklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `manufacturing_order_id` int(11) NOT NULL,
  `section_name` varchar(50) NOT NULL COMMENT 'details or before_loading',
  `item_key` varchar(100) NOT NULL,
  `item_text` varchar(255) NOT NULL,
  `item_type` enum('number','checkbox') NOT NULL DEFAULT 'checkbox',
  `item_value` varchar(255) DEFAULT NULL,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `manufacturing_order_id` (`manufacturing_order_id`),
  CONSTRAINT `manufacturing_dispatch_checklist_ibfk_1` FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
