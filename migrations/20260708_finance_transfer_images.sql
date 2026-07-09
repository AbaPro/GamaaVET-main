-- Add optional image evidence to financial transfers.
SET @db_name := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `finance_transfers` ADD COLUMN `image_path` varchar(255) DEFAULT NULL AFTER `notes`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @db_name
    AND table_name = 'finance_transfers'
    AND column_name = 'image_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
