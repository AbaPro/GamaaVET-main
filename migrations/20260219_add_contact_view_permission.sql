-- Add permission for viewing contact details (name and phone)

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('contacts.view', 'View Contact Information', 'general', 'Permission to see contact person names and phone numbers in orders and purchase orders.');
