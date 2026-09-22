<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../finance/account_balance_adjustments.php';

$canManageCustomerWallet = hasPermission('customers.wallet') || hasPermission('finance.customer_payment.process');
$canAdjustCustomerWalletBalance = hasPermission('customers.wallet.balance.edit') || canSettleFinanceBalances();
$canViewCustomerWallet = hasPermission('customers.wallet.view')
    || $canManageCustomerWallet
    || $canAdjustCustomerWalletBalance
    || hasPermission('finance.customer_wallet.view');

if (!$canViewCustomerWallet) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid customer ID.');
    redirect('index.php');
}

$customer_id = (int)$_GET['id'];
if (!canAccessCustomer($customer_id) || !isCustomerInCurrentChannel($customer_id)) {
    setAlert('danger', 'You do not have permission to access this customer.');
    redirect('index.php');
}
$page_title = 'Customer Account';

// Get customer info for header
$customer_sql = "SELECT name, wallet_balance FROM customers WHERE id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();

if ($customer_result->num_rows === 0) {
    setAlert('danger', 'Customer not found.');
    redirect('index.php');
}

$customer = $customer_result->fetch_assoc();
$customer_stmt->close();
$walletBalanceAdjustmentStorageReady = tableExists('customer_wallet_balance_adjustments');
$financeBalanceFormToken = financeAccountFormToken();

