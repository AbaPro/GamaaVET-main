-- Add Destination as an available brand for safes and bank accounts.
-- CureVet remains in accounts for existing data and other modules, but the
-- finance creation forms intentionally exclude it.
INSERT INTO `accounts` (`name`, `slug`, `is_active`)
VALUES ('Destination', 'destination', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `is_active` = VALUES(`is_active`);
