CREATE TABLE IF NOT EXISTS finance_deletion_requests (
 id INT AUTO_INCREMENT PRIMARY KEY,
 account_id INT NOT NULL,
 entity_type VARCHAR(30) NOT NULL,
 entity_id INT NOT NULL,
 force_delete TINYINT NOT NULL DEFAULT 0,
 requested_by INT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 reviewed_by INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 INDEX (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO permissions (module, name, `key`, description)
SELECT 'finance', 'Finance - Approve deletions', 'finance.deletions.approve', 'Approve or reject finance deletion requests submitted by another user'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE `key` = 'finance.deletions.approve');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` = 'finance.deletions.approve'
WHERE r.slug = 'admin' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
