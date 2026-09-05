START TRANSACTION;

INSERT INTO permissions (module, name, `key`, description) VALUES
('finance', 'Finance - Safes delete', 'finance.safes.delete', 'Delete zero-balance safes with no linked financial records'),
('finance', 'Finance - Bank accounts delete', 'finance.bank_accounts.delete', 'Delete zero-balance bank accounts with no linked financial records'),
('finance', 'Finance - Personal accounts delete', 'finance.personal_accounts.delete', 'Delete zero-balance personal accounts with no linked financial records')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Creation permission does not grant deletion. Other roles must opt in.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin'
  AND p.`key` IN ('finance.safes.delete', 'finance.bank_accounts.delete', 'finance.personal_accounts.delete')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

COMMIT;
