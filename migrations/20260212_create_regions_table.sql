CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert example values
INSERT IGNORE INTO regions (name) VALUES ('Cairo'), ('Alex');
