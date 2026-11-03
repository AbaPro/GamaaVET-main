-- One permanent factory-channel inventory for manufacturing waste, across locations.
ALTER TABLE inventories
    ADD COLUMN IF NOT EXISTS system_key varchar(50) NULL,
    ADD UNIQUE INDEX IF NOT EXISTS inventories_system_key (system_key);

INSERT INTO inventories (name, description, is_active, direct_sale, system_key)
SELECT 'مخزن الهالك', 'هالك أوامر التصنيع المكتملة', 1, NULL, 'manufacturing_waste'
WHERE NOT EXISTS (SELECT 1 FROM inventories WHERE system_key = 'manufacturing_waste');

ALTER TABLE manufacturing_orders
    ADD COLUMN IF NOT EXISTS finished_goods_waste_quantity decimal(12,2) NULL;
