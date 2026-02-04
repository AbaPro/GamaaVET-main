-- Migration: Introduce roles and permissions, attach all users to an Admin role
-- Target DB: MariaDB 10.4+ (from repo dump header)

START TRANSACTION;

-- 1) Create roles table
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Create permissions table
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `key` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Create role_permissions pivot table
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Add role_id column on users and index + FK
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `role_id` int(11) NULL AFTER `role`;

-- Add index if missing
SET @has_role_idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'role_id'
);
SET @stmt_idx := IF(@has_role_idx = 0, 'ALTER TABLE `users` ADD INDEX `role_id` (`role_id`);', 'SELECT 1');
PREPARE s1 FROM @stmt_idx; EXECUTE s1; DEALLOCATE PREPARE s1;

-- Add FK if missing
SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'users_ibfk_role'
);
SET @stmt_fk := IF(@has_fk = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `users_ibfk_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;',
  'SELECT 1');
PREPARE s2 FROM @stmt_fk; EXECUTE s2; DEALLOCATE PREPARE s2;

-- 5) Seed a base Admin role
INSERT INTO `roles` (`name`, `slug`, `description`, `is_active`)
SELECT 'Administrator', 'admin', 'Full access role', 1
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `slug` = 'admin');

SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin');

INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  -- Sales Dashboard
  ('sales', 'Dashboard - View', 'sales.dashboard.view', NULL),
  ('sales', 'Dashboard - Orders pending', 'sales.dashboard.orders_pending', NULL),
  ('sales', 'Dashboard - Overall orders', 'sales.dashboard.overall_orders', NULL),
  ('sales', 'Dashboard - This month orders', 'sales.dashboard.this_month', NULL),
  ('sales', 'Dashboard - Recent orders', 'sales.dashboard.recent_orders', NULL),
  ('sales', 'Orders - View all', 'sales.orders.view_all', NULL),
  ('sales', 'Orders - Create new', 'sales.orders.create', NULL),
  ('sales', 'Orders - View order', 'sales.orders.view', NULL),
  ('sales', 'Orders - Print invoice', 'sales.orders.print_invoice', NULL),
  ('sales', 'Orders - Add return', 'sales.orders.returns.add', NULL),
  ('sales', 'Orders - View payment history', 'sales.orders.payments.history', NULL),
  ('sales', 'Orders - Update status', 'sales.orders.status.update', NULL),
  ('sales', 'Orders - Edit discount', 'sales.orders.discount.edit', NULL),
  ('sales', 'Orders - Edit shipping', 'sales.orders.shipping.edit', NULL),

  -- Customers
  ('customers', 'Customers - View', 'customers.view', NULL),
  ('customers', 'Customers - Create', 'customers.create', NULL),
  ('customers', 'Customers - Edit', 'customers.edit', NULL),
  ('customers', 'Customers - Delete', 'customers.delete', NULL),
  ('customers', 'Customers - View details', 'customers.details.view', NULL),
  ('customers', 'Customers - Wallet', 'customers.wallet', NULL),
  ('customers', 'Customers - WhatsApp portal', 'customers.whatsapp_portal', NULL),
  ('customers', 'Customers - Contacts manage', 'customers.contacts.manage', NULL),
  ('customers', 'Customers - Addresses manage', 'customers.addresses.manage', NULL),
  ('customers', 'Customers - Create order', 'customers.orders.create', NULL),
  ('customers', 'Customers - Orders view', 'customers.orders.view', NULL),

  -- Inventories
  ('inventories', 'Inventories - View', 'inventories.view', NULL),
  ('inventories', 'Inventories - Create', 'inventories.create', NULL),
  ('inventories', 'Inventories - Edit', 'inventories.edit', NULL),
  ('inventories', 'Inventories - Delete', 'inventories.delete', NULL),
  ('inventories', 'Inventories - Transfer items', 'inventories.transfer', NULL),
  ('inventories', 'Inventories - Low stock view', 'inventories.low_stock.view', NULL),
  ('inventories', 'Inventories - Print', 'inventories.print', NULL),
  ('inventories', 'Inventory Products - Add product', 'inventories.products.add', NULL),

  -- Products
  ('products', 'Products - View', 'products.view', NULL),
  ('products', 'Products - Create', 'products.create', NULL),
  ('products', 'Products - Bulk upload', 'products.bulk_upload', NULL),
  ('products', 'Products - Edit', 'products.edit', NULL),
  ('products', 'Products - Edit min stock', 'products.edit_min_stock', NULL),
  ('products', 'Products - Delete', 'products.delete', NULL),

  -- Purchasing
  ('purchases', 'PO - View recent', 'purchases.view_recent', NULL),
  ('purchases', 'PO - View all', 'purchases.view_all', NULL),
  ('purchases', 'PO - Create new', 'purchases.create', NULL),
  ('purchases', 'PO - View', 'purchases.view', NULL),
  ('purchases', 'PO - Receive items', 'purchases.receive', NULL),
  ('purchases', 'PO - Payments process', 'purchases.payments.process', NULL),

  -- Vendors
  ('vendors', 'Vendors - View', 'vendors.view', NULL),
  ('vendors', 'Vendors - Create', 'vendors.create', NULL),
  ('vendors', 'Vendors - Edit', 'vendors.edit', NULL),
  ('vendors', 'Vendors - Delete', 'vendors.delete', NULL),
  ('vendors', 'Vendors - Contact', 'vendors.contact', NULL),
  ('vendors', 'Vendors - Wallet', 'vendors.wallet', NULL),
  ('vendors', 'Vendors - WhatsApp portal', 'vendors.whatsapp_portal', NULL),
  ('vendors', 'Vendors - View details', 'vendors.details.view', NULL),

  -- Users
  ('users', 'Users - Manage', 'users.manage', NULL),

  -- Finance
  ('finance', 'Finance - Customer wallet view', 'finance.customer_wallet.view', NULL),
  ('finance', 'Finance - Customer payment', 'finance.customer_payment.process', NULL),
  ('finance', 'Finance - Safes create', 'finance.safes.create', NULL),
  ('finance', 'Finance - Bank account create', 'finance.bank_accounts.create', NULL),
  ('finance', 'Finance - Personal account create', 'finance.personal_accounts.create', NULL),
  ('finance', 'Finance - Transfers create', 'finance.transfers.create', NULL),
  ('finance', 'Finance - PO payment', 'finance.po_payment.process', NULL),
  ('finance', 'Finance - Vendor wallet view', 'finance.vendor_wallet.view', NULL)
ON DUPLICATE KEY UPDATE `module`=VALUES(`module`), `name`=VALUES(`name`), `description`=VALUES(`description`);

-- 7) Grant ALL permissions to the Admin role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, p.`id` FROM `permissions` p
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- 8) Assign Admin role to all existing users
UPDATE `users` SET `role_id` = @admin_role_id;

COMMIT;
