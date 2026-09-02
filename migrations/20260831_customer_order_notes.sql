-- Customer-authored notes submitted from the customer portal.
CREATE TABLE IF NOT EXISTS `customer_order_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `customer_order_notes_order_idx` (`order_id`),
  KEY `customer_order_notes_customer_idx` (`customer_id`),
  KEY `customer_order_notes_created_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
