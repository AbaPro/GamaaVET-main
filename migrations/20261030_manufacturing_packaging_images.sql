-- Store packaging & labeling step images.

CREATE TABLE IF NOT EXISTS manufacturing_packaging_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL,
    manufacturing_order_step_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mpki_order (manufacturing_order_id),
    INDEX idx_mpki_step (manufacturing_order_step_id),
    CONSTRAINT fk_mpki_order FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_mpki_step FOREIGN KEY (manufacturing_order_step_id) REFERENCES manufacturing_order_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
