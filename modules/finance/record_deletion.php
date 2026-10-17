<?php
require_once __DIR__ . '/deletion_approval.php';
require_once __DIR__ . '/transfer_helpers.php';
require_once __DIR__ . '/../purchases/payment_sources.php';

function financeRecordDeletionConfig($type) {
    return [
        'customer_transaction' => 'customer_wallet_transactions', 'vendor_transaction' => 'vendor_wallet_transactions',
        'order_payment' => 'order_payments', 'purchase_payment' => 'purchase_order_payments',
        'expense_payment' => 'expense_payments', 'expense' => 'expenses',
        'transfer' => 'finance_transfers', 'customer_adjustment' => 'customer_wallet_balance_adjustments',
        'account_adjustment' => 'finance_account_balance_adjustments',
        'customer_account' => 'customers', 'vendor_account' => 'vendors',
        'sales_invoice' => 'orders', 'purchase_invoice' => 'purchase_orders',
    ][$type] ?? null;
}

function financeRecordRow($table, $id, $lock = false) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('Record not found.');
    return $row;
}

function financeRequireFactory() {
    if (($_SESSION['login_region'] ?? 'factory') !== 'factory') throw new DomainException('This record is available only in Factory.');
}

function financeScopedBalanceRow($type, $id, $lock = false) {
    global $pdo;
    if ($type === 'customer') {
        $scope = getCustomerChannelScopeSql('c', 'f');
        $stmt = $pdo->prepare("SELECT c.* FROM customers c LEFT JOIN factories f ON f.id = c.factory_id WHERE c.id = ? AND $scope" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Customer account access denied.');
        return $row;
    }
    if ($type === 'vendor') {
        financeRequireFactory();
        return financeRecordRow('vendors', $id, $lock);
    }
    $config = financeTransferAccountConfig($type);
    if (!$config) throw new DomainException('Invalid financial account.');
    $scope = $type === 'safe' ? getSafeScopeSql() : ($type === 'bank' ? getBankScopeSql() : getAccountScopeSql());
    $stmt = $pdo->prepare("SELECT * FROM `{$config['table']}` WHERE id = ? AND $scope" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('Financial account not found or access denied.');
    return $row;
}

function financeRecordDeletionRow($type, $id, $lock = false) {
    global $pdo;
    $table = financeRecordDeletionConfig($type);
    if (!$table || $id < 1) throw new DomainException('Invalid finance record.');
    $row = financeRecordRow($table, $id, $lock);
    if (in_array($type, ['vendor_transaction', 'purchase_payment', 'vendor_account', 'purchase_invoice'], true)) financeRequireFactory();
    if ($type === 'sales_invoice') financeScopedBalanceRow('customer', (int)$row['customer_id'], $lock);
    if ($type === 'customer_account') financeScopedBalanceRow('customer', $id, $lock);
    if ($type === 'customer_transaction' || $type === 'customer_adjustment') financeScopedBalanceRow('customer', (int)$row['customer_id'], $lock);
    if ($type === 'vendor_transaction') financeScopedBalanceRow('vendor', (int)$row['vendor_id'], $lock);
    if ($type === 'account_adjustment') financeScopedBalanceRow($row['account_type'], (int)$row['account_id'], $lock);
    if ($type === 'order_payment') {
        $order = financeRecordRow('orders', (int)$row['order_id'], $lock);
        financeScopedBalanceRow('customer', (int)$order['customer_id'], $lock);
    }
    if ($type === 'expense' || $type === 'expense_payment') {
        $expenseId = $type === 'expense' ? $id : (int)$row['expense_id'];
        $scope = getAccountScopeSql();
        $stmt = $pdo->prepare("SELECT id FROM expenses WHERE id = ? AND $scope" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$expenseId]);
        if (!$stmt->fetchColumn()) throw new DomainException('Expense not found or access denied.');
    }
    if ($type === 'transfer') {
        $scope = financeTransferScopeSql('f');
        $stmt = $pdo->prepare("SELECT f.id FROM finance_transfers f WHERE f.id = ? AND $scope");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new DomainException('Transfer account access denied.');
    }
    foreach (['safe_id' => 'safe', 'bank_account_id' => 'bank'] as $column => $accountType) {
        if (!empty($row[$column])) financeScopedBalanceRow($accountType, (int)$row[$column], $lock);
    }
    if ($type === 'purchase_payment' && !empty($row['payment_source_type'])) financeScopedBalanceRow($row['payment_source_type'], (int)$row['payment_source_id'], $lock);
    return $row;
}

// Absolute balance settlements supersede previous activity. Remove newer
// settlements first instead of applying a reversal against an unrelated balance.
function financeCheckLaterSettlement($type, $id, $createdAt, $excludedId = 0) {
    global $pdo;
    $table = $type === 'customer' ? 'customer_wallet_balance_adjustments' : 'finance_account_balance_adjustments';
    if (!$createdAt || !tableExists($table)) return;
    $where = $type === 'customer' ? 'customer_id = ?' : 'account_type = ? AND account_id = ?';
    $args = $type === 'customer' ? [$id] : [$type, $id];
    // Within one table IDs establish order for timestamps with second precision.
    $where .= $excludedId ? ' AND (created_at > ? OR (created_at = ? AND id > ?))' : ' AND created_at >= ?';
    array_push($args, $createdAt);
    if ($excludedId) array_push($args, $createdAt, $excludedId);
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE $where LIMIT 1 FOR UPDATE");
    $stmt->execute($args);
    if ($stmt->fetchColumn()) throw new DomainException('Delete the newer balance settlements for this account first.');
}

function financeReverseBalance($type, $id, $delta, $createdAt = null, $excludedAdjustment = 0) {
    global $pdo;
    financeScopedBalanceRow($type, $id, true);
    financeCheckLaterSettlement($type, $id, $createdAt, $excludedAdjustment);
    $table = ['customer' => 'customers', 'vendor' => 'vendors', 'safe' => 'safes', 'bank' => 'bank_accounts', 'personal' => 'personal_accounts'][$type];
    $column = in_array($type, ['customer', 'vendor'], true) ? 'wallet_balance' : 'balance';
    $delta = round((float)$delta, 2);
    if (abs($delta) < 0.005) return;
    $guard = in_array($type, ['safe', 'bank', 'personal'], true) && $delta < 0 ? " AND `$column` >= ?" : '';
    $args = [$delta, $id];
    if ($guard) $args[] = -$delta;
    $stmt = $pdo->prepare("UPDATE `$table` SET `$column` = `$column` + ? WHERE id = ?$guard");
    $stmt->execute($args);
    if ($stmt->rowCount() !== 1) throw new DomainException('Insufficient account balance to reverse this transaction.');
}

function financeDeletionAttachmentFiles($table, $column, $id) {
    global $pdo;
    if (!tableExists($table)) return [];
    $stmt = $pdo->prepare("SELECT file_path FROM `$table` WHERE `$column` = ?");
    $stmt->execute([$id]);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$column` = ?");
    $stmt->execute([$id]);
    return $files;
}

function financeDeleteRow($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Record was already deleted.');
}

function financeLinkedWalletRow($table, $ownerColumn, $ownerId, $referenceType, $referenceId, $payment) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$ownerColumn` = ? AND reference_type = ? AND reference_id = ? AND type = 'payment' AND amount = ? AND transaction_date = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$ownerId, $referenceType, $referenceId, $payment['amount'], $payment['transaction_date']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function financeReverseOrderPayment($payment) {
    global $pdo;
    financeDeletionCheckPermission('order_payment', false);
    $order = financeRecordRow('orders', (int)$payment['order_id'], true);
    $amount = $payment['amount'];
    $method = $payment['payment_method'];
    if ($method === 'wallet') {
        financeReverseBalance('customer', (int)$order['customer_id'], $amount, $payment['created_at']);
        $wallet = financeLinkedWalletRow('customer_wallet_transactions', 'customer_id', $order['customer_id'], 'order', $order['id'], $payment);
        if ($wallet) financeDeleteRow('customer_wallet_transactions', (int)$wallet['id']);
    } elseif ($method === 'cash' || $method === 'transfer') {
        $type = $method === 'cash' ? 'safe' : 'bank';
        $id = (int)($payment[$method === 'cash' ? 'safe_id' : 'bank_account_id'] ?? 0);
        if (!$id) throw new DomainException('Payment destination is missing; reconcile the account before deletion.');
        financeReverseBalance($type, $id, -$amount, $payment['created_at']);
        // These transfers are an audit mirror of the payment, not a second movement.
        $stmt = $pdo->prepare("SELECT id FROM finance_transfers WHERE from_type = 'personal' AND from_id = 0 AND to_type = ? AND to_id = ? AND amount = ? AND notes = ? AND transaction_date = ? AND status = 'approved' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$type, $id, $amount, 'Order Payment Reference: ' . $payment['reference'], $payment['transaction_date']]);
        $transferId = $stmt->fetchColumn();
        if ($transferId) {
            $reason = 'Source order payment #' . $payment['id'] . ' deleted after approval.';
            $pdo->prepare("UPDATE finance_transfers SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ? WHERE id = ?")->execute([$_SESSION['user_id'], $reason, $transferId]);
            $pdo->prepare("INSERT INTO finance_transfer_history (finance_transfer_id, action, note, created_by) VALUES (?, 'reversed', ?, ?)")->execute([$transferId, $reason, $_SESSION['user_id']]);
        }
    } else throw new DomainException('Unsupported order payment method.');
    $stmt = $pdo->prepare('UPDATE orders SET paid_amount = paid_amount - ? WHERE id = ? AND paid_amount >= ?');
    $stmt->execute([$amount, $order['id'], $amount]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Order payment totals are inconsistent. Reconcile them first.');
    $files = financeDeletionAttachmentFiles('order_payment_attachments', 'order_payment_id', (int)$payment['id']);
    financeDeleteRow('order_payments', (int)$payment['id']);
    return $files;
}

function financeReversePurchasePayment($payment) {
    global $pdo;
    financeDeletionCheckPermission('purchase_payment', false);
    financeRequireFactory();
    if (!empty($payment['expense_payment_id'])) {
        return financeReverseExpensePayment(financeRecordDeletionRow('expense_payment', (int)$payment['expense_payment_id'], true));
    }
    if (strpos((string)($payment['notes'] ?? ''), 'Payment via expense: ') === 0) {
        $stmt = $pdo->prepare('SELECT ep.id FROM expense_payments ep JOIN expenses e ON e.id = ep.expense_id WHERE e.po_id = ? AND ep.amount = ? AND ep.transaction_date = ? AND ep.payment_method = ? AND ep.reference <=> ? AND ep.safe_id <=> ? AND ep.bank_account_id <=> ? AND ep.created_by = ? LIMIT 2 FOR UPDATE');
        $stmt->execute([$payment['purchase_order_id'], $payment['amount'], $payment['transaction_date'], $payment['payment_method'], $payment['reference'], $payment['safe_id'], $payment['bank_account_id'], $payment['created_by']]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) throw new DomainException('Select the exact source expense payment to delete this linked purchase payment.');
        return financeReverseExpensePayment(financeRecordDeletionRow('expense_payment', (int)$ids[0], true));
    }
    $po = financeRecordRow('purchase_orders', (int)$payment['purchase_order_id'], true);
    $files = [];
    if ($payment['payment_method'] === 'wallet') {
        financeReverseBalance('vendor', (int)$po['vendor_id'], $payment['amount'], $payment['created_at']);
        $wallet = financeLinkedWalletRow('vendor_wallet_transactions', 'vendor_id', $po['vendor_id'], 'purchase_order', $po['id'], $payment);
        if ($wallet) {
            $files = financeDeletionAttachmentFiles('vendor_wallet_transaction_attachments', 'vendor_wallet_transaction_id', (int)$wallet['id']);
            financeDeleteRow('vendor_wallet_transactions', (int)$wallet['id']);
        }
    }
    if (!empty($payment['payment_source_type'])) {
        financeReverseBalance($payment['payment_source_type'], (int)$payment['payment_source_id'], $payment['amount'], $payment['created_at']);
    }
    $stmt = $pdo->prepare('UPDATE purchase_orders SET paid_amount = paid_amount - ? WHERE id = ? AND paid_amount >= ?');
    $stmt->execute([$payment['amount'], $po['id'], $payment['amount']]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Purchase payment totals are inconsistent.');
    $files = array_merge($files, financeDeletionAttachmentFiles('purchase_order_payment_attachments', 'purchase_order_payment_id', (int)$payment['id']));
    financeDeleteRow('purchase_order_payments', (int)$payment['id']);
    return $files;
}

function financeReverseExpensePayment($payment, $checkPermission = true) {
    global $pdo;
    if ($checkPermission) financeDeletionCheckPermission('expense_payment', false);
    $expense = financeRecordDeletionRow('expense', (int)$payment['expense_id'], true);
    $amount = $payment['amount'];
    $method = $payment['payment_method'];
    $files = [];
    if ($method === 'cash' || $method === 'transfer') {
        financeReverseBalance($method === 'cash' ? 'safe' : 'bank', (int)$payment[$method === 'cash' ? 'safe_id' : 'bank_account_id'], $amount, $payment['created_at']);
    } elseif ($method === 'wallet') {
        financeRequireFactory();
        $stmt = $pdo->prepare("SELECT * FROM vendor_wallet_transactions WHERE reference_type = 'expense_payment' AND reference_id = ? FOR UPDATE");
        $stmt->execute([$payment['id']]);
        $wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $vendorId = $wallets ? (int)$wallets[0]['vendor_id'] : (int)$expense['vendor_id'];
        financeReverseBalance('vendor', $vendorId, $amount, $payment['created_at']);
    } else throw new DomainException('Unsupported expense payment method.');
    if (!empty($expense['po_id'])) {
        financeRequireFactory();
        $stmt = $pdo->prepare('SELECT * FROM purchase_order_payments WHERE expense_payment_id = ? FOR UPDATE');
        $stmt->execute([$payment['id']]);
        $mirrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$mirrors) {
            $stmt = $pdo->prepare("SELECT * FROM purchase_order_payments WHERE expense_payment_id IS NULL AND purchase_order_id = ? AND notes LIKE 'Payment via expense: %' AND amount = ? AND transaction_date = ? AND payment_method = ? AND reference <=> ? AND safe_id <=> ? AND bank_account_id <=> ? AND created_by = ? LIMIT 2 FOR UPDATE");
            $stmt->execute([$expense['po_id'], $amount, $payment['transaction_date'], $method, $payment['reference'], $payment['safe_id'], $payment['bank_account_id'], $payment['created_by']]);
            $mirrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (count($mirrors) !== 1) throw new DomainException('The linked purchase payment cannot be identified uniquely. Reconcile it before deletion.');
        $mirror = $mirrors[0];
        if ((int)$mirror['purchase_order_id'] !== (int)$expense['po_id'] || (float)$mirror['amount'] !== (float)$amount) throw new DomainException('Linked purchase payment does not match the expense.');
        $files = financeDeletionAttachmentFiles('purchase_order_payment_attachments', 'purchase_order_payment_id', (int)$mirror['id']);
        financeDeleteRow('purchase_order_payments', (int)$mirror['id']);
        $stmt = $pdo->prepare('UPDATE purchase_orders SET paid_amount = paid_amount - ? WHERE id = ? AND paid_amount >= ?');
        $stmt->execute([$amount, $expense['po_id'], $amount]);
        if ($stmt->rowCount() !== 1) throw new DomainException('Linked purchase order totals are inconsistent.');
    }
    $stmt = $pdo->prepare("UPDATE expenses SET paid_amount = paid_amount - ?, status = CASE WHEN paid_amount <= 0 THEN 'pending' WHEN paid_amount < amount THEN 'partially-paid' ELSE 'paid' END WHERE id = ? AND paid_amount >= ?");
    $stmt->execute([$amount, $expense['id'], $amount]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Expense payment totals are inconsistent.');
    $files = array_merge($files, financeDeletionAttachmentFiles('expense_payment_attachments', 'expense_payment_id', (int)$payment['id']));
    $stmt = $pdo->prepare("SELECT id FROM vendor_wallet_transactions WHERE reference_type = 'expense_payment' AND reference_id = ? FOR UPDATE");
    $stmt->execute([$payment['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $walletId) $files = array_merge($files, financeDeletionAttachmentFiles('vendor_wallet_transaction_attachments', 'vendor_wallet_transaction_id', (int)$walletId));
    $pdo->prepare("DELETE FROM vendor_wallet_transactions WHERE reference_type = 'expense_payment' AND reference_id = ?")->execute([$payment['id']]);
    financeDeleteRow('expense_payments', (int)$payment['id']);
    return $files;
}

function financeDeleteWalletTransaction($type, $row) {
    global $pdo;
    $customer = $type === 'customer_transaction';
    $reference = $row['reference_type'] ?? '';
    if ($reference && $reference !== 'manual') {
        if (!$customer && $reference === 'expense_payment') {
            return financeReverseExpensePayment(financeRecordDeletionRow('expense_payment', (int)$row['reference_id'], true));
        }
        if (($customer && $reference === 'order') || (!$customer && $reference === 'purchase_order' && $row['type'] === 'payment')) {
            $source = $customer ? 'order_payment' : 'purchase_payment';
            financeDeletionCheckPermission($source, false);
            $table = $customer ? 'order_payments' : 'purchase_order_payments';
            $column = $customer ? 'order_id' : 'purchase_order_id';
            $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$column` = ? AND payment_method = 'wallet' AND amount = ? AND transaction_date = ? ORDER BY id DESC LIMIT 2 FOR UPDATE");
            $stmt->execute([$row['reference_id'], $row['amount'], $row['transaction_date']]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) throw new DomainException('Select the exact source payment on the order to delete this linked wallet entry.');
            $payment = financeRecordDeletionRow($source, (int)$ids[0], true);
            $owner = financeRecordRow($customer ? 'orders' : 'purchase_orders', (int)$row['reference_id'], true);
            if ((int)$owner[$customer ? 'customer_id' : 'vendor_id'] !== (int)$row[$customer ? 'customer_id' : 'vendor_id']) throw new DomainException('Wallet source does not match the account.');
            $linked = financeLinkedWalletRow($customer ? 'customer_wallet_transactions' : 'vendor_wallet_transactions', $customer ? 'customer_id' : 'vendor_id', $row[$customer ? 'customer_id' : 'vendor_id'], $reference, $row['reference_id'], $payment);
            if (!$linked || (int)$linked['id'] !== (int)$row['id']) throw new DomainException('Select the exact source payment to delete this wallet entry.');
            return $customer ? financeReverseOrderPayment($payment) : financeReversePurchasePayment($payment);
        }
        throw new DomainException('This wallet entry was created by a goods receipt. Reverse the source receipt on its purchase order first.');
    }
    $credit = $customer ? in_array($row['type'], ['deposit', 'refund'], true) : $row['type'] === 'deposit';
    financeReverseBalance($customer ? 'customer' : 'vendor', (int)$row[$customer ? 'customer_id' : 'vendor_id'], $credit ? -$row['amount'] : $row['amount'], $row['created_at']);
    if ($customer && $row['type'] === 'payment') {
        if (($row['payment_method'] ?? '') === 'cash' && !empty($row['safe_id'])) financeReverseBalance('safe', (int)$row['safe_id'], -$row['amount'], $row['created_at']);
        elseif (($row['payment_method'] ?? '') === 'transfer' && !empty($row['bank_account_id'])) financeReverseBalance('bank', (int)$row['bank_account_id'], -$row['amount'], $row['created_at']);
        else throw new DomainException('Wallet payment destination is missing.');
    }
    $files = $customer ? [] : financeDeletionAttachmentFiles('vendor_wallet_transaction_attachments', 'vendor_wallet_transaction_id', (int)$row['id']);
    financeDeleteRow($customer ? 'customer_wallet_transactions' : 'vendor_wallet_transactions', (int)$row['id']);
    return $files;
}

function financeRestoreSalesInvoiceStock($orderId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $movements = [];
        if (tableExists('inventory_stock_logs')) {
            $logs = $pdo->prepare("SELECT inventory_id, product_id, SUM(change_quantity) AS change_quantity, MIN(quantity_before) AS quantity_before FROM inventory_stock_logs WHERE source_type IN ('sales_order', 'portal_order_conversion', 'quotation_conversion') AND source_id = ? GROUP BY inventory_id, product_id");
            $logs->execute([$item['id']]);
            $movements = $logs->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($movements && abs(array_sum(array_column($movements, 'change_quantity')) + (float)$item['quantity']) >= 0.005) {
            throw new DomainException('Invoice stock history does not match the item quantity. Reconcile stock before deletion.');
        }
        foreach ($movements as $movement) {
            if ((int)$movement['product_id'] !== (int)$item['product_id'] || (float)$movement['change_quantity'] >= 0) throw new DomainException('Invoice inventory movements are inconsistent.');
            $scope = getInventoryChannelScopeSql('i');
            $scopeStmt = $pdo->prepare("SELECT i.id FROM inventories i WHERE i.id = ? AND $scope FOR UPDATE");
            $scopeStmt->execute([$movement['inventory_id']]);
            if (!$scopeStmt->fetchColumn()) throw new DomainException('You do not have access to this invoice inventory.');
            $stockStmt = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? FOR UPDATE');
            $stockStmt->execute([$movement['inventory_id'], $movement['product_id']]);
            $before = $stockStmt->fetchColumn();
            if ($before === false) {
                // Older sales orders logged against inventory 1 even when the product was never stocked there,
                // so a zero starting quantity with no stock row means nothing was actually deducted.
                if (abs((float)$movement['quantity_before']) < 0.005) continue;
                throw new DomainException('Invoice inventory no longer exists. Reconcile stock before deletion.');
            }
            $quantity = -(float)$movement['change_quantity'];
            $pdo->prepare('UPDATE inventory_products SET quantity = quantity + ? WHERE inventory_id = ? AND product_id = ?')->execute([$quantity, $movement['inventory_id'], $movement['product_id']]);
            $pdo->prepare("INSERT INTO inventory_stock_logs (inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id, notes, created_by) VALUES (?, ?, ?, ?, ?, 'sales_invoice_deletion', ?, ?, ?)")->execute([$movement['inventory_id'], $movement['product_id'], $quantity, $before, (float)$before + $quantity, $orderId, 'Stock restored after approved invoice deletion.', $_SESSION['user_id']]);
        }
    }
}

function financeExecuteRecordDeletion($type, $row) {
    global $pdo;
    $id = (int)$row['id'];
    if ($type === 'order_payment') return financeReverseOrderPayment($row);
    if ($type === 'purchase_payment') return financeReversePurchasePayment($row);
    if ($type === 'expense_payment') return financeReverseExpensePayment($row);
    if ($type === 'customer_transaction' || $type === 'vendor_transaction') return financeDeleteWalletTransaction($type, $row);
    if ($type === 'sales_invoice' || $type === 'purchase_invoice') {
        $sales = $type === 'sales_invoice';
        if ((float)$row['paid_amount'] != 0.0) throw new DomainException('Delete the invoice payments before deleting the invoice.');
        $references = $sales
            ? [['order_payments', 'order_id'], ['order_returns', 'order_id'], ['manufacturing_orders', 'sales_order_id'], ['quotations', 'order_id'], ['customer_wallet_transactions', 'reference_id']]
            : [['purchase_order_payments', 'purchase_order_id'], ['purchase_order_receipts', 'purchase_order_id'], ['expenses', 'po_id'], ['vendor_wallet_transactions', 'reference_id']];
        foreach ($references as [$table, $column]) {
            if (!tableExists($table) || !tableHasColumn($table, $column)) continue;
            $extra = $table === 'vendor_wallet_transactions' ? " AND reference_type = 'purchase_order'" : ($table === 'customer_wallet_transactions' ? " AND reference_type = 'order'" : '');
            $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$column` = ?$extra LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) throw new DomainException('Remove or reverse the linked payments, receipts and source records before deleting this invoice.');
        }
        if ($sales && !in_array($row['status'], ['new', 'pending', 'in-production', 'in-packing'], true)) throw new DomainException('Reverse fulfillment before deleting this sales invoice.');
        if ($sales) financeRestoreSalesInvoiceStock($id);
        if (!$sales) {
            $stmt = $pdo->prepare('SELECT id FROM purchase_order_items WHERE purchase_order_id = ? AND received_quantity > 0 LIMIT 1 FOR UPDATE');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) throw new DomainException('Reverse received inventory before deleting this purchase invoice.');
        }
        $files = $sales ? [] : financeDeletionAttachmentFiles('purchase_order_images', 'purchase_order_id', $id);
        if ($sales && tableExists('customer_order_notes')) $pdo->prepare('DELETE FROM customer_order_notes WHERE order_id = ?')->execute([$id]);
        $items = $sales ? 'order_items' : 'purchase_order_items';
        $column = $sales ? 'order_id' : 'purchase_order_id';
        $pdo->prepare("DELETE FROM `$items` WHERE `$column` = ?")->execute([$id]);
        financeDeleteRow($sales ? 'orders' : 'purchase_orders', $id);
        return $files;
    }
    if ($type === 'expense') {
        $stmt = $pdo->prepare('SELECT * FROM expense_payments WHERE expense_id = ? ORDER BY id DESC FOR UPDATE');
        $stmt->execute([$id]);
        $files = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) $files = array_merge($files, financeReverseExpensePayment($payment, false));
        financeDeleteRow('expenses', $id);
        return $files;
    }
    if ($type === 'customer_account' || $type === 'vendor_account') {
        $customer = $type === 'customer_account';
        if ($row['finance_account_closed_at']) throw new DomainException('Finance account is already closed.');
        if (round((float)$row['wallet_balance'], 2) !== 0.0) throw new DomainException('Settle the wallet balance before closing the finance account.');
        $table = $customer ? 'orders' : 'purchase_orders';
        $column = $customer ? 'customer_id' : 'vendor_id';
        $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$column` = ? AND total_amount > paid_amount LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) throw new DomainException('Settle outstanding orders before closing the finance account.');
        $table = $customer ? 'customers' : 'vendors';
        $pdo->prepare("UPDATE `$table` SET finance_account_closed_at = NOW(), finance_account_closed_by = ? WHERE id = ?")->execute([$_SESSION['user_id'], $id]);
        return [];
    }
    if ($type === 'customer_adjustment' || $type === 'account_adjustment') {
        $accountType = $type === 'customer_adjustment' ? 'customer' : $row['account_type'];
        $accountId = (int)$row[$type === 'customer_adjustment' ? 'customer_id' : 'account_id'];
        financeReverseBalance($accountType, $accountId, (float)$row['previous_balance'] - (float)$row['new_balance'], $row['created_at'], $id);
        financeDeleteRow(financeRecordDeletionConfig($type), $id);
        return [];
    }
    if ($type === 'transfer') {
        if ($row['status'] === 'approved') {
            if ((int)$row['from_id'] === 0 || (int)$row['to_id'] === 0) throw new DomainException('Delete the source order payment first. Its transfer history can then be deleted.');
            financeReverseBalance($row['to_type'], (int)$row['to_id'], -$row['amount'], $row['approved_at'] ?: $row['created_at']);
            financeReverseBalance($row['from_type'], (int)$row['from_id'], $row['amount'], $row['approved_at'] ?: $row['created_at']);
        } elseif (!in_array($row['status'], ['pending', 'rejected', 'reversed'], true)) throw new DomainException('Unsupported transfer status.');
        $files = financeDeletionAttachmentFiles('finance_transfer_images', 'finance_transfer_id', $id);
        if (!empty($row['image_path'])) $files[] = $row['image_path'];
        $pdo->prepare('DELETE FROM finance_transfer_history WHERE finance_transfer_id = ?')->execute([$id]);
        financeDeleteRow('finance_transfers', $id);
        return $files;
    }
    throw new DomainException('Unsupported finance deletion.');
}

function handleFinanceRecordDeletion($type, $id, $returnPage = 'deletion_requests.php') {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { setAlert('danger', 'Use Request Deletion to submit a request.'); redirect($returnPage); }
    try {
        financeDeletionValidateToken();
        financeDeletionCheckPermission($type, false);
        financeRecordDeletionRow($type, $id);
        financeDeletionRequest($type, $id, false, $returnPage);
        $pdo->beginTransaction();
        financeDeletionApprove($pdo, $type, $id);
        $row = financeRecordDeletionRow($type, $id, true);
        $files = financeExecuteRecordDeletion($type, $row);
        $pdo->commit();
        foreach (array_unique($files) as $file) {
            $path = realpath(ROOT_PATH . '/' . $file);
            if ($path && (strpos($path, ROOT_PATH . '/assets/uploads/') === 0 || strpos($path, ROOT_PATH . '/uploads/') === 0) && is_file($path)) unlink($path);
        }
        logActivity('Approved finance record deletion', ['type' => $type, 'record' => $row, 'request_id' => (int)$_POST['deletion_request_id']], 'delete', 'finance_deletion_request', (int)$_POST['deletion_request_id']);
        setAlert('success', str_ends_with($type, '_account') ? 'Finance account closed. Profile and history retained.' : 'Deletion approved. Related balances were reversed.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!($error instanceof DomainException)) error_log('Finance record deletion failed: ' . $error->getMessage());
        setAlert('danger', $error instanceof DomainException ? $error->getMessage() : 'Unable to delete this record. No changes were made.');
    }
    redirect($returnPage);
}
