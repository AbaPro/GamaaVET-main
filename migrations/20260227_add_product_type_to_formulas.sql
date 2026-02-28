-- Add product_id, type, batch_unit, and preparation_fields_json to manufacturing_formulas

ALTER TABLE manufacturing_formulas
    ADD COLUMN product_id INT NULL AFTER customer_id,
    ADD COLUMN type ENUM('liquid', 'powder') NULL AFTER name,
    ADD COLUMN batch_unit VARCHAR(50) DEFAULT NULL AFTER batch_size,
    ADD COLUMN preparation_fields_json TEXT DEFAULT NULL,
    ADD INDEX idx_mf_product_id (product_id),
    ADD CONSTRAINT fk_mf_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL;
