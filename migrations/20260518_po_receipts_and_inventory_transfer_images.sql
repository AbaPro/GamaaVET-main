-- Store required receiving images for PO item receipt batches.
CREATE TABLE IF NOT EXISTS `purchase_order_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `purchase_order_receipts_po_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_receipts_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Attach image evidence and explicit actor/time audit fields to inventory transfers.
SET @db_name := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `image_path` varchar(255) DEFAULT NULL AFTER `notes`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @db_name
    AND table_name = 'inventory_transfers'
    AND column_name = 'image_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `transferred_by` int(11) DEFAULT NULL AFTER `requested_by`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @db_name
    AND table_name = 'inventory_transfers'
    AND column_name = 'transferred_by'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `transferred_at` datetime DEFAULT NULL AFTER `transferred_by`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @db_name
    AND table_name = 'inventory_transfers'
    AND column_name = 'transferred_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `inventory_transfers`
SET `transferred_by` = COALESCE(`transferred_by`, `requested_by`),
    `transferred_at` = COALESCE(`transferred_at`, `created_at`)
WHERE `transferred_by` IS NULL
   OR `transferred_at` IS NULL;
