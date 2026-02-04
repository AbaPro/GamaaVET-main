START TRANSACTION;

-- Seed permissions for notifications and tickets
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  ('notifications', 'Notifications - View', 'notifications.view', 'View system notifications'),
  ('notifications', 'Notifications - Manage', 'notifications.manage', 'Manage notifications'),
  ('tickets', 'Tickets - View', 'tickets.view', 'View assigned tickets'),
  ('tickets', 'Tickets - Create', 'tickets.create', 'Create support tickets'),
  ('tickets', 'Tickets - Manage', 'tickets.manage', 'Assign/resolve tickets')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Grant to admin role if exists
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`,`permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` IN ('notifications.view','notifications.manage','tickets.view','tickets.create','tickets.manage')
ON DUPLICATE KEY UPDATE role_id=role_id;

COMMIT;

