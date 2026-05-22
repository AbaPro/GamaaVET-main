START TRANSACTION;

-- New read-only visibility permissions.
INSERT INTO `permissions` (`module`, `name`, `key`, `description`) VALUES
  ('customers', 'Customers - Wallet view', 'customers.wallet.view', 'Used on customer list/detail pages and customer wallet pages to show wallet balances and transaction history without allowing new wallet transactions.'),
  ('contacts', 'Phone numbers - View', 'contacts.phone.view', 'Used anywhere customer, vendor, order, purchase order, or contact phone numbers are rendered.')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `module`=VALUES(`module`), `description`=VALUES(`description`);

-- Preserve existing access: roles that could manage customer wallets can also view them.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT DISTINCT rp.role_id, p_view.id
FROM role_permissions rp
JOIN permissions p_existing ON p_existing.id = rp.permission_id
JOIN permissions p_view ON p_view.`key` = 'customers.wallet.view'
WHERE p_existing.`key` IN ('customers.wallet', 'finance.customer_wallet.view')
ON DUPLICATE KEY UPDATE role_permissions.role_id=role_permissions.role_id;

-- Preserve existing access: roles that could view contact details can also view phone numbers.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT DISTINCT rp.role_id, p_phone.id
FROM role_permissions rp
JOIN permissions p_existing ON p_existing.id = rp.permission_id
JOIN permissions p_phone ON p_phone.`key` = 'contacts.phone.view'
WHERE p_existing.`key` = 'contacts.view'
ON DUPLICATE KEY UPDATE role_permissions.role_id=role_permissions.role_id;

-- Notifications are role/user-targeted in code; give the notification page to roles that already work those flows.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT DISTINCT rp.role_id, p_notif.id
FROM role_permissions rp
JOIN permissions p_existing ON p_existing.id = rp.permission_id
JOIN permissions p_notif ON p_notif.`key` = 'notifications.view'
WHERE p_existing.`key` IN (
  'tickets.view',
  'tickets.create',
  'tickets.manage',
  'sales.orders.view',
  'purchases.view',
  'inventories.view',
  'products.view',
  'manufacturing.orders.view'
)
ON DUPLICATE KEY UPDATE role_permissions.role_id=role_permissions.role_id;

