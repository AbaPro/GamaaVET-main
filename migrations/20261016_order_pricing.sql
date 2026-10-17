-- Order pricing sheets: cost an order's selected products from their formulas
-- (raw materials) and packaging kits, then apply a profit margin.
START TRANSACTION;

CREATE TABLE IF NOT EXISTS order_pricings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    margin_percent DECIMAL(7,2) NOT NULL DEFAULT 0,
    total_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
    total_selling_price DECIMAL(15,4) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'EGP',
    notes TEXT NULL,
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order_pricings_order (order_id),
    INDEX idx_order_pricings_customer (customer_id),
    CONSTRAINT fk_order_pricings_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- details_json keeps the material and packaging rows with the prices used, so a
-- saved sheet stays unchanged when formulas or purchase prices change later.
CREATE TABLE IF NOT EXISTS order_pricing_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_pricing_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(191) NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
    basis_units DECIMAL(15,3) NOT NULL DEFAULT 1000,
    formula_id INT NULL,
    packaging_enabled TINYINT(1) NOT NULL DEFAULT 0,
    packaging_option_id INT NULL,
    raw_material_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
    packaging_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
    total_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
    selling_price DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_selling_price DECIMAL(15,6) NOT NULL DEFAULT 0,
    details_json LONGTEXT NOT NULL,
    INDEX idx_order_pricing_lines_pricing (order_pricing_id),
    CONSTRAINT fk_order_pricing_lines_pricing FOREIGN KEY (order_pricing_id) REFERENCES order_pricings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (module, name, `key`, description) VALUES
('finance', 'Finance - View order pricing', 'finance.pricing.view', 'View saved order pricing sheets; also requires the formula password'),
('finance', 'Finance - Manage order pricing', 'finance.pricing.manage', 'Create, edit and delete order pricing sheets; also requires the formula password')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin'
AND p.`key` IN ('finance.pricing.view', 'finance.pricing.manage')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

COMMIT;
