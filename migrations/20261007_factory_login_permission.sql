-- Factory/GamaaVET access must be explicitly granted to non-admin roles.
INSERT IGNORE INTO permissions (module, name, `key`, description) VALUES
('regions', 'Access Factory / GamaaVET', 'region.factory', 'Allow user to login to the Factory / GamaaVET company');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.`key` = 'region.factory';
