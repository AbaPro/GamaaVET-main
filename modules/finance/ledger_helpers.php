<?php
// Shared safe / bank account ledger. The safe and bank detail pages and the
// treasury movement report all read from here so their numbers always agree.
require_once __DIR__ . '/transfer_helpers.php';
require_once __DIR__ . '/account_balance_adjustments.php';
require_once __DIR__ . '/../purchases/payment_sources.php';

function financeLedgerCounterpartyName($row, $side) {
    $type = $row[$side . '_type'] ?? '';
    $id = (int)($row[$side . '_id'] ?? 0);

    if ($type === 'safe') {
        return $row[$side . '_safe_name'] ?: 'Safe #' . $id;
    }
    if ($type === 'bank') {
        return $row[$side . '_bank_name'] ?: 'Bank account #' . $id;
    }
    if ($type === 'personal') {
        if ($id === 0) {
            return financeTransferExternalLabel($row);
        }
        return $row[$side . '_personal_name'] ?: 'Personal account #' . $id;
    }

    return ucfirst((string)$type) . ($id > 0 ? ' #' . $id : '');
}

/**
 * Every movement of one safe or bank account, newest first, each with the
 * balance right after it. Returns transactions plus total_in / total_out.
 */
function financeAccountLedger($accountType, $accountId, $currency, $currentBalance) {
    global $conn;

    $accountId = (int)$accountId;
    $isSafe = $accountType === 'safe';
    $method = $isSafe ? 'cash' : 'transfer';
    $methodColumn = $isSafe ? 'safe_id' : 'bank_account_id';
    $transactions = [];

    // Customer order payments credit the account. The matching automatically
    // generated finance-transfer row is excluded below so each payment appears once.
    $customerScope = getCustomerChannelScopeSql('c', 'customer_factory');
    $orderPaymentStmt = $conn->prepare("
        SELECT op.id, op.amount, op.reference, op.notes, op.transaction_date, op.created_at,
               op.created_by, u.name AS created_by_name,
               o.id AS order_id, o.internal_id AS order_number, o.currency AS source_currency,
               c.name AS customer_name
        FROM order_payments op
        JOIN orders o ON o.id = op.order_id
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
        LEFT JOIN users u ON u.id = op.created_by
        WHERE op.payment_method = '$method' AND op.$methodColumn = ? AND $customerScope
    ");
    $orderPaymentStmt->bind_param('i', $accountId);
    $orderPaymentStmt->execute();
    $orderPayments = $orderPaymentStmt->get_result();
    while ($row = $orderPayments->fetch_assoc()) {
        $transactions[] = [
            'source_key' => 'order_payment_' . $row['id'],
            'sort_id' => (int)$row['id'],
            'transaction_date' => $row['transaction_date'],
            'created_at' => $row['created_at'],
            'type' => 'Customer Payment',
            'direction' => 'in',
            'amount' => (float)$row['amount'],
            'reference' => $row['reference'] ?: 'Order ' . $row['order_number'],
            'details' => $row['customer_name'] . ' — ' . $row['order_number'],
            'details_url' => '../sales/order_details.php?id=' . (int)$row['order_id'],
            'notes' => $row['notes'],
            'created_by_name' => $row['created_by_name'] ?: 'System',
            'attachments' => [],
            'source_currency' => $row['source_currency'] ?: $currency,
        ];
    }
    $orderPaymentStmt->close();

    // Manual customer-wallet deposits and payments also credit the selected account,
    // but do not create an order_payment or finance_transfer row.
    $walletPaymentStmt = $conn->prepare("
        SELECT wt.id, wt.type, wt.amount, wt.notes, wt.transaction_date, wt.created_at,
               u.name AS created_by_name,
               c.id AS customer_id, c.name AS customer_name
        FROM customer_wallet_transactions wt
        JOIN customers c ON c.id = wt.customer_id
        LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
        LEFT JOIN users u ON u.id = wt.created_by
        WHERE wt.type IN ('deposit', 'payment')
          AND wt.payment_method = '$method'
          AND wt.$methodColumn = ?
          AND $customerScope
    ");
    $walletPaymentStmt->bind_param('i', $accountId);
    $walletPaymentStmt->execute();
    $walletPayments = $walletPaymentStmt->get_result();
    while ($row = $walletPayments->fetch_assoc()) {
        $transactions[] = [
            'source_key' => 'wallet_payment_' . $row['id'],
            'sort_id' => (int)$row['id'],
            'transaction_date' => $row['transaction_date'],
            'created_at' => $row['created_at'],
            'type' => $row['type'] === 'deposit' ? 'Customer Wallet Deposit' : 'Customer Wallet Payment',
            'direction' => 'in',
            'amount' => (float)$row['amount'],
            'reference' => 'Wallet transaction #' . $row['id'],
            'details' => $row['customer_name'],
            'details_url' => '../customers/wallet.php?id=' . (int)$row['customer_id'],
            'notes' => $row['notes'],
            'created_by_name' => $row['created_by_name'] ?: 'System',
            'attachments' => [],
            'source_currency' => $currency,
        ];
    }
    $walletPaymentStmt->close();

    // Expense payments take money out. A linked purchase-order payment is another
    // view of this same payment, so expense_payments is the canonical row here.
    $expenseScope = getAccountScopeSql('e');
    $expensePaymentStmt = $conn->prepare("
        SELECT ep.id, ep.amount, ep.reference, ep.notes, ep.transaction_date, ep.created_at,
               u.name AS created_by_name,
               e.id AS expense_id, e.name AS expense_name, e.notes AS expense_notes, e.currency AS source_currency,
               v.name AS vendor_name
        FROM expense_payments ep
        JOIN expenses e ON e.id = ep.expense_id
        LEFT JOIN vendors v ON v.id = e.vendor_id
        LEFT JOIN users u ON u.id = ep.created_by
        WHERE ep.payment_method = '$method' AND ep.$methodColumn = ? AND $expenseScope
    ");
    $expensePaymentStmt->bind_param('i', $accountId);
    $expensePaymentStmt->execute();
    $expensePayments = $expensePaymentStmt->get_result();
    while ($row = $expensePayments->fetch_assoc()) {
        $details = $row['expense_name'];
        if (!empty($row['vendor_name'])) {
            $details .= ' — ' . $row['vendor_name'];
        }

        $transactions[] = [
            'source_key' => 'expense_payment_' . $row['id'],
            'sort_id' => (int)$row['id'],
            'transaction_date' => $row['transaction_date'],
            'created_at' => $row['created_at'],
            'type' => 'Expense Payment',
            'direction' => 'out',
            'amount' => (float)$row['amount'],
            'reference' => $row['reference'] ?: 'Expense #' . $row['expense_id'],
            'details' => $details,
            'details_url' => 'expenses/details.php?id=' . (int)$row['expense_id'],
            'notes' => $row['notes'] ?: $row['expense_notes'],
            'created_by_name' => $row['created_by_name'] ?: 'System',
            'attachments' => [],
            'source_currency' => $row['source_currency'] ?: $currency,
        ];
    }
    $expensePaymentStmt->close();

    $transferScope = financeTransferScopeSql('f');
    $transferStmt = $conn->prepare("
        SELECT f.*,
               u.name AS created_by_name,
               from_safe.name AS from_safe_name,
               from_bank.bank_name AS from_bank_name,
               from_personal.name AS from_personal_name,
               to_safe.name AS to_safe_name,
               to_bank.bank_name AS to_bank_name,
               to_personal.name AS to_personal_name,
               COALESCE(from_safe.currency, from_bank.currency, 'EGP') AS currency,
               COALESCE(to_safe.currency, to_bank.currency, 'EGP') AS to_currency
        FROM finance_transfers f
        LEFT JOIN users u ON u.id = f.created_by
        LEFT JOIN safes from_safe ON f.from_type = 'safe' AND f.from_id = from_safe.id
        LEFT JOIN bank_accounts from_bank ON f.from_type = 'bank' AND f.from_id = from_bank.id
        LEFT JOIN personal_accounts from_personal ON f.from_type = 'personal' AND f.from_id = from_personal.id
        LEFT JOIN safes to_safe ON f.to_type = 'safe' AND f.to_id = to_safe.id
        LEFT JOIN bank_accounts to_bank ON f.to_type = 'bank' AND f.to_id = to_bank.id
        LEFT JOIN personal_accounts to_personal ON f.to_type = 'personal' AND f.to_id = to_personal.id
        WHERE ((f.from_type = ? AND f.from_id = ?)
            OR (f.to_type = ? AND f.to_id = ?))
          AND $transferScope
          AND f.status = 'approved'
          AND NOT (
              f.from_type = 'personal'
              AND f.from_id = 0
              AND f.to_type = ?
              AND f.to_id = ?
              AND f.notes LIKE 'Order Payment Reference:%'
          )
    ");
    $transferStmt->bind_param('sisisi', $accountType, $accountId, $accountType, $accountId, $accountType, $accountId);
    $transferStmt->execute();
    $transferRows = $transferStmt->get_result();
    $transferEvents = [];
    while ($row = $transferRows->fetch_assoc()) {
        $isOutgoing = $row['from_type'] === $accountType && (int)$row['from_id'] === $accountId;
        $isIncoming = $row['to_type'] === $accountType && (int)$row['to_id'] === $accountId;
        $direction = $isIncoming && !$isOutgoing ? 'in' : ($isOutgoing && !$isIncoming ? 'out' : 'neutral');
        $counterpartySide = $isOutgoing ? 'to' : 'from';
        $isExternal = (int)$row[$counterpartySide . '_id'] === 0;
        $details = financeLedgerCounterpartyName($row, $counterpartySide);
        if (financeTransferIsExchange($row)) {
            $details .= ' · ' . financeTransferExchangeSummary($row);
        }

        $transferEvents[(int)$row['id']] = [
            'source_key' => 'transfer_' . $row['id'],
            'sort_id' => (int)$row['id'],
            'transaction_date' => $row['transaction_date'],
            'created_at' => $row['created_at'],
            'type' => $isExternal
                ? ($direction === 'in' ? 'Cash Deposit' : 'Cash Withdrawal')
                : (financeTransferIsExchange($row) ? 'Currency Exchange' : 'Finance Transfer'),
            'direction' => $direction,
            'amount' => $direction === 'in' ? financeTransferReceivedAmount($row) : (float)$row['amount'],
            'reference' => $row['transfer_reference'] ?: 'Transfer #' . $row['id'],
            'details' => $details,
            'details_url' => 'transfer_details.php?id=' . (int)$row['id'],
            'notes' => $row['reason'] ?: $row['notes'],
            'created_by_name' => $row['created_by_name'] ?: 'System',
            'attachments' => [],
            'source_currency' => $currency,
        ];
    }
    $transferStmt->close();

    if ($transferEvents && tableExists('finance_transfer_images')) {
        $ids = implode(',', array_map('intval', array_keys($transferEvents)));
        $imageResult = $conn->query("
            SELECT finance_transfer_id, file_path, original_name
            FROM finance_transfer_images
            WHERE finance_transfer_id IN ($ids)
            ORDER BY created_at ASC, id ASC
        ");
        while ($imageResult && $image = $imageResult->fetch_assoc()) {
            $transferEvents[(int)$image['finance_transfer_id']]['attachments'][] = $image;
        }
    }
    $transactions = array_merge($transactions, array_values($transferEvents));

    foreach (poPaymentSourceHistory($accountType, $accountId) as $payment) {
        $transactions[] = [
            'source_key' => 'po_payment_' . $payment['id'],
            'sort_id' => (int)$payment['id'],
            'transaction_date' => $payment['transaction_date'],
            'created_at' => $payment['created_at'],
            'type' => 'PO Payment', 'direction' => 'out',
            'amount' => (float)$payment['amount'],
            'reference' => $payment['reference'],
            'details' => $payment['vendor_name'] . ' — PO #' . $payment['purchase_order_id'],
            'details_url' => '../purchases/po_details.php?id=' . (int)$payment['purchase_order_id'],
            'notes' => $payment['notes'], 'created_by_name' => $payment['created_by_name'] ?: 'System',
            'attachments' => [],
            'source_currency' => $currency,
        ];
    }

    foreach (financeAccountBalanceAdjustments($accountType, $accountId) as $adjustment) {
        $change = (float)$adjustment['new_balance'] - (float)$adjustment['previous_balance'];
        $transactions[] = [
            'source_key' => 'balance_adjustment_' . $adjustment['id'],
            'sort_id' => (int)$adjustment['id'],
            'transaction_date' => $adjustment['transaction_date'],
            'created_at' => $adjustment['created_at'],
            'type' => 'Balance Adjustment',
            'direction' => $change > 0 ? 'in' : ($change < 0 ? 'out' : 'neutral'),
            'amount' => abs($change),
            'reference' => 'Adjustment #' . $adjustment['id'],
            'details' => number_format((float)$adjustment['previous_balance'], 2) . ' → ' . number_format((float)$adjustment['new_balance'], 2),
            'details_url' => null,
            'notes' => $adjustment['reason'],
            'created_by_name' => $adjustment['created_by_name'] ?: 'System',
            'attachments' => [],
            'source_currency' => $adjustment['currency'] ?: $currency,
        ];
    }

    usort($transactions, function ($left, $right) {
        $dateComparison = strcmp($right['transaction_date'], $left['transaction_date']);
        if ($dateComparison !== 0) {
            return $dateComparison;
        }
        $dateComparison = strcmp($right['created_at'], $left['created_at']);
        if ($dateComparison !== 0) {
            return $dateComparison;
        }
        return $right['sort_id'] <=> $left['sort_id'];
    });

    $totalIn = 0.0;
    $totalOut = 0.0;
    $runningBalance = (float)$currentBalance;
    foreach ($transactions as &$transaction) {
        $transaction['running_balance'] = $runningBalance;
        if ($transaction['direction'] === 'in') {
            $totalIn += $transaction['amount'];
            $runningBalance -= $transaction['amount'];
        } elseif ($transaction['direction'] === 'out') {
            $totalOut += $transaction['amount'];
            $runningBalance += $transaction['amount'];
        }
    }
    unset($transaction);

    return ['transactions' => $transactions, 'total_in' => $totalIn, 'total_out' => $totalOut];
}
