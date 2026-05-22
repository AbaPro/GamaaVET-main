-- Store formula sample images and preparation/mixing step images.

ALTER TABLE manufacturing_formulas
    ADD COLUMN sample_images_json TEXT DEFAULT NULL AFTER preparation_fields_json;

CREATE TABLE IF NOT EXISTS manufacturing_preparation_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL,
    manufacturing_order_step_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mpi_order (manufacturing_order_id),
    INDEX idx_mpi_step (manufacturing_order_step_id),
    CONSTRAINT fk_mpi_order FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_mpi_step FOREIGN KEY (manufacturing_order_step_id) REFERENCES manufacturing_order_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
