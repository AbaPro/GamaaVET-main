START TRANSACTION;

ALTER TABLE `bank_accounts`
  ADD COLUMN IF NOT EXISTS `currency` varchar(3) NOT NULL DEFAULT 'EGP' AFTER `account_number`,
  ADD COLUMN IF NOT EXISTS `account_holder` varchar(150) DEFAULT NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `branch_name` varchar(150) DEFAULT NULL AFTER `account_holder`,
  ADD COLUMN IF NOT EXISTS `iban` varchar(100) DEFAULT NULL AFTER `branch_name`,
  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL AFTER `balance`;

ALTER TABLE `bank_accounts` MODIFY COLUMN `balance` decimal(15,2) DEFAULT 0.00;

ALTER TABLE `safes`
  ADD COLUMN IF NOT EXISTS `location_id` int(11) DEFAULT NULL AFTER `account_id`,
  ADD COLUMN IF NOT EXISTS `currency` varchar(3) NOT NULL DEFAULT 'EGP' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL AFTER `balance`;

ALTER TABLE `safes` MODIFY COLUMN `balance` decimal(15,2) DEFAULT 0.00;

SET @safe_location_fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'safes'
    AND CONSTRAINT_NAME = 'safes_location_fk'
);
SET @safe_location_fk_sql := IF(
  @safe_location_fk_exists = 0,
  'ALTER TABLE `safes` ADD CONSTRAINT `safes_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE safe_location_fk_stmt FROM @safe_location_fk_sql;
EXECUTE safe_location_fk_stmt;
DEALLOCATE PREPARE safe_location_fk_stmt;

CREATE TABLE IF NOT EXISTS `finance_account_balance_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_type` enum('safe','bank') NOT NULL,
  `account_id` int(11) NOT NULL,
  `previous_balance` decimal(15,2) NOT NULL,
  `new_balance` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EGP',
  `reason` varchar(500) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `finance_account_adjustment_account_idx` (`account_type`, `account_id`, `created_at`),
  KEY `finance_account_adjustment_user_idx` (`created_by`),
  CONSTRAINT `finance_account_adjustment_user_fk`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  ('finance', 'Finance - Safes edit', 'finance.safes.edit', 'Edit safe details, currency, brand, and location'),
  ('finance', 'Finance - Safes delete', 'finance.safes.delete', 'Delete zero-balance safes with no linked financial records'),
  ('finance', 'Finance - Safes set balance', 'finance.safes.balance.edit', 'Set a safe balance directly with a required audited reason'),
  ('finance', 'Finance - Bank accounts edit', 'finance.bank_accounts.edit', 'Edit bank account details, currency, and brand'),
  ('finance', 'Finance - Bank accounts delete', 'finance.bank_accounts.delete', 'Delete zero-balance bank accounts with no linked financial records'),
  ('finance', 'Finance - Bank accounts set balance', 'finance.bank_accounts.balance.edit', 'Set a bank account balance directly with a required audited reason')
ON DUPLICATE KEY UPDATE
  `module` = VALUES(`module`),
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.slug = 'admin'
  AND p.`key` IN (
    'finance.safes.edit',
    'finance.safes.delete',
    'finance.safes.balance.edit',
    'finance.bank_accounts.edit',
    'finance.bank_accounts.delete',
    'finance.bank_accounts.balance.edit'
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `role_permissions` rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

COMMIT;
