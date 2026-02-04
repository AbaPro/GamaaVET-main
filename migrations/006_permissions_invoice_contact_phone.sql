START TRANSACTION;

INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  ('sales', 'Invoice - Contact phone view', 'sales.invoice.contact_phone.view', 'View contact phone on invoices')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`,`permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` IN ('sales.invoice.contact_phone.view')
ON DUPLICATE KEY UPDATE role_id=role_id;

COMMIT;
