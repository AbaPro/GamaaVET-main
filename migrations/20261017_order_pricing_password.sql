-- Order pricing has its own shared password, separate from the formula password.
-- No default is seeded: an admin sets it from Finance > Order Pricing before first use.
CREATE TABLE IF NOT EXISTS order_pricing_password (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    password_hash VARCHAR(255) NOT NULL,
    updated_by INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
