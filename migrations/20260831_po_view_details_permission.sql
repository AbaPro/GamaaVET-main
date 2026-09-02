-- Give the existing PO detail permission a clear, assignable label in Roles & Permissions.
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
('purchases', 'PO - View Details', 'purchases.view',
 'Open a purchase order and view its details from purchase and finance pages')
ON DUPLICATE KEY UPDATE
  `module` = VALUES(`module`),
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);
