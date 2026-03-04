-- Migration to add item_notes column to manufacturing_packaging_checklist
ALTER TABLE manufacturing_packaging_checklist 
ADD COLUMN item_notes TEXT NULL AFTER item_value;
