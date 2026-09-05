<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'transfer_helpers.php';

if (!hasPermission('finance.personal_accounts.create')
    && !hasPermission('finance.personal_accounts.delete')
    && !hasPermission('finance.transfers.create')
    && !hasPermission('finance.transfers.approve')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$personalAccountId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$personalAccountId) {
    setAlert('danger', 'Invalid personal account.');
    redirect('personal.php');
}

$accountStmt = $conn->prepare("
    SELECT pa.*, a.name AS account_name,
           COALESCE(r.name, u.role) AS holder_role
    FROM personal_accounts pa
    LEFT JOIN accounts a ON a.id = pa.account_id
    LEFT JOIN users u ON u.id = pa.holder_user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE pa.id = ?
    LIMIT 1
");
$accountStmt->bind_param('i', $personalAccountId);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();
if (!$account) {
    setAlert('danger', 'Personal account not found.');
    redirect('personal.php');
}

$transferStmt = $conn->prepare(
    financeTransferSelectSql()
    . " WHERE (f.from_type = 'personal' AND f.from_id = ?)
          OR (f.to_type = 'personal' AND f.to_id = ?)
        ORDER BY f.created_at DESC, f.id DESC"
);
$transferStmt->bind_param('ii', $personalAccountId, $personalAccountId);
$transferStmt->execute();
$transfers = $transferStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$transferStmt->close();

$transferImages = [];
if ($transfers) {
    $imageResult = $conn->query("
        SELECT finance_transfer_id, file_path, original_name
        FROM finance_transfer_images
        ORDER BY created_at, id
    ");
    if ($imageResult) {
        while ($image = $imageResult->fetch_assoc()) {
            $transferImages[(int)$image['finance_transfer_id']][] = $image;
        }
    }
}

require_once __DIR__ . '/../purchases/payment_sources.php';
foreach (poPaymentSourceHistory('personal', $personalAccountId) as $payment) {
    $transfers[] = [
        'id' => 0, 'po_payment_id' => $payment['id'],
        'created_at' => $payment['created_at'], 'transfer_reference' => 'PO Payment #' . $payment['id'],
        'status' => 'approved', 'from_type' => 'personal', 'from_id' => $personalAccountId,
        'to_type' => 'vendor', 'to_id' => 0, 'to_account_name' => $payment['vendor_name'],
        'amount' => $payment['amount'], 'reason' => $payment['notes'], 'notes' => $payment['reference'],
        'purchase_order_id' => $payment['purchase_order_id'], 'ticket_id' => null,
        'requested_by_name' => $payment['created_by_name'], 'approved_by_name' => null,
    ];
}
usort($transfers, function ($a, $b) {
    return strcmp($b['created_at'], $a['created_at']) ?: (($b['po_payment_id'] ?? $b['id']) <=> ($a['po_payment_id'] ?? $a['id']));
});

$totalReceived = 0.0;
$totalSpent = 0.0;
$runningBalance = (float)$account['balance'];
foreach ($transfers as &$transfer) {
    $isIncoming = $transfer['to_type'] === 'personal' && (int)$transfer['to_id'] === $personalAccountId;
    $isOutgoing = $transfer['from_type'] === 'personal' && (int)$transfer['from_id'] === $personalAccountId;
    $transfer['direction'] = $isIncoming && !$isOutgoing ? 'in' : ($isOutgoing && !$isIncoming ? 'out' : 'neutral');
    $counterpartySide = $isOutgoing ? 'to' : 'from';
    $transfer['counterparty'] = financeTransferAccountName($transfer, $counterpartySide);
    $transfer['running_balance'] = null;

    if ($transfer['status'] === 'approved') {
        $transfer['running_balance'] = $runningBalance;
        if ($transfer['direction'] === 'in') {
            $totalReceived += (float)$transfer['amount'];
            $runningBalance -= (float)$transfer['amount'];
        } elseif ($transfer['direction'] === 'out') {
            $totalSpent += (float)$transfer['amount'];
            $runningBalance += (float)$transfer['amount'];
        }
    }
}
unset($transfer);

$statusColors = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger', 'reversed' => 'secondary'];
$page_title = e($account['name']);
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1"><?= e($account['name']); ?></h2>
        <div class="text-muted">
            Personal Account Ledger · <?= e($account['holder_role'] ?: 'Standalone Account'); ?> · <?= e($account['account_name'] ?: 'GammaVet'); ?>
        </div>
    </div>
    <a href="personal.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Personal Accounts</a>
</div>

<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-uppercase text-muted fw-bold">Current Balance</div>
        <div class="h3 mb-0 <?= (float)$account['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= number_format((float)$account['balance'], 2); ?> EGP</div>
    </div></div></div>
    <div class="col-lg-3 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-uppercase text-muted fw-bold">Total Received</div>
        <div class="h3 mb-0 text-success"><?= number_format($totalReceived, 2); ?> EGP</div>
    </div></div></div>
    <div class="col-lg-3 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-uppercase text-muted fw-bold">Total Spent / Sent</div>
        <div class="h3 mb-0 text-danger"><?= number_format($totalSpent, 2); ?> EGP</div>
    </div></div></div>
    <div class="col-lg-3 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-uppercase text-muted fw-bold">Transaction Records</div>
        <div class="h3 mb-0"><?= number_format(count($transfers)); ?></div>
    </div></div></div>
</div>

<?php if ($account['description']): ?>
    <div class="alert alert-info"><strong>Responsibility:</strong> <?= nl2br(e($account['description'])); ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Complete Money Trail</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle js-datatable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th><th>Reference</th><th>Status</th><th>Direction</th><th>From / To</th>
                        <th class="text-end">Received</th><th class="text-end">Spent</th><th>Reason</th>
                        <th>PO / Ticket</th><th>Requested By</th><th>Approved By</th><th>Images</th>
                        <th class="text-end">Running Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transfers): ?>
                        <?php foreach ($transfers as $transfer): ?>
                            <tr>
                                <td data-order="<?= (int)strtotime($transfer['created_at']); ?>"><?= date('M d, Y H:i', strtotime($transfer['created_at'])); ?></td>
                                <td><a href="<?= isset($transfer['po_payment_id']) ? '../purchases/po_details.php?id=' . (int)$transfer['purchase_order_id'] : 'transfer_details.php?id=' . (int)$transfer['id']; ?>" class="fw-semibold"><?= e($transfer['transfer_reference']); ?></a></td>
                                <td><span class="badge bg-<?= $statusColors[$transfer['status']] ?? 'secondary'; ?>"><?= e(ucfirst($transfer['status'])); ?></span></td>
                                <td>
                                    <?php if ($transfer['direction'] === 'in'): ?><span class="badge bg-success">Received From</span>
                                    <?php elseif ($transfer['direction'] === 'out'): ?><span class="badge bg-danger">Sent / Spent To</span>
                                    <?php else: ?><span class="badge bg-secondary">Internal</span><?php endif; ?>
                                </td>
                                <td><?= e($transfer['counterparty']); ?></td>
                                <td class="text-end text-success fw-semibold"><?= $transfer['status'] === 'approved' && $transfer['direction'] === 'in' ? number_format((float)$transfer['amount'], 2) : '-'; ?></td>
                                <td class="text-end text-danger fw-semibold"><?= $transfer['status'] === 'approved' && $transfer['direction'] === 'out' ? number_format((float)$transfer['amount'], 2) : '-'; ?></td>
                                <td><?= e($transfer['reason'] ?: $transfer['notes']); ?></td>
                                <td>
                                    <?php if ($transfer['purchase_order_id']): ?><a href="../purchases/po_details.php?id=<?= (int)$transfer['purchase_order_id']; ?>">PO #<?= (int)$transfer['purchase_order_id']; ?></a><?php endif; ?>
                                    <?php if ($transfer['ticket_id']): ?><div><a href="../tickets/view.php?id=<?= (int)$transfer['ticket_id']; ?>">Ticket #<?= (int)$transfer['ticket_id']; ?></a></div><?php endif; ?>
                                    <?php if (!$transfer['purchase_order_id'] && !$transfer['ticket_id']): ?>-<?php endif; ?>
                                </td>
                                <td><?= e($transfer['requested_by_name'] ?: 'System'); ?></td>
                                <td><?= e($transfer['approved_by_name'] ?: '-'); ?></td>
                                <td><?= renderAttachmentThumbnails($transferImages[(int)$transfer['id']] ?? []); ?></td>
                                <td class="text-end fw-bold"><?= $transfer['running_balance'] !== null ? number_format($transfer['running_balance'], 2) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="13" class="text-center text-muted py-4">No transaction history for this personal account.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