// A balance adjustment sets the customer account balance directly. It deliberately does
// not create a wallet transaction or move money through a safe/bank account.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_wallet_balance'])) {
    if (!$canAdjustCustomerWalletBalance) {
        setAlert('danger', 'You do not have permission to set customer wallet balances directly.');
        redirect('wallet.php?id=' . $customer_id);
    }

    if (!$walletBalanceAdjustmentStorageReady) {
        setAlert('danger', 'The wallet balance adjustment migration has not been applied yet.');
        redirect('wallet.php?id=' . $customer_id);
    }

    validateFinanceAccountFormToken('wallet.php?id=' . $customer_id);

    $newBalanceInput = trim((string)($_POST['wallet_balance'] ?? ''));
    $adjustmentReason = trim(strip_tags((string)($_POST['adjustment_reason'] ?? '')));
    $transactionDate = normalizeTransactionDate($_POST['transaction_date'] ?? '');

    if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $newBalanceInput)) {
        setAlert('danger', 'Enter a valid wallet balance between -999,999,999,999.99 and 999,999,999,999.99.');
        redirect('wallet.php?id=' . $customer_id);
    }

    if ($adjustmentReason === '') {
        setAlert('danger', 'A reason is required when setting the wallet balance directly.');
        redirect('wallet.php?id=' . $customer_id);
    }
    if ($transactionDate === null) {
        setAlert('danger', 'Enter a valid transaction date.');
        redirect('wallet.php?id=' . $customer_id);
    }

    $reasonLength = function_exists('mb_strlen')
        ? mb_strlen($adjustmentReason, 'UTF-8')
        : strlen($adjustmentReason);
    if ($reasonLength > 500) {
        setAlert('danger', 'The adjustment reason cannot exceed 500 characters.');
        redirect('wallet.php?id=' . $customer_id);
    }

    $newBalance = round((float)$newBalanceInput, 2);
    $userId = (int)$_SESSION['user_id'];

    $conn->begin_transaction();

    try {
        $balanceStmt = $conn->prepare("SELECT wallet_balance FROM customers WHERE id = ? FOR UPDATE");
        $balanceStmt->bind_param('i', $customer_id);
        $balanceStmt->execute();
        $balanceRow = $balanceStmt->get_result()->fetch_assoc();
        $balanceStmt->close();

        if (!$balanceRow) {
            throw new Exception('Customer not found.');
        }

        $previousBalance = round((float)$balanceRow['wallet_balance'], 2);
        if (abs($previousBalance - $newBalance) < 0.005) {
            $conn->rollback();
            setAlert('info', 'The wallet balance is already ' . number_format($newBalance, 2) . '.');
            redirect('wallet.php?id=' . $customer_id);
        }

        $updateStmt = $conn->prepare("UPDATE customers SET wallet_balance = ? WHERE id = ?");
        $updateStmt->bind_param('di', $newBalance, $customer_id);
        $updateStmt->execute();
        $updateStmt->close();

        $adjustmentStmt = $conn->prepare("
            INSERT INTO customer_wallet_balance_adjustments
                (customer_id, previous_balance, new_balance, reason, transaction_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $adjustmentStmt->bind_param('iddssi', $customer_id, $previousBalance, $newBalance, $adjustmentReason, $transactionDate, $userId);
        $adjustmentStmt->execute();
        $adjustmentId = $adjustmentStmt->insert_id;
        $adjustmentStmt->close();

        logActivity('Set customer wallet balance directly', [
            'customer_id' => $customer_id,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'reason' => $adjustmentReason,
            'adjustment_id' => $adjustmentId,
        ]);

        $conn->commit();
        setAlert(
            'success',
            'Wallet balance updated from ' . number_format($previousBalance, 2)
            . ' to ' . number_format($newBalance, 2) . ' without creating a wallet transaction.'
        );
    } catch (Throwable $e) {
        $conn->rollback();
        setAlert('danger', 'Unable to update the wallet balance: ' . $e->getMessage());
    }

    redirect('wallet.php?id=' . $customer_id);
}

// Fetch safes and bank accounts for payment methods, scoped to the current brand
$safes_data = [];
$safes_result = $conn->query("SELECT id, CONCAT(name, ' — ', currency) AS name FROM safes WHERE " . getAccountScopeSql() . " ORDER BY name");
if ($safes_result) {
    while ($safe = $safes_result->fetch_assoc()) {
        $safes_data[] = $safe;
    }
}

$banks_data = [];
$banks_result = $conn->query("SELECT id, CONCAT(bank_name, ' — ', currency) AS name FROM bank_accounts WHERE " . getAccountScopeSql() . " ORDER BY bank_name");
if ($banks_result) {
    while ($bank = $banks_result->fetch_assoc()) {
        $banks_data[] = $bank;
    }
}

// Handle wallet transactions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageCustomerWallet) {
        setAlert('danger', 'You do not have permission to process customer wallet transactions.');
        redirect('wallet.php?id=' . $customer_id);
    }

    $amount = sanitize($_POST['amount']);
    $type = sanitize($_POST['type']);
    $notes = sanitize($_POST['notes']);
    $payment_method = sanitize($_POST['payment_method'] ?? 'cash');
    $safe_id = !empty($_POST['safe_id']) ? (int)$_POST['safe_id'] : null;
    $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $transactionDate = normalizeTransactionDate($_POST['transaction_date'] ?? '');
    $user_id = $_SESSION['user_id'];
    $isDebit = in_array($type, ['payment', 'withdrawal'], true);

    $validationError = null;
    if ($transactionDate === null) {
        $validationError = 'Enter a valid transaction date.';
    } elseif (!in_array($payment_method, ['cash', 'transfer'], true)) {
        $validationError = 'Select a valid payment method.';
    } elseif ($payment_method === 'cash' && (!$safe_id || !isSafeInCurrentAccount($safe_id))) {
        $validationError = 'Select a cash safe available for this brand.';
    } elseif ($payment_method === 'transfer' && (!$bank_account_id || !isBankAccountInCurrentAccount($bank_account_id))) {
        $validationError = 'Selected bank account is not available for this brand.';
    } elseif ($isDebit) {
        $currentStmt = $conn->prepare("SELECT wallet_balance FROM customers WHERE id = ?");
        $currentStmt->bind_param("i", $customer_id);
        $currentStmt->execute();
        $currentBalance = (float)$currentStmt->get_result()->fetch_assoc()['wallet_balance'];
        $currentStmt->close();

        if ($currentBalance < $amount) {
            $validationError = 'Insufficient wallet balance. Available: ' . number_format($currentBalance, 2);
        }
    }

    if ($validationError !== null) {
        setAlert('danger', $validationError);
        redirect('wallet.php?id=' . $customer_id);
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Insert wallet transaction
        $transaction_sql = "INSERT INTO customer_wallet_transactions
                           (customer_id, amount, type, notes, transaction_date, payment_method, safe_id, bank_account_id, reference_type, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?)";
        $transaction_stmt = $conn->prepare($transaction_sql);
        $transaction_stmt->bind_param("idssssiii", $customer_id, $amount, $type, $notes, $transactionDate, $payment_method, $safe_id, $bank_account_id, $user_id);
        $transaction_stmt->execute();
        $transaction_id = $transaction_stmt->insert_id;
        $transaction_stmt->close();

        // Update customer wallet balance
        $update_sql = "UPDATE customers SET wallet_balance = wallet_balance ";
        $update_sql .= in_array($type, ['deposit', 'refund'], true) ? '+' : '-';
        $update_sql .= " ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("di", $amount, $customer_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Update destination balance based on payment method (for payment type only)
        if ($type === 'payment') {
            if ($payment_method === 'cash' && $safe_id) {
                $safe_stmt = $conn->prepare("UPDATE safes SET balance = balance + ? WHERE id = ?");
                $safe_stmt->bind_param("di", $amount, $safe_id);
                $safe_stmt->execute();
                $safe_stmt->close();
            } elseif ($payment_method === 'transfer' && $bank_account_id) {
                $bank_stmt = $conn->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                $bank_stmt->bind_param("di", $amount, $bank_account_id);
                $bank_stmt->execute();
                $bank_stmt->close();
            }
        }

        // Commit transaction
        $conn->commit();

        setAlert('success', 'Wallet transaction completed successfully.');
        logActivity("Processed wallet transaction ID: $transaction_id for customer ID: $customer_id ($type: $amount)");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error processing wallet transaction: ' . $e->getMessage());
    }

    redirect('wallet.php?id=' . $customer_id);
}

