ALTER TABLE `order_payments` ADD COLUMN `safe_id` INT DEFAULT NULL;
ALTER TABLE `order_payments` ADD COLUMN `bank_account_id` INT DEFAULT NULL;

ALTER TABLE `order_payments` ADD CONSTRAINT `fk_op_safe` FOREIGN KEY (`safe_id`) REFERENCES `safes`(`id`) ON DELETE SET NULL;
ALTER TABLE `order_payments` ADD CONSTRAINT `fk_op_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE SET NULL;
