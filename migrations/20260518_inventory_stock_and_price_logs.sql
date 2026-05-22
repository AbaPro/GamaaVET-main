-- 2026-05-18: Inventory movement and product price history logs.

CREATE TABLE IF NOT EXISTS `inventory_stock_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `change_quantity` decimal(12,2) NOT NULL,
  `quantity_before` decimal(12,2) NOT NULL DEFAULT 0.00,
  `quantity_after` decimal(12,2) NOT NULL DEFAULT 0.00,
  `source_type` varchar(50) NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `unit_price` decimal(12,4) DEFAULT NULL,
  `sell_price` decimal(12,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_stock_product` (`product_id`),
  KEY `idx_inventory_stock_inventory` (`inventory_id`),
  KEY `idx_inventory_stock_source` (`source_type`, `source_id`),
  KEY `idx_inventory_stock_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_price_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `price_type` enum('unit','sell') NOT NULL,
  `price` decimal(12,4) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_price_product_type` (`product_id`, `price_type`),
  KEY `idx_product_price_source` (`source_type`, `source_id`),
  KEY `idx_product_price_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `inventory_stock_logs`
  (`inventory_id`, `product_id`, `change_quantity`, `quantity_before`, `quantity_after`, `source_type`, `source_id`, `notes`, `created_at`)
SELECT
  ip.inventory_id,
  ip.product_id,
  ip.quantity,
  0,
  ip.quantity,
  'opening_balance',
  ip.id,
  'Opening balance captured when inventory stock logging was introduced.',
  COALESCE(ip.last_updated, CURRENT_TIMESTAMP)
FROM `inventory_products` ip
WHERE ip.quantity <> 0
  AND NOT EXISTS (
    SELECT 1 FROM `inventory_stock_logs` isl
    WHERE isl.source_type = 'opening_balance'
      AND isl.source_id = ip.id
  );

INSERT INTO `product_price_logs` (`product_id`, `price_type`, `price`, `quantity`, `source_type`, `source_id`, `created_by`, `created_at`)
SELECT
  poi.product_id,
  'unit',
  poi.unit_price,
  CASE WHEN COALESCE(poi.received_quantity, 0) > 0 THEN poi.received_quantity ELSE poi.quantity END,
  'purchase_order_item',
  poi.id,
  po.created_by,
  COALESCE(po.created_at, CURRENT_TIMESTAMP)
FROM `purchase_order_items` poi
JOIN `purchase_orders` po ON po.id = poi.purchase_order_id
WHERE poi.unit_price > 0
  AND (CASE WHEN COALESCE(poi.received_quantity, 0) > 0 THEN poi.received_quantity ELSE poi.quantity END) > 0
  AND NOT EXISTS (
    SELECT 1 FROM `product_price_logs` ppl
    WHERE ppl.source_type = 'purchase_order_item'
      AND ppl.source_id = poi.id
      AND ppl.price_type = 'unit'
  );

INSERT INTO `product_price_logs` (`product_id`, `price_type`, `price`, `quantity`, `source_type`, `source_id`, `created_by`, `created_at`)
SELECT
  oi.product_id,
  'sell',
  oi.unit_price,
  oi.quantity,
  'order_item',
  oi.id,
  o.created_by,
  COALESCE(o.created_at, CURRENT_TIMESTAMP)
FROM `order_items` oi
JOIN `orders` o ON o.id = oi.order_id
WHERE oi.unit_price > 0
  AND oi.quantity > 0
  AND COALESCE(oi.is_free_sample, 0) = 0
  AND NOT EXISTS (
    SELECT 1 FROM `product_price_logs` ppl
    WHERE ppl.source_type = 'order_item'
      AND ppl.source_id = oi.id
      AND ppl.price_type = 'sell'
  );
