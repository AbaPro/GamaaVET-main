-- Safe migration: Fix manufacturing_orders status ENUM values
-- This migration is idempotent and can be run multiple times safely

-- First, check and expand the ENUM to include both old and new values (if needed)
-- This won't fail if the ENUM already has the correct values
ALTER TABLE `manufacturing_orders` 
MODIFY COLUMN `status` ENUM('getting','preparing','delivering','completed','pending','in_progress','cancelled') NOT NULL DEFAULT 'pending';

-- Migrate old status values to new ones (safe - only updates if old values exist)
UPDATE `manufacturing_orders` SET `status` = 'pending' WHERE `status` = 'getting';
UPDATE `manufacturing_orders` SET `status` = 'in_progress' WHERE `status` = 'preparing';
UPDATE `manufacturing_orders` SET `status` = 'completed' WHERE `status` = 'delivering';

-- Finally, set the ENUM to only the new values
-- This will succeed now that all old values have been migrated
ALTER TABLE `manufacturing_orders` 
MODIFY COLUMN `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending';