// Get wallet transactions with payment method info. Account names are joined
// only when the destination belongs to the active brand.
$safeScope = getAccountScopeSql('s');
$bankScope = getAccountScopeSql('ba');
$transactions_sql = "SELECT wt.*, u.name as created_by_name,
                            CASE
                                WHEN wt.payment_method = 'cash' THEN s.name
                                WHEN wt.payment_method = 'transfer' THEN ba.bank_name
                                ELSE NULL
                            END as destination_name
                     FROM customer_wallet_transactions wt
                     LEFT JOIN users u ON wt.created_by = u.id
                     LEFT JOIN safes s ON wt.safe_id = s.id AND $safeScope
                     LEFT JOIN bank_accounts ba ON wt.bank_account_id = ba.id AND $bankScope
                     WHERE wt.customer_id = ?
                     ORDER BY wt.transaction_date DESC, wt.created_at DESC";
$transactions_stmt = $conn->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $customer_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

// Compute a running balance per row (result set is newest-first, so walk backwards from the current balance).
$wallet_transactions = $transactions_result->fetch_all(MYSQLI_ASSOC);
$transactions_stmt->close();

// Order payments are part of the customer's financial activity even when they
// are paid directly into a cash safe or bank account instead of prepaid credit.
$orderPaymentsStmt = $conn->prepare("
    SELECT op.*, o.internal_id AS order_number, u.name AS created_by_name,
           CASE
               WHEN op.payment_method = 'cash' THEN s.name
               WHEN op.payment_method = 'transfer' THEN ba.bank_name
               ELSE 'Customer wallet'
           END AS destination_name
    FROM order_payments op
    JOIN orders o ON o.id = op.order_id
    LEFT JOIN users u ON u.id = op.created_by
    LEFT JOIN safes s ON s.id = op.safe_id AND $safeScope
    LEFT JOIN bank_accounts ba ON ba.id = op.bank_account_id AND $bankScope
    WHERE o.customer_id = ?
    ORDER BY op.transaction_date DESC, op.created_at DESC, op.id DESC
");
$orderPaymentsStmt->bind_param('i', $customer_id);
$orderPaymentsStmt->execute();
$orderPayments = $orderPaymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$orderPaymentsStmt->close();

$outstandingOrdersStmt = $conn->prepare("
    SELECT COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)), 0)
    FROM orders
    WHERE customer_id = ?
