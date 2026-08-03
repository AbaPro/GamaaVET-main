-- Reusable ingredient layouts for customer formulas.
-- Quantities copied from a template remain editable on each formula.
CREATE TABLE IF NOT EXISTS manufacturing_formula_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    components_json LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_manufacturing_formula_template_name (name),
    KEY idx_manufacturing_formula_templates_active (is_active),
    KEY idx_manufacturing_formula_templates_created_by (created_by),
    CONSTRAINT fk_manufacturing_formula_templates_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
