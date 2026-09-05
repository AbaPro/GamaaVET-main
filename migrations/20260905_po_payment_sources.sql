-- Nullable source fields preserve historical payments without inventing balance movements.
ALTER TABLE purchase_order_payments
  ADD COLUMN IF NOT EXISTS payment_source_type enum('safe','bank','personal') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS payment_source_id int(11) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_po_payment_source ON purchase_order_payments (payment_source_type, payment_source_id);
