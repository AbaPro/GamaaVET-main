START TRANSACTION;

-- Permission to force-delete finance accounts (safes, bank accounts, personal
-- accounts) that are linked to transfers, PO payments, balance adjustments,
-- or other financial records. Regular delete refuses these to preserve
-- financial history; force delete removes the account anyway and leaves
-- those historical records pointing at a deleted account. Admin-only by
-- default — not auto-granted to every role that holds the base *.delete
-- permission.
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'finance', 'Finance - Safes force delete', 'finance.safes.force_delete', 'Delete safes even if linked to transfers or other financial records'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'finance.safes.force_delete');

INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'finance', 'Finance - Bank accounts force delete', 'finance.bank_accounts.force_delete', 'Delete bank accounts even if linked to transfers or other financial records'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'finance.bank_accounts.force_delete');

INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'finance', 'Finance - Personal accounts force delete', 'finance.personal_accounts.force_delete', 'Delete personal accounts even if linked to transfers or other financial records'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'finance.personal_accounts.force_delete');

-- Grant to admin role only
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` IN ('finance.safes.force_delete', 'finance.bank_accounts.force_delete', 'finance.personal_accounts.force_delete')
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = @admin_role_id AND rp.permission_id = p.id
  );

COMMIT;
