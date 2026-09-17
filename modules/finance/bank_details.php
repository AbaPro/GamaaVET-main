<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_balance_adjustments.php';

if (!hasPermission('finance.bank_accounts.create')
    && !hasPermission('finance.bank_accounts.edit')
    && !hasPermission('finance.bank_accounts.delete')
    && !hasPermission('finance.bank_accounts.balance.edit')
    && !hasPermission('finance.transfers.create')
    && !hasPermission('finance.transfers.approve')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$bankId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$bankId) {
    setAlert('danger', 'Invalid bank account.');
    redirect('banks.php');
}

$bankScope = getAccountScopeSql('b');
$bankStmt = $conn->prepare("SELECT b.*, a.name AS account_name
                            FROM bank_accounts b
                            LEFT JOIN accounts a ON a.id = b.account_id
                            WHERE b.id = ? AND $bankScope
                            LIMIT 1");
$bankStmt->bind_param('i', $bankId);
$bankStmt->execute();
$bank = $bankStmt->get_result()->fetch_assoc();
$bankStmt->close();

if (!$bank) {
    setAlert('danger', 'Bank account not found.');
    redirect('banks.php');
}

$canSetBalance = hasPermission('finance.bank_accounts.balance.edit');
$formToken = financeAccountFormToken();
handleFinanceAccountBalanceSettlement('bank', $bankId, $canSetBalance, 'bank_details.php?id=' . $bankId);
$currency = $bank['currency'] ?: 'EGP';

function bankHistoryCounterpartyName($row, $side) {
    $type = $row[$side . '_type'] ?? '';
    $id = (int)($row[$side . '_id'] ?? 0);

    if ($type === 'safe') {
        return $row[$side . '_safe_name'] ?: 'Safe #' . $id;
    }
    if ($type === 'bank') {
        return $row[$side . '_bank_name'] ?: 'Bank account #' . $id;
    }
    if ($type === 'personal') {
        if ($id === 0) {
            return 'External / Customer Payment';
        }
        return $row[$side . '_personal_name'] ?: 'Personal account #' . $id;
    }

    return ucfirst((string)$type) . ($id > 0 ? ' #' . $id : '');
}

$transactions = [];

// Customer bank transfers are incoming account movements. Their automatically
// generated finance-transfer mirrors are excluded below to prevent duplication.
$customerScope = getCustomerChannelScopeSql('c', 'customer_factory');
$orderPaymentStmt = $conn->prepare("
    SELECT op.id, op.amount, op.reference, op.notes, op.transaction_date, op.created_at,
           u.name AS created_by_name,
           o.id AS order_id, o.internal_id AS order_number, o.currency AS source_currency,
           c.name AS customer_name
    FROM order_payments op
    JOIN orders o ON o.id = op.order_id
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
    LEFT JOIN users u ON u.id = op.created_by
    WHERE op.payment_method = 'transfer' AND op.bank_account_id = ? AND $customerScope
");
$orderPaymentStmt->bind_param('i', $bankId);
$orderPaymentStmt->execute();
$orderPayments = $orderPaymentStmt->get_result();
while ($row = $orderPayments->fetch_assoc()) {
    $transactions[] = [
        'sort_id' => (int)$row['id'],
        'source_key' => 'order_payment_' . $row['id'],
        'transaction_date' => $row['transaction_date'],
        'created_at' => $row['created_at'],
        'type' => 'Customer Payment',
        'direction' => 'in',
        'amount' => (float)$row['amount'],
        'reference' => $row['reference'] ?: 'Order ' . $row['order_number'],
        'details' => $row['customer_name'] . ' — ' . $row['order_number'],
        'details_url' => '../sales/order_details.php?id=' . (int)$row['order_id'],
        'notes' => $row['notes'],
        'created_by_name' => $row['created_by_name'] ?: 'System',
        'attachments' => [],
        'source_currency' => $row['source_currency'] ?: $currency,
    ];
}
$orderPaymentStmt->close();

// Manual customer-wallet payments sent by bank transfer also credit the selected
// bank account, but do not create an order_payment or finance_transfer row.
$walletPaymentStmt = $conn->prepare("
    SELECT wt.id, wt.amount, wt.notes, wt.transaction_date, wt.created_at,
           u.name AS created_by_name,
           c.id AS customer_id, c.name AS customer_name
    FROM customer_wallet_transactions wt
    JOIN customers c ON c.id = wt.customer_id
    LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
    LEFT JOIN users u ON u.id = wt.created_by
    WHERE wt.type = 'payment'
      AND wt.payment_method = 'transfer'
      AND wt.bank_account_id = ?
      AND $customerScope
");
$walletPaymentStmt->bind_param('i', $bankId);
$walletPaymentStmt->execute();
$walletPayments = $walletPaymentStmt->get_result();
while ($row = $walletPayments->fetch_assoc()) {
    $transactions[] = [
        'sort_id' => (int)$row['id'],
        'source_key' => 'wallet_payment_' . $row['id'],
        'transaction_date' => $row['transaction_date'],
        'created_at' => $row['created_at'],
        'type' => 'Customer Wallet Payment',
        'direction' => 'in',
        'amount' => (float)$row['amount'],
        'reference' => 'Wallet transaction #' . $row['id'],
        'details' => $row['customer_name'],
        'details_url' => '../customers/wallet.php?id=' . (int)$row['customer_id'],
        'notes' => $row['notes'],
        'created_by_name' => $row['created_by_name'] ?: 'System',
        'attachments' => [],
        'source_currency' => $currency,
    ];
}
$walletPaymentStmt->close();

// Expense payments are outgoing account movements. Linked PO payment rows are
// alternate records of the same movement and are intentionally not duplicated.
$expenseScope = getAccountScopeSql('e');
$expensePaymentStmt = $conn->prepare("
    SELECT ep.id, ep.amount, ep.reference, ep.notes, ep.transaction_date, ep.created_at,
           u.name AS created_by_name,
           e.id AS expense_id, e.name AS expense_name, e.notes AS expense_notes, e.currency AS source_currency,
           v.name AS vendor_name
    FROM expense_payments ep
    JOIN expenses e ON e.id = ep.expense_id
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN users u ON u.id = ep.created_by
    WHERE ep.payment_method = 'transfer' AND ep.bank_account_id = ? AND $expenseScope
");
$expensePaymentStmt->bind_param('i', $bankId);
$expensePaymentStmt->execute();
$expensePayments = $expensePaymentStmt->get_result();
while ($row = $expensePayments->fetch_assoc()) {
    $details = $row['expense_name'];
    if (!empty($row['vendor_name'])) {
        $details .= ' — ' . $row['vendor_name'];
    }

    $transactions[] = [
        'sort_id' => (int)$row['id'],
        'source_key' => 'expense_payment_' . $row['id'],
        'transaction_date' => $row['transaction_date'],
        'created_at' => $row['created_at'],
        'type' => 'Expense Payment',
        'direction' => 'out',
        'amount' => (float)$row['amount'],
        'reference' => $row['reference'] ?: 'Expense #' . $row['expense_id'],
        'details' => $details,
        'details_url' => 'expenses/details.php?id=' . (int)$row['expense_id'],
        'notes' => $row['notes'] ?: $row['expense_notes'],
        'created_by_name' => $row['created_by_name'] ?: 'System',
        'attachments' => [],
        'source_currency' => $row['source_currency'] ?: $currency,
    ];
}
$expensePaymentStmt->close();

$transferScope = financeTransferScopeSql('f');
$transferStmt = $conn->prepare("
    SELECT f.*,
           u.name AS created_by_name,
           from_safe.name AS from_safe_name,
           from_bank.bank_name AS from_bank_name,
           from_personal.name AS from_personal_name,
           to_safe.name AS to_safe_name,
           to_bank.bank_name AS to_bank_name,
           to_personal.name AS to_personal_name
    FROM finance_transfers f
    LEFT JOIN users u ON u.id = f.created_by
    LEFT JOIN safes from_safe ON f.from_type = 'safe' AND f.from_id = from_safe.id
    LEFT JOIN bank_accounts from_bank ON f.from_type = 'bank' AND f.from_id = from_bank.id
    LEFT JOIN personal_accounts from_personal ON f.from_type = 'personal' AND f.from_id = from_personal.id
    LEFT JOIN safes to_safe ON f.to_type = 'safe' AND f.to_id = to_safe.id
    LEFT JOIN bank_accounts to_bank ON f.to_type = 'bank' AND f.to_id = to_bank.id
    LEFT JOIN personal_accounts to_personal ON f.to_type = 'personal' AND f.to_id = to_personal.id
    WHERE ((f.from_type = 'bank' AND f.from_id = ?)
        OR (f.to_type = 'bank' AND f.to_id = ?))
      AND $transferScope
      AND f.status = 'approved'
      AND NOT (
          f.from_type = 'personal'
          AND f.from_id = 0
          AND f.to_type = 'bank'
          AND f.to_id = ?
          AND f.notes LIKE 'Order Payment Reference:%'
      )
");
$transferStmt->bind_param('iii', $bankId, $bankId, $bankId);
$transferStmt->execute();
$transferRows = $transferStmt->get_result();
$transferEvents = [];
$transferIds = [];
while ($row = $transferRows->fetch_assoc()) {
    $isOutgoing = $row['from_type'] === 'bank' && (int)$row['from_id'] === $bankId;
    $isIncoming = $row['to_type'] === 'bank' && (int)$row['to_id'] === $bankId;
    $direction = $isIncoming && !$isOutgoing ? 'in' : ($isOutgoing && !$isIncoming ? 'out' : 'neutral');
    $counterpartySide = $isOutgoing ? 'to' : 'from';

    $transferEvents[(int)$row['id']] = [
        'sort_id' => (int)$row['id'],
        'source_key' => 'transfer_' . $row['id'],
        'transaction_date' => $row['transaction_date'],
        'created_at' => $row['created_at'],
        'type' => 'Finance Transfer',
        'direction' => $direction,
        'amount' => (float)$row['amount'],
        'reference' => $row['transfer_reference'] ?: 'Transfer #' . $row['id'],
        'details' => bankHistoryCounterpartyName($row, $counterpartySide),
        'details_url' => 'transfer_details.php?id=' . (int)$row['id'],
        'notes' => $row['reason'] ?: $row['notes'],
        'created_by_name' => $row['created_by_name'] ?: 'System',
        'attachments' => [],
        'source_currency' => $currency,
    ];
    $transferIds[(int)$row['id']] = true;
}
$transferStmt->close();

if ($transferIds && tableExists('finance_transfer_images')) {
    $imageResult = $conn->query("
        SELECT finance_transfer_id, file_path, original_name
        FROM finance_transfer_images
        ORDER BY created_at ASC, id ASC
    ");
    if ($imageResult) {
        while ($image = $imageResult->fetch_assoc()) {
            $transferId = (int)$image['finance_transfer_id'];
            if (isset($transferEvents[$transferId])) {
                $transferEvents[$transferId]['attachments'][] = $image;
            }
        }
    }
}
$transactions = array_merge($transactions, array_values($transferEvents));

require_once __DIR__ . '/../purchases/payment_sources.php';
foreach (poPaymentSourceHistory('bank', $bankId) as $payment) {
    $transactions[] = [
        'source_key' => 'po_payment_' . $payment['id'],
        'sort_id' => (int)$payment['id'],
        'transaction_date' => $payment['transaction_date'],
        'created_at' => $payment['created_at'],
        'type' => 'PO Payment', 'direction' => 'out',
        'amount' => (float)$payment['amount'],
        'reference' => $payment['reference'],
        'details' => $payment['vendor_name'] . ' — PO #' . $payment['purchase_order_id'],
        'details_url' => '../purchases/po_details.php?id=' . (int)$payment['purchase_order_id'],
        'notes' => $payment['notes'], 'created_by_name' => $payment['created_by_name'] ?: 'System',
        'attachments' => [],
        'source_currency' => $currency,
    ];
}

foreach (financeAccountBalanceAdjustments('bank', $bankId) as $adjustment) {
    $change = (float)$adjustment['new_balance'] - (float)$adjustment['previous_balance'];
    $transactions[] = [
        'source_key' => 'balance_adjustment_' . $adjustment['id'],
        'sort_id' => (int)$adjustment['id'],
        'transaction_date' => $adjustment['transaction_date'],
        'created_at' => $adjustment['created_at'],
        'type' => 'Balance Adjustment',
        'direction' => $change > 0 ? 'in' : ($change < 0 ? 'out' : 'neutral'),
        'amount' => abs($change),
        'reference' => 'Adjustment #' . $adjustment['id'],
        'details' => number_format((float)$adjustment['previous_balance'], 2) . ' → ' . number_format((float)$adjustment['new_balance'], 2),
        'details_url' => null,
        'notes' => $adjustment['reason'],
        'created_by_name' => $adjustment['created_by_name'] ?: 'System',
        'attachments' => [],
        'source_currency' => $adjustment['currency'] ?: $currency,
    ];
}

usort($transactions, function ($left, $right) {
    $dateComparison = strcmp($right['transaction_date'], $left['transaction_date']);
    if ($dateComparison !== 0) {
        return $dateComparison;
    }
    $dateComparison = strcmp($right['created_at'], $left['created_at']);
    if ($dateComparison !== 0) {
        return $dateComparison;
    }
    return $right['sort_id'] <=> $left['sort_id'];
});

$totalIn = 0.0;
$totalOut = 0.0;
$runningBalance = (float)$bank['balance'];
foreach ($transactions as &$transaction) {
    $transaction['running_balance'] = $runningBalance;
    if ($transaction['direction'] === 'in') {
        $totalIn += $transaction['amount'];
        $runningBalance -= $transaction['amount'];
    } elseif ($transaction['direction'] === 'out') {
        $totalOut += $transaction['amount'];
        $runningBalance += $transaction['amount'];
    }
}
unset($transaction);

$filterDirection = in_array($_GET['direction'] ?? '', ['in', 'out', 'neutral'], true) ? $_GET['direction'] : '';
$filterType = trim((string)($_GET['type'] ?? ''));
$filterDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$filterDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
$transactionTypes = array_values(array_unique(array_column($transactions, 'type')));
sort($transactionTypes);
$visibleTransactions = array_values(array_filter($transactions, function ($transaction) use ($filterDirection, $filterType, $filterDateFrom, $filterDateTo) {
    $date = $transaction['transaction_date'];
    if ($filterDirection !== '' && $transaction['direction'] !== $filterDirection) return false;
    if ($filterType !== '' && $transaction['type'] !== $filterType) return false;
    if ($filterDateFrom !== '' && $date < $filterDateFrom) return false;
    if ($filterDateTo !== '' && $date > $filterDateTo) return false;
    return true;
}));

$page_title = e($bank['bank_name']);
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1"><?= e($bank['bank_name']); ?></h2>
        <div class="text-muted">
            Bank Account Ledger · Account #<?= e($bank['account_number']); ?> · <?= e($bank['account_name'] ?: 'GammaVet'); ?> · <?= e($currency); ?>
        </div>
    </div>
    <a href="banks.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back to Bank Accounts
    </a>
</div>

<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-uppercase text-muted fw-bold">Current Balance</div>
            <div class="h3 mb-0 <?= (float)$bank['balance'] < 0 ? 'text-danger' : 'text-success'; ?>">
                <?= e(formatCurrency((float)$bank['balance'], $currency)); ?>
            </div>
        </div></div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-uppercase text-muted fw-bold">Total Money In</div>
            <div class="h3 mb-0 text-success"><?= e(formatCurrency($totalIn, $currency)); ?></div>
        </div></div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-uppercase text-muted fw-bold">Total Money Out</div>
            <div class="h3 mb-0 text-danger"><?= e(formatCurrency($totalOut, $currency)); ?></div>
        </div></div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-uppercase text-muted fw-bold">Transactions</div>
            <div class="h3 mb-0"><?= number_format(count($visibleTransactions)); ?></div>
            <?php if (count($visibleTransactions) !== count($transactions)): ?><div class="small text-muted">of <?= number_format(count($transactions)); ?> total</div><?php endif; ?>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>Account Details</h5></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-3"><div class="small text-muted">Account Holder</div><div class="fw-semibold"><?= $bank['account_holder'] ? e($bank['account_holder']) : '-'; ?></div></div>
        <div class="col-md-3"><div class="small text-muted">Branch</div><div class="fw-semibold"><?= $bank['branch_name'] ? e($bank['branch_name']) : '-'; ?></div></div>
        <div class="col-md-3"><div class="small text-muted">IBAN</div><div class="fw-semibold text-break"><?= $bank['iban'] ? e($bank['iban']) : '-'; ?></div></div>
        <div class="col-md-3"><div class="small text-muted">Currency / Brand</div><div class="fw-semibold"><?= e($currency); ?> · <?= e($bank['account_name'] ?: 'GammaVet'); ?></div></div>
        <?php if ($bank['notes']): ?><div class="col-12"><div class="small text-muted">Account Notes</div><div><?= nl2br(e($bank['notes'])); ?></div></div><?php endif; ?>
    </div></div>
</div>

<?php if ($canSetBalance): ?>
<div class="card border-warning shadow-sm mb-4" id="set-balance">
    <div class="card-header bg-warning-subtle"><h5 class="mb-0"><i class="fas fa-scale-balanced me-2"></i>Set Account Balance</h5></div>
    <div class="card-body">
        <?php if (financeAccountBalanceAdjustmentStorageReady()): ?>
        <div class="alert alert-warning py-2">Use this only to reconcile the stored balance with the bank statement. The old value, new value, reason, user, and date will be kept in this ledger.</div>
        <form method="post" class="row g-3 align-items-end" onsubmit="return confirm('Set this bank account to the entered balance?');">
            <input type="hidden" name="csrf_token" value="<?= e($formToken); ?>"><input type="hidden" name="set_finance_account_balance" value="1">
            <div class="col-md-3"><label class="form-label">New Balance (<?= e($currency); ?>)*</label><input type="number" class="form-control" name="new_balance" value="<?= e(number_format((float)$bank['balance'], 2, '.', '')); ?>" min="-999999999999.99" max="999999999999.99" step="0.01" required></div>
            <div class="col-md-2"><label class="form-label">Transaction Date*</label><input type="date" class="form-control" name="transaction_date" value="<?= date('Y-m-d'); ?>" required></div>
            <div class="col-md-5"><label class="form-label">Reconciliation Reason*</label><input type="text" class="form-control" name="adjustment_reason" maxlength="500" placeholder="Example: Matched September bank statement" required></div>
            <div class="col-md-2"><button type="submit" class="btn btn-warning w-100">Set Balance</button></div>
        </form>
        <?php else: ?><div class="alert alert-warning mb-0">Apply migration <code>20260916_finance_account_details_and_adjustments.sql</code> to enable audited balance updates.</div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>All Transactions</h5>
    </div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-4">
            <input type="hidden" name="id" value="<?= (int)$bankId; ?>">
            <div class="col-lg-3 col-md-6"><label class="form-label mb-1">Transaction Type</label><select class="form-select" name="type"><option value="">All types</option><?php foreach ($transactionTypes as $type): ?><option value="<?= e($type); ?>" <?= $filterType === $type ? 'selected' : ''; ?>><?= e($type); ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-2 col-md-6"><label class="form-label mb-1">Direction</label><select class="form-select" name="direction"><option value="">All directions</option><option value="in" <?= $filterDirection === 'in' ? 'selected' : ''; ?>>Money In</option><option value="out" <?= $filterDirection === 'out' ? 'selected' : ''; ?>>Money Out</option><option value="neutral" <?= $filterDirection === 'neutral' ? 'selected' : ''; ?>>Internal</option></select></div>
            <div class="col-lg-2 col-md-4"><label class="form-label mb-1">From</label><input type="date" class="form-control" name="date_from" value="<?= e($filterDateFrom); ?>"></div>
            <div class="col-lg-2 col-md-4"><label class="form-label mb-1">To</label><input type="date" class="form-control" name="date_to" value="<?= e($filterDateTo); ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button></div>
            <?php if ($filterType || $filterDirection || $filterDateFrom || $filterDateTo): ?><div class="col-auto"><a href="bank_details.php?id=<?= (int)$bankId; ?>" class="btn btn-outline-secondary">Clear</a></div><?php endif; ?>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle js-datatable mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Details</th>
                        <th class="text-end">Money In</th>
                        <th class="text-end">Money Out</th>
                        <th>Notes</th>
                        <th>Attachments</th>
                        <th>Recorded By</th>
                        <th class="text-end">Running Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($visibleTransactions): ?>
                        <?php foreach ($visibleTransactions as $transaction): ?>
                            <tr>
                                <td data-order="<?= e(strtotime($transaction['transaction_date'])); ?>">
                                    <?= date('M d, Y', strtotime($transaction['transaction_date'])); ?>
                                </td>
                                <td>
                                    <?php if ($transaction['direction'] === 'in'): ?>
                                        <span class="badge bg-success">Money In</span>
                                    <?php elseif ($transaction['direction'] === 'out'): ?>
                                        <span class="badge bg-danger">Money Out</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Internal</span>
                                    <?php endif; ?>
                                    <div class="small mt-1"><?= e($transaction['type']); ?></div>
                                    <div class="small text-muted"><?= e($transaction['source_key']); ?></div>
                                </td>
                                <td><?= e($transaction['reference']); ?></td>
                                <td><?php if ($transaction['details_url']): ?><a href="<?= e($transaction['details_url']); ?>" class="text-decoration-none fw-semibold"><?= e($transaction['details']); ?></a><?php else: ?><span class="fw-semibold"><?= e($transaction['details']); ?></span><?php endif; ?><?php if (($transaction['source_currency'] ?? $currency) !== $currency): ?><div class="small text-warning"><i class="fas fa-triangle-exclamation me-1"></i>Source: <?= e($transaction['source_currency']); ?></div><?php endif; ?></td>
                                <td class="text-end text-success fw-semibold">
                                    <?= $transaction['direction'] === 'in' ? e(formatCurrency($transaction['amount'], $currency)) : '-'; ?>
                                </td>
                                <td class="text-end text-danger fw-semibold">
                                    <?= $transaction['direction'] === 'out' ? e(formatCurrency($transaction['amount'], $currency)) : '-'; ?>
                                </td>
                                <td><?= $transaction['notes'] ? nl2br(e($transaction['notes'])) : '<span class="text-muted">-</span>'; ?></td>
                                <td><?= renderAttachmentThumbnails($transaction['attachments']); ?></td>
                                <td><?= e($transaction['created_by_name']); ?></td>
                                <td class="text-end fw-bold <?= $transaction['running_balance'] < 0 ? 'text-danger' : ''; ?>">
                                    <?= e(formatCurrency($transaction['running_balance'], $currency)); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No transactions match the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
