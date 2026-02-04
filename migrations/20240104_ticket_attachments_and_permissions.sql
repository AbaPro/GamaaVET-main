-- Ticket attachments storage
CREATE TABLE IF NOT EXISTS `ticket_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_idx` (`ticket_id`),
  KEY `ticket_attachment_user_idx` (`created_by`),
  CONSTRAINT `ticket_attachments_ticket_fk` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_attachments_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Permissions for order/ticket status updates
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'sales', 'Update Order Status', 'sales.orders.update_status', 'Allows changing the workflow status of sales orders'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'sales.orders.update_status');

INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'tickets', 'Update Ticket Status', 'tickets.update_status', 'Allows non-admin users to update ticket status/priority'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'tickets.update_status');

-- Dedicated sales team roles
INSERT INTO `roles` (`name`, `slug`, `description`, `is_active`)
SELECT 'Factory Sales', 'factory_sales', 'Factory sales team access', 1
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `slug` = 'factory_sales');

INSERT INTO `roles` (`name`, `slug`, `description`, `is_active`)
SELECT 'Representative Sales', 'representative_sales', 'Representative sales team access', 1
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `slug` = 'representative_sales');

-- Copy salesman permissions to both new sales roles
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT target.id, rp.permission_id
FROM role_permissions rp
JOIN roles src ON rp.role_id = src.id AND src.slug = 'salesman'
JOIN roles target ON target.slug = 'factory_sales'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions existing
    WHERE existing.role_id = target.id AND existing.permission_id = rp.permission_id
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT target.id, rp.permission_id
FROM role_permissions rp
JOIN roles src ON rp.role_id = src.id AND src.slug = 'salesman'
JOIN roles target ON target.slug = 'representative_sales'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions existing
    WHERE existing.role_id = target.id AND existing.permission_id = rp.permission_id
);

-- Grant new order status permission to sales-focused roles
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` = 'sales.orders.update_status'
WHERE r.slug IN ('salesman','sales_manager','factory_sales','representative_sales')
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- Grant ticket status permission to relevant roles
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` = 'tickets.update_status'
WHERE r.slug IN ('salesman','sales_manager','operations_manager','factory_sales','representative_sales')
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
