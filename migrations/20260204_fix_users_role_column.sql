-- Fix users.role column to allow any role slug value
-- The system now uses role_id as the primary role identifier, but role column is kept for backward compatibility

ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NULL DEFAULT 'salesman';
