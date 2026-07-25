-- Migration: Scope safes and bank_accounts to brand accounts
-- Date: 2026-07-25

ALTER TABLE `safes`         ADD COLUMN `account_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `bank_accounts` ADD COLUMN `account_id` int(11) DEFAULT NULL AFTER `id`;

ALTER TABLE `safes`         ADD CONSTRAINT `safes_account_fk`
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL;
ALTER TABLE `bank_accounts` ADD CONSTRAINT `bank_accounts_account_fk`
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL;

-- accounts only had factory/curva/primer; add the two brands added later
INSERT INTO `accounts` (`name`, `slug`) VALUES
  ('Naturous', 'naturous'),
  ('Activita', 'activita')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Keep account display names in sync with the brand renames from 20260422_add_naturous_activita_regions.sql
UPDATE `accounts` SET `name` = 'CureVet'    WHERE `slug` = 'curva';
UPDATE `accounts` SET `name` = 'PremiumVet' WHERE `slug` = 'primer';
UPDATE `accounts` SET `name` = 'GammaVet'   WHERE `slug` = 'factory';

-- Existing safes/bank accounts remain NULL == Factory (consistent with how expenses.account_id treats NULL).
