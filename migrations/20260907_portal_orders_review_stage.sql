-- Staging area for customer-portal order submissions. A portal order lands
-- here first (no stock deduction, no price confirmed) and must be reviewed
-- and priced by staff before it becomes a real row in `orders`.
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `portal_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `factory_id` int(11) DEFAULT NULL,
  `status` enum('pending_review','priced','approved','rejected','converted') NOT NULL DEFAULT 'pending_review',
  `customer_note` text DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `responsible_user_id` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `converted_order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `portal_orders_customer_idx` (`customer_id`),
  KEY `portal_orders_status_idx` (`status`),
  KEY `portal_orders_converted_order_idx` (`converted_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `portal_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `portal_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_priced` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `portal_order_items_order_idx` (`portal_order_id`),
  KEY `portal_order_items_product_idx` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Permission for staff to review/price/approve/convert portal orders.
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'sales', 'Portal Orders - Manage', 'sales.portal_orders.manage', 'Review, price, approve and convert customer portal order requests'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'sales.portal_orders.manage');

SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` = 'sales.portal_orders.manage'
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = @admin_role_id AND rp.permission_id = p.id
  );

-- Grant to any role that already holds sales.orders.create (existing order-creating staff).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
FROM role_permissions rp
JOIN permissions create_p ON create_p.id = rp.permission_id AND create_p.`key` = 'sales.orders.create'
JOIN permissions p ON p.`key` = 'sales.portal_orders.manage'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions existing
    WHERE existing.role_id = rp.role_id AND existing.permission_id = p.id
);

COMMIT;
