-- Extend vendor wallet transactions with attachment metadata
ALTER TABLE `vendor_wallet_transactions`
  ADD COLUMN `attachment_path` VARCHAR(255) NULL DEFAULT NULL AFTER `notes`,
  ADD COLUMN `attachment_original_name` VARCHAR(255) NULL DEFAULT NULL AFTER `attachment_path`;

-- Permission to allow updating purchase order statuses
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'purchases', 'Update Purchase Order Status', 'purchases.update_status', 'Allows updating PO workflow statuses'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'purchases.update_status');

-- Purchasing officer role
INSERT INTO `roles` (`name`, `slug`, `description`, `is_active`)
SELECT 'Purchasing Officer', 'purchasing_officer', 'Purchasing execution role', 1
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `slug` = 'purchasing_officer');

-- Copy purchasing supervisor permissions to purchasing officer
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT target.id, rp.permission_id
FROM role_permissions rp
JOIN roles src ON rp.role_id = src.id AND src.slug = 'purchasing_supervisor'
JOIN roles target ON target.slug = 'purchasing_officer'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions existing
    WHERE existing.role_id = target.id AND existing.permission_id = rp.permission_id
);

-- Grant PO status permission to purchasing roles and finance
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` = 'purchases.update_status'
WHERE r.slug IN ('admin','accountant','purchasing_supervisor','purchasing_officer')
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
