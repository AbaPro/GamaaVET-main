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

/**
 * Page (relative to modules/finance/) showing the record a deletion request is
 * about. Child records open their parent (a payment opens its order). Returns
 * null once the record no longer exists, e.g. after an approved deletion.
 */
function financeDeletionRecordUrl($type, $id) {
    global $pdo;
    $id = (int)$id;
    $direct = [
        'safe' => 'safe_details.php', 'bank' => 'bank_details.php', 'personal' => 'personal_details.php',
        'expense' => 'expenses/details.php', 'transfer' => 'transfer_details.php',
        'customer_account' => '../customers/wallet.php', 'vendor_account' => '../vendors/wallet.php',
        'sales_invoice' => '../sales/order_details.php', 'purchase_invoice' => '../purchases/po_details.php',
    ];
    $tables = [
        'safe' => 'safes', 'bank' => 'bank_accounts', 'personal' => 'personal_accounts', 'expense' => 'expenses',
        'transfer' => 'finance_transfers', 'customer_account' => 'customers', 'vendor_account' => 'vendors',
        'sales_invoice' => 'orders', 'purchase_invoice' => 'purchase_orders',
    ];
    // [table, parent column, parent page]
    $children = [
        'customer_transaction' => ['customer_wallet_transactions', 'customer_id', '../customers/wallet.php'],
        'customer_adjustment' => ['customer_wallet_balance_adjustments', 'customer_id', '../customers/wallet.php'],
        'vendor_transaction' => ['vendor_wallet_transactions', 'vendor_id', '../vendors/wallet.php'],
        'order_payment' => ['order_payments', 'order_id', '../sales/order_details.php'],
        'purchase_payment' => ['purchase_order_payments', 'purchase_order_id', '../purchases/po_details.php'],
        'expense_payment' => ['expense_payments', 'expense_id', 'expenses/details.php'],
    ];
    try {
        if ($type === 'category') return 'expenses/categories.php';
        if (isset($direct[$type])) {
            $stmt = $pdo->prepare("SELECT id FROM `{$tables[$type]}` WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() ? $direct[$type] . '?id=' . $id : null;
        }
        if (isset($children[$type])) {
            [$table, $column, $page] = $children[$type];
            $stmt = $pdo->prepare("SELECT `$column` FROM `$table` WHERE id = ?");
            $stmt->execute([$id]);
            $parentId = (int)$stmt->fetchColumn();
            return $parentId > 0 ? $page . '?id=' . $parentId : null;
        }
        if ($type === 'account_adjustment') {
            $stmt = $pdo->prepare('SELECT account_type, account_id FROM finance_account_balance_adjustments WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $pages = ['safe' => 'safe_details.php', 'bank' => 'bank_details.php', 'personal' => 'personal_details.php'];
            return $row && isset($pages[$row['account_type']]) ? $pages[$row['account_type']] . '?id=' . (int)$row['account_id'] : null;
        }
    } catch (PDOException $e) {
        return null;
    }
    return null;
}

/**
 * Human-facing reference for a request's record: sales invoices show the order
 * number (ORD-...) used on Order Details instead of the internal row id.
 */
function financeDeletionRecordLabel($type, $id) {
    global $pdo;
    $id = (int)$id;
    if ($type === 'sales_invoice') {
        try {
            $stmt = $pdo->prepare('SELECT internal_id FROM orders WHERE id = ?');
            $stmt->execute([$id]);
            $number = $stmt->fetchColumn();
            if ($number) return '#' . $number;
        } catch (PDOException $e) {
        }
    }
    return '#' . $id;
}

function renderFinanceDeletionRequest($type, $id, $label = 'Request Deletion') {
    $permission = financeDeletionPermission($type);
    if (!$permission || !hasPermission($permission) || !hasPermission('finance.deletions.request')) return;
    ?>
    <form method="post" action="<?= htmlspecialchars(BASE_URL . 'modules/finance/delete_record.php') ?>" class="d-inline" onsubmit="return confirm('Submit this deletion for approval? Balances will be reversed only after an authorized user approves.');">
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
    setAlert('success', 'Deletion requested. An authorized user must approve it. Admins can approve their own requests.');
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
    if ((int)$row['requested_by'] === (int)$_SESSION['user_id'] && !isAdminUser()) throw new DomainException('You cannot approve your own deletion request.');
    financeDeletionQuery($db, "UPDATE finance_deletion_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [(int)$_SESSION['user_id'], (int)$row['id']]);
    // This update rolls back if any subsequent deletion or balance check fails.
}
