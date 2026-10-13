START TRANSACTION;

CREATE TABLE IF NOT EXISTS manufacturing_deletion_requests (
 id INT AUTO_INCREMENT PRIMARY KEY,
 account_id INT NOT NULL,
 entity_type VARCHAR(30) NOT NULL,
 entity_id INT NOT NULL,
 requested_by INT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 reviewed_by INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 INDEX (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (module, name, `key`, description) VALUES
('finance', 'Finance - Request deletions', 'finance.deletions.request', 'Submit finance deletion requests; also requires the permission for the requested record/action'),
('manufacturing', 'Manufacturing - Request deletions', 'manufacturing.deletions.request', 'Submit deletion requests for orders, formulas, templates, bottle sizes and packaging options; also requires manufacturing view and delete permissions'),
('manufacturing', 'Manufacturing - Approve deletions', 'manufacturing.deletions.approve', 'Approve or reject another user''s manufacturing deletion requests; execution also requires manufacturing view, delete and record access')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Preserve request access for roles already allowed to delete these records.
-- Approval remains a separate permission, granted only to admins by default.
INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, target.id
FROM role_permissions rp
JOIN permissions existing ON existing.id = rp.permission_id
JOIN permissions target ON target.`key` = 'finance.deletions.request'
WHERE existing.`key` IN (
 'finance.safes.delete', 'finance.safes.force_delete',
 'finance.bank_accounts.delete', 'finance.bank_accounts.force_delete',
 'finance.personal_accounts.delete', 'finance.personal_accounts.force_delete',
 'finance.expenses.manage', 'finance.expenses.categories'
)
AND NOT EXISTS (SELECT 1 FROM role_permissions assigned WHERE assigned.role_id = rp.role_id AND assigned.permission_id = target.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, target.id FROM role_permissions rp
JOIN permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'manufacturing.delete'
JOIN permissions target ON target.`key` = 'manufacturing.deletions.request'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions assigned WHERE assigned.role_id = rp.role_id AND assigned.permission_id = target.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin'
AND p.`key` IN ('finance.deletions.request', 'finance.deletions.approve', 'manufacturing.deletions.request', 'manufacturing.deletions.approve')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

COMMIT;
