<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/deletion_approval.php';
require_once __DIR__ . '/account_balance_adjustments.php';

if (!hasPermission('finance.safes.create')
    && !hasPermission('finance.safes.edit')
    && !hasPermission('finance.safes.delete')
    && !hasPermission('finance.safes.force_delete')
    && !hasPermission('finance.safes.balance.edit')
    && !hasPermission('finance.balances.settle')
    && !hasPermission('finance.transfers.create')
    && !hasPermission('finance.transfers.approve')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$safeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$safeId) {
    setAlert('danger', 'Invalid safe.');
    redirect('safes.php');
}

$safeScope = getSafeScopeSql('s');
$safeStmt = $conn->prepare("SELECT s.*, a.name AS account_name, l.name AS location_name, l.address AS location_address
                            FROM safes s
                            LEFT JOIN accounts a ON a.id = s.account_id
                            LEFT JOIN locations l ON l.id = s.location_id
                            WHERE s.id = ? AND $safeScope
                            LIMIT 1");
$safeStmt->bind_param('i', $safeId);
$safeStmt->execute();
$safe = $safeStmt->get_result()->fetch_assoc();
$safeStmt->close();

if (!$safe) {
    setAlert('danger', 'Safe not found.');
    redirect('safes.php');
}

$canSetBalance = canSettleFinanceBalances() || hasPermission('finance.safes.balance.edit');
$formToken = financeAccountFormToken();
handleFinanceAccountBalanceSettlement('safe', $safeId, $canSetBalance, 'safe_details.php?id=' . $safeId);
$currency = $safe['currency'] ?: 'EGP';
$showLocation = ($_SESSION['login_region'] ?? 'factory') === 'factory';

require_once __DIR__ . '/ledger_helpers.php';
['transactions' => $transactions, 'total_in' => $totalIn, 'total_out' => $totalOut]
    = financeAccountLedger('safe', $safeId, $currency, (float)$safe['balance']);

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

$page_title = e($safe['name']);
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1"><?= e($safe['name']); ?></h2>
        <div class="text-muted">Safe Ledger<?= $showLocation ? ' · ' . e($safe['location_name'] ?: 'Unassigned location') : ''; ?> · <?= e($safe['account_name'] ?: 'GammaVet'); ?> · <?= e($currency); ?></div>
    </div>
    <a href="safes.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back to Safes
    </a>
</div>

<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Current Balance</div>
                <div class="h3 mb-0 <?= (float)$safe['balance'] < 0 ? 'text-danger' : 'text-success'; ?>">
                    <?= e(formatCurrency((float)$safe['balance'], $currency)); ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Total Money In</div>
                <div class="h3 mb-0 text-success"><?= e(formatCurrency($totalIn, $currency)); ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Total Money Out</div>
                <div class="h3 mb-0 text-danger"><?= e(formatCurrency($totalOut, $currency)); ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Transactions</div>
                <div class="h3 mb-0"><?= number_format(count($visibleTransactions)); ?></div>
                <?php if (count($visibleTransactions) !== count($transactions)): ?><div class="small text-muted">of <?= number_format(count($transactions)); ?> total</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>Safe Details</h5></div>
    <div class="card-body"><div class="row g-3">
        <?php if ($showLocation): ?><div class="col-md-3"><div class="small text-muted">Location</div><div class="fw-semibold"><?= $safe['location_name'] ? e($safe['location_name']) : 'Unassigned'; ?></div><?php if ($safe['location_address']): ?><div class="small text-muted"><?= e($safe['location_address']); ?></div><?php endif; ?></div><?php endif; ?>
        <div class="col-md-3"><div class="small text-muted">Currency</div><div class="fw-semibold"><?= e($currency); ?></div></div>
        <div class="col-md-3"><div class="small text-muted">Brand</div><div class="fw-semibold"><?= e($safe['account_name'] ?: 'GammaVet'); ?></div></div>
        <div class="col-md-3"><div class="small text-muted">Safe ID</div><div class="fw-semibold">#<?= (int)$safeId; ?></div></div>
        <?php if ($safe['notes']): ?><div class="col-12"><div class="small text-muted">Safe Notes</div><div><?= nl2br(e($safe['notes'])); ?></div></div><?php endif; ?>
    </div></div>
</div>

<?php if ($canSetBalance): ?>
<div class="card border-warning shadow-sm mb-4" id="set-balance">
    <div class="card-header bg-warning-subtle"><h5 class="mb-0"><i class="fas fa-scale-balanced me-2"></i>Set Safe Balance</h5></div>
    <div class="card-body">
        <?php if (financeAccountBalanceAdjustmentStorageReady()): ?>
        <div class="alert alert-warning py-2">Use this after a physical cash count. The old value, new value, reason, user, and date will be kept in this ledger.</div>
        <form method="post" class="row g-3 align-items-end" onsubmit="return confirm('Set this safe to the entered balance?');">
            <input type="hidden" name="csrf_token" value="<?= e($formToken); ?>"><input type="hidden" name="set_finance_account_balance" value="1">
            <div class="col-md-3"><label class="form-label">New Balance (<?= e($currency); ?>)*</label><input type="number" class="form-control" name="new_balance" value="<?= e(number_format((float)$safe['balance'], 2, '.', '')); ?>" min="-999999999999.99" max="999999999999.99" step="0.01" required></div>
            <div class="col-md-2"><label class="form-label">Transaction Date*</label><input type="date" class="form-control" name="transaction_date" value="<?= date('Y-m-d'); ?>" required></div>
            <div class="col-md-5"><label class="form-label">Count / Reconciliation Reason*</label><input type="text" class="form-control" name="adjustment_reason" maxlength="500" placeholder="Example: Physical cash count on September 16" required></div>
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
            <input type="hidden" name="id" value="<?= (int)$safeId; ?>">
            <div class="col-lg-3 col-md-6"><label class="form-label mb-1">Transaction Type</label><select class="form-select" name="type"><option value="">All types</option><?php foreach ($transactionTypes as $type): ?><option value="<?= e($type); ?>" <?= $filterType === $type ? 'selected' : ''; ?>><?= e($type); ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-2 col-md-6"><label class="form-label mb-1">Direction</label><select class="form-select" name="direction"><option value="">All directions</option><option value="in" <?= $filterDirection === 'in' ? 'selected' : ''; ?>>Money In</option><option value="out" <?= $filterDirection === 'out' ? 'selected' : ''; ?>>Money Out</option><option value="neutral" <?= $filterDirection === 'neutral' ? 'selected' : ''; ?>>Internal</option></select></div>
            <div class="col-lg-2 col-md-4"><label class="form-label mb-1">From</label><input type="date" class="form-control" name="date_from" value="<?= e($filterDateFrom); ?>"></div>
            <div class="col-lg-2 col-md-4"><label class="form-label mb-1">To</label><input type="date" class="form-control" name="date_to" value="<?= e($filterDateTo); ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button></div>
            <?php if ($filterType || $filterDirection || $filterDateFrom || $filterDateTo): ?><div class="col-auto"><a href="safe_details.php?id=<?= (int)$safeId; ?>" class="btn btn-outline-secondary">Clear</a></div><?php endif; ?>
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
                        <th class="text-end">Running Balance</th><th>Actions</th>
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
                                <td>
                                    <?php if ($transaction['details_url']): ?>
                                        <a href="<?= e($transaction['details_url']); ?>" class="text-decoration-none fw-semibold">
                                            <?= e($transaction['details']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="fw-semibold"><?= e($transaction['details']); ?></span>
                                    <?php endif; ?>
                                    <?php if (($transaction['source_currency'] ?? $currency) !== $currency): ?><div class="small text-warning"><i class="fas fa-triangle-exclamation me-1"></i>Source: <?= e($transaction['source_currency']); ?></div><?php endif; ?>
                                </td>
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
                                <td><?php
                                    $deleteTypes = ['order_payment_' => 'order_payment', 'wallet_payment_' => 'customer_transaction', 'expense_payment_' => 'expense_payment', 'po_payment_' => 'purchase_payment', 'transfer_' => 'transfer', 'balance_adjustment_' => 'account_adjustment'];
                                    foreach ($deleteTypes as $prefix => $deleteType) {
                                        if (strpos($transaction['source_key'], $prefix) === 0) { renderFinanceDeletionRequest($deleteType, $transaction['sort_id']); break; }
                                    }
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">No transactions match the selected filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
