-- Record the cash-safe destination for manual customer wallet transactions.
ALTER TABLE `customer_wallet_transactions`
  ADD COLUMN IF NOT EXISTS `safe_id` int(11) DEFAULT NULL AFTER `payment_method`;
