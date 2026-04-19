-- Patch to update expenses schema for the new tracking system

-- 1. Add missing columns to expenses table
ALTER TABLE `expenses` 
ADD COLUMN `is_recurring` tinyint(1) DEFAULT 0,
ADD COLUMN `recurrence_interval` enum('monthly','quarterly','yearly') DEFAULT NULL;

-- 2. Create expense_payments table for detailed history
CREATE TABLE IF NOT EXISTS `expense_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','transfer','wallet') NOT NULL,
  `safe_id` int(11) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`safe_id`) REFERENCES `safes` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. (Optional) Remove old columns/tables if they were created by the previous attempt
-- ALTER TABLE `expenses` DROP FOREIGN KEY `expenses_ibfk_5`; -- Or whatever the name was
-- ALTER TABLE `expenses` DROP COLUMN `recurring_expense_id`;
-- DROP TABLE IF EXISTS `recurring_expenses`;
