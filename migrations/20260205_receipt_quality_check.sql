-- Add receipt and quality check fields to sourcing components table
-- This allows tracking approval status for each material in the Receipt & Quality Check step

-- Add receipt_status column if it doesn't exist
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_name = 'manufacturing_sourcing_components'
   AND table_schema = DATABASE()
   AND column_name = 'receipt_status') > 0,
  "SELECT 1",
  "ALTER TABLE manufacturing_sourcing_components ADD COLUMN receipt_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' AFTER notes"
));
PREPARE alterStatement FROM @preparedStatement;
EXECUTE alterStatement;
DEALLOCATE PREPARE alterStatement;

-- Add receipt_notes column if it doesn't exist
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_name = 'manufacturing_sourcing_components'
   AND table_schema = DATABASE()
   AND column_name = 'receipt_notes') > 0,
  "SELECT 1",
  "ALTER TABLE manufacturing_sourcing_components ADD COLUMN receipt_notes TEXT AFTER receipt_status"
));
PREPARE alterStatement FROM @preparedStatement;
EXECUTE alterStatement;
DEALLOCATE PREPARE alterStatement;
