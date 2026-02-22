-- Add permission for creating manufacturing orders

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('manufacturing.orders.create', 'Create Manufacturing Order', 'manufacturing', 'Permission to create new manufacturing orders, including starting them from sales orders or manually.');
