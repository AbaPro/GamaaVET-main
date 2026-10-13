ALTER TABLE customers ADD COLUMN IF NOT EXISTS finance_account_closed_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS finance_account_closed_by INT NULL;
ALTER TABLE vendors ADD COLUMN IF NOT EXISTS finance_account_closed_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS finance_account_closed_by INT NULL;

ALTER TABLE purchase_order_payments ADD COLUMN IF NOT EXISTS expense_payment_id INT NULL,
 ADD UNIQUE INDEX IF NOT EXISTS purchase_payment_expense_idx (expense_payment_id);

START TRANSACTION;
INSERT INTO permissions (module, name, `key`, description) VALUES
('finance', 'Finance - Delete customer wallet transactions', 'finance.customer_transactions.delete', 'Request deletion of customer wallet entries and reverse balances; linked payments also require payment deletion permission'),
('finance', 'Finance - Delete vendor wallet transactions', 'finance.vendor_transactions.delete', 'Request deletion of vendor wallet entries and reverse balances; linked payments also require payment deletion permission'),
('finance', 'Finance - Delete expense payments', 'finance.expense_payments.delete', 'Request deletion of individual expense payments and reverse account, wallet and expense balances'),
('finance', 'Finance - Delete transfers', 'finance.transfers.delete', 'Request deletion of transfers; approved transfers reverse both account balances'),
('finance', 'Finance - Delete balance adjustments', 'finance.balance_adjustments.delete', 'Request deletion of balance settlements and reverse their balance impact; newer settlements must be removed first'),
('finance', 'Finance - Close customer accounts', 'finance.customer_accounts.delete', 'Request closure of settled customer finance accounts while retaining customer profiles and history; reopen closed accounts'),
('finance', 'Finance - Close vendor accounts', 'finance.vendor_accounts.delete', 'Request closure of settled vendor finance accounts while retaining vendor profiles and history; reopen closed accounts')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.`key` IN (
 'finance.customer_transactions.delete', 'finance.vendor_transactions.delete', 'finance.expense_payments.delete',
 'finance.transfers.delete', 'finance.balance_adjustments.delete', 'finance.customer_accounts.delete', 'finance.vendor_accounts.delete'
) AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, target.id FROM role_permissions rp
JOIN permissions existing ON existing.id = rp.permission_id
JOIN permissions target ON target.`key` = 'finance.deletions.request'
WHERE existing.`key` IN ('finance.payments.delete', 'finance.purchase_payments.delete', 'sales.orders.delete', 'purchases.orders.delete')
AND NOT EXISTS (SELECT 1 FROM role_permissions assigned WHERE assigned.role_id = rp.role_id AND assigned.permission_id = target.id);
COMMIT;
