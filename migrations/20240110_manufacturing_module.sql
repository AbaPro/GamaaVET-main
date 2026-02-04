-- Manufacturing module schema
CREATE TABLE manufacturing_formulas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    description TEXT,
    batch_size DECIMAL(12,2) DEFAULT 0,
    components_json TEXT NOT NULL,
    instructions TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_provider_formula (customer_id, name),
    INDEX idx_manufacturing_formulas_customer (customer_id),
    CONSTRAINT fk_manufacturing_formulas_customer
      FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE manufacturing_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(60) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    formula_id INT NOT NULL,
    batch_size DECIMAL(12,2) DEFAULT 0,
    due_date DATE DEFAULT NULL,
    priority ENUM('normal','rush','critical') NOT NULL DEFAULT 'normal',
    status ENUM('getting','preparing','delivering','completed','cancelled') NOT NULL DEFAULT 'getting',
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_manufacturing_orders_customer (customer_id),
    INDEX idx_manufacturing_orders_formula (formula_id),
    CONSTRAINT fk_manufacturing_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
CONSTRAINT fk_manufacturing_orders_formula FOREIGN KEY (formula_id) REFERENCES manufacturing_formulas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE manufacturing_order_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL,
    step_key VARCHAR(60) NOT NULL,
    label VARCHAR(120) NOT NULL,
    status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    notes TEXT,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_manufacturing_step (manufacturing_order_id, step_key),
    INDEX idx_manufacturing_steps_order (manufacturing_order_id),
    CONSTRAINT fk_manufacturing_steps_order FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE manufacturing_step_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_step_id INT NOT NULL,
    type ENUM('excel','pdf') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    generated_by INT DEFAULT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_manufacturing_documents_step (manufacturing_order_step_id),
    CONSTRAINT fk_manufacturing_documents_step FOREIGN KEY (manufacturing_order_step_id) REFERENCES manufacturing_order_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
