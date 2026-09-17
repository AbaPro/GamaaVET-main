<?php

function financeTransferTypeLabel($type) {
    return [
        'safe' => 'Safe',
        'bank' => 'Bank',
        'personal' => 'Personal',
    ][$type] ?? ucfirst((string)$type);
}

function financeTransferAccountConfig($type) {
    $configs = [
        'safe' => ['table' => 'safes', 'name' => 'name', 'balance' => 'balance', 'currency' => 'currency'],
        'bank' => ['table' => 'bank_accounts', 'name' => 'bank_name', 'balance' => 'balance', 'currency' => 'currency'],
        'personal' => ['table' => 'personal_accounts', 'name' => 'name', 'balance' => 'balance', 'currency' => null],
    ];
    return $configs[$type] ?? null;
}

function financeTransferGetAccount($type, $id, $forUpdate = false) {
    global $conn;
    $config = financeTransferAccountConfig($type);
    $id = (int)$id;
    if (!$config || $id <= 0) return null;

    $currencySql = $config['currency'] ? "`{$config['currency']}`" : "'EGP'";
    $scope = getAccountScopeSql();
    $sql = "SELECT id, `{$config['name']}` AS account_name, `{$config['balance']}` AS balance,
                   $currencySql AS currency
            FROM `{$config['table']}` WHERE id = ? AND $scope" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * SQL condition ensuring both ends of a transfer belong to the current brand.
 * A personal id of zero is the external/customer-payment pseudo account and is
 * allowed only when the opposite endpoint is a real in-scope account.
 */
function financeTransferScopeSql($transferAlias = 'f') {
    $accountScope = getAccountScopeSql('scope_account');
    $sideScope = static function ($typeColumn, $idColumn) use ($accountScope) {
        return "(
            ($typeColumn = 'safe' AND EXISTS (
                SELECT 1 FROM safes scope_account WHERE scope_account.id = $idColumn AND $accountScope
            )) OR
            ($typeColumn = 'bank' AND EXISTS (
                SELECT 1 FROM bank_accounts scope_account WHERE scope_account.id = $idColumn AND $accountScope
            )) OR
            ($typeColumn = 'personal' AND $idColumn = 0) OR
            ($typeColumn = 'personal' AND EXISTS (
                SELECT 1 FROM personal_accounts scope_account WHERE scope_account.id = $idColumn AND $accountScope
            ))
        )";
    };

    $fromScope = $sideScope("$transferAlias.from_type", "$transferAlias.from_id");
    $toScope = $sideScope("$transferAlias.to_type", "$transferAlias.to_id");
    return "($fromScope AND $toScope AND ($transferAlias.from_id > 0 OR $transferAlias.to_id > 0))";
}

function isFinanceTransferInCurrentAccount($transferId) {
    global $conn;

    $transferId = (int)$transferId;
    if ($transferId <= 0) return false;
    $scope = financeTransferScopeSql('f');
    $stmt = $conn->prepare("SELECT f.id FROM finance_transfers f WHERE f.id = ? AND $scope LIMIT 1");
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $allowed;
}

function financeTransferAdjustBalance($type, $id, $delta) {
    global $conn;
    $config = financeTransferAccountConfig($type);
    $id = (int)$id;
    $delta = round((float)$delta, 2);
    if (!$config || $id <= 0 || abs($delta) < 0.005) return false;

    if ($delta < 0) {
        $required = abs($delta);
        $sql = "UPDATE `{$config['table']}`
                SET `{$config['balance']}` = `{$config['balance']}` - ?
                WHERE id = ? AND `{$config['balance']}` >= ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('did', $required, $id, $required);
    } else {
        $sql = "UPDATE `{$config['table']}`
                SET `{$config['balance']}` = `{$config['balance']}` + ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('di', $delta, $id);
    }

    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    return $changed;
}

function financeTransferAccountName($row, $side) {
    $type = $row[$side . '_type'] ?? '';
    $id = (int)($row[$side . '_id'] ?? 0);
    $name = $row[$side . '_account_name'] ?? null;
    if ($type === 'personal' && $id === 0) return 'External / Customer Payment';
    return $name ?: financeTransferTypeLabel($type) . ' #' . $id;
}

