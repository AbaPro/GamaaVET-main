-- Primary products are distinct SKUs linked to their packaged final product.
-- Permanent warehouses are owned by the location-inventories migration.
CREATE TABLE IF NOT EXISTS manufacturing_primary_products (
    final_product_id int NOT NULL PRIMARY KEY,
    primary_product_id int NOT NULL UNIQUE,
    FOREIGN KEY (final_product_id) REFERENCES products(id),
    FOREIGN KEY (primary_product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE manufacturing_orders
    ADD COLUMN IF NOT EXISTS primary_stock_quantity int NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS primary_stock_deducted_at datetime NULL;
ALTER TABLE order_items ADD COLUMN IF NOT EXISTS primary_stock_quantity int NOT NULL DEFAULT 0;
ALTER TABLE manufacturing_packaging_material_tracking MODIFY posted_at datetime NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS manufacturing_packaging_output (
    manufacturing_order_id int NOT NULL PRIMARY KEY,
    produced_quantity int NOT NULL,
    finished_quantity int NOT NULL,
    primary_quantity int NOT NULL,
    primary_product_id int NULL,
    posted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (primary_product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
