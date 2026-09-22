START TRANSACTION;

-- One permission grants access to audited direct settlement for every balance
-- surfaced by Finance: customer/vendor wallets, safes, banks, and personal accounts.
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  ('finance', 'Finance - Settle all balances', 'finance.balances.settle', 'Set a new balance for customer and vendor wallets, safes, bank accounts, and personal accounts. Every change requires a reason and is audited.')
ON DUPLICATE KEY UPDATE
  `module` = VALUES(`module`),
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

-- The shared adjustment ledger originally covered only safes and banks.
ALTER TABLE `finance_account_balance_adjustments`
  MODIFY COLUMN `account_type` enum('safe','bank','personal','vendor') NOT NULL;

-- Use the same range for all directly settled balances.
ALTER TABLE `personal_accounts`
  MODIFY COLUMN `balance` decimal(15,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `vendors`
  MODIFY COLUMN `wallet_balance` decimal(15,2) DEFAULT 0.00;
ALTER TABLE `customers`
  MODIFY COLUMN `wallet_balance` decimal(15,2) DEFAULT 0.00;
ALTER TABLE `customer_wallet_balance_adjustments`
  MODIFY COLUMN `previous_balance` decimal(15,2) NOT NULL,
  MODIFY COLUMN `new_balance` decimal(15,2) NOT NULL;

-- Admin receives the new high-risk permission by default. Other roles can be
-- granted it explicitly from Roles > Permissions.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON p.`key` = 'finance.balances.settle'
WHERE r.slug = 'admin'
  AND NOT EXISTS (
    SELECT 1
    FROM `role_permissions` rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

COMMIT;
