-- Add and backfill sales dashboard permissions.

INSERT IGNORE INTO permissions (`module`, `name`, `key`, `description`) VALUES
  ('sales', 'Dashboard - View', 'sales.dashboard.view', 'Permission to access the sales dashboard shell'),
  ('sales', 'Dashboard - Orders pending', 'sales.dashboard.orders_pending', 'Permission to view pending orders on the sales dashboard'),
  ('sales', 'Dashboard - Overall orders', 'sales.dashboard.overall_orders', 'Permission to view overall order totals on the sales dashboard'),
  ('sales', 'Dashboard - This month orders', 'sales.dashboard.this_month', 'Permission to view current month sales and order figures on the sales dashboard'),
  ('sales', 'Dashboard - Recent orders', 'sales.dashboard.recent_orders', 'Permission to view recent orders on the sales dashboard');

-- Preserve current access for roles that could already view all sales orders.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, p_dashboard.id
FROM role_permissions rp
JOIN permissions p_orders ON p_orders.id = rp.permission_id
JOIN permissions p_dashboard ON p_dashboard.`key` IN (
  'sales.dashboard.view',
  'sales.dashboard.orders_pending',
  'sales.dashboard.overall_orders',
  'sales.dashboard.this_month',
  'sales.dashboard.recent_orders'
)
WHERE p_orders.`key` = 'sales.orders.view_all';

-- Admin should always receive newly introduced permissions.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` IN (
  'sales.dashboard.view',
  'sales.dashboard.orders_pending',
  'sales.dashboard.overall_orders',
  'sales.dashboard.this_month',
  'sales.dashboard.recent_orders'
)
WHERE r.slug = 'admin';
