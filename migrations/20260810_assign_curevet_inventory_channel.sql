-- CUREVET is a CureVet direct-sale inventory, not a factory inventory.
-- Normalize legacy rows that were created before channel assignment was enforced.
UPDATE `inventories`
SET `direct_sale` = 'curva'
WHERE `direct_sale` IS NULL
  AND LOWER(REPLACE(TRIM(`name`), ' ', '')) IN ('curevet', 'curevetinventory');
