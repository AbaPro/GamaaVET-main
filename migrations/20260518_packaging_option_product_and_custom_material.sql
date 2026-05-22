-- Link packaging options to a final product and add an editable packaging material label.
ALTER TABLE packaging_options
    ADD COLUMN product_id INT NULL AFTER customer_id,
    ADD KEY idx_packaging_options_product_id (product_id),
    ADD CONSTRAINT fk_packaging_options_product
        FOREIGN KEY (product_id) REFERENCES products(id);

-- Existing packaging checklist rows keep their quantity keys; this row stores the custom display label.
INSERT INTO manufacturing_packaging_checklist
    (manufacturing_order_id, section_name, item_key, item_text, item_type, item_value, item_order)
SELECT mo.id, 'after', 'custom_material_name', 'اسم المادة', 'text', 'مطبوعات', 263
FROM manufacturing_orders mo
WHERE NOT EXISTS (
    SELECT 1
    FROM manufacturing_packaging_checklist mpc
    WHERE mpc.manufacturing_order_id = mo.id
      AND mpc.item_key = 'custom_material_name'
);
