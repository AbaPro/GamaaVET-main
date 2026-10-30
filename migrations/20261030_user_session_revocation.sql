-- Apply before deploying the session-revocation PHP changes.
-- Version 0 preserves existing sessions until an explicit revocation.
ALTER TABLE users ADD COLUMN IF NOT EXISTS session_version BIGINT UNSIGNED NOT NULL DEFAULT 0;