function financeTransferAccountMeta($row, $side) {
    $type = $row[$side . '_type'] ?? '';
    $id = (int)($row[$side . '_id'] ?? 0);
    if ($type === 'bank' && !empty($row[$side . '_account_number'])) {
        return 'Account #' . $row[$side . '_account_number'];
    }
    if ($type === 'personal' && !empty($row[$side . '_account_email'])) {
        return $row[$side . '_account_email'];
    }
    return financeTransferTypeLabel($type) . ($id > 0 ? ' #' . $id : '');
}

function financeTransferAccountUrl($type, $id) {
    $id = (int)$id;
    if ($id <= 0) return null;
    if ($type === 'safe') return 'safe_details.php?id=' . $id;
    if ($type === 'bank') return 'bank_details.php?id=' . $id;
    if ($type === 'personal') return 'personal_details.php?id=' . $id;
    return null;
}

function logFinanceTransferHistory($transferId, $action, $note = null, $createdBy = null) {
    global $conn;
    if (!tableExists('finance_transfer_history')) return;

    $transferId = (int)$transferId;
    $action = substr((string)$action, 0, 50);
    $note = $note !== null ? (string)$note : null;
    $createdBy = $createdBy !== null ? (int)$createdBy : (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("
        INSERT INTO finance_transfer_history (finance_transfer_id, action, note, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('issi', $transferId, $action, $note, $createdBy);
    $stmt->execute();
    $stmt->close();
}

function financeTransferSelectSql() {
    return "SELECT f.*,
                   requester.name AS requested_by_name,
                   assigned.name AS assigned_approver_name,
                   approver.name AS approved_by_name,
                   rejecter.name AS rejected_by_name,
                   reverser.name AS reversed_by_name,
                   from_safe.name AS from_safe_name,
                   from_bank.bank_name AS from_bank_name,
                   from_bank.account_number AS from_bank_account_number,
                   from_personal.name AS from_personal_name,
                   from_personal.email AS from_personal_email,
                   to_safe.name AS to_safe_name,
                   to_bank.bank_name AS to_bank_name,
                   to_bank.account_number AS to_bank_account_number,
                   to_personal.name AS to_personal_name,
                   to_personal.email AS to_personal_email,
                   COALESCE(from_safe.currency, from_bank.currency, 'EGP') AS currency,
                   COALESCE(to_safe.currency, to_bank.currency, 'EGP') AS to_currency,
                   COALESCE(from_safe.name, from_bank.bank_name, from_personal.name) AS from_account_name,
                   from_bank.account_number AS from_account_number,
                   from_personal.email AS from_account_email,
                   COALESCE(to_safe.name, to_bank.bank_name, to_personal.name) AS to_account_name,
                   to_bank.account_number AS to_account_number,
                   to_personal.email AS to_account_email,
                   v.name AS purchase_order_vendor,
                   t.title AS ticket_title
            FROM finance_transfers f
            LEFT JOIN users requester ON requester.id = f.created_by
            LEFT JOIN users assigned ON assigned.id = f.assigned_approver_id
            LEFT JOIN users approver ON approver.id = f.approved_by
            LEFT JOIN users rejecter ON rejecter.id = f.rejected_by
            LEFT JOIN users reverser ON reverser.id = f.reversed_by
            LEFT JOIN safes from_safe ON f.from_type = 'safe' AND f.from_id = from_safe.id
            LEFT JOIN bank_accounts from_bank ON f.from_type = 'bank' AND f.from_id = from_bank.id
            LEFT JOIN personal_accounts from_personal ON f.from_type = 'personal' AND f.from_id = from_personal.id
            LEFT JOIN safes to_safe ON f.to_type = 'safe' AND f.to_id = to_safe.id
            LEFT JOIN bank_accounts to_bank ON f.to_type = 'bank' AND f.to_id = to_bank.id
            LEFT JOIN personal_accounts to_personal ON f.to_type = 'personal' AND f.to_id = to_personal.id
            LEFT JOIN purchase_orders po ON po.id = f.purchase_order_id
            LEFT JOIN vendors v ON v.id = po.vendor_id
            LEFT JOIN tickets t ON t.id = f.ticket_id";
}
