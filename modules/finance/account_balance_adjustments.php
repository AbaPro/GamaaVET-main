<?php

require_once __DIR__ . '/transfer_helpers.php';

function financeAccountFormToken() {
    if (empty($_SESSION['finance_account_form_token'])) {
        $_SESSION['finance_account_form_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['finance_account_form_token'];
}

function validateFinanceAccountFormToken($returnUrl) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = financeAccountFormToken();
    if (!is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
        setAlert('danger', 'Invalid request. Refresh the page and try again.');
        redirect($returnUrl);
    }
}

function financeAccountCurrencies() {
    global $conn;

    $currencies = [];
    if (tableExists('currencies')) {
        $result = $conn->query('SELECT code, name, symbol FROM currencies ORDER BY is_default DESC, code');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $currencies[$row['code']] = $row;
            }
        }
    }

    if (!$currencies) {
        $currencies['EGP'] = ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'ج.م'];
    }
    return $currencies;
}

function financeAccountBalanceAdjustmentStorageReady() {
    return tableExists('finance_account_balance_adjustments');
}

function financeAccountBalanceAdjustmentTypeReady($type) {
    global $conn;

    if (!financeAccountBalanceAdjustmentStorageReady()) {
        return false;
    }

    static $columnType = null;
    if ($columnType === null) {
        $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_account_balance_adjustments' AND COLUMN_NAME = 'account_type' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $columnType = (string)($row['COLUMN_TYPE'] ?? '');
    }

    return strpos($columnType, "'" . str_replace("'", "''", (string)$type) . "'") !== false;
}

function canSettleFinanceBalances() {
    return hasPermission('finance.balances.settle');
}

function financeBalanceAccountConfig($type) {
    $configs = [
        'safe' => ['table' => 'safes', 'name' => 'name', 'balance' => 'balance', 'currency' => 'currency', 'scoped' => true],
        'bank' => ['table' => 'bank_accounts', 'name' => 'bank_name', 'balance' => 'balance', 'currency' => 'currency', 'scoped' => true],
        'personal' => ['table' => 'personal_accounts', 'name' => 'name', 'balance' => 'balance', 'currency' => null, 'scoped' => true],
        'vendor' => ['table' => 'vendors', 'name' => 'name', 'balance' => 'wallet_balance', 'currency' => null, 'scoped' => false],
    ];
    return $configs[$type] ?? null;
}

function financeBalanceGetAccount($type, $accountId, $forUpdate = false) {
    global $conn;

    $config = financeBalanceAccountConfig($type);
    $accountId = (int)$accountId;
    if (!$config || $accountId <= 0) {
        return null;
    }

    $currencySql = $config['currency'] ? "`{$config['currency']}`" : "'EGP'";
    $scopeSql = $config['scoped'] ? ' AND ' . getAccountScopeSql() : '';
    $sql = "SELECT id, `{$config['name']}` AS account_name, `{$config['balance']}` AS balance,
                   $currencySql AS currency
            FROM `{$config['table']}`
            WHERE id = ?$scopeSql" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $account ?: null;
}

