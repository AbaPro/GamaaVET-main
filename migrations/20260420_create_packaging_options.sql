-- Packaging Options: per-customer packaging kits (bottle + cap + label + box, etc.)
CREATE TABLE IF NOT EXISTS packaging_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    UNIQUE KEY unique_customer_packaging (customer_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items inside each packaging option (each is a material product)
CREATE TABLE IF NOT EXISTS packaging_option_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    packaging_option_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit VARCHAR(32) NULL,
    notes VARCHAR(255) NULL,
    FOREIGN KEY (packaging_option_id) REFERENCES packaging_options(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add packaging_option_id and number_of_bottles to manufacturing_orders
ALTER TABLE manufacturing_orders
    ADD COLUMN number_of_bottles INT NULL AFTER bottle_size_id,
    ADD COLUMN packaging_option_id INT NULL AFTER number_of_bottles,
    ADD CONSTRAINT fk_mo_packaging_option
        FOREIGN KEY (packaging_option_id) REFERENCES packaging_options(id);

-- Track where each sourcing component came from, and whether it was deducted
ALTER TABLE manufacturing_sourcing_components
    ADD COLUMN source ENUM('formula','packaging') NOT NULL DEFAULT 'formula' AFTER manufacturing_order_id,
    ADD COLUMN deducted_at DATETIME NULL AFTER receipt_notes;