-- Fill permission notes for common keys that previously had empty descriptions.
UPDATE `permissions` SET `description` = CASE `key`
  WHEN 'sales.dashboard.view' THEN 'Used to open the sales dashboard shell and related sales dashboard navigation.'
  WHEN 'sales.dashboard.orders_pending' THEN 'Used on sales dashboards to show pending order counts.'
  WHEN 'sales.dashboard.overall_orders' THEN 'Used on sales dashboards to show total/new/in-production order cards.'
  WHEN 'sales.dashboard.this_month' THEN 'Used on sales dashboards to show current-day and current-month sales/order cards.'
  WHEN 'sales.dashboard.recent_orders' THEN 'Used on dashboard and sales dashboard recent-order tables.'
  WHEN 'sales.orders.view_all' THEN 'Used by the sales order list page.'
  WHEN 'sales.orders.create' THEN 'Used by sales order creation actions.'
  WHEN 'sales.orders.view' THEN 'Used by sales order detail pages and order notification deep links.'
  WHEN 'sales.orders.print_invoice' THEN 'Used by invoice generation and print actions.'
  WHEN 'sales.orders.returns.add' THEN 'Used by sales order return/refund actions.'
  WHEN 'sales.orders.payments.history' THEN 'Used on sales order details to show payment history.'
  WHEN 'sales.orders.update_status' THEN 'Used on sales order details to update order workflow status.'
  WHEN 'sales.orders.status.update' THEN 'Legacy status-update permission kept for older role setups.'
  WHEN 'sales.orders.discount.edit' THEN 'Used by order creation/edit flows for discount changes.'
  WHEN 'sales.orders.discount.view' THEN 'Used on sales order details and invoices to show discount fields.'
  WHEN 'sales.orders.shipping.edit' THEN 'Used on sales order details to edit shipping charges.'
  WHEN 'sales.orders.shipping.view' THEN 'Used on sales order details and invoices to show shipping charges.'
  WHEN 'customers.view' THEN 'Used by the customers list page.'
  WHEN 'customers.create' THEN 'Used by customer creation forms.'
  WHEN 'customers.edit' THEN 'Used by customer edit forms and edit buttons.'
  WHEN 'customers.delete' THEN 'Used by customer delete actions.'
  WHEN 'customers.details.view' THEN 'Used by customer detail pages.'
  WHEN 'customers.wallet' THEN 'Used by customer wallet transaction processing.'
  WHEN 'customers.whatsapp_portal' THEN 'Used by customer portal access and WhatsApp portal-link actions.'
  WHEN 'customers.contacts.manage' THEN 'Used by customer contact management pages.'
  WHEN 'customers.addresses.manage' THEN 'Used by customer address management pages.'
  WHEN 'customers.orders.create' THEN 'Used by customer-to-order creation shortcuts.'
  WHEN 'customers.orders.view' THEN 'Used by customer order history views.'
  WHEN 'inventories.view' THEN 'Used by inventory list/detail pages and low-stock views.'
  WHEN 'inventories.create' THEN 'Used by inventory creation forms.'
  WHEN 'inventories.edit' THEN 'Used by inventory edit forms.'
  WHEN 'inventories.delete' THEN 'Used by inventory delete actions.'
  WHEN 'inventories.transfer' THEN 'Used by inventory transfer pages and actions.'
  WHEN 'inventories.low_stock.view' THEN 'Used by low-stock inventory reporting.'
  WHEN 'inventories.print' THEN 'Used by inventory print views.'
  WHEN 'inventories.products.add' THEN 'Used by add-product-to-inventory actions.'
  WHEN 'products.view' THEN 'Used by products list/detail pages.'
  WHEN 'products.create' THEN 'Used by product creation forms.'
  WHEN 'products.bulk_upload' THEN 'Used by product upload/import pages.'
  WHEN 'products.edit' THEN 'Used by product edit forms.'
  WHEN 'products.edit_min_stock' THEN 'Used by minimum stock editing controls.'
  WHEN 'products.delete' THEN 'Used by product delete and bulk delete actions.'
  WHEN 'products.final.price.view' THEN 'Used to show final product selling prices.'
  WHEN 'products.final.cost.view' THEN 'Used to show final product costs.'
  WHEN 'products.material.price.view' THEN 'Used to show raw material prices.'
  WHEN 'products.material.cost.view' THEN 'Used to show raw material costs and PO item prices.'
  WHEN 'purchases.view_recent' THEN 'Used by purchase-order recent list widgets.'
  WHEN 'purchases.view_all' THEN 'Used by full purchase-order list pages.'
  WHEN 'purchases.create' THEN 'Used by purchase-order creation forms.'
  WHEN 'purchases.view' THEN 'Used by purchase-order detail pages.'
  WHEN 'purchases.receive' THEN 'Used by receive-items and undo-receipt actions.'
  WHEN 'purchases.update_status' THEN 'Used by purchase-order status update controls.'
  WHEN 'finance.customer_wallet.view' THEN 'Used by Finance > Customer Wallets and customer wallet viewing.'
  WHEN 'finance.customer_payment.process' THEN 'Used by customer payment processing and receivables pages.'
  WHEN 'finance.po_payment.process' THEN 'Used by purchase-order payment processing and PO payment history access.'
  WHEN 'finance.payments.delete' THEN 'Used by sales payment delete/reversal actions.'
  WHEN 'finance.purchase_payments.delete' THEN 'Used by purchase-order payment delete/reversal actions.'
  WHEN 'finance.vendor_wallet.view' THEN 'Used by Finance > Vendor Wallets and vendor wallet viewing.'
  WHEN 'notifications.view' THEN 'Used by the notification bell, notification list, and unread notification polling.'
  WHEN 'notifications.manage' THEN 'Used by notification management actions.'
  WHEN 'contacts.view' THEN 'Used to show contact names and non-phone contact details.'
  ELSE `description`
END
WHERE `description` IS NULL OR `description` = '';

COMMIT;
