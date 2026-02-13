INSERT IGNORE INTO permissions (module, name, `key`, description) VALUES 
('Settings', 'Manage Regions', 'regions.manage', 'Can view and manage regions'),
('Settings', 'Create Regions', 'regions.create', 'Can create new regions'),
('Settings', 'Edit Regions', 'regions.edit', 'Can edit regions'),
('Settings', 'Delete Regions', 'regions.delete', 'Can delete regions');

-- Grant regions.manage to admins (assuming role_id 1 is admin)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE `key` LIKE 'regions.%';
