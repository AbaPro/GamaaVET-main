-- Add permissions for manufacturing order steps

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('manufacturing.view_step_sourcing', 'View Sourcing Step', 'manufacturing', 'Permission to view the sourcing step in manufacturing orders'),
('manufacturing.view_step_receipt', 'View Receipt Step', 'manufacturing', 'Permission to view the receipt step in manufacturing orders'),
('manufacturing.view_step_preparation', 'View Preparation Step', 'manufacturing', 'Permission to view the preparation step in manufacturing orders'),
('manufacturing.view_step_quality', 'View Quality Step', 'manufacturing', 'Permission to view the quality step in manufacturing orders'),
('manufacturing.view_step_packaging', 'View Packaging Step', 'manufacturing', 'Permission to view the packaging step in manufacturing orders'),
('manufacturing.view_step_dispatch', 'View Dispatch Step', 'manufacturing', 'Permission to view the dispatch step in manufacturing orders'),
('manufacturing.view_step_delivering', 'View Delivering Step', 'manufacturing', 'Permission to view the delivering step in manufacturing orders');
