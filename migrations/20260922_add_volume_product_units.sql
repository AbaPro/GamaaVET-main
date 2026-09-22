-- Add volume units for final products and raw materials.
ALTER TABLE `products`
  MODIFY `unit` ENUM('each', 'gram', 'kilo', 'milliliter', 'liter', '') DEFAULT NULL;
