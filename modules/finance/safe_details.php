<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.safes.create')
    && !hasPermission('finance.safes.delete')
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

$safeStmt = $conn->prepare("SELECT s.*, a.name AS account_name
                            FROM safes s
                            LEFT JOIN accounts a ON a.id = s.account_id
                            WHERE s.id = ?
                            LIMIT 1");
$safeStmt->bind_param('i', $safeId);
$safeStmt->execute();
$safe = $safeStmt->get_result()->fetch_assoc();
$safeStmt->close();

if (!$safe) {
    setAlert('danger', 'Safe not found.');
    redirect('safes.php');
}

function safeHistoryCounterpartyName($row, $side) {
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

// Customer order payments put cash into the safe. The matching automatically
// generated finance-transfer row is excluded below so each payment appears once.
$orderPaymentStmt = $conn->prepare("
    SELECT op.id, op.amount, op.reference, op.notes, op.created_at,
           op.created_by, u.name AS created_by_name,
           o.id AS order_id, o.internal_id AS order_number,
           c.name AS customer_name
    FROM order_payments op
    JOIN orders o ON o.id = op.order_id
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = op.created_by
    WHERE op.payment_method = 'cash' AND op.safe_id = ?
");
$orderPaymentStmt->bind_param('i', $safeId);
$orderPaymentStmt->execute();
$orderPayments = $orderPaymentStmt->get_result();
while ($row = $orderPayments->fetch_assoc()) {
    $transactions[] = [
        'source_key' => 'order_payment_' . $row['id'],
        'sort_id' => (int)$row['id'],
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
    ];
}
$orderPaymentStmt->close();

// Manual customer-wallet cash payments also credit the selected safe.
$walletPaymentStmt = $conn->prepare("
    SELECT wt.id, wt.amount, wt.notes, wt.created_at,
           u.name AS created_by_name,
           c.id AS customer_id, c.name AS customer_name
    FROM customer_wallet_transactions wt
    JOIN customers c ON c.id = wt.customer_id
    LEFT JOIN users u ON u.id = wt.created_by
    WHERE wt.type = 'payment'
      AND wt.payment_method = 'cash'
      AND wt.safe_id = ?
");
$walletPaymentStmt->bind_param('i', $safeId);
$walletPaymentStmt->execute();
$walletPayments = $walletPaymentStmt->get_result();
while ($row = $walletPayments->fetch_assoc()) {
    $transactions[] = [
        'source_key' => 'wallet_payment_' . $row['id'],
        'sort_id' => (int)$row['id'],
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
    ];
}
$walletPaymentStmt->close();

// Expense payments take cash out of the safe. A linked purchase-order payment is
// another view of this same payment, so expense_payments is the canonical row here.
$expensePaymentStmt = $conn->prepare("
    SELECT ep.id, ep.amount, ep.reference, ep.notes, ep.created_at,
           u.name AS created_by_name,
           e.id AS expense_id, e.name AS expense_name, e.notes AS expense_notes,
           v.name AS vendor_name
    FROM expense_payments ep
    JOIN expenses e ON e.id = ep.expense_id
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN users u ON u.id = ep.created_by
    WHERE ep.payment_method = 'cash' AND ep.safe_id = ?
");
$expensePaymentStmt->bind_param('i', $safeId);
$expensePaymentStmt->execute();
$expensePayments = $expensePaymentStmt->get_result();
while ($row = $expensePayments->fetch_assoc()) {
    $details = $row['expense_name'];
    if (!empty($row['vendor_name'])) {
        $details .= ' — ' . $row['vendor_name'];
    }

    $transactions[] = [
        'source_key' => 'expense_payment_' . $row['id'],
        'sort_id' => (int)$row['id'],
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
    ];
}
$expensePaymentStmt->close();

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
    WHERE ((f.from_type = 'safe' AND f.from_id = ?)
        OR (f.to_type = 'safe' AND f.to_id = ?))
      AND f.status = 'approved'
      AND NOT (
          f.from_type = 'personal'
          AND f.from_id = 0
          AND f.to_type = 'safe'
          AND f.to_id = ?
          AND f.notes LIKE 'Order Payment Reference:%'
      )
");
$transferStmt->bind_param('iii', $safeId, $safeId, $safeId);
$transferStmt->execute();
$transferRows = $transferStmt->get_result();
$transferEvents = [];
$transferIds = [];
while ($row = $transferRows->fetch_assoc()) {
    $isOutgoing = $row['from_type'] === 'safe' && (int)$row['from_id'] === $safeId;
    $isIncoming = $row['to_type'] === 'safe' && (int)$row['to_id'] === $safeId;
    $direction = $isIncoming && !$isOutgoing ? 'in' : ($isOutgoing && !$isIncoming ? 'out' : 'neutral');
    $counterpartySide = $isOutgoing ? 'to' : 'from';

    $event = [
        'source_key' => 'transfer_' . $row['id'],
        'sort_id' => (int)$row['id'],
        'created_at' => $row['created_at'],
        'type' => 'Finance Transfer',
        'direction' => $direction,
        'amount' => (float)$row['amount'],
        'reference' => $row['transfer_reference'] ?: 'Transfer #' . $row['id'],
        'details' => safeHistoryCounterpartyName($row, $counterpartySide),
        'details_url' => 'transfer_details.php?id=' . (int)$row['id'],
        'notes' => $row['reason'] ?: $row['notes'],
        'created_by_name' => $row['created_by_name'] ?: 'System',
        'attachments' => [],
    ];
    $transferEvents[(int)$row['id']] = $event;
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
foreach (poPaymentSourceHistory('safe', $safeId) as $payment) {
    $transactions[] = [
        'source_key' => 'po_payment_' . $payment['id'],
        'sort_id' => (int)$payment['id'],
        'created_at' => $payment['created_at'],
        'type' => 'PO Payment', 'direction' => 'out',
        'amount' => (float)$payment['amount'],
        'reference' => $payment['reference'],
        'details' => $payment['vendor_name'] . ' — PO #' . $payment['purchase_order_id'],
        'details_url' => '../purchases/po_details.php?id=' . (int)$payment['purchase_order_id'],
        'notes' => $payment['notes'], 'created_by_name' => $payment['created_by_name'] ?: 'System',
        'attachments' => [],
    ];
}

usort($transactions, function ($left, $right) {
    $dateComparison = strcmp($right['created_at'], $left['created_at']);
    if ($dateComparison !== 0) {
        return $dateComparison;
    }
    return $right['sort_id'] <=> $left['sort_id'];
});

$totalIn = 0.0;
$totalOut = 0.0;
$runningBalance = (float)$safe['balance'];
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

$page_title = e($safe['name']);
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1"><?= e($safe['name']); ?></h2>
        <div class="text-muted">Safe Transaction History · <?= e($safe['account_name'] ?: 'GammaVet'); ?></div>
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
                    <?= number_format((float)$safe['balance'], 2); ?> EGP
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Total Money In</div>
                <div class="h3 mb-0 text-success"><?= number_format($totalIn, 2); ?> EGP</div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Total Money Out</div>
                <div class="h3 mb-0 text-danger"><?= number_format($totalOut, 2); ?> EGP</div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-bold">Transactions</div>
                <div class="h3 mb-0"><?= number_format(count($transactions)); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>All Transactions</h5>
    </div>
    <div class="card-body">
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
                    <?php if ($transactions): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td data-order="<?= e(strtotime($transaction['created_at'])); ?>">
                                    <?= date('M d, Y H:i', strtotime($transaction['created_at'])); ?>
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
                                </td>
                                <td><?= e($transaction['reference']); ?></td>
                                <td>
                                    <?php if ($transaction['details_url']): ?>
                                        <a href="<?= e($transaction['details_url']); ?>" class="text-decoration-none fw-semibold">
                                            <?= e($transaction['details']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?= e($transaction['details']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-success fw-semibold">
                                    <?= $transaction['direction'] === 'in' ? number_format($transaction['amount'], 2) : '-'; ?>
                                </td>
                                <td class="text-end text-danger fw-semibold">
                                    <?= $transaction['direction'] === 'out' ? number_format($transaction['amount'], 2) : '-'; ?>
                                </td>
                                <td><?= $transaction['notes'] ? nl2br(e($transaction['notes'])) : '<span class="text-muted">-</span>'; ?></td>
                                <td><?= renderAttachmentThumbnails($transaction['attachments']); ?></td>
                                <td><?= e($transaction['created_by_name']); ?></td>
                                <td class="text-end fw-bold <?= $transaction['running_balance'] < 0 ? 'text-danger' : ''; ?>">
                                    <?= number_format($transaction['running_balance'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No transactions found for this safe.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
