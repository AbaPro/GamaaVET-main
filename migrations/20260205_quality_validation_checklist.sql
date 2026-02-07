-- Create table to store quality validation checklist for quality step
-- Each order will have predefined checklist items that can be approved/rejected with notes

CREATE TABLE IF NOT EXISTS `manufacturing_quality_checklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `manufacturing_order_id` int(11) NOT NULL,
  `section_name` varchar(255) NOT NULL,
  `item_text` text NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `notes` text,
  `item_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `fk_quality_order_id` (`manufacturing_order_id`),
  FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
