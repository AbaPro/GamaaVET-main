-- Migration to generate SKUs for products that are currently missing them
-- Format: SKU-YYMMDD-ID_PADDED (where YYMMDD is from created_at or current date)
-- This ensures uniqueness using the primary key ID.

UPDATE products 
SET sku = CONCAT('SKU-', DATE_FORMAT(COALESCE(created_at, NOW()), '%y%m%d'), '-', LPAD(id, 6, '0'))
WHERE sku IS NULL OR sku = '' OR sku = 'SKU-';
