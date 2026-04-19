-- Create Expense Categories table
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Recurring Expenses table
CREATE TABLE IF NOT EXISTS `recurring_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `frequency` enum('monthly','yearly') NOT NULL,
  `start_date` date NOT NULL,
  `next_payment_date` date NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create Expenses table
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `expense_date` date NOT NULL,
  `status` enum('pending','partially-paid','paid') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','transfer','wallet') DEFAULT NULL,
  `safe_id` int(11) DEFAULT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL,
  `recurring_expense_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  FOREIGN KEY (`safe_id`) REFERENCES `safes` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`recurring_expense_id`) REFERENCES `recurring_expenses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add Permissions
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
('finance', 'View Expenses', 'finance.expenses.view', 'View list of expenses and reports'),
('finance', 'Manage Expenses', 'finance.expenses.manage', 'Create, edit and delete expenses'),
('finance', 'Manage Recurring Expenses', 'finance.expenses.recurring', 'Manage monthly and yearly recurring costs'),
('finance', 'Expense Categories', 'finance.expenses.categories', 'Manage expense categories')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Grant Permissions to Admin and Accountant
SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'admin');
SET @accountant_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'accountant');

SET @perm_view = (SELECT `id` FROM `permissions` WHERE `key` = 'finance.expenses.view');
SET @perm_manage = (SELECT `id` FROM `permissions` WHERE `key` = 'finance.expenses.manage');
SET @perm_recurring = (SELECT `id` FROM `permissions` WHERE `key` = 'finance.expenses.recurring');
SET @perm_cats = (SELECT `id` FROM `permissions` WHERE `key` = 'finance.expenses.categories');

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(@admin_role_id, @perm_view),
(@admin_role_id, @perm_manage),
(@admin_role_id, @perm_recurring),
(@admin_role_id, @perm_cats),
(@accountant_role_id, @perm_view),
(@accountant_role_id, @perm_manage),
(@accountant_role_id, @perm_recurring),
(@accountant_role_id, @perm_cats)
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
