-- Warehouse destination for purchase orders
ALTER TABLE `purchase_orders`
  ADD COLUMN `warehouse_location` VARCHAR(255) NULL DEFAULT NULL AFTER `notes`;
