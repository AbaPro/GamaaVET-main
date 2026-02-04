-- Safe migration: Fix manufacturing_order_steps status ENUM values
-- This migration is idempotent and can be run multiple times safely

-- Ensure the ENUM has the correct values
-- If already correct, this will have no effect
ALTER TABLE `manufacturing_order_steps` 
MODIFY COLUMN `status` ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending';
