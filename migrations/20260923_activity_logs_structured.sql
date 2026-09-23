START TRANSACTION;

-- Structured columns so the activity log can be filtered by what happened and to which record.
-- Existing rows are left NULL here; modules/users/activity_logs.php backfills them from the
-- action text (same inference as logActivity()) the first time the page is opened.
ALTER TABLE `activity_logs`
  ADD COLUMN IF NOT EXISTS `action_type` varchar(20) DEFAULT NULL AFTER `action`,
  ADD COLUMN IF NOT EXISTS `entity_type` varchar(50) DEFAULT NULL AFTER `action_type`,
  ADD COLUMN IF NOT EXISTS `entity_id` int(11) DEFAULT NULL AFTER `entity_type`;

ALTER TABLE `activity_logs`
  ADD INDEX IF NOT EXISTS `idx_activity_logs_created_at` (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_activity_logs_action_type` (`action_type`),
  ADD INDEX IF NOT EXISTS `idx_activity_logs_entity` (`entity_type`, `entity_id`);

-- Dedicated permission for the activity log page (already present in most databases).
INSERT INTO `permissions` (`module`, `name`, `key`, `description`)
SELECT 'users', 'View Activity Logs', 'users.activity_logs.view', 'View the user activity log (logins, creates, edits, deletes)'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `key` = 'users.activity_logs.view');

-- Grant to admin role
SET @admin_role_id := (SELECT `id` FROM `roles` WHERE `slug`='admin' LIMIT 1);
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @admin_role_id, p.id FROM permissions p
WHERE p.`key` = 'users.activity_logs.view'
  AND @admin_role_id IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp WHERE rp.role_id = @admin_role_id AND rp.permission_id = p.id
  );

-- Keep access for any role that could already open the page via users.manage
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
FROM role_permissions rp
JOIN permissions manage_p ON manage_p.id = rp.permission_id AND manage_p.`key` = 'users.manage'
JOIN permissions p ON p.`key` = 'users.activity_logs.view'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions existing
    WHERE existing.role_id = rp.role_id AND existing.permission_id = p.id
);

COMMIT;
