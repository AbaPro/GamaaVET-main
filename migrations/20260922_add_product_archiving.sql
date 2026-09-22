-- Keep products with stock or transaction history recoverable instead of
-- attempting a destructive delete that foreign keys will reject.
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `min_stock_level`,
    ADD INDEX IF NOT EXISTS `idx_products_is_active` (`is_active`);
