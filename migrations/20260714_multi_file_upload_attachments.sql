-- Multi-file upload support: child attachment tables for 5 single-file features,
-- plus backfill of existing single-file data.

-- 1. Vendor wallet transaction attachments
CREATE TABLE IF NOT EXISTS `vendor_wallet_transaction_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_wallet_transaction_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `vwta_transaction_idx` (`vendor_wallet_transaction_id`),
  KEY `vwta_created_by_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `vendor_wallet_transaction_attachments`
  (`vendor_wallet_transaction_id`, `file_path`, `original_name`, `created_by`, `created_at`)
SELECT `id`, `attachment_path`, `attachment_original_name`, `created_by`, `created_at`
FROM `vendor_wallet_transactions`
WHERE `attachment_path` IS NOT NULL AND `attachment_path` != '';

-- 2. PO payment screenshots
CREATE TABLE IF NOT EXISTS `purchase_order_payment_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_payment_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `popa_payment_idx` (`purchase_order_payment_id`),
  KEY `popa_created_by_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchase_order_payment_attachments`
  (`purchase_order_payment_id`, `file_path`, `original_name`, `created_by`, `created_at`)
SELECT `id`, `screenshot_path`, NULL, `created_by`, `created_at`
FROM `purchase_order_payments`
WHERE `screenshot_path` IS NOT NULL AND `screenshot_path` != '';

-- 3. PO receipt images
CREATE TABLE IF NOT EXISTS `purchase_order_receipt_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_receipt_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pori_receipt_idx` (`purchase_order_receipt_id`),
  KEY `pori_created_by_idx` (`created_by`),
  CONSTRAINT `purchase_order_receipt_images_receipt_fk`
    FOREIGN KEY (`purchase_order_receipt_id`) REFERENCES `purchase_order_receipts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchase_order_receipt_images`
  (`purchase_order_receipt_id`, `file_path`, `original_name`, `created_by`, `created_at`)
SELECT `id`, `image_path`, NULL, `created_by`, `created_at`
FROM `purchase_order_receipts`
WHERE `image_path` IS NOT NULL AND `image_path` != '';

-- 4. Finance transfer images
CREATE TABLE IF NOT EXISTS `finance_transfer_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `finance_transfer_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fti_transfer_idx` (`finance_transfer_id`),
  KEY `fti_created_by_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `finance_transfer_images`
  (`finance_transfer_id`, `file_path`, `original_name`, `created_by`, `created_at`)
SELECT `id`, `image_path`, NULL, `created_by`, `created_at`
FROM `finance_transfers`
WHERE `image_path` IS NOT NULL AND `image_path` != '';

-- 5. Inventory transfer images
CREATE TABLE IF NOT EXISTS `inventory_transfer_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_transfer_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `iti_transfer_idx` (`inventory_transfer_id`),
  KEY `iti_created_by_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `inventory_transfer_images`
  (`inventory_transfer_id`, `file_path`, `original_name`, `created_by`, `created_at`)
SELECT `id`, `image_path`, NULL, `transferred_by`, `created_at`
FROM `inventory_transfers`
WHERE `image_path` IS NOT NULL AND `image_path` != '';

-- purchase_order_receipts.image_path is NOT NULL today; new receipt rows will stop
-- populating it, so it must become nullable or every future insert fails.
ALTER TABLE `purchase_order_receipts`
  MODIFY COLUMN `image_path` varchar(255) DEFAULT NULL;
