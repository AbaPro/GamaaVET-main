-- Schema patch: extend orders/order_items and introduce order_returns tracking.
-- Run this on the existing GammaVET database to keep live data intact.

START TRANSACTION;

-- 1) Extend orders table with discount, free-sample, and shipping columns.
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00 AFTER `paid_amount`,
  ADD COLUMN IF NOT EXISTS `discount_basis` enum('none','product_quantity','cash','free_sample','mixed') NOT NULL DEFAULT 'none' AFTER `discount_percentage`,
  ADD COLUMN IF NOT EXISTS `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_basis`,
  ADD COLUMN IF NOT EXISTS `discount_product_count` int(11) NOT NULL DEFAULT 0 AFTER `discount_amount`,
  ADD COLUMN IF NOT EXISTS `free_sample_count` int(11) NOT NULL DEFAULT 0 AFTER `discount_product_count`,
  ADD COLUMN IF NOT EXISTS `shipping_cost_type` enum('none','manual') NOT NULL DEFAULT 'none' AFTER `free_sample_count`,
  ADD COLUMN IF NOT EXISTS `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `shipping_cost_type`;

-- 2) Flag free samples inside each order item.
ALTER TABLE `order_items`
  ADD COLUMN IF NOT EXISTS `is_free_sample` tinyint(1) NOT NULL DEFAULT 0 AFTER `total_price`;

-- 3) Dedicated table for per-product returns.
CREATE TABLE IF NOT EXISTS `order_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `order_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `returned_quantity` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_returns_order_id` (`order_id`),
  KEY `idx_order_returns_item_id` (`order_item_id`),
  KEY `idx_order_returns_product_id` (`product_id`),
  KEY `idx_order_returns_created_by` (`created_by`),
  CONSTRAINT `order_returns_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_returns_ibfk_2` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_returns_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `order_returns_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
