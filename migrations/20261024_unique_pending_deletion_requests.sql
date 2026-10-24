-- Retain the oldest pending request, including when normal/force deletion
-- requests compete for the same finance record. Preserve all rows as history.
UPDATE finance_deletion_requests duplicate_request
JOIN finance_deletion_requests earlier
  ON earlier.account_id = duplicate_request.account_id
 AND earlier.entity_type = duplicate_request.entity_type
 AND earlier.entity_id = duplicate_request.entity_id
 AND ((earlier.status = 'approved' AND earlier.reviewed_at >= duplicate_request.created_at)
      OR (earlier.status = 'pending' AND earlier.id < duplicate_request.id))
SET duplicate_request.status = 'rejected', duplicate_request.reviewed_at = NOW()
WHERE duplicate_request.status = 'pending';

UPDATE manufacturing_deletion_requests duplicate_request
JOIN manufacturing_deletion_requests earlier
  ON earlier.account_id = duplicate_request.account_id
 AND earlier.entity_type = duplicate_request.entity_type
 AND earlier.entity_id = duplicate_request.entity_id
 AND ((earlier.status = 'approved' AND earlier.reviewed_at >= duplicate_request.created_at)
      OR (earlier.status = 'pending' AND earlier.id < duplicate_request.id))
SET duplicate_request.status = 'rejected', duplicate_request.reviewed_at = NOW()
WHERE duplicate_request.status = 'pending';

-- NULL permits unlimited reviewed requests but only one pending request per
-- account and record. Rejection frees the key for a later resubmission.
ALTER TABLE finance_deletion_requests
 ADD COLUMN IF NOT EXISTS pending_entity_id INT GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN entity_id ELSE NULL END) STORED,
 ADD UNIQUE INDEX IF NOT EXISTS unique_pending_record (account_id, entity_type, pending_entity_id);

ALTER TABLE manufacturing_deletion_requests
 ADD COLUMN IF NOT EXISTS pending_entity_id INT GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN entity_id ELSE NULL END) STORED,
 ADD UNIQUE INDEX IF NOT EXISTS unique_pending_record (account_id, entity_type, pending_entity_id);
