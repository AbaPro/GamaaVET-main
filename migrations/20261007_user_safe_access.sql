-- NULL preserves access to all brand safes; [] denies all safes.
ALTER TABLE users ADD COLUMN IF NOT EXISTS safe_access_ids TEXT DEFAULT NULL;
