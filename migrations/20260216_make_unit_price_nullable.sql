-- 2026-02-16: Make unit_price nullable on products table
-- Allows the application to insert products without providing a unit price.

ALTER TABLE `products`
  MODIFY `unit_price` DECIMAL(10,2) DEFAULT NULL;
