-- Bottle sizes catalog for manufacturing orders

CREATE TABLE IF NOT EXISTS bottle_sizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    size DECIMAL(12,3) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    type ENUM('liquid', 'powder') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bottle_sizes_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link bottle size to manufacturing orders
ALTER TABLE manufacturing_orders
    ADD COLUMN bottle_size_id INT NULL AFTER product_id,
    ADD INDEX idx_mo_bottle_size (bottle_size_id),
    ADD CONSTRAINT fk_mo_bottle_size FOREIGN KEY (bottle_size_id) REFERENCES bottle_sizes(id) ON DELETE SET NULL;
