-- Add packaging checklist table for tracking packaging operations
CREATE TABLE IF NOT EXISTS manufacturing_packaging_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturing_order_id INT NOT NULL,
    section_name VARCHAR(100) NOT NULL COMMENT 'before/during/after',
    item_key VARCHAR(100) NOT NULL COMMENT 'Unique identifier for the item',
    item_text TEXT COMMENT 'Arabic label text',
    item_type ENUM('checkbox', 'number', 'text') DEFAULT 'checkbox',
    item_value TEXT COMMENT 'Stored value (checked/unchecked for checkbox, number for number, text for text)',
    item_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manufacturing_order_id) REFERENCES manufacturing_orders(id) ON DELETE CASCADE,
    INDEX idx_order_section (manufacturing_order_id, section_name),
    UNIQUE KEY unique_order_item (manufacturing_order_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
