-- Add document path columns for customer tax and commercial registration scans
ALTER TABLE `customers`
  ADD COLUMN `tax_document_path` VARCHAR(255) NULL DEFAULT NULL AFTER `tax_number`,
  ADD COLUMN `commercial_document_path` VARCHAR(255) NULL DEFAULT NULL AFTER `tax_document_path`;

