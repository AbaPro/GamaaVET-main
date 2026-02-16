-- Add general view permission for manufacturing module

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('manufacturing.view', 'View Manufacturing Module', 'manufacturing', 'Permission to access the manufacturing module and view orders');