");
$outstandingOrdersStmt->bind_param('i', $customer_id);
$outstandingOrdersStmt->execute();
$outstandingOrdersStmt->bind_result($outstandingOrderBalance);
$outstandingOrdersStmt->fetch();
$outstandingOrdersStmt->close();
$outstandingOrderBalance = (float)$outstandingOrderBalance;
$customerReceivable = max($outstandingOrderBalance, max(-(float)$customer['wallet_balance'], 0));

// Direct balance changes have their own audit history and remain separate from
// deposits, refunds, and payments.
$walletBalanceAdjustments = [];
if ($walletBalanceAdjustmentStorageReady) {
    $adjustmentsStmt = $conn->prepare("
        SELECT wa.*, u.name AS created_by_name
        FROM customer_wallet_balance_adjustments wa
        LEFT JOIN users u ON wa.created_by = u.id
        WHERE wa.customer_id = ?
        ORDER BY wa.transaction_date DESC, wa.created_at DESC, wa.id DESC
    ");
    $adjustmentsStmt->bind_param('i', $customer_id);
    $adjustmentsStmt->execute();
    $walletBalanceAdjustments = $adjustmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $adjustmentsStmt->close();
}

// Build a combined internal ledger so transaction running balances remain
// correct even when an administrator has set an absolute balance between them.
$ledgerEvents = [];
foreach ($wallet_transactions as $txn) {
    $ledgerEvents[] = [
        'kind' => 'transaction',
        'id' => (int)$txn['id'],
        'transaction_date' => $txn['transaction_date'],
        'created_at' => $txn['created_at'],
        'amount' => (float)$txn['amount'],
        'is_credit' => in_array($txn['type'], ['deposit', 'refund'], true),
    ];
}
foreach ($walletBalanceAdjustments as $adjustment) {
    $ledgerEvents[] = [
        'kind' => 'adjustment',
        'id' => (int)$adjustment['id'],
        'transaction_date' => $adjustment['transaction_date'],
        'created_at' => $adjustment['created_at'],
        'previous_balance' => (float)$adjustment['previous_balance'],
    ];
}
usort($ledgerEvents, function ($left, $right) {
    $dateComparison = strcmp($right['transaction_date'], $left['transaction_date']);
    if ($dateComparison !== 0) {
        return $dateComparison;
    }
    $dateComparison = strcmp($right['created_at'], $left['created_at']);
    if ($dateComparison !== 0) {
        return $dateComparison;
    }

    // A direct adjustment is the final absolute balance at its timestamp.
    if ($left['kind'] !== $right['kind']) {
        return $left['kind'] === 'adjustment' ? -1 : 1;
    }

    return $right['id'] <=> $left['id'];
});

$transactionRunningBalances = [];
$runningBalance = (float)$customer['wallet_balance'];
foreach ($ledgerEvents as $event) {
    if ($event['kind'] === 'adjustment') {
        $runningBalance = $event['previous_balance'];
        continue;
    }

    $transactionRunningBalances[$event['id']] = $runningBalance;
    $runningBalance += $event['is_credit'] ? -$event['amount'] : $event['amount'];
}
foreach ($wallet_transactions as &$txn) {
    $txn['running_balance'] = $transactionRunningBalances[(int)$txn['id']] ?? (float)$customer['wallet_balance'];
}
unset($txn);

require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-1">
    <h2>
        Customer Account for: <?php echo e($customer['name']); ?>
        <span class="badge bg-<?php echo $customer['wallet_balance'] >= 0 ? 'success' : 'danger'; ?>">
            Wallet: <?php echo number_format($customer['wallet_balance'], 2); ?>
        </span>
        <span class="badge bg-<?php echo $outstandingOrderBalance > 0 ? 'danger' : 'success'; ?>">
            Outstanding Orders: <?php echo number_format($outstandingOrderBalance, 2); ?>
        </span>
        <span class="badge bg-<?php echo $customerReceivable > 0 ? 'dark' : 'success'; ?>">
            Receivable: <?php echo number_format($customerReceivable, 2); ?>
        </span>
    </h2>
    <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">Back to Customer</a>
</div>
<p class="text-muted mb-4">Positive wallet balances are customer credit; negative balances are customer debt. Cash and bank down payments are shown in Order Payment History below and reduce the outstanding order balance. Direct administrative balance changes are listed separately.</p>

<div class="row mb-4">
    <?php if ($canManageCustomerWallet): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Wallet Transaction</h5>
            </div>
            <div class="card-body">
                <form action="wallet.php?id=<?php echo $customer_id; ?>" method="POST">
                    <div class="mb-3">
                        <label for="type" class="form-label">Transaction Type*</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="deposit">Deposit</option>
                            <!-- <option value="withdrawal">Withdrawal</option> -->
                            <option value="payment">Payment</option>
                            <option value="refund">Refund</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount*</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label for="transaction_date" class="form-label">Transaction Date*</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-select" id="payment_method" name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3" id="bank_account_container" style="display: none;">
                        <label for="bank_account_id" class="form-label">Bank Account</label>
                        <select class="form-select" id="bank_account_id" name="bank_account_id">
                            <option value="">-- Select Bank Account --</option>
                            <?php foreach ($banks_data as $bank): ?>
                                <option value="<?php echo $bank['id']; ?>"><?php echo e($bank['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="safe_container">
                        <label for="safe_id" class="form-label">Destination Safe</label>
                        <select class="form-select" id="safe_id" name="safe_id">
                            <option value="">-- Select Safe --</option>
                            <?php foreach ($safes_data as $safe): ?>
                                <option value="<?php echo (int)$safe['id']; ?>"><?php echo e($safe['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Process Transaction</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canAdjustCustomerWalletBalance && $walletBalanceAdjustmentStorageReady): ?>
    <div class="col-md-6">
        <div class="card border-warning" id="set-balance">
            <div class="card-header">
                <h5 class="card-title mb-0">Set Wallet Balance</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2">
                    This sets the customer account balance directly. Use a negative value for debt and a positive value for credit. It will not create a wallet transaction or change any cash safe or bank account.
                </div>
                <form action="wallet.php?id=<?php echo $customer_id; ?>" method="POST" onsubmit="return confirm('Set this wallet to the entered balance?');">
                    <input type="hidden" name="csrf_token" value="<?php echo e($financeBalanceFormToken); ?>">
                    <input type="hidden" name="set_wallet_balance" value="1">
                    <div class="mb-3">
                        <label for="wallet_balance" class="form-label">New Balance*</label>
                        <input type="number"
                               class="form-control"
                               id="wallet_balance"
                               name="wallet_balance"
                               value="<?php echo e(number_format((float)$customer['wallet_balance'], 2, '.', '')); ?>"
                               min="-999999999999.99"
                               max="999999999999.99"
                               step="0.01"
                               required>
                    </div>
                    <div class="mb-3">
                        <label for="adjustment_reason" class="form-label">Reason*</label>
                        <textarea class="form-control"
                                  id="adjustment_reason"
                                  name="adjustment_reason"
                                  rows="2"
                                  maxlength="500"
                                  placeholder="Why is the stored balance being corrected?"
                                  required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="adjustment_transaction_date" class="form-label">Transaction Date*</label>
                        <input type="date" class="form-control" id="adjustment_transaction_date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-warning">Update Balance</button>
                </form>
            </div>
        </div>
    </div>
    <?php elseif ($canAdjustCustomerWalletBalance): ?>
    <div class="col-md-6">
        <div class="alert alert-warning mb-0">
            Apply migration <code>20260830_customer_wallet_balance_adjustments.sql</code> to enable direct balance updates.
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Wallet Transaction History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Destination</th>
                        <th>Notes</th>
                        <th>Processed By</th>
                        <th>Running Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($wallet_transactions)): ?>
                        <?php foreach ($wallet_transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $transaction['type'] === 'deposit' || $transaction['type'] === 'refund' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($transaction['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($transaction['amount'], 2); ?></td>
                                <td>
                                    <?php if (!empty($transaction['payment_method'])): ?>
                                        <span class="badge bg-info"><?php echo ucfirst($transaction['payment_method']); ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($transaction['destination_name']) ? e($transaction['destination_name']) : '-'; ?></td>
                                <td><?php echo $transaction['notes'] ? e($transaction['notes']) : '-'; ?></td>
                                <td><?php echo $transaction['created_by_name'] ? e($transaction['created_by_name']) : 'System'; ?></td>
                                <td><?php echo number_format($transaction['running_balance'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">No transactions found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Order Payment History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Destination</th>
                        <th>Reference</th>
                        <th>Notes</th>
                        <th>Processed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($orderPayments)): ?>
                        <?php foreach ($orderPayments as $payment): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['transaction_date'])); ?></td>
                                <td>
                                    <a href="../sales/order_details.php?id=<?php echo (int)$payment['order_id']; ?>">
                                        <?php echo e($payment['order_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo number_format($payment['amount'], 2); ?></td>
                                <td><span class="badge bg-info"><?php echo e(ucfirst($payment['payment_method'])); ?></span></td>
                                <td><?php echo $payment['destination_name'] ? e($payment['destination_name']) : '-'; ?></td>
                                <td><?php echo $payment['reference'] ? e($payment['reference']) : '-'; ?></td>
                                <td><?php echo $payment['notes'] ? e($payment['notes']) : '-'; ?></td>
                                <td><?php echo $payment['created_by_name'] ? e($payment['created_by_name']) : 'System'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">No order payments found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($walletBalanceAdjustmentStorageReady && ($canAdjustCustomerWalletBalance || !empty($walletBalanceAdjustments))): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Administrative Balance Adjustment History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Previous Balance</th>
                        <th>New Balance</th>
                        <th>Change</th>
                        <th>Reason</th>
                        <th>Changed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($walletBalanceAdjustments)): ?>
                        <?php foreach ($walletBalanceAdjustments as $adjustment): ?>
                            <?php $balanceChange = (float)$adjustment['new_balance'] - (float)$adjustment['previous_balance']; ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($adjustment['transaction_date'])); ?></td>
                                <td><?php echo number_format($adjustment['previous_balance'], 2); ?></td>
                                <td><?php echo number_format($adjustment['new_balance'], 2); ?></td>
                                <td class="<?php echo $balanceChange >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($balanceChange >= 0 ? '+' : '') . number_format($balanceChange, 2); ?>
                                </td>
                                <td><?php echo e($adjustment['reason']); ?></td>
                                <td><?php echo $adjustment['created_by_name'] ? e($adjustment['created_by_name']) : 'System'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">No direct balance adjustments found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Handle payment method change
    $('#payment_method').change(function() {
        const method = $(this).val();
        if (method === 'transfer') {
            $('#bank_account_container').show();
            $('#bank_account_id').prop('required', true);
            $('#safe_container').hide();
            $('#safe_id').prop('required', false).val('');
        } else {
            $('#bank_account_container').hide();
            $('#bank_account_id').prop('required', false).val('');
            $('#safe_container').show();
            $('#safe_id').prop('required', true);
        }
    });

    // Trigger initial state
    $('#payment_method').trigger('change');
});
</script>

<?php require_once '../../includes/footer.php'; ?>
