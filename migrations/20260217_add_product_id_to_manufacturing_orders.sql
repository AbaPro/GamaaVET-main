-- Add product_id to manufacturing_orders for associating with a final product
ALTER TABLE `manufacturing_orders` ADD COLUMN `product_id` int(11) DEFAULT NULL;

-- Add foreign key constraint for manufacturing_orders product_id
ALTER TABLE `manufacturing_orders` ADD CONSTRAINT `fk_manufacturing_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL;
