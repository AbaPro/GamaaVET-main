-- Add permission for managing customer factories

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('customers.factories.manage', 'Manage Customer Factories', 'customers', 'Permission to create, edit, and delete customer factories');