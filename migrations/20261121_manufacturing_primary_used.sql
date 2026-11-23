ALTER TABLE manufacturing_packaging_output ADD COLUMN IF NOT EXISTS primary_used_quantity int NULL;
UPDATE manufacturing_packaging_output po JOIN manufacturing_orders mo ON mo.id = po.manufacturing_order_id
SET po.primary_used_quantity = CASE WHEN mo.primary_stock_deducted_at IS NOT NULL THEN mo.primary_stock_quantity ELSE 0 END
WHERE po.primary_used_quantity IS NULL;
ALTER TABLE manufacturing_packaging_output MODIFY primary_used_quantity int NOT NULL DEFAULT 0;

INSERT IGNORE INTO manufacturing_packaging_checklist
    (manufacturing_order_id, section_name, item_key, item_text, item_type, item_value, item_order)
SELECT mo.id, 'after', 'primary_product_used', 'منتج اولي مستخدم', 'number',
    COALESCE(po.primary_used_quantity, mo.primary_stock_quantity, 0), 13
FROM manufacturing_orders mo LEFT JOIN manufacturing_packaging_output po ON po.manufacturing_order_id = mo.id
WHERE EXISTS (SELECT 1 FROM manufacturing_packaging_checklist pc WHERE pc.manufacturing_order_id = mo.id);
