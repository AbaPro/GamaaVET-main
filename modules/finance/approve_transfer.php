<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'transfer_helpers.php';

if (!hasPermission('finance.transfers.approve')) {
    setAlert('danger', 'You do not have permission to approve finance transfers.');
    redirect('transfers.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    setAlert('danger', 'Invalid approval request.');
    redirect('transfers.php');
}

$transferId = (int)$_POST['id'];
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!isFinanceTransferInCurrentAccount($transferId)) {
    setAlert('danger', 'Finance transfer not found.');
    redirect('transfers.php');
}
$conn->begin_transaction();

try {
    $stmt = $conn->prepare('SELECT * FROM finance_transfers WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transfer) throw new Exception('Finance transfer not found.');
    if ($transfer['status'] !== 'pending') throw new Exception('Only pending transfers can be approved.');
    if ((int)$transfer['assigned_approver_id'] !== $userId && !isAdminUser()) {
        throw new Exception('This transfer is assigned to another approver.');
    }
    if ((int)$transfer['created_by'] === $userId && !isAdminUser()) {
        throw new Exception('You cannot approve your own transfer request.');
    }

    // Personal id 0 is outside cash: a deposit has no sender to debit and a
    // withdrawal has no receiver to credit.
    $fromExternal = financeTransferIsExternal($transfer['from_type'], $transfer['from_id']);
    $toExternal = financeTransferIsExternal($transfer['to_type'], $transfer['to_id']);
    if ($fromExternal && $toExternal) throw new Exception('A transfer needs at least one real account.');
    $source = $fromExternal ? null : financeTransferGetAccount($transfer['from_type'], $transfer['from_id'], true);
    $receiver = $toExternal ? null : financeTransferGetAccount($transfer['to_type'], $transfer['to_id'], true);
    if (!$fromExternal && !$source) throw new Exception('Sender account no longer exists.');
    if (!$toExternal && !$receiver) throw new Exception('Receiver account no longer exists.');

    $sourceCurrency = $source['currency'] ?? $receiver['currency'] ?? 'EGP';
    $receiverCurrency = $receiver['currency'] ?? $sourceCurrency;
    $isExchange = financeTransferIsExchange($transfer);
    if ($sourceCurrency !== $receiverCurrency && !$isExchange) {
        throw new Exception('Sender and receiver currencies no longer match. The transfer cannot be approved.');
    }
    if ($sourceCurrency === $receiverCurrency && $isExchange) {
        throw new Exception('Sender and receiver now use the same currency, so the recorded exchange no longer applies. Reject and resubmit the transfer.');
    }

    $amount = round((float)$transfer['amount'], 2);
    $receivedAmount = financeTransferReceivedAmount($transfer);
    if ($amount <= 0 || $receivedAmount <= 0) throw new Exception('Transfer amount is invalid.');
    // Safes and bank accounts may go negative; personal accounts may not.
    if ($transfer['from_type'] === 'personal' && !$fromExternal && (float)$source['balance'] < $amount) {
        throw new Exception(
            'Insufficient balance in ' . $source['account_name'] . '. Available: '
            . number_format((float)$source['balance'], 2) . ' ' . $sourceCurrency . '.'
        );
    }

    if (!$fromExternal && !financeTransferAdjustBalance($transfer['from_type'], $transfer['from_id'], -$amount)) {
        throw new Exception('Could not deduct the sender balance. It may have changed; please review and try again.');
    }
    if (!$toExternal && !financeTransferAdjustBalance($transfer['to_type'], $transfer['to_id'], $receivedAmount)) {
        throw new Exception('Could not credit the receiver balance.');
    }

    $now = date('Y-m-d H:i:s');
    $update = $conn->prepare("
        UPDATE finance_transfers
        SET status = 'approved', approved_by = ?, approved_at = ?,
            rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL
        WHERE id = ? AND status = 'pending'
    ");
    $update->bind_param('isi', $userId, $now, $transferId);
    $update->execute();
    if ($update->affected_rows !== 1) throw new Exception('Transfer status changed before approval completed.');
    $update->close();

    logFinanceTransferHistory(
        $transferId,
        'approved',
        'Approved; ' . number_format($amount, 2) . ' ' . $sourceCurrency
            . ' moved from ' . ($source['account_name'] ?? 'external cash')
            . ' to ' . ($receiver['account_name'] ?? 'external cash')
            . ($isExchange ? ' (credited ' . number_format($receivedAmount, 2) . ' ' . $receiverCurrency . ')' : ''),
        $userId
    );
    $conn->commit();

    if (!empty($transfer['created_by']) && (int)$transfer['created_by'] !== $userId) {
        createNotification(
            'finance_transfer_approved',
            'Finance transfer approved',
            $transfer['transfer_reference'] . ' was approved and balances were updated.',
            'finance',
            'finance_transfer',
            $transferId,
            'info',
            null,
            (int)$transfer['created_by'],
            $userId
        );
    }
    logActivity('Approved finance transfer', [
        'finance_transfer_id' => $transferId,
        'reference' => $transfer['transfer_reference'],
        'amount' => $amount,
    ], 'update', 'finance_transfer', $transferId);
    setAlert('success', 'Transfer approved. Sender and receiver balances were updated atomically.');
} catch (Throwable $e) {
    $conn->rollback();
    setAlert('danger', 'Unable to approve transfer: ' . $e->getMessage());
}

redirect('transfer_details.php?id=' . $transferId);
