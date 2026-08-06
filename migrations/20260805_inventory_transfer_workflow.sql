-- Sender -> Receiver -> Verifier workflow for inventory transfers.
--
-- Before this migration transfer.php hardcoded status='accepted' and stamped the
-- sender into accepted_by/transferred_by, so the pending workflow (and
-- accept_transfer.php / delete_transfer.php) was unreachable dead code.
--
-- New model:
--   pending --receiver confirms--> transferred --verifier signs off--> verified
--      |                                 |
--      | receiver rejects                | verifier sends back
--      v                                 |
--   rejected <---------------------------+ (back to pending)
--
-- Stock moves ONLY on the pending -> transferred edge.

SET @db_name := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. Status enum: widen, migrate legacy 'accepted' rows, then narrow.
-- ---------------------------------------------------------------------------

ALTER TABLE `inventory_transfers`
  MODIFY COLUMN `status` enum('pending','accepted','transferred','verified','rejected')
  NOT NULL DEFAULT 'pending';

-- Every historical row was created as 'accepted' with stock already moved,
-- which is exactly what 'transferred' now means.
UPDATE `inventory_transfers` SET `status` = 'transferred' WHERE `status` = 'accepted';

ALTER TABLE `inventory_transfers`
  MODIFY COLUMN `status` enum('pending','transferred','verified','rejected')
  NOT NULL DEFAULT 'pending';

-- ---------------------------------------------------------------------------
-- 2. Actor / timestamp columns (idempotent guards, matching the pattern in
--    20260518_po_receipts_and_inventory_transfer_images.sql).
-- ---------------------------------------------------------------------------

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `received_by` int(11) DEFAULT NULL AFTER `accepted_by`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'received_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `received_at` datetime DEFAULT NULL AFTER `received_by`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'received_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `verified_by` int(11) DEFAULT NULL AFTER `received_at`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'verified_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `verified_at` datetime DEFAULT NULL AFTER `verified_by`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'verified_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `rejected_by` int(11) DEFAULT NULL AFTER `verified_at`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'rejected_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `rejected_at` datetime DEFAULT NULL AFTER `rejected_by`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'rejected_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD COLUMN `rejection_reason` text DEFAULT NULL AFTER `rejected_at`',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND column_name = 'rejection_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD INDEX `it_received_by_idx` (`received_by`)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND index_name = 'it_received_by_idx'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `inventory_transfers` ADD INDEX `it_status_idx` (`status`)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = @db_name AND table_name = 'inventory_transfers' AND index_name = 'it_status_idx'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Legacy rows: the sender was stamped as accepted_by, and stock moved at creation.
-- Treat that as "received" so the details view is not blank for historical data.
UPDATE `inventory_transfers`
SET `received_by` = COALESCE(`received_by`, `accepted_by`, `transferred_by`),
    `received_at` = COALESCE(`received_at`, `transferred_at`, `created_at`)
WHERE `status` IN ('transferred', 'verified')
  AND (`received_by` IS NULL OR `received_at` IS NULL);

-- ---------------------------------------------------------------------------
-- 3. Edit / state-change history.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `inventory_transfer_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_transfer_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `field_name` varchar(100) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ith_transfer_idx` (`inventory_transfer_id`),
  KEY `ith_created_by_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed a 'created' row for every existing transfer so history is never empty.
INSERT INTO `inventory_transfer_history`
  (`inventory_transfer_id`, `action`, `note`, `created_by`, `created_at`)
SELECT it.`id`, 'created', 'Backfilled from existing transfer record', it.`requested_by`, it.`created_at`
FROM `inventory_transfers` it
WHERE NOT EXISTS (
  SELECT 1 FROM `inventory_transfer_history` h
  WHERE h.`inventory_transfer_id` = it.`id` AND h.`action` = 'created'
);

-- ---------------------------------------------------------------------------
-- 4. Permissions (pattern from 20260314_add_sales_delete_permission.sql).
-- ---------------------------------------------------------------------------

INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
VALUES ('inventories', 'Inventories - Receive Transfers', 'inventories.transfer.receive',
        'Be assignable as a transfer receiver and confirm/reject incoming transfers (moves stock)')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
VALUES ('inventories', 'Inventories - Verify Transfers', 'inventories.transfer.verify',
        'Verify a received transfer, or send it back to the receiver for correction')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

SET @admin_role_id = (SELECT `id` FROM `roles` WHERE `slug` = 'admin');

SET @perm_receive = (SELECT `id` FROM `permissions` WHERE `key` = 'inventories.transfer.receive');
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (@admin_role_id, @perm_receive)
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

SET @perm_verify = (SELECT `id` FROM `permissions` WHERE `key` = 'inventories.transfer.verify');
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (@admin_role_id, @perm_verify)
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
