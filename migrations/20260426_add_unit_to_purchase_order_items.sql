-- Add unit column to purchase_order_items to track material unit of measurement
ALTER TABLE `purchase_order_items`
    ADD COLUMN `unit` VARCHAR(50) DEFAULT NULL AFTER `quantity`;
