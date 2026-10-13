<?php
// All executions use the same connection/transaction as the financial deletion.
function financeDeletionQuery($db, $sql, array $args = []) {
    $stmt = $db->prepare($sql);
    if ($db instanceof PDO) {
        $stmt->execute($args);
    } else {
        if ($args) $stmt->bind_param(str_repeat('s', count($args)), ...$args);
        $stmt->execute();
    }
    return $stmt;
}

function financeDeletionToken() {
    if (empty($_SESSION['finance_deletion_token'])) $_SESSION['finance_deletion_token'] = bin2hex(random_bytes(32));
    return $_SESSION['finance_deletion_token'];
}

function financeDeletionValidateToken() {
    $token = $_POST['deletion_token'] ?? '';
    if (!is_string($token) || !hash_equals(financeDeletionToken(), $token)) {
        throw new DomainException('Invalid deletion request. Refresh and try again.');
    }
}

function financeDeletionPermission($type, $force = false) {
    $accounts = ['safe' => 'finance.safes', 'bank' => 'finance.bank_accounts', 'personal' => 'finance.personal_accounts'];
    if (isset($accounts[$type])) return $accounts[$type] . ($force ? '.force_delete' : '.delete');
    if ($force) return null;
    return [
        'expense' => 'finance.expenses.manage', 'category' => 'finance.expenses.categories',
        'customer_transaction' => 'finance.customer_transactions.delete',
        'vendor_transaction' => 'finance.vendor_transactions.delete',
        'order_payment' => 'finance.payments.delete',
        'purchase_payment' => 'finance.purchase_payments.delete',
        'expense_payment' => 'finance.expense_payments.delete',
        'transfer' => 'finance.transfers.delete',
        'customer_adjustment' => 'finance.balance_adjustments.delete',
        'account_adjustment' => 'finance.balance_adjustments.delete',
        'customer_account' => 'finance.customer_accounts.delete',
        'vendor_account' => 'finance.vendor_accounts.delete',
        'sales_invoice' => 'sales.orders.delete',
        'purchase_invoice' => 'purchases.orders.delete',
    ][$type] ?? null;
}

function renderFinanceDeletionRequest($type, $id, $label = 'Request Deletion') {
    $permission = financeDeletionPermission($type);
    if (!$permission || !hasPermission($permission) || !hasPermission('finance.deletions.request')) return;
    ?>
    <form method="post" action="<?= htmlspecialchars(BASE_URL . 'modules/finance/delete_record.php') ?>" class="d-inline" onsubmit="return confirm('Submit this deletion for approval? Balances will be reversed only after another authorized user approves.');">
        <input type="hidden" name="entity_type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="record_id" value="<?= (int)$id ?>">
        <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken()) ?>">
        <button class="btn btn-sm btn-outline-danger" type="submit"><?= htmlspecialchars($label) ?></button>
    </form>
    <?php
}

function renderFinanceAccountClosure($type, array $account) {
    if (!hasPermission(financeDeletionPermission($type))) return;
    if (empty($account['finance_account_closed_at'])) {
        renderFinanceDeletionRequest($type, $account['id'], 'Request Account Closure');
        return;
    }
    ?>
    <span class="badge bg-secondary">Finance account closed</span>
    <form method="post" action="<?= htmlspecialchars(BASE_URL . 'modules/finance/reopen_account.php') ?>" class="d-inline">
        <input type="hidden" name="entity_type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="record_id" value="<?= (int)$account['id'] ?>">
        <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken()) ?>">
        <button class="btn btn-sm btn-outline-primary" type="submit">Reopen Finance Account</button>
    </form>
    <?php
}

function financeDeletionCheckPermission($type, $force) {
    $permission = financeDeletionPermission($type, $force);
    if (!$permission || !hasPermission($permission)) throw new DomainException('Permission for this finance deletion action is required.');
}

// Returns only for an approval attempt; ordinary deletion actions create requests.
function financeCreateDeletionRequest($type, $id, $force = false) {
    global $pdo;
    financeDeletionValidateToken();
    financeDeletionCheckPermission($type, $force);
    if (!hasPermission('finance.deletions.request')) throw new DomainException('Finance deletion request permission is required.');
    $stmt = $pdo->prepare("INSERT INTO finance_deletion_requests (account_id, entity_type, entity_id, force_delete, requested_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([getCurrentAccountId(), $type, $id, (int)$force, (int)$_SESSION['user_id']]);
    logActivity('Requested finance deletion', ['type' => $type, 'id' => $id, 'force' => $force], 'create', 'finance_deletion_request', (int)$pdo->lastInsertId());
    return (int)$pdo->lastInsertId();
}

function financeDeletionRequest($type, $id, $force, $returnPage) {
    financeDeletionValidateToken();
    financeDeletionCheckPermission($type, $force);
    if (isset($_POST['deletion_request_id'])) return;
    financeCreateDeletionRequest($type, $id, $force);
    setAlert('success', 'Deletion requested. Another user with Finance deletion approval permission must approve it.');
    redirect($returnPage);
    exit;
}

function financeDeletionApprove($db, $type, $id, $force = false) {
    financeDeletionValidateToken();
    financeDeletionCheckPermission($type, $force);
    if (!hasPermission('finance.deletions.approve')) throw new DomainException('Finance deletion approval permission is required.');
    $stmt = financeDeletionQuery($db, "SELECT * FROM finance_deletion_requests WHERE id = ? AND account_id = ? FOR UPDATE", [(int)($_POST['deletion_request_id'] ?? 0), getCurrentAccountId()]);
    $row = $db instanceof PDO ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->get_result()->fetch_assoc();
    if (!$row || $row['status'] !== 'pending' || $row['entity_type'] !== $type || (int)$row['entity_id'] !== (int)$id || (bool)$row['force_delete'] !== (bool)$force) {
        throw new DomainException('This deletion request is invalid or has already been reviewed.');
    }
    if ((int)$row['requested_by'] === (int)$_SESSION['user_id']) throw new DomainException('You cannot approve your own deletion request.');
    financeDeletionQuery($db, "UPDATE finance_deletion_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [(int)$_SESSION['user_id'], (int)$row['id']]);
    // This update rolls back if any subsequent deletion or balance check fails.
}
