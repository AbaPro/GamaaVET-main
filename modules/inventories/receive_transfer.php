<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// The receiver confirming a transfer is what actually moves stock:
// source is deducted and destination credited atomically here, not at creation.
if (!hasPermission('inventories.transfer.receive')) {
    setAlert('danger', 'You do not have permission to receive transfers.');
    redirect('transfers_list.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid transfer.');
    redirect('transfers_list.php');
}

$transfer_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'] ?? null;
$now = date('Y-m-d H:i:s');

if (!canAccessInventoryTransfer($transfer_id)) {
    setAlert('danger', 'Transfer not found in the currently selected region.');
    redirect('transfers_list.php');
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
        throw new Exception('Only pending transfers can be received.');
    }

    // Only the assigned receiver may confirm; admins may act on their behalf.
    if ((int)$transfer['received_by'] !== (int)$user_id && !isAdminUser()) {
        throw new Exception('This transfer is assigned to another user for receiving.');
    }

    // Throws (and rolls the whole thing back) if any line is short, so a transfer
    // is never partially applied and stock never goes negative.
    $stockLogs = applyTransferStockMovement($transfer, 'out');

    $update_stmt = $conn->prepare("
        UPDATE inventory_transfers
        SET status = 'transferred',
            received_by = ?,
            received_at = ?,
            transferred_by = ?,
            transferred_at = ?,
            rejected_by = NULL,
            rejected_at = NULL,
            rejection_reason = NULL
        WHERE id = ?
    ");
    $update_stmt->bind_param("isisi", $user_id, $now, $user_id, $now, $transfer_id);
    $update_stmt->execute();
    $update_stmt->close();

    $conn->commit();

    writeTransferStockLogs($stockLogs, 'inventory_transfer', $transfer_id);

    logTransferHistory($transfer_id, 'transferred', 'status', 'pending', 'transferred', 'Receiver confirmed the transfer; stock moved');
    logActivity("Received inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']}) - stock moved");

    // Notify everyone who can verify, plus the original sender.
    foreach (getRoleIdsWithPermission('inventories.transfer.verify') as $roleId) {
        createNotification(
            'inventory_transfer_verify',
            'Transfer awaiting verification',
            'Transfer ' . $transfer['transfer_reference'] . ' has been received and needs verification.',
            'inventories',
            'inventory_transfer',
            $transfer_id,
            'info',
            $roleId,
            null,
            $user_id
        );
    }

    if (!empty($transfer['requested_by']) && (int)$transfer['requested_by'] !== (int)$user_id) {
        createNotification(
            'inventory_transfer_received',
            'Your transfer was received',
            'Transfer ' . $transfer['transfer_reference'] . ' has been confirmed by the receiver and is awaiting verification.',
            'inventories',
            'inventory_transfer',
            $transfer_id,
            'info',
            null,
            (int)$transfer['requested_by'],
            $user_id
        );
    }

    setAlert('success', 'Transfer received. Stock has been moved and it is now awaiting verification.');
} catch (Exception $e) {
    $conn->rollback();
    setAlert('danger', 'Error receiving transfer: ' . $e->getMessage());
}

redirect('transfer_details.php?id=' . $transfer_id);
exit;