function financeAccountHasFinancialHistory($type, $accountId) {
    global $conn;

    if (!in_array($type, ['safe', 'bank'], true) || (int)$accountId <= 0) {
        return false;
    }

    $accountId = (int)$accountId;
    $column = $type === 'safe' ? 'safe_id' : 'bank_account_id';
    foreach (['order_payments', 'customer_wallet_transactions', 'expense_payments'] as $table) {
        if (!tableExists($table)) continue;
        $stmt = $conn->prepare("SELECT id FROM `$table` WHERE `$column` = ? LIMIT 1");
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }

    if (tableExists('finance_transfers')) {
        $stmt = $conn->prepare('SELECT id FROM finance_transfers WHERE (from_type = ? AND from_id = ?) OR (to_type = ? AND to_id = ?) LIMIT 1');
        $stmt->bind_param('sisi', $type, $accountId, $type, $accountId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }

    if (tableExists('purchase_order_payments')) {
        $stmt = $conn->prepare('SELECT id FROM purchase_order_payments WHERE payment_source_type = ? AND payment_source_id = ? LIMIT 1');
        $stmt->bind_param('si', $type, $accountId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }

    if (financeAccountBalanceAdjustmentStorageReady()) {
        $stmt = $conn->prepare('SELECT id FROM finance_account_balance_adjustments WHERE account_type = ? AND account_id = ? LIMIT 1');
        $stmt->bind_param('si', $type, $accountId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }

    return false;
}

function handleFinanceAccountBalanceSettlement($type, $accountId, $canSetBalance, $returnUrl) {
    global $conn;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['set_finance_account_balance'])) {
        return;
    }
    if (!$canSetBalance) {
        setAlert('danger', 'You do not have permission to set this account balance.');
        redirect($returnUrl);
    }

    validateFinanceAccountFormToken($returnUrl);

    if (!financeAccountBalanceAdjustmentTypeReady($type)) {
        setAlert('danger', 'The finance account balance adjustment migration has not been applied yet.');
        redirect($returnUrl);
    }

    $config = financeBalanceAccountConfig($type);
    $accountId = (int)$accountId;
    $newBalanceInput = trim((string)($_POST['new_balance'] ?? ''));
    $reason = trim(strip_tags((string)($_POST['adjustment_reason'] ?? '')));
    $transactionDate = normalizeTransactionDate($_POST['transaction_date'] ?? '');

    if (!$config || $accountId <= 0) {
        setAlert('danger', 'Invalid finance account.');
        redirect($returnUrl);
    }
    if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $newBalanceInput)) {
        setAlert('danger', 'Enter a valid balance between -999,999,999,999.99 and 999,999,999,999.99.');
        redirect($returnUrl);
    }
    if ($reason === '') {
        setAlert('danger', 'A reason is required when setting an account balance.');
        redirect($returnUrl);
    }
    if ($transactionDate === null) {
        setAlert('danger', 'Enter a valid transaction date.');
        redirect($returnUrl);
    }
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($reasonLength > 500) {
        setAlert('danger', 'The adjustment reason cannot exceed 500 characters.');
        redirect($returnUrl);
    }

    $newBalance = round((float)$newBalanceInput, 2);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $conn->begin_transaction();
    try {
        $account = financeBalanceGetAccount($type, $accountId, true);
        if (!$account) {
            throw new DomainException('Account not found.');
        }
        $previousBalance = round((float)$account['balance'], 2);
        if (abs($previousBalance - $newBalance) < 0.005) {
            $conn->rollback();
            setAlert('info', 'The account balance is already ' . number_format($newBalance, 2) . '.');
            redirect($returnUrl);
        }

        $table = $config['table'];
        $balanceColumn = $config['balance'];
        $updateStmt = $conn->prepare("UPDATE `$table` SET `$balanceColumn` = ? WHERE id = ?");
        $updateStmt->bind_param('di', $newBalance, $accountId);
        $updateStmt->execute();
        if ($updateStmt->affected_rows !== 1) {
            $updateStmt->close();
            throw new RuntimeException('The account balance could not be updated.');
        }
        $updateStmt->close();

        $currency = $account['currency'] ?? 'EGP';

        $adjustmentStmt = $conn->prepare("
            INSERT INTO finance_account_balance_adjustments
                (account_type, account_id, previous_balance, new_balance, currency, reason, transaction_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $adjustmentStmt->bind_param('siddsssi', $type, $accountId, $previousBalance, $newBalance, $currency, $reason, $transactionDate, $userId);
        $adjustmentStmt->execute();
        $adjustmentId = $adjustmentStmt->insert_id;
        $adjustmentStmt->close();

        logActivity('Set finance account balance directly', [
            'account_type' => $type,
            'account_id' => $accountId,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'currency' => $currency,
            'reason' => $reason,
            'adjustment_id' => $adjustmentId,
        ], 'update', ['safe' => 'safe', 'bank' => 'bank_account', 'personal' => 'personal_account'][$type] ?? $type, $accountId);

        $conn->commit();
        setAlert('success', 'Balance updated from ' . number_format($previousBalance, 2) . ' to ' . number_format($newBalance, 2) . ' ' . $currency . '.');
    } catch (Throwable $error) {
        $conn->rollback();
        if (!($error instanceof DomainException)) {
            error_log('Finance account balance settlement failed: ' . $error->getMessage());
        }
        setAlert('danger', $error instanceof DomainException ? $error->getMessage() : 'Unable to update the balance. Please try again.');
    }

    redirect($returnUrl);
}

function financeAccountBalanceAdjustments($type, $accountId) {
    global $conn;

    if (!financeAccountBalanceAdjustmentTypeReady($type)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT a.*, u.name AS created_by_name
        FROM finance_account_balance_adjustments a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.account_type = ? AND a.account_id = ?
        ORDER BY a.transaction_date DESC, a.created_at DESC, a.id DESC
    ");
    $stmt->bind_param('si', $type, $accountId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
