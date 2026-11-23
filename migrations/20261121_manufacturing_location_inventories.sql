-- Each location owns its own permanent manufacturing waste and primary stores.
-- Keep unassigned legacy warehouses and their stock/history in place.
ALTER TABLE inventories
    DROP INDEX IF EXISTS inventories_system_key,
    ADD UNIQUE INDEX IF NOT EXISTS inventories_location_system (location_id, system_key);

INSERT INTO inventories (name, location_id, description, is_active, direct_sale, system_key)
SELECT 'مخزن هالك', l.id, 'هالك التصنيع في هذا الموقع', 1,
    CASE WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(l.name),' ',''),'-',''),'_','')) IN ('curevet','curevetinventory') THEN 'curva' ELSE NULL END,
    'manufacturing_waste'
FROM locations l
WHERE NOT EXISTS (SELECT 1 FROM inventories i WHERE i.location_id = l.id AND i.system_key = 'manufacturing_waste');

INSERT INTO inventories (name, location_id, description, is_active, direct_sale, system_key)
SELECT 'مخزن منتج اولي', l.id, 'منتجات غير معبأة متاحة لإعادة التعبئة في هذا الموقع', 1,
    CASE WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(l.name),' ',''),'-',''),'_','')) IN ('curevet','curevetinventory') THEN 'curva' ELSE NULL END,
    'manufacturing_primary'
FROM locations l
WHERE NOT EXISTS (SELECT 1 FROM inventories i WHERE i.location_id = l.id AND i.system_key = 'manufacturing_primary');

-- Covers every location insertion path; creating the location and its two stores is atomic.
CREATE TRIGGER IF NOT EXISTS locations_create_manufacturing_inventories
AFTER INSERT ON locations FOR EACH ROW
INSERT INTO inventories (name, location_id, description, is_active, direct_sale, system_key) VALUES
('مخزن هالك', NEW.id, 'هالك التصنيع في هذا الموقع', 1,
 CASE WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(NEW.name),' ',''),'-',''),'_','')) IN ('curevet','curevetinventory') THEN 'curva' ELSE NULL END, 'manufacturing_waste'),
('مخزن منتج اولي', NEW.id, 'منتجات غير معبأة متاحة لإعادة التعبئة في هذا الموقع', 1,
 CASE WHEN LOWER(REPLACE(REPLACE(REPLACE(TRIM(NEW.name),' ',''),'-',''),'_','')) IN ('curevet','curevetinventory') THEN 'curva' ELSE NULL END, 'manufacturing_primary');
