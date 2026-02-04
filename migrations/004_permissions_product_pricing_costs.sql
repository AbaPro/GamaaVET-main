START TRANSACTION;

INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  ('products', 'Products - Final price view', 'products.final.price.view', 'View final product prices'),
  ('products', 'Products - Final cost view', 'products.final.cost.view', 'View final product costs'),
  ('products', 'Products - Raw material price view', 'products.material.price.view', 'View raw material prices'),
  ('products', 'Products - Raw material cost view', 'products.material.cost.view', 'View raw material costs')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`,`permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` IN (
  'products.final.price.view',
  'products.final.cost.view',
  'products.material.price.view',
  'products.material.cost.view'
)
ON DUPLICATE KEY UPDATE role_id=role_id;

COMMIT;
