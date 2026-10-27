-- Manual rates are EGP per currency unit; foreign rates require explicit setup.
ALTER TABLE currencies
    ADD COLUMN IF NOT EXISTS rate_to_egp DECIMAL(10,4) NULL,
    ADD COLUMN IF NOT EXISTS rate_updated_at DATETIME NULL;
UPDATE currencies SET rate_to_egp = 1.0000 WHERE code = 'EGP';

INSERT INTO permissions (module, name, `key`, description) VALUES
('settings', 'View Currency Rates and Convert', 'settings.currencies.view', 'View currency settings and use the currency converter.'),
('settings', 'Manage Currency Rates', 'settings.currencies.manage', 'Set system exchange rates against EGP. New orders use these rates; existing orders retain their saved rates.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.`key` IN ('settings.currencies.view', 'settings.currencies.manage');
