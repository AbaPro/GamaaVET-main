-- Auditable money-transfer workflow and first-class personal finance accounts.
-- New transfers remain pending and do not move money until an assigned approver
-- approves them. Historical transfers are retained as approved records.

CREATE TABLE IF NOT EXISTS `personal_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `holder_user_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_accounts_holder_uq` (`holder_user_id`),
  KEY `personal_accounts_account_idx` (`account_id`),
  KEY `personal_accounts_active_idx` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Preserve user IDs as personal-account IDs so every historical
-- finance_transfers personal from_id/to_id remains valid without rewriting it.
INSERT INTO `personal_accounts`
  (`id`, `holder_user_id`, `account_id`, `name`, `email`, `balance`, `is_active`, `created_at`)
SELECT u.`id`, u.`id`, NULL, u.`name`, u.`email`, COALESCE(u.`personal_balance`, 0), u.`is_active`, u.`created_at`
FROM `users` u
LEFT JOIN `roles` r ON r.`id` = u.`role_id`
WHERE COALESCE(r.`slug`, u.`role`) <> 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `personal_accounts` existing
    WHERE existing.`holder_user_id` = u.`id` OR existing.`id` = u.`id`
  );

ALTER TABLE `finance_transfers`
  ADD COLUMN IF NOT EXISTS `transfer_reference` varchar(50) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `status` enum('pending','approved','rejected','reversed') NOT NULL DEFAULT 'approved' AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `reason` text DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `purchase_order_id` int(11) DEFAULT NULL AFTER `reason`,
  ADD COLUMN IF NOT EXISTS `ticket_id` int(11) DEFAULT NULL AFTER `purchase_order_id`,
  ADD COLUMN IF NOT EXISTS `assigned_approver_id` int(11) DEFAULT NULL AFTER `ticket_id`,
  ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `assigned_approver_id`,
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `rejected_by` int(11) DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `rejected_at` datetime DEFAULT NULL AFTER `rejected_by`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` text DEFAULT NULL AFTER `rejected_at`,
  ADD COLUMN IF NOT EXISTS `reversed_by` int(11) DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN IF NOT EXISTS `reversed_at` datetime DEFAULT NULL AFTER `reversed_by`,
  ADD COLUMN IF NOT EXISTS `reversal_reason` text DEFAULT NULL AFTER `reversed_at`;

UPDATE `finance_transfers`
SET `transfer_reference` = CONCAT('FT-', DATE_FORMAT(COALESCE(`created_at`, NOW()), '%Y%m%d'), '-', LPAD(`id`, 6, '0'))
WHERE `transfer_reference` IS NULL OR `transfer_reference` = '';

UPDATE `finance_transfers`
SET `status` = 'approved',
    `reason` = COALESCE(NULLIF(`reason`, ''), NULLIF(`notes`, ''), 'Legacy finance transfer'),
    `approved_by` = COALESCE(`approved_by`, `created_by`),
    `approved_at` = COALESCE(`approved_at`, `created_at`)
WHERE `status` = 'approved';

ALTER TABLE `finance_transfers`
  MODIFY COLUMN `status` enum('pending','approved','rejected','reversed') NOT NULL DEFAULT 'approved',
  MODIFY COLUMN `transfer_reference` varchar(50) NOT NULL;

CREATE TABLE IF NOT EXISTS `finance_transfer_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `finance_transfer_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fth_transfer_idx` (`finance_transfer_id`),
  KEY `fth_created_by_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `finance_transfer_history`
  (`finance_transfer_id`, `action`, `note`, `created_by`, `created_at`)
SELECT f.`id`, 'approved', 'Backfilled approved finance transfer', f.`created_by`, f.`created_at`
FROM `finance_transfers` f
WHERE NOT EXISTS (
  SELECT 1 FROM `finance_transfer_history` h
  WHERE h.`finance_transfer_id` = f.`id`
);

INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
('finance', 'Finance - Transfers approve', 'finance.transfers.approve',
 'Approve or reject money transfers and move balances atomically')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1);
SET @approve_permission_id := (SELECT `id` FROM `permissions` WHERE `key` = 'finance.transfers.approve' LIMIT 1);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, @approve_permission_id
WHERE @admin_role_id IS NOT NULL AND @approve_permission_id IS NOT NULL
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
