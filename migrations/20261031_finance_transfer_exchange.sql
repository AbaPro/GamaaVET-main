-- Cross-currency finance transfers (currency exchange between safes / bank accounts).
-- `amount` stays in the sender's currency. `to_amount` is what the receiver gets in
-- its own currency, and `exchange_rate` is receiver units per one sender unit.
-- Both stay NULL for same-currency transfers, so existing rows are unchanged.
ALTER TABLE `finance_transfers`
  ADD COLUMN IF NOT EXISTS `to_amount` decimal(12,2) DEFAULT NULL AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `exchange_rate` decimal(16,6) DEFAULT NULL AFTER `to_amount`;
