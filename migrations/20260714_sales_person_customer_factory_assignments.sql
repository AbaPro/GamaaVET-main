-- Assign direct-sales customers and factories to individual salespeople.
ALTER TABLE `factories`
  ADD COLUMN IF NOT EXISTS `sales_person_id` int(11) DEFAULT NULL AFTER `name`,
  ADD INDEX IF NOT EXISTS `idx_factories_sales_person` (`sales_person_id`);

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `sales_person_id` int(11) DEFAULT NULL AFTER `factory_id`,
  ADD INDEX IF NOT EXISTS `idx_customers_sales_person` (`sales_person_id`);

SET @factory_sales_person_fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factories'
    AND CONSTRAINT_NAME = 'factories_sales_person_fk'
);
SET @factory_sales_person_fk_sql = IF(
  @factory_sales_person_fk_exists = 0,
  'ALTER TABLE `factories` ADD CONSTRAINT `factories_sales_person_fk` FOREIGN KEY (`sales_person_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE factory_sales_person_fk_stmt FROM @factory_sales_person_fk_sql;
EXECUTE factory_sales_person_fk_stmt;
DEALLOCATE PREPARE factory_sales_person_fk_stmt;

SET @customer_sales_person_fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'customers'
    AND CONSTRAINT_NAME = 'customers_sales_person_fk'
);
SET @customer_sales_person_fk_sql = IF(
  @customer_sales_person_fk_exists = 0,
  'ALTER TABLE `customers` ADD CONSTRAINT `customers_sales_person_fk` FOREIGN KEY (`sales_person_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE customer_sales_person_fk_stmt FROM @customer_sales_person_fk_sql;
EXECUTE customer_sales_person_fk_stmt;
DEALLOCATE PREPARE customer_sales_person_fk_stmt;

-- Safely backfill only regions that have exactly one active salesperson.
UPDATE customers c
JOIN (
  SELECT u.region, MIN(u.id) AS sales_person_id
  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  WHERE u.is_active = 1
    AND u.region IS NOT NULL
    AND u.region <> ''
    AND COALESCE(r.slug, u.role) IN ('salesman', 'factory_sales', 'representative_sales')
  GROUP BY u.region
  HAVING COUNT(*) = 1
) single_sales_person ON single_sales_person.region = COALESCE(c.direct_sale, c.region)
SET c.sales_person_id = single_sales_person.sales_person_id
WHERE c.sales_person_id IS NULL;

