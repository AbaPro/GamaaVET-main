<?php
// Run after deletion_workflows.php, using the same disposable test database.
if (!getenv('GAMMAVET_DELETION_TESTS')) throw new RuntimeException('Disposable database required.');
session_start();
require_once __DIR__ . '/../includes/functions.php';
if (DB_NAME !== 'gammavet_deletion_tests') throw new RuntimeException('Refusing to modify application data.');
require_once __DIR__ . '/../modules/finance/record_deletion.php';
function expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function value($sql) { global $pdo; return $pdo->query($sql)->fetchColumn(); }
$cases = [
 'customer-deposit', 'customer-refund', 'customer-withdrawal', 'customer-payment', 'customer-linked-payment',
 'vendor-deposit', 'vendor-refund', 'vendor-linked-payment', 'vendor-receipt-blocked',
 'expense-cash', 'expense-bank', 'expense-wallet', 'expense-mirror', 'expense-mirror-legacy', 'purchase-mirror', 'expense-whole',
 'purchase-cash', 'purchase-wallet', 'order-cash', 'order-bank', 'order-wallet', 'order-transfer-mirror',
 'transfer-approved', 'transfer-pending', 'transfer-rejected', 'transfer-reversed', 'transfer-insufficient', 'transfer-external',
 'customer-settlement', 'bank-settlement', 'personal-settlement', 'vendor-settlement', 'newer-settlement-blocked', 'older-settlement-blocked',
 'customer-close', 'customer-close-debt', 'customer-close-orders', 'vendor-close', 'vendor-close-debt', 'vendor-close-orders',
 'customer-reopen', 'reopen-denied', 'invoice-sales', 'invoice-purchase', 'invoice-paid-blocked', 'invoice-receipt-blocked', 'invoice-inventory-blocked', 'invoice-stock', 'invoice-stock-missing', 'invoice-stock-otherbrand', 'invoice-stock-duplicate',
 'request-only', 'no-request-permission', 'no-action-permission', 'no-source-permission', 'no-approval-permission', 'self-approval', 'wrong-brand', 'wrong-request-account', 'csrf', 'already-approved',
];
$mode = $argv[1] ?? 'suite';
if ($mode === 'suite') {
    $pdo->exec('ALTER TABLE customers ADD name VARCHAR(100), ADD wallet_balance DECIMAL(15,2) DEFAULT 0');
    $pdo->exec('CREATE TABLE vendors (id INT PRIMARY KEY, name VARCHAR(100), wallet_balance DECIMAL(15,2) DEFAULT 0) ENGINE=InnoDB');
    $schema = [
        'orders' => 'id INT PRIMARY KEY, customer_id INT, total_amount DECIMAL(15,2), paid_amount DECIMAL(15,2), status VARCHAR(30)',
        'purchase_orders' => 'id INT PRIMARY KEY, vendor_id INT, total_amount DECIMAL(15,2), paid_amount DECIMAL(15,2), status VARCHAR(30)',
        'order_items' => 'id INT PRIMARY KEY, order_id INT, product_id INT, quantity INT',
        'products' => 'id INT PRIMARY KEY, type VARCHAR(30)',
        'inventories' => 'id INT PRIMARY KEY, name VARCHAR(100), direct_sale VARCHAR(30) NULL',
        'inventory_products' => 'id INT PRIMARY KEY, inventory_id INT, product_id INT, quantity DECIMAL(12,2)',
        'inventory_stock_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY, inventory_id INT, product_id INT, change_quantity DECIMAL(12,2), quantity_before DECIMAL(12,2), quantity_after DECIMAL(12,2), source_type VARCHAR(50), source_id INT, notes TEXT, created_by INT',
        'purchase_order_items' => 'id INT PRIMARY KEY, purchase_order_id INT, received_quantity INT',
        'purchase_order_receipts' => 'id INT PRIMARY KEY, purchase_order_id INT',
        'order_returns' => 'id INT PRIMARY KEY, order_id INT',
        'expenses' => 'id INT PRIMARY KEY, name VARCHAR(100), amount DECIMAL(15,2), paid_amount DECIMAL(15,2), status VARCHAR(30), account_id INT, vendor_id INT NULL, po_id INT NULL',
        'order_payments' => 'id INT PRIMARY KEY, order_id INT, amount DECIMAL(15,2), payment_method VARCHAR(30), safe_id INT NULL, bank_account_id INT NULL, reference VARCHAR(100), transaction_date DATE, created_at DATETIME, created_by INT',
        'expense_payments' => 'id INT PRIMARY KEY, expense_id INT, amount DECIMAL(15,2), payment_method VARCHAR(30), safe_id INT NULL, bank_account_id INT NULL, reference VARCHAR(100), transaction_date DATE, created_at DATETIME, created_by INT',
        'customer_wallet_transactions' => 'id INT PRIMARY KEY, customer_id INT, amount DECIMAL(15,2), type VARCHAR(30), reference_type VARCHAR(50) NULL, reference_id INT NULL, payment_method VARCHAR(30) NULL, safe_id INT NULL, bank_account_id INT NULL, transaction_date DATE, created_at DATETIME',
        'vendor_wallet_transactions' => 'id INT PRIMARY KEY, vendor_id INT, amount DECIMAL(15,2), type VARCHAR(30), reference_type VARCHAR(50) NULL, reference_id INT NULL, transaction_date DATE, created_at DATETIME',
        'customer_wallet_balance_adjustments' => 'id INT PRIMARY KEY, customer_id INT, previous_balance DECIMAL(15,2), new_balance DECIMAL(15,2), created_at DATETIME',
        'finance_account_balance_adjustments' => 'id INT PRIMARY KEY, account_type VARCHAR(30), account_id INT, previous_balance DECIMAL(15,2), new_balance DECIMAL(15,2), created_at DATETIME',
        'finance_transfer_history' => 'id INT AUTO_INCREMENT PRIMARY KEY, finance_transfer_id INT, action VARCHAR(30), note TEXT, created_by INT',
    ];
    foreach ($schema as $table => $columns) $pdo->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    foreach (['purchase_order_payments' => 'purchase_order_id INT, amount DECIMAL(15,2), payment_method VARCHAR(30), safe_id INT NULL, bank_account_id INT NULL, reference VARCHAR(100), notes TEXT, transaction_date DATE, created_at DATETIME, created_by INT', 'finance_transfers' => 'amount DECIMAL(15,2), transaction_date DATE, created_at DATETIME, status VARCHAR(30), approved_at DATETIME NULL, notes TEXT, image_path TEXT NULL, reversed_by INT NULL, reversed_at DATETIME NULL, reversal_reason TEXT NULL'] as $table => $columns) {
        $pdo->exec("ALTER TABLE `$table` ADD " . str_replace(', ', ', ADD ', $columns));
    }
    foreach (['expense_payment_attachments' => 'expense_payment_id', 'purchase_order_payment_attachments' => 'purchase_order_payment_id', 'vendor_wallet_transaction_attachments' => 'vendor_wallet_transaction_id', 'finance_transfer_images' => 'finance_transfer_id', 'purchase_order_images' => 'purchase_order_id'] as $table => $column) $pdo->exec("CREATE TABLE `$table` (id INT AUTO_INCREMENT PRIMARY KEY, `$column` INT, file_path TEXT) ENGINE=InnoDB");
    $migration = file_get_contents(__DIR__ . '/../migrations/20261013_finance_record_deletion.sql');
    $pdo->exec($migration); $pdo->exec($migration);
    expect((int)value("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'admin' AND p.`key` IN ('finance.customer_transactions.delete','finance.vendor_transactions.delete','finance.expense_payments.delete','finance.transfers.delete','finance.balance_adjustments.delete','finance.customer_accounts.delete','finance.vendor_accounts.delete')") === 7, 'Admin action permissions');
    foreach ($cases as $case) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case), $status);
        expect($status === 0, 'Failed: ' . $case);
    }
    echo 'PASS: finance migration is idempotent; ' . count($cases) . " financial deletion scenarios passed.\n";
    exit;
}
$tables = ['finance_deletion_requests', 'customers', 'vendors', 'orders', 'purchase_orders', 'order_items', 'purchase_order_items', 'purchase_order_receipts', 'order_returns', 'order_payments', 'expense_payments', 'expenses', 'customer_wallet_transactions', 'vendor_wallet_transactions', 'customer_wallet_balance_adjustments', 'finance_account_balance_adjustments', 'safes', 'bank_accounts', 'personal_accounts', 'purchase_order_payments', 'finance_transfers', 'finance_transfer_history', 'expense_payment_attachments', 'purchase_order_payment_attachments', 'vendor_wallet_transaction_attachments', 'finance_transfer_images', 'purchase_order_images', 'manufacturing_orders', 'products', 'inventories', 'inventory_products', 'inventory_stock_logs'];
foreach ($tables as $table) $pdo->exec("DELETE FROM `$table`");
$pdo->exec('ALTER TABLE finance_deletion_requests AUTO_INCREMENT = 1');
$pdo->exec("INSERT INTO customers (id, name, wallet_balance, direct_sale) VALUES (1, 'Customer', 100, NULL), (2, 'Other brand', 100, 'primer')");
$pdo->exec("INSERT INTO vendors (id, name, wallet_balance) VALUES (1, 'Vendor', 100)");
$pdo->exec("INSERT INTO orders VALUES (1, 1, 100, 25, 'pending')");
$pdo->exec("INSERT INTO purchase_orders VALUES (1, 1, 100, 25, 'pending')");
$pdo->exec("INSERT INTO expenses VALUES (1, 'Expense', 100, 25, 'partially-paid', 1, 1, NULL)");
$pdo->exec("INSERT INTO safes VALUES (1, 'Safe', 100, 'EGP', 1)");
$pdo->exec("INSERT INTO bank_accounts VALUES (1, 'Bank', 100, 'EGP', 1)");
$pdo->exec("INSERT INTO personal_accounts VALUES (1, 'Personal', 100, 1)");
$pdo->exec("INSERT INTO customer_wallet_transactions VALUES (1, 1, 25, 'deposit', 'manual', NULL, 'cash', NULL, NULL, '2026-01-01', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO vendor_wallet_transactions VALUES (1, 1, 25, 'deposit', NULL, NULL, '2026-01-01', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO order_payments VALUES (1, 1, 25, 'cash', 1, NULL, 'REF', '2026-01-01', '2026-01-01 00:00:00', 1)");
$pdo->exec("INSERT INTO expense_payments VALUES (1, 1, 25, 'cash', 1, NULL, 'REF', '2026-01-01', '2026-01-01 00:00:00', 1)");
$pdo->exec("INSERT INTO purchase_order_payments (id, purchase_order_id, amount, payment_method, reference, transaction_date, created_at, created_by) VALUES (1, 1, 25, 'cash', 'REF', '2026-01-01', '2026-01-01 00:00:00', 1)");
$pdo->exec("INSERT INTO finance_transfers (id, from_type, from_id, to_type, to_id, amount, transaction_date, created_at, status, approved_at) VALUES (1, 'safe', 1, 'bank', 1, 25, '2026-01-01', '2026-01-01 00:00:00', 'approved', '2026-01-01 00:00:00')");
$_SESSION = ['user_id' => 2, 'role_slug' => 'admin', 'permissions' => [], 'login_region' => 'factory'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$type = 'customer_transaction'; $checks = []; $blocked = false;
$checks['SELECT wallet_balance FROM customers WHERE id = 1'] = 75;
if (str_starts_with($mode, 'vendor-')) { $type = 'vendor_transaction'; $checks = ['SELECT wallet_balance FROM vendors WHERE id = 1' => 75]; }
if ($mode === 'customer-refund') $pdo->exec("UPDATE customer_wallet_transactions SET type = 'refund'");
if ($mode === 'customer-withdrawal' || $mode === 'customer-payment') {
    $pdo->exec("UPDATE customer_wallet_transactions SET type = '" . ($mode === 'customer-payment' ? 'payment' : 'withdrawal') . "'");
    $checks = ['SELECT wallet_balance FROM customers WHERE id = 1' => 125];
    if ($mode === 'customer-payment') { $pdo->exec('UPDATE customer_wallet_transactions SET safe_id = 1'); $checks['SELECT balance FROM safes WHERE id = 1'] = 75; }
}
if ($mode === 'vendor-refund') { $pdo->exec("UPDATE vendor_wallet_transactions SET type = 'refund'"); $checks = ['SELECT wallet_balance FROM vendors WHERE id = 1' => 125]; }
if ($mode === 'vendor-receipt-blocked') { $pdo->exec("UPDATE vendor_wallet_transactions SET type = 'withdrawal', reference_type = 'purchase_order', reference_id = 1"); $blocked = true; }
if (str_starts_with($mode, 'order-') || $mode === 'customer-linked-payment' || $mode === 'no-source-permission') {
    $type = $mode === 'customer-linked-payment' || $mode === 'no-source-permission' ? 'customer_transaction' : 'order_payment';
    $method = $mode === 'order-bank' ? 'transfer' : ($mode === 'order-wallet' || $type === 'customer_transaction' ? 'wallet' : 'cash');
    $pdo->exec("UPDATE order_payments SET payment_method = '$method', safe_id = " . ($method === 'cash' ? '1' : 'NULL') . ', bank_account_id = ' . ($method === 'transfer' ? '1' : 'NULL'));
    $checks = ['SELECT paid_amount FROM orders WHERE id = 1' => 0];
    if ($method === 'wallet') { $pdo->exec("UPDATE customer_wallet_transactions SET type = 'payment', reference_type = 'order', reference_id = 1, payment_method = 'wallet'"); $checks['SELECT wallet_balance FROM customers WHERE id = 1'] = 125; $checks['SELECT COUNT(*) FROM customer_wallet_transactions'] = 0; }
    else $checks['SELECT balance FROM ' . ($method === 'cash' ? 'safes' : 'bank_accounts') . ' WHERE id = 1'] = 75;
    if ($mode === 'order-transfer-mirror') { $pdo->exec("UPDATE finance_transfers SET from_type = 'personal', from_id = 0, to_type = 'safe', notes = 'Order Payment Reference: REF'"); $checks["SELECT COUNT(*) FROM finance_transfers WHERE status = 'reversed'"] = 1; }
}
if (str_starts_with($mode, 'purchase-') || $mode === 'vendor-linked-payment') {
    $type = $mode === 'vendor-linked-payment' ? 'vendor_transaction' : 'purchase_payment';
    $checks = ['SELECT paid_amount FROM purchase_orders WHERE id = 1' => 0];
    if ($mode === 'purchase-wallet' || $mode === 'vendor-linked-payment') { $pdo->exec("UPDATE purchase_order_payments SET payment_method = 'wallet'"); $pdo->exec("UPDATE vendor_wallet_transactions SET type = 'payment', reference_type = 'purchase_order', reference_id = 1"); $checks['SELECT wallet_balance FROM vendors WHERE id = 1'] = 125; $checks['SELECT COUNT(*) FROM vendor_wallet_transactions'] = 0; }
    else { $pdo->exec("UPDATE purchase_order_payments SET payment_source_type = 'safe', payment_source_id = 1"); $checks['SELECT balance FROM safes WHERE id = 1'] = 125; }
}
if (str_starts_with($mode, 'expense-') || $mode === 'purchase-mirror') {
    $type = $mode === 'expense-whole' ? 'expense' : ($mode === 'purchase-mirror' ? 'purchase_payment' : 'expense_payment');
    $checks = ['SELECT paid_amount FROM expenses WHERE id = 1' => 0, "SELECT COUNT(*) FROM expenses WHERE status = 'pending'" => 1, 'SELECT balance FROM safes WHERE id = 1' => 125];
    if ($mode === 'expense-bank') { $pdo->exec("UPDATE expense_payments SET payment_method = 'transfer', safe_id = NULL, bank_account_id = 1"); unset($checks['SELECT balance FROM safes WHERE id = 1']); $checks['SELECT balance FROM bank_accounts WHERE id = 1'] = 125; }
    if ($mode === 'expense-wallet') { $pdo->exec("UPDATE expense_payments SET payment_method = 'wallet', safe_id = NULL"); $pdo->exec("UPDATE vendor_wallet_transactions SET type = 'payment', reference_type = 'expense_payment', reference_id = 1"); unset($checks['SELECT balance FROM safes WHERE id = 1']); $checks['SELECT wallet_balance FROM vendors WHERE id = 1'] = 125; $checks['SELECT COUNT(*) FROM vendor_wallet_transactions'] = 0; }
    if (in_array($mode, ['expense-mirror','expense-mirror-legacy','purchase-mirror'], true)) { $pdo->exec('UPDATE expenses SET po_id = 1'); $pdo->exec("UPDATE purchase_order_payments SET expense_payment_id = " . ($mode === 'expense-mirror-legacy' ? 'NULL' : '1') . ", notes = 'Payment via expense: Expense', safe_id = 1"); $checks['SELECT paid_amount FROM purchase_orders WHERE id = 1'] = 0; $checks['SELECT COUNT(*) FROM purchase_order_payments'] = 0; $checks['SELECT COUNT(*) FROM expense_payments'] = 0; }
    if ($mode === 'expense-whole') { $pdo->exec("INSERT INTO expense_payments VALUES (2, 1, 25, 'transfer', NULL, 1, 'REF2', '2026-01-01', '2026-01-01 00:00:00', 1)"); $pdo->exec('UPDATE expenses SET paid_amount = 50'); $checks = ['SELECT COUNT(*) FROM expenses' => 0, 'SELECT COUNT(*) FROM expense_payments' => 0, 'SELECT balance FROM safes WHERE id = 1' => 125, 'SELECT balance FROM bank_accounts WHERE id = 1' => 125]; }
}
if (str_starts_with($mode, 'transfer-')) {
    $type = 'transfer'; $checks = ['SELECT balance FROM safes WHERE id = 1' => 100, 'SELECT balance FROM bank_accounts WHERE id = 1' => 100];
    if ($mode === 'transfer-approved') $checks = ['SELECT balance FROM safes WHERE id = 1' => 125, 'SELECT balance FROM bank_accounts WHERE id = 1' => 75];
    elseif ($mode === 'transfer-insufficient') { $pdo->exec('UPDATE bank_accounts SET balance = 0'); $blocked = true; }
    elseif ($mode === 'transfer-external') { $pdo->exec("UPDATE finance_transfers SET from_type = 'personal', from_id = 0"); $blocked = true; }
    else $pdo->exec("UPDATE finance_transfers SET status = '" . substr($mode, strlen('transfer-')) . "'");
}
if (str_ends_with($mode, '-settlement') || $mode === 'older-settlement-blocked') {
    $customer = $mode === 'customer-settlement';
    $type = $customer ? 'customer_adjustment' : 'account_adjustment';
    $accountType = $customer ? 'customer' : explode('-', $mode)[0];
    if ($mode === 'older-settlement-blocked') $accountType = 'bank';
    $pdo->exec($customer ? "INSERT INTO customer_wallet_balance_adjustments VALUES (1, 1, 80, 100, '2026-01-02')" : "INSERT INTO finance_account_balance_adjustments VALUES (1, '$accountType', 1, 80, 100, '2026-01-02')");
    $table = ['customer' => 'customers', 'vendor' => 'vendors', 'bank' => 'bank_accounts', 'personal' => 'personal_accounts'][$accountType];
    $checks = ['SELECT ' . ($customer || $accountType === 'vendor' ? 'wallet_balance' : 'balance') . " FROM `$table` WHERE id = 1" => 80];
    if ($mode === 'older-settlement-blocked') { $pdo->exec("INSERT INTO finance_account_balance_adjustments VALUES (2, 'bank', 1, 100, 120, '2026-01-03')"); $blocked = true; }
}
if ($mode === 'newer-settlement-blocked') { $pdo->exec("INSERT INTO customer_wallet_balance_adjustments VALUES (1, 1, 80, 100, '2026-01-02')"); $blocked = true; }
if (str_contains($mode, '-close') || str_contains($mode, 'reopen')) {
    $customer = !str_starts_with($mode, 'vendor-'); $type = $customer ? 'customer_account' : 'vendor_account'; $table = $customer ? 'customers' : 'vendors';
    $pdo->exec("UPDATE `$table` SET wallet_balance = 0 WHERE id = 1");
    $pdo->exec('UPDATE ' . ($customer ? 'orders' : 'purchase_orders') . ' SET paid_amount = total_amount');
    $checks = ["SELECT COUNT(*) FROM `$table` WHERE id = 1" => 1, "SELECT COUNT(*) FROM `$table` WHERE finance_account_closed_at IS NOT NULL" => 1, 'SELECT COUNT(*) FROM ' . ($customer ? 'customer_wallet_transactions' : 'vendor_wallet_transactions') => 1];
    if (str_ends_with($mode, '-debt')) { $pdo->exec("UPDATE `$table` SET wallet_balance = -10"); $blocked = true; }
    if (str_ends_with($mode, '-orders')) { $pdo->exec('UPDATE ' . ($customer ? 'orders' : 'purchase_orders') . ' SET paid_amount = 0'); $blocked = true; }
    if (str_contains($mode, 'reopen')) { $pdo->exec("UPDATE customers SET finance_account_closed_at = NOW() WHERE id = 1"); $checks['SELECT COUNT(*) FROM `customers` WHERE finance_account_closed_at IS NOT NULL'] = 0; }
}
if (str_starts_with($mode, 'invoice-')) {
    $sales = $mode === 'invoice-sales' || $mode === 'invoice-paid-blocked' || str_starts_with($mode, 'invoice-stock'); $type = $sales ? 'sales_invoice' : 'purchase_invoice';
    $pdo->exec('DELETE FROM ' . ($sales ? 'order_payments' : 'purchase_order_payments'));
    $pdo->exec('UPDATE ' . ($sales ? 'orders' : 'purchase_orders') . ' SET paid_amount = 0');
    $checks = ['SELECT COUNT(*) FROM ' . ($sales ? 'orders' : 'purchase_orders') => 0];
    if ($mode === 'invoice-paid-blocked') { $pdo->exec('UPDATE orders SET paid_amount = 25'); $blocked = true; }
    if ($mode === 'invoice-receipt-blocked') { $pdo->exec('INSERT INTO purchase_order_receipts VALUES (1, 1)'); $blocked = true; }
    if (str_starts_with($mode, 'invoice-stock')) {
        $pdo->exec("INSERT INTO products VALUES (1, 'final')");
        $pdo->exec("INSERT INTO inventories VALUES (1, 'Main', NULL)");
        $pdo->exec('INSERT INTO inventory_products VALUES (1, 1, 1, 8)');
        $pdo->exec('INSERT INTO order_items VALUES (1, 1, 1, 2)');
        if ($mode !== 'invoice-stock-missing') $pdo->exec("INSERT INTO inventory_stock_logs (id, inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id) VALUES (1, 1, 1, -2, 10, 8, 'sales_order', 1)");
        if ($mode === 'invoice-stock-duplicate') $pdo->exec("INSERT INTO inventory_stock_logs (inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id) VALUES (1, 1, -2, 10, 8, 'sales_order', 1)");
        if ($mode === 'invoice-stock-otherbrand') $pdo->exec("UPDATE inventories SET direct_sale = 'primer'");
        if ($mode !== 'invoice-stock') $blocked = true;
        $checks['SELECT quantity FROM inventory_products WHERE id = 1'] = 10;
        $checks["SELECT COUNT(*) FROM inventory_stock_logs WHERE source_type = 'sales_invoice_deletion'"] = 1;
    }
    if ($mode === 'invoice-inventory-blocked') { $pdo->exec('INSERT INTO purchase_order_items VALUES (1, 1, 1)'); $blocked = true; }
}
$_POST = ['deletion_token' => financeDeletionToken(), 'deletion_request_id' => 1];
$pdo->prepare('INSERT INTO finance_deletion_requests (id, account_id, entity_type, entity_id, requested_by) VALUES (1, 1, ?, 1, 1)')->execute([$type]);
$status = 'approved'; $alert = 'success';
if ($mode === 'request-only' || $mode === 'no-request-permission' || $mode === 'no-action-permission') {
    $pdo->exec('DELETE FROM finance_deletion_requests'); $pdo->exec('ALTER TABLE finance_deletion_requests AUTO_INCREMENT = 1'); unset($_POST['deletion_request_id']); $_SESSION['user_id'] = 1; $_SESSION['role_slug'] = 'user';
    $_SESSION['permissions'] = ['finance.customer_transactions.delete', 'finance.deletions.request'];
    if ($mode === 'no-request-permission') $_SESSION['permissions'] = ['finance.customer_transactions.delete'];
    if ($mode === 'no-action-permission') $_SESSION['permissions'] = ['finance.deletions.request'];
    $status = $mode === 'request-only' ? 'pending' : null;
    $blocked = $mode !== 'request-only'; $checks = [];
}
if ($mode === 'no-source-permission') { $_SESSION['role_slug'] = 'user'; $_SESSION['permissions'] = ['finance.customer_transactions.delete', 'finance.deletions.approve']; $blocked = true; }
if ($mode === 'no-approval-permission') { $_SESSION['role_slug'] = 'user'; $_SESSION['permissions'] = ['finance.customer_transactions.delete']; $blocked = true; }
if ($mode === 'self-approval') { $_SESSION['user_id'] = 1; $blocked = true; }
if ($mode === 'wrong-brand') { $pdo->exec('UPDATE customer_wallet_transactions SET customer_id = 2'); $blocked = true; }
if ($mode === 'wrong-request-account') { $pdo->exec('UPDATE finance_deletion_requests SET account_id = 2'); $blocked = true; }
if ($mode === 'csrf') { $_POST['deletion_token'] = 'invalid'; $blocked = true; }
if ($mode === 'already-approved') { $pdo->exec("UPDATE finance_deletion_requests SET status = 'approved'"); $blocked = true; }
if ($blocked) { $status = in_array($mode, ['no-request-permission','no-action-permission'], true) ? null : ($mode === 'already-approved' ? 'approved' : 'pending'); $alert = 'danger'; $checks = []; }
$isReopen = str_contains($mode, 'reopen');
if (!$blocked && $mode !== 'request-only' && !$isReopen && !str_ends_with($type, '_account')) $checks['SELECT COUNT(*) FROM `' . financeRecordDeletionConfig($type) . '` WHERE id = 1'] = 0;
$before = [];
foreach ($tables as $table) if ($table !== 'finance_deletion_requests') $before[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$preserve = $blocked || $mode === 'request-only';
if ($mode === 'reopen-denied') { $_SESSION['user_id'] = 1; $_SESSION['role_slug'] = 'user'; $preserve = true; $checks = []; $alert = 'danger'; }
if (str_contains($mode, 'reopen')) $status = 'pending';
register_shutdown_function(function () use ($mode, $status, $alert, $checks, $preserve, $before) {
    global $pdo;
    try {
        expect(($_SESSION['alert']['type'] ?? null) === $alert, "$mode alert: " . json_encode($_SESSION['alert'] ?? null));
        if ($status) expect(value('SELECT status FROM finance_deletion_requests WHERE id = 1') === $status, "$mode status");
        else expect((int)value('SELECT COUNT(*) FROM finance_deletion_requests') === 0, "$mode no requests");
        foreach ($checks as $query => $expected) expect((float)value($query) === (float)$expected, "$mode: $query expected $expected, got " . value($query));
        if ($preserve) foreach ($before as $table => $rows) expect($pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $rows, "$mode must preserve $table");
        if ($status === 'approved' && !$preserve) expect((int)value('SELECT reviewed_by FROM finance_deletion_requests WHERE id = 1') === 2, "$mode reviewer");
        echo "PASS $mode\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
});
if (str_contains($mode, 'reopen')) {
    $_POST['entity_type'] = 'customer_account'; $_POST['record_id'] = 1;
    $_SERVER['PHP_SELF'] = 'reopen_account.php'; $_SERVER['REQUEST_URI'] = '/modules/finance/reopen_account.php';
    chdir(__DIR__ . '/../modules/finance'); require 'reopen_account.php';
} else handleFinanceRecordDeletion($type, 1);
