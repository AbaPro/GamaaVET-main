ALTER TABLE `customers`
  ADD COLUMN `portal_password_hash` varchar(255) DEFAULT NULL AFTER `portal_token_expires`,
  ADD COLUMN `portal_password_hint` varchar(120) DEFAULT NULL AFTER `portal_password_hash`,
  ADD COLUMN `portal_password_updated_at` datetime DEFAULT NULL AFTER `portal_password_hint`,
  ADD COLUMN `portal_last_access_at` datetime DEFAULT NULL AFTER `portal_password_updated_at`;
