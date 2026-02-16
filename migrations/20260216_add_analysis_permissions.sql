-- Add permissions for the analysis / reports module

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('analysis.view_reports', 'View Analysis Reports', 'analysis', 'Permission to view the analysis/reports hub'),
('analysis.export_reports', 'Export Analysis Reports', 'analysis', 'Permission to export analysis reports (CSV/PDF)'),
('analysis.manage_dashboards', 'Manage Analysis Dashboards', 'analysis', 'Permission to create, edit, and remove analysis dashboards and saved reports'),
('analysis.view_sales_summary', 'View Sales Summary', 'analysis', 'Permission to view sales summary reports'),
('analysis.view_inventory_levels', 'View Inventory Levels', 'analysis', 'Permission to view inventory level reports'),
('analysis.view_purchase_summary', 'View Purchase Summary', 'analysis', 'Permission to view purchase summary reports'),
('analysis.view_finance_reports', 'View Finance Reports', 'analysis', 'Permission to view finance reports'),
('analysis.view_accounting_reports', 'View Accounting Reports', 'analysis', 'Permission to view accounting reports'),
('analysis.view_operations_reports', 'View Operations Reports', 'analysis', 'Permission to view operations reports');
