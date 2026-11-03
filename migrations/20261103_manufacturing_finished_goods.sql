-- Finished goods stock-in when a manufacturing order is completed.
-- inventory_id (already on the table, previously unused) now holds the warehouse
-- that received the finished product. finished_goods_added_at guards against
-- adding the same order's output to stock twice.
ALTER TABLE `manufacturing_orders`
  ADD COLUMN IF NOT EXISTS `finished_goods_quantity` decimal(12,2) DEFAULT NULL AFTER `number_of_bottles`,
  ADD COLUMN IF NOT EXISTS `finished_goods_added_at` datetime DEFAULT NULL AFTER `finished_goods_quantity`;
