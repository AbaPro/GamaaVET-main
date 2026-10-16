-- Per-user bank account access, mirroring users.safe_access_ids.
-- NULL preserves access to all brand bank accounts; [] denies all bank accounts.
ALTER TABLE users ADD COLUMN IF NOT EXISTS bank_access_ids TEXT DEFAULT NULL;
