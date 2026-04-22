-- Migration: Add accounts, currencies, and multi-currency support
-- Date: 2026-04-21

-- 1. currencies lookup table
CREATE TABLE IF NOT EXISTS `currencies` (
  `code` varchar(3) NOT NULL,
  `name` varchar(50) NOT NULL,
  `symbol` varchar(5) NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `currencies` (`code`, `name`, `symbol`, `is_default`) VALUES
('EGP', 'Egyptian Pound', 'ج.م', 1),
('USD', 'US Dollar', '$', 0),
('EUR', 'Euro', '€', 0),
('SAR', 'Saudi Riyal', 'ر.س', 0)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 2. accounts table (slugs match login_region values)
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `accounts` (`name`, `slug`) VALUES
('Gamma Vet', 'factory'),
('Curva', 'curva'),
('Primer', 'primer')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Add account_id and currency to expenses
-- account_id NULL = Gamma Vet (factory) for legacy records
ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `account_id` int(11) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `currency` varchar(3) NOT NULL DEFAULT 'EGP' AFTER `amount`;

-- Add FK only if not exists (run separately if ALTER above fails)
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_account_fk` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL;

-- 4. Add currency and exchange_rate to orders
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `currency` varchar(3) NOT NULL DEFAULT 'EGP' AFTER `total_amount`,
  ADD COLUMN IF NOT EXISTS `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000 AFTER `currency`;

-- 5. New permission: view expenses across all accounts
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
('finance', 'View All Accounts', 'finance.expenses.all_accounts', 'View and filter expenses across all accounts')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Grant to admin role
SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1);
SET @perm_id = (SELECT `id` FROM `permissions` WHERE `key` = 'finance.expenses.all_accounts' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, @perm_id
WHERE @admin_role_id IS NOT NULL AND @perm_id IS NOT NULL
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
