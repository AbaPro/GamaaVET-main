INSERT INTO permissions (`key`, name, module, description) VALUES
('manufacturing.orders.complete', 'Complete manufacturing orders', 'manufacturing', 'Finalize orders and post finished goods. Delivery-step access alone cannot complete an order.'),
('manufacturing.orders.technical_details.view', 'View manufacturing technical details', 'manufacturing', 'View batch sizes, production notes and technical bottle specifications, including reports.'),
('manufacturing.audit.view', 'View manufacturing audit', 'manufacturing', 'View audit warnings. Formula access, formula unlock and technical access are also required.'),
('manufacturing.bottle_sizes.view', 'View bottle sizes', 'manufacturing', 'Open the bottle-size catalog.'),
('manufacturing.bottle_sizes.manage', 'Manage bottle sizes', 'manufacturing', 'Create, edit and request deletion of bottle sizes; catalog view permission is also required.'),
('manufacturing.packaging_options.view', 'View packaging options', 'manufacturing', 'Open the packaging-option catalog.'),
('manufacturing.packaging_options.manage', 'Manage packaging options', 'manufacturing', 'Create, edit and request deletion of packaging options; catalog view permission is also required.'),
('manufacturing.waste.manage', 'Record material waste', 'manufacturing', 'Record explicit waste quantities for consumed materials on open manufacturing orders.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Deliberately no grant to general manufacturing or driver roles.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` IN (
 'manufacturing.orders.complete', 'manufacturing.orders.technical_details.view', 'manufacturing.audit.view',
 'manufacturing.bottle_sizes.view', 'manufacturing.bottle_sizes.manage',
 'manufacturing.packaging_options.view', 'manufacturing.packaging_options.manage', 'manufacturing.waste.manage'
) WHERE r.slug = 'admin';
