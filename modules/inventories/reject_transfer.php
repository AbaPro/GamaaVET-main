<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Receiver-side rejection of a pending transfer. No stock has moved at this
// point, so nothing needs reversing - only the status and the reason are stored.
if (!hasPermission('inventories.transfer.receive')) {
    setAlert('danger', 'You do not have permission to reject transfers.');
    redirect('transfers_list.php');
}

$transfer_id = (int)($_POST['transfer_id'] ?? $_GET['id'] ?? 0);
$reason = trim(sanitize($_POST['rejection_reason'] ?? ''));
$user_id = $_SESSION['user_id'] ?? null;
$now = date('Y-m-d H:i:s');

if ($transfer_id <= 0) {
    setAlert('danger', 'Invalid transfer.');
    redirect('transfers_list.php');
}

if (!canAccessInventoryTransfer($transfer_id)) {
    setAlert('danger', 'Transfer not found in the currently selected region.');
    redirect('transfers_list.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setAlert('danger', 'Invalid request.');
    redirect('transfer_details.php?id=' . $transfer_id);
}

if ($reason === '') {
    setAlert('danger', 'Please provide a reason for rejecting this transfer.');
    redirect('transfer_details.php?id=' . $transfer_id);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("SELECT * FROM inventory_transfers WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $transfer_id);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transfer) {
        throw new Exception('Transfer not found.');
    }

    if ($transfer['status'] !== 'pending') {
        throw new Exception('Only pending transfers can be rejected.');
    }

    if ((int)$transfer['received_by'] !== (int)$user_id && !isAdminUser()) {
        throw new Exception('This transfer is assigned to another user.');
    }

    $update = $conn->prepare("
        UPDATE inventory_transfers
        SET status = 'rejected',
            rejected_by = ?,
            rejected_at = ?,
            rejection_reason = ?
        WHERE id = ?
    ");
    $update->bind_param("issi", $user_id, $now, $reason, $transfer_id);
    $update->execute();
    $update->close();

    $conn->commit();

    logTransferHistory($transfer_id, 'rejected', 'status', 'pending', 'rejected', $reason);
    logActivity("Rejected inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']})");

    if (!empty($transfer['requested_by']) && (int)$transfer['requested_by'] !== (int)$user_id) {
        createNotification(
            'inventory_transfer_rejected',
            'Your transfer was rejected',
            'Transfer ' . $transfer['transfer_reference'] . ' was rejected by the receiver. Reason: ' . $reason,
            'inventories',
            'inventory_transfer',
            $transfer_id,
            'warning',
            null,
            (int)$transfer['requested_by'],
            $user_id
        );
    }

    setAlert('success', 'Transfer rejected. No stock was moved.');
} catch (Exception $e) {
    $conn->rollback();
    setAlert('danger', 'Error rejecting transfer: ' . $e->getMessage());
}

redirect('transfer_details.php?id=' . $transfer_id);
exit;
