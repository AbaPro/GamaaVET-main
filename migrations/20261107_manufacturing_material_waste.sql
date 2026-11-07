-- An explicit material identity and unit are required; breakage_count is not a SKU.
CREATE TABLE IF NOT EXISTS manufacturing_material_waste (
    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id int NOT NULL,
    sourcing_component_id int NOT NULL,
    product_id int NOT NULL,
    quantity decimal(14,4) NOT NULL,
    unit varchar(30) NOT NULL,
    stock_quantity decimal(12,2) NOT NULL,
    recorded_by int NULL,
    posted_at datetime NULL,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY waste_component (manufacturing_order_id, sourcing_component_id),
    KEY waste_order_posted (manufacturing_order_id, posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
