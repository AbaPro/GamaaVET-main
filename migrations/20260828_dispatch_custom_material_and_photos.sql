-- Migration: Dispatch Prep — editable material name + multi-image load photos
-- Date: 2026-08-28
--
-- 1. Allow 'text' and 'image' item types on the dispatch checklist. The render
--    code already branched on 'image', but the enum never permitted the value.
-- 2. Widen item_value to hold a JSON array of image paths (varchar(255) only
--    fits a single path).
-- 3. Add item_notes so the dispatch material-tracking notes have a home,
--    matching manufacturing_packaging_checklist.
-- 4. Seed the editable 'اسم المادة' field and convert the load-photo row to
--    the image type for existing orders.

ALTER TABLE `manufacturing_dispatch_checklist`
    MODIFY COLUMN `item_type` enum('number','checkbox','text','image') NOT NULL DEFAULT 'checkbox';

ALTER TABLE `manufacturing_dispatch_checklist`
    MODIFY COLUMN `item_value` text DEFAULT NULL;

ALTER TABLE `manufacturing_dispatch_checklist`
    ADD COLUMN IF NOT EXISTS `item_notes` varchar(255) DEFAULT NULL AFTER `item_value`;

-- Existing orders: the load-photo row was seeded as a checkbox. Convert it to
-- an image row, dropping the 'checked' marker (never a real path).
UPDATE `manufacturing_dispatch_checklist`
SET `item_type` = 'image',
    `item_value` = NULL
WHERE `item_key` = 'load_photographed';

-- Existing orders: add the editable material name field, mirroring the
-- packaging step's default of 'مطبوعات'.
INSERT INTO `manufacturing_dispatch_checklist`
    (`manufacturing_order_id`, `section_name`, `item_key`, `item_text`, `item_type`, `item_value`, `item_order`)
SELECT mo.`id`, 'details', 'custom_material_name', 'اسم المادة', 'text', 'مطبوعات', 3
FROM `manufacturing_orders` mo
WHERE NOT EXISTS (
    SELECT 1 FROM `manufacturing_dispatch_checklist` c
    WHERE c.`manufacturing_order_id` = mo.`id`
      AND c.`item_key` = 'custom_material_name'
);
