-- Migration: Grant Notification and Order View Permissions to Production Roles
-- Date: 2026-02-28

START TRANSACTION;

-- Grant permissions to Production Manager and Supervisor
-- Using a JOIN to avoid IF statements not supported in direct SQL scripts
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug IN ('production_manager', 'production_supervisor')
AND p.`key` IN ('notifications.view', 'sales.orders.view');

COMMIT;
