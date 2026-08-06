<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Manager sign-off on a received transfer, plus the "send back" path.
// Verification itself moves no stock; sending back DOES reverse the movement,
// because stock already changed hands when the receiver confirmed.
if (!hasPermission('inventories.transfer.verify')) {
    setAlert('danger', 'You do not have permission to verify transfers.');
    redirect('transfers_list.php');
}

$transfer_id = (int)($_POST['transfer_id'] ?? $_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'verify';
$reason = trim(sanitize($_POST['send_back_reason'] ?? ''));
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

    if ($transfer['status'] !== 'transferred') {
        throw new Exception('Only received transfers can be verified or sent back.');
    }

    // A verifier signing off on their own receipt defeats the point of the step.
    $actedOnIt = (int)$transfer['received_by'] === (int)$user_id
        || (int)$transfer['transferred_by'] === (int)$user_id;
    if ($actedOnIt && !isAdminUser()) {
        throw new Exception('You received this transfer, so it must be verified by someone else.');
    }

    if ($action === 'send_back') {
        if ($reason === '') {
            throw new Exception('Please provide a reason when sending a transfer back.');
        }

        // Stock moved at receive time, so returning to 'pending' must undo it.
        $stockLogs = applyTransferStockMovement($transfer, 'in');

        $update = $conn->prepare("
            UPDATE inventory_transfers
            SET status = 'pending',
                transferred_by = NULL,
                transferred_at = NULL,
                received_at = NULL,
                verified_by = NULL,
                verified_at = NULL
            WHERE id = ?
        ");
        $update->bind_param("i", $transfer_id);
        $update->execute();
        $update->close();

        $conn->commit();

        writeTransferStockLogs($stockLogs, 'inventory_transfer_sent_back', $transfer_id);

        logTransferHistory($transfer_id, 'sent_back', 'status', 'transferred', 'pending', $reason);
        logActivity("Sent inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']}) back to the receiver - stock reversed");

        foreach ([$transfer['received_by'], $transfer['requested_by']] as $notifyUserId) {
            if (!empty($notifyUserId) && (int)$notifyUserId !== (int)$user_id) {
                createNotification(
                    'inventory_transfer_sent_back',
                    'Transfer sent back for correction',
                    'Transfer ' . $transfer['transfer_reference'] . ' was sent back by the verifier. Reason: ' . $reason,
                    'inventories',
                    'inventory_transfer',
                    $transfer_id,
                    'warning',
                    null,
                    (int)$notifyUserId,
                    $user_id
                );
            }
        }

        setAlert('success', 'Transfer sent back to the receiver. The stock movement has been reversed.');
        redirect('transfer_details.php?id=' . $transfer_id);
        exit;
    }

    // Plain verification: a sign-off flag only, no stock change.
    $update = $conn->prepare("
        UPDATE inventory_transfers
        SET status = 'verified',
            verified_by = ?,
            verified_at = ?
        WHERE id = ?
    ");
    $update->bind_param("isi", $user_id, $now, $transfer_id);
    $update->execute();
    $update->close();

    $conn->commit();

    logTransferHistory($transfer_id, 'verified', 'status', 'transferred', 'verified', 'Verified by manager');
    logActivity("Verified inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']})");

    foreach ([$transfer['requested_by'], $transfer['received_by']] as $notifyUserId) {
        if (!empty($notifyUserId) && (int)$notifyUserId !== (int)$user_id) {
            createNotification(
                'inventory_transfer_verified',
                'Transfer verified',
                'Transfer ' . $transfer['transfer_reference'] . ' has been verified.',
                'inventories',
                'inventory_transfer',
                $transfer_id,
                'info',
                null,
                (int)$notifyUserId,
                $user_id
            );
        }
    }

    setAlert('success', 'Transfer verified.');
} catch (Exception $e) {
    $conn->rollback();
    setAlert('danger', 'Error processing transfer: ' . $e->getMessage());
}

redirect('transfer_details.php?id=' . $transfer_id);
exit;
