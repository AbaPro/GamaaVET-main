-- Add sales_order_id to manufacturing_orders to create a proper link with sales orders
ALTER TABLE manufacturing_orders
    ADD COLUMN sales_order_id INT DEFAULT NULL,
    ADD INDEX idx_mfg_orders_sales_order_id (sales_order_id);
