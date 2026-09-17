<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'transfer_helpers.php';

if (!hasPermission('finance.transfers.approve')) {
    setAlert('danger', 'You do not have permission to reject finance transfers.');
    redirect('transfers.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    setAlert('danger', 'Invalid rejection request.');
    redirect('transfers.php');
}

$transferId = (int)$_POST['id'];
$reason = trim(strip_tags((string)($_POST['rejection_reason'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!isFinanceTransferInCurrentAccount($transferId)) {
    setAlert('danger', 'Finance transfer not found.');
    redirect('transfers.php');
}
if ($reason === '') {
    setAlert('danger', 'Rejection reason is required.');
    redirect('transfer_details.php?id=' . $transferId);
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('SELECT * FROM finance_transfers WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transfer) throw new Exception('Finance transfer not found.');
    if ($transfer['status'] !== 'pending') throw new Exception('Only pending transfers can be rejected.');
    if ((int)$transfer['assigned_approver_id'] !== $userId && !isAdminUser()) {
        throw new Exception('This transfer is assigned to another approver.');
    }
    if ((int)$transfer['created_by'] === $userId && !isAdminUser()) {
        throw new Exception('You cannot reject your own transfer request.');
    }

    $now = date('Y-m-d H:i:s');
    $update = $conn->prepare("
        UPDATE finance_transfers
        SET status = 'rejected', rejected_by = ?, rejected_at = ?, rejection_reason = ?
        WHERE id = ? AND status = 'pending'
    ");
    $update->bind_param('issi', $userId, $now, $reason, $transferId);
    $update->execute();
    if ($update->affected_rows !== 1) throw new Exception('Transfer status changed before rejection completed.');
    $update->close();

    logFinanceTransferHistory($transferId, 'rejected', $reason, $userId);
    $conn->commit();

    if (!empty($transfer['created_by']) && (int)$transfer['created_by'] !== $userId) {
        createNotification(
            'finance_transfer_rejected',
            'Finance transfer rejected',
            $transfer['transfer_reference'] . ' was rejected: ' . $reason,
            'finance',
            'finance_transfer',
            $transferId,
            'danger',
            null,
            (int)$transfer['created_by'],
            $userId
        );
    }
    logActivity('Rejected finance transfer', [
        'finance_transfer_id' => $transferId,
        'reference' => $transfer['transfer_reference'],
        'reason' => $reason,
    ]);
    setAlert('success', 'Transfer rejected. No balances were changed.');
} catch (Throwable $e) {
    $conn->rollback();
    setAlert('danger', 'Unable to reject transfer: ' . $e->getMessage());
}

redirect('transfer_details.php?id=' . $transferId);
