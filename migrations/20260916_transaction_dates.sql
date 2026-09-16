-- Separate the user-selected business date from the immutable audit timestamp.
-- Historical rows retain their original date by deriving it from created_at.

START TRANSACTION;

ALTER TABLE `order_payments`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `notes`;
UPDATE `order_payments`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `order_payments`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `order_payments_transaction_date_idx` (`transaction_date`);

ALTER TABLE `purchase_order_payments`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `notes`;
UPDATE `purchase_order_payments`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `purchase_order_payments`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `purchase_order_payments_transaction_date_idx` (`transaction_date`);

ALTER TABLE `expense_payments`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `notes`;
UPDATE `expense_payments`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `expense_payments`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `expense_payments_transaction_date_idx` (`transaction_date`);

ALTER TABLE `customer_wallet_transactions`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `notes`;
UPDATE `customer_wallet_transactions`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `customer_wallet_transactions`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `customer_wallet_transactions_date_idx` (`transaction_date`);

ALTER TABLE `vendor_wallet_transactions`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `notes`;
UPDATE `vendor_wallet_transactions`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `vendor_wallet_transactions`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `vendor_wallet_transactions_date_idx` (`transaction_date`);

ALTER TABLE `finance_transfers`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `amount`;
UPDATE `finance_transfers`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `finance_transfers`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `finance_transfers_transaction_date_idx` (`transaction_date`);

ALTER TABLE `finance_account_balance_adjustments`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `reason`;
UPDATE `finance_account_balance_adjustments`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `finance_account_balance_adjustments`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `finance_account_adjustments_date_idx` (`transaction_date`);

ALTER TABLE `customer_wallet_balance_adjustments`
  ADD COLUMN IF NOT EXISTS `transaction_date` date DEFAULT NULL AFTER `reason`;
UPDATE `customer_wallet_balance_adjustments`
SET `transaction_date` = COALESCE(DATE(`created_at`), CURRENT_DATE)
WHERE `transaction_date` IS NULL;
ALTER TABLE `customer_wallet_balance_adjustments`
  MODIFY COLUMN `transaction_date` date NOT NULL DEFAULT (CURRENT_DATE),
  ADD INDEX IF NOT EXISTS `customer_wallet_adjustments_date_idx` (`transaction_date`);

COMMIT;
