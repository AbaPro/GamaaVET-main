CREATE TABLE IF NOT EXISTS `expense_payment_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_payment_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `expense_payment_attachments_payment_idx` (`expense_payment_id`),
  CONSTRAINT `expense_payment_attachments_payment_fk`
    FOREIGN KEY (`expense_payment_id`) REFERENCES `expense_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
