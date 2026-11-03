INSERT IGNORE INTO manufacturing_packaging_checklist
    (manufacturing_order_id, section_name, item_key, item_text, item_type, item_order)
SELECT mo.id, 'after', 'last_carton_units', 'كسور في اخر كرتونة', 'number', 12
FROM manufacturing_orders mo
WHERE EXISTS (SELECT 1 FROM manufacturing_packaging_checklist pc WHERE pc.manufacturing_order_id = mo.id);
