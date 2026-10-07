<?php

require_once __DIR__ . '/transfer_helpers.php';
require_once __DIR__ . '/deletion_approval.php';

function handleFinanceAccountDeletion($type, $canDelete, $returnPage, $canForceDelete = false) {
    global $conn;

    if (empty($_SESSION['finance_account_delete_token'])) {
        $_SESSION['finance_account_delete_token'] = bin2hex(random_bytes(32));
    }
    $isForce = isset($_POST['force_delete_account']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!isset($_POST['delete_account']) && !$isForce)) {
        return;
    }
    if ($isForce) {
        if (!$canForceDelete) {
            setAlert('danger', 'Access denied. You do not have permission to force-delete this account type.');
            redirect($returnPage);
        }
    } elseif (!$canDelete) {
        setAlert('danger', 'Access denied. You do not have permission to delete this account type.');
        redirect($returnPage);
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['finance_account_delete_token'], $token)) {
        setAlert('danger', 'Invalid request. Refresh the page and try again.');
        redirect($returnPage);
    }
    $id = filter_var($isForce ? $_POST['force_delete_account'] : $_POST['delete_account'], FILTER_VALIDATE_INT);
    $config = financeTransferAccountConfig($type);
    if (!$config || !$id || $id < 1) {
        setAlert('danger', 'Invalid account.');
        redirect($returnPage);
    }

    try {
        if (!financeTransferGetAccount($type, $id)) throw new DomainException('Account not found.');
        financeDeletionRequest($type, $id, $isForce, $returnPage);
    } catch (Throwable $error) {
        setAlert('danger', $error->getMessage());
        redirect($returnPage);
        return;
    }
    $conn->begin_transaction();
    try {
        financeDeletionApprove($conn, $type, $id, $isForce);
        $account = financeTransferGetAccount($type, $id, true);
        if (!$account) {
            throw new DomainException('Account not found.');
        }
        if ((float)$account['balance'] != 0.0) {
            throw new DomainException('Cannot delete an account with a nonzero balance.');
        }

        $linkedRecords = [];

        // Includes pending, rejected and reversed transfers: their account names
        // and approval/reversal destinations must remain available.
        $stmt = $conn->prepare('SELECT id FROM finance_transfers WHERE (from_type = ? AND from_id = ?) OR (to_type = ? AND to_id = ?) LIMIT 1 FOR UPDATE');
        $stmt->bind_param('sisi', $type, $id, $type, $id);
        $stmt->execute();
        $hasHistory = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($hasHistory) {
            if (!$isForce) {
                throw new DomainException('Cannot delete an account linked to transfers. Its financial history must be preserved.');
            }
            $linkedRecords[] = 'transfers';
        }

        $stmt = $conn->prepare('SELECT id FROM purchase_order_payments WHERE payment_source_type = ? AND payment_source_id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('si', $type, $id);
        $stmt->execute();
        $hasPayments = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($hasPayments) {
            if (!$isForce) {
                throw new DomainException('Cannot delete an account linked to PO payments. Its history must be preserved.');
            }
            $linkedRecords[] = 'PO payments';
        }

        if (tableExists('finance_account_balance_adjustments') && in_array($type, ['safe', 'bank', 'personal'], true)) {
            $stmt = $conn->prepare('SELECT id FROM finance_account_balance_adjustments WHERE account_type = ? AND account_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('si', $type, $id);
            $stmt->execute();
            $hasAdjustments = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($hasAdjustments) {
                if (!$isForce) {
                    throw new DomainException('Cannot delete an account with balance-adjustment history. Its audit trail must be preserved.');
                }
                $linkedRecords[] = 'balance adjustments';
            }
        }

        // Find direct payment references, including optional payment tables in
        // older installations. ON DELETE SET NULL would otherwise erase links.
        $column = ['safe' => 'safe_id', 'bank' => 'bank_account_id', 'personal' => 'personal_account_id'][$type];
        $stmt = $conn->prepare('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?');
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $tables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($tables as $table) {
            $tableName = str_replace('`', '``', $table['TABLE_NAME']);
            $stmt = $conn->prepare("SELECT `$column` FROM `$tableName` WHERE `$column` = ? LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $hasHistory = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($hasHistory) {
                if (!$isForce) {
                    throw new DomainException('Cannot delete an account linked to financial records. Its history must be preserved.');
                }
                $linkedRecords[] = $table['TABLE_NAME'];
            }
        }

        $stmt = $conn->prepare("DELETE FROM `{$config['table']}` WHERE id = ? AND balance = 0");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$deleted) {
            throw new DomainException('Account could not be deleted. Refresh and try again.');
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        if (!($error instanceof DomainException)) {
            error_log('Finance account deletion failed: ' . $error->getMessage());
        }
        setAlert('danger', $error instanceof DomainException ? $error->getMessage() : 'Unable to delete this account. Please try again.');
        redirect($returnPage);
        return;
    }

    $entityType = ['safe' => 'safe', 'bank' => 'bank_account', 'personal' => 'personal_account'][$type] ?? null;
    if ($isForce && $linkedRecords) {
        logActivity('Force-deleted finance account', ['type' => $type, 'id' => $id, 'name' => $account['account_name'], 'linked_records' => $linkedRecords], 'delete', $entityType, $id);
        setAlert('warning', 'Account force-deleted. It was linked to: ' . implode(', ', array_unique($linkedRecords)) . '. Those historical records now reference a deleted account.');
    } else {
        logActivity('Deleted finance account', ['type' => $type, 'id' => $id, 'name' => $account['account_name']], 'delete', $entityType, $id);
        setAlert('success', 'Account deleted.');
    }
    redirect($returnPage);
}

function renderFinanceAccountDeleteButton(array $account, $canForceDelete = false) {
    if ((float)$account['balance'] != 0.0) {
        echo '<span class="d-inline-block"><button type="button" class="btn btn-sm btn-danger" disabled>Delete</button><small class="d-block text-muted">Balance must be zero to delete.</small></span>';
        return;
    }
    ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Request approval to delete this account? Accounts linked to financial records cannot be deleted.');">
        <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['finance_account_delete_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" name="delete_account" value="<?= (int)$account['id']; ?>" class="btn btn-sm btn-danger">Request Deletion</button>
    </form>
    <?php if ($canForceDelete): ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Request approval to force delete this account even if it is linked to transfers, PO payments, or other financial records? Those historical records will remain but will point to a deleted account. This cannot be undone. Continue?');">
        <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['finance_account_delete_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" name="force_delete_account" value="<?= (int)$account['id']; ?>" class="btn btn-sm btn-outline-danger" title="Force delete even if linked to financial history">Request Force Delete</button>
    </form>
    <?php endif; ?>
    <?php
}
