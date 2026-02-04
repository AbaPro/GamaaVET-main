-- Schema patch: factories catalog, customer portal tokens, and order factory linkage.
-- Run after backing up production data.

START TRANSACTION;

-- 1) Master data for factories.
CREATE TABLE IF NOT EXISTS `factories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(25) DEFAULT NULL,
  `whatsapp_number` varchar(25) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Extend customers with factory link + WhatsApp + portal token.
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `factory_id` int(11) DEFAULT NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `whatsapp_phone` varchar(20) DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `portal_token` varchar(64) DEFAULT NULL AFTER `wallet_balance`,
  ADD COLUMN IF NOT EXISTS `portal_token_expires` datetime DEFAULT NULL AFTER `portal_token`;

ALTER TABLE `customers`
  ADD INDEX `factory_id` (`factory_id`);

-- 3) Extend orders so they persist the factory snapshot for invoices.
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `factory_id` int(11) DEFAULT NULL AFTER `customer_id`,
  ADD INDEX `factory_id` (`factory_id`);

-- 4) Foreign keys.
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`factory_id`) REFERENCES `factories` (`id`) ON DELETE SET NULL;

ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`factory_id`) REFERENCES `factories` (`id`) ON DELETE SET NULL;

-- 5) Backfill order factory_id from latest customer assignment.
UPDATE `orders` o
JOIN `customers` c ON o.customer_id = c.id
SET o.factory_id = c.factory_id
WHERE o.factory_id IS NULL AND c.factory_id IS NOT NULL;

COMMIT;
