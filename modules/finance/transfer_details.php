<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'transfer_helpers.php';

$canView = hasPermission('finance.transfers.create') || hasPermission('finance.transfers.approve');
if (!$canView) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$transferId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$transferId) {
    setAlert('danger', 'Invalid finance transfer.');
    redirect('transfers.php');
}

$stmt = $conn->prepare(financeTransferSelectSql() . ' WHERE f.id = ? LIMIT 1');
$stmt->bind_param('i', $transferId);
$stmt->execute();
$transfer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$transfer) {
    setAlert('danger', 'Finance transfer not found.');
    redirect('transfers.php');
}

$images = [];
$imageStmt = $conn->prepare("
    SELECT file_path, original_name
    FROM finance_transfer_images
    WHERE finance_transfer_id = ?
    ORDER BY created_at, id
");
$imageStmt->bind_param('i', $transferId);
$imageStmt->execute();
$images = $imageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$imageStmt->close();

$history = [];
if (tableExists('finance_transfer_history')) {
    $historyStmt = $conn->prepare("
        SELECT h.*, u.name AS created_by_name
        FROM finance_transfer_history h
        LEFT JOIN users u ON u.id = h.created_by
        WHERE h.finance_transfer_id = ?
        ORDER BY h.created_at, h.id
    ");
    $historyStmt->bind_param('i', $transferId);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$mayApprove = $transfer['status'] === 'pending'
    && hasPermission('finance.transfers.approve')
    && ((int)$transfer['assigned_approver_id'] === $currentUserId || isAdminUser())
    && ((int)$transfer['created_by'] !== $currentUserId || isAdminUser());
$fromAccountUrl = financeTransferAccountUrl($transfer['from_type'], $transfer['from_id']);
$toAccountUrl = financeTransferAccountUrl($transfer['to_type'], $transfer['to_id']);
$statusColors = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger', 'reversed' => 'secondary'];
$page_title = e($transfer['transfer_reference']);
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1"><?= e($transfer['transfer_reference']); ?></h2>
        <span class="badge bg-<?= $statusColors[$transfer['status']] ?? 'secondary'; ?> fs-6"><?= e(ucfirst($transfer['status'])); ?></span>
    </div>
    <a href="transfers.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Transfers</a>
</div>

<?php displayAlert(); ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0">Transfer Route</h5></div>
            <div class="card-body">
                <div class="row align-items-center text-center">
                    <div class="col-md-5">
                        <div class="text-muted text-uppercase small fw-bold">Sender</div>
                        <div class="h5 mt-2">
                            <?php if ($fromAccountUrl): ?><a href="<?= e($fromAccountUrl); ?>"><?= e(financeTransferAccountName($transfer, 'from')); ?></a>
                            <?php else: ?><?= e(financeTransferAccountName($transfer, 'from')); ?><?php endif; ?>
                        </div>
                        <span class="badge bg-secondary"><?= e(financeTransferTypeLabel($transfer['from_type'])); ?></span>
                    </div>
                    <div class="col-md-2 py-3"><i class="fas fa-arrow-right fa-2x text-primary"></i></div>
                    <div class="col-md-5">
                        <div class="text-muted text-uppercase small fw-bold">Receiver</div>
                        <div class="h5 mt-2">
                            <?php if ($toAccountUrl): ?><a href="<?= e($toAccountUrl); ?>"><?= e(financeTransferAccountName($transfer, 'to')); ?></a>
                            <?php else: ?><?= e(financeTransferAccountName($transfer, 'to')); ?><?php endif; ?>
                        </div>
                        <span class="badge bg-secondary"><?= e(financeTransferTypeLabel($transfer['to_type'])); ?></span>
                    </div>
                </div>
                <hr>
                <div class="text-center">
                    <div class="text-muted text-uppercase small fw-bold">Amount</div>
                    <div class="display-6 fw-bold"><?= e(formatCurrency((float)$transfer['amount'], $transfer['currency'] ?? 'EGP')); ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0">Reason and Supporting Documents</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Transaction Date</dt><dd class="col-sm-9"><?= date('M d, Y', strtotime($transfer['transaction_date'])); ?></dd>
                    <dt class="col-sm-3">Reason</dt><dd class="col-sm-9"><?= nl2br(e($transfer['reason'])); ?></dd>
                    <dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><?= $transfer['notes'] ? nl2br(e($transfer['notes'])) : '-'; ?></dd>
                    <dt class="col-sm-3">Purchase Order</dt>
                    <dd class="col-sm-9">
                        <?php if ($transfer['purchase_order_id']): ?>
                            <a href="../purchases/po_details.php?id=<?= (int)$transfer['purchase_order_id']; ?>">PO #<?= (int)$transfer['purchase_order_id']; ?> — <?= e($transfer['purchase_order_vendor']); ?></a>
                        <?php else: ?>-<?php endif; ?>
                    </dd>
                    <dt class="col-sm-3">Ticket</dt>
                    <dd class="col-sm-9">
                        <?php if ($transfer['ticket_id']): ?>
                            <a href="../tickets/view.php?id=<?= (int)$transfer['ticket_id']; ?>">Ticket #<?= (int)$transfer['ticket_id']; ?> — <?= e($transfer['ticket_title']); ?></a>
                        <?php else: ?>-<?php endif; ?>
                    </dd>
                    <dt class="col-sm-3">Images</dt><dd class="col-sm-9"><?= renderAttachmentThumbnails($images); ?></dd>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h5 class="mb-0">Audit Trail</h5></div>
            <div class="card-body">
                <?php if ($history): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($history as $event): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-semibold text-capitalize"><?= e(str_replace('_', ' ', $event['action'])); ?></span>
                                    <span class="text-muted small"><?= date('M d, Y H:i', strtotime($event['created_at'])); ?></span>
                                </div>
                                <div><?= e($event['note']); ?></div>
                                <div class="text-muted small">By <?= e($event['created_by_name'] ?: 'System'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted">No audit events recorded.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h5 class="mb-0">Authorization</h5></div>
            <div class="card-body">
                <div class="mb-3"><div class="text-muted small">Requested By</div><div class="fw-semibold"><?= e($transfer['requested_by_name'] ?: 'System'); ?></div></div>
                <div class="mb-3"><div class="text-muted small">Assigned Approver</div><div class="fw-semibold"><?= e($transfer['assigned_approver_name'] ?: '-'); ?></div></div>
                <div class="mb-3"><div class="text-muted small">Approved By</div><div class="fw-semibold"><?= e($transfer['approved_by_name'] ?: '-'); ?></div></div>
                <?php if ($transfer['approved_at']): ?><div class="mb-3"><div class="text-muted small">Approved At</div><div><?= date('M d, Y H:i', strtotime($transfer['approved_at'])); ?></div></div><?php endif; ?>
                <?php if ($transfer['rejected_by_name']): ?><div class="mb-3"><div class="text-muted small">Rejected By</div><div class="fw-semibold text-danger"><?= e($transfer['rejected_by_name']); ?></div></div><?php endif; ?>
                <?php if ($transfer['rejection_reason']): ?><div class="alert alert-danger py-2 mb-0"><strong>Rejection:</strong><br><?= nl2br(e($transfer['rejection_reason'])); ?></div><?php endif; ?>
                <?php if ($transfer['reversed_by_name']): ?><div class="mb-3"><div class="text-muted small">Reversed By</div><div class="fw-semibold"><?= e($transfer['reversed_by_name']); ?></div></div><?php endif; ?>
                <?php if ($transfer['reversed_at']): ?><div class="mb-3"><div class="text-muted small">Reversed At</div><div><?= date('M d, Y H:i', strtotime($transfer['reversed_at'])); ?></div></div><?php endif; ?>
                <?php if ($transfer['reversal_reason']): ?><div class="alert alert-secondary py-2 mb-0"><strong>Reversal:</strong><br><?= nl2br(e($transfer['reversal_reason'])); ?></div><?php endif; ?>
            </div>
        </div>

        <?php if ($mayApprove): ?>
            <div class="card border-warning shadow-sm">
                <div class="card-header bg-warning"><h5 class="mb-0">Approval Required</h5></div>
                <div class="card-body">
                    <p>Approving deducts the sender and credits the receiver in one database transaction.</p>
                    <form action="approve_transfer.php" method="post" onsubmit="return confirm('Approve this transfer and move the money now?');">
                        <input type="hidden" name="id" value="<?= (int)$transferId; ?>">
                        <button class="btn btn-success w-100 mb-3"><i class="fas fa-check me-1"></i>Approve and Move Money</button>
                    </form>
                    <form action="reject_transfer.php" method="post">
                        <input type="hidden" name="id" value="<?= (int)$transferId; ?>">
                        <label class="form-label" for="rejection_reason">Rejection Reason</label>
                        <textarea class="form-control mb-2" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        <button class="btn btn-outline-danger w-100"><i class="fas fa-times me-1"></i>Reject Transfer</button>
                    </form>
                </div>
            </div>
        <?php elseif ($transfer['status'] === 'pending'): ?>
            <div class="alert alert-warning">Waiting for <?= e($transfer['assigned_approver_name'] ?: 'the assigned approver'); ?>.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
