START TRANSACTION;

CREATE TABLE IF NOT EXISTS `customer_wallet_balance_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `previous_balance` decimal(10,2) NOT NULL,
  `new_balance` decimal(10,2) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `customer_wallet_adjustments_customer_idx` (`customer_id`, `created_at`),
  KEY `customer_wallet_adjustments_user_idx` (`created_by`),
  CONSTRAINT `customer_wallet_adjustments_customer_fk`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_wallet_adjustments_user_fk`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
VALUES (
  'customers',
  'Customers - Set wallet balance',
  'customers.wallet.balance.edit',
  'Allows an authorized user to set a customer wallet/account balance directly, including negative debt balances, without creating a wallet transaction or changing a cash/bank account.'
)
ON DUPLICATE KEY UPDATE
  `module` = VALUES(`module`),
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1);
SET @wallet_balance_permission_id := (SELECT `id` FROM `permissions` WHERE `key` = 'customers.wallet.balance.edit' LIMIT 1);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, @wallet_balance_permission_id
WHERE @admin_role_id IS NOT NULL AND @wallet_balance_permission_id IS NOT NULL
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

COMMIT;
