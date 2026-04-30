-- Add optional screenshot attachment for purchase order payments
ALTER TABLE `purchase_order_payments`
  ADD COLUMN `screenshot_path` VARCHAR(255) NULL DEFAULT NULL AFTER `notes`;
