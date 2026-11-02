-- Editing sales orders, and receiving vendor money into a safe or bank account.

INSERT INTO permissions (module, name, `key`, description) VALUES
('sales', 'Orders - Edit', 'sales.orders.edit', 'Edit the contact, date, notes, items, prices, discount and shipping of a sales order before delivery. Customer and currency stay fixed, and the total cannot drop below the amount already paid.'),
('vendors', 'Vendors - Receive money into safe/bank', 'vendors.wallet.receive_cash', 'Record vendor refunds received as cash or bank transfer; the selected safe or bank account is credited. Also requires Vendors - Wallet.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.`key` IN ('sales.orders.edit', 'vendors.wallet.receive_cash');

-- Destination of cash received from a vendor. NULL for wallet-only entries.
ALTER TABLE `vendor_wallet_transactions`
  ADD COLUMN IF NOT EXISTS `payment_method` varchar(20) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `safe_id` int(11) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `bank_account_id` int(11) DEFAULT NULL AFTER `safe_id`,
  ADD INDEX IF NOT EXISTS `vendor_wallet_transactions_safe_idx` (`safe_id`),
  ADD INDEX IF NOT EXISTS `vendor_wallet_transactions_bank_idx` (`bank_account_id`);
