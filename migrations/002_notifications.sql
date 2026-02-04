START TRANSACTION;

-- Notifications for system alerts (e.g., low stock)
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(50) NOT NULL,                -- e.g., low_stock
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT DEFAULT NULL,
  `module` VARCHAR(50) DEFAULT NULL,          -- e.g., inventories/products
  `entity_type` VARCHAR(50) DEFAULT NULL,     -- e.g., product
  `entity_id` INT(11) DEFAULT NULL,
  `severity` ENUM('info','warning','danger') DEFAULT 'warning',
  `created_for_role_id` INT(11) DEFAULT NULL, -- recipients by role
  `created_for_user_id` INT(11) DEFAULT NULL, -- or specific user
  `is_read` TINYINT(1) DEFAULT 0,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_for_role_id` (`created_for_role_id`),
  KEY `created_for_user_id` (`created_for_user_id`),
  KEY `entity_idx` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tickets linked to notifications (routing to teams)
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `notification_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
  `priority` ENUM('low','medium','high','urgent') DEFAULT 'medium',
  `assigned_to_role_id` INT(11) DEFAULT NULL,
  `assigned_to_user_id` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `notification_id` (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

