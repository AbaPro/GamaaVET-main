<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/export_helpers.php';
require_once '../../config/database.php';

if (!hasPermission('analysis.view_reports') || !hasPermission('analysis.view_finance_reports')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

require_once '../../includes/libs/SimpleXLSXGen.php';

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
$dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;

// Validate dates before building report queries.
foreach ([$dateFrom, $dateTo] as $date) {
    if ($date === null) continue;
    $parsed = is_string($date) ? DateTime::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        setAlert('danger', 'Please enter a valid date.');
        redirect('financial_workbook.php');
    }
}
if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
    setAlert('danger', 'The start date must be before the end date.');
    redirect('financial_workbook.php');
}

function addFinancialSheet($xlsx, $data, $name) {
    // Match the price permissions used by the corresponding reports.
    $blocked = [];
    $headers = array_map('strip_tags', $data[2]);
    if ((strpos($name, 'Receivable') !== false || strpos($name, 'Sales') !== false) && !canViewProductPrice('final')) {
        $blocked = ['Total', 'Paid', 'Balance', 'Unit Price', 'Line Total', 'Discount', 'Shipping', 'Order Total'];
    }
    if ((strpos($name, 'Payable') !== false || strpos($name, 'Purchasing') !== false) && !hasPermission('purchases.po.price.view')) {
        $blocked = ['Total', 'Paid', 'Balance', 'Unit Price', 'Line Total', 'PO Total', 'PO Paid', 'PO Balance'];
    }
    if (strpos($name, 'Inventory') !== false) {
        if (!canViewProductCost('material') || !canViewProductCost('final')) {
            $blocked = array_merge($blocked, ['Unit Cost', 'Avg Unit Price', 'Total Value']);
        }
        if (!canViewProductPrice('final')) $blocked[] = 'Avg Sell Price';
    }
    $indexes = array_keys(array_filter($headers, static function ($label) use ($blocked) { return in_array($label, $blocked, true); }));
    foreach ($data as $rowIndex => &$row) {
        if ($rowIndex < 2 || count($row) < 2) continue;
        foreach ($indexes as $index) unset($row[$index]);
        $row = array_values($row);
    }
    unset($row);
    exportWorkbookSheet($xlsx, $data, $name, 3);
}

function dateFilterClause($column, $from, $to) {
    $clauses = [];
    if ($from !== null) {
        $clauses[] = "$column >= '$from'";
    }
    if ($to !== null) {
        $clauses[] = "$column <= '$to'";
    }
    return $clauses;
}

function timestampFilterClause($column, $from, $to) {
    $clauses = [];
    if ($from !== null) {
        $clauses[] = "DATE($column) >= '$from'";
    }
    if ($to !== null) {
        $clauses[] = "DATE($column) <= '$to'";
    }
    return $clauses;
}

function fmtDate($value) {
    if (empty($value)) return '';
    if (strpos($value, ' ') !== false) {
        return substr($value, 0, 10);
    }
    return $value;
}

function fmtNum($value) {
    if ($value === null || $value === '') return 0;
    return (float)$value;
}

function appendQueryErrorRow(&$sheetData, $message, $colspan = 1) {
    $row = ['<b><style color="C00000">Query error</style></b>', $message];
    while (count($row) < $colspan) {
        $row[] = '';
    }
    error_log('Financial export: ' . $message);
    $row[1] = 'This section could not be loaded. Please contact an administrator.';
    $sheetData[] = $row;
}

function statusLabel($status) {
    $map = [
        'new' => 'New',
        'in-production' => 'In Production',
        'in-packing' => 'In Packing',
        'delivering' => 'Delivering',
        'delivered' => 'Delivered',
        'returned' => 'Returned',
        'returned-refunded' => 'Returned & Refunded',
        'partially-returned' => 'Partially Returned',
        'partially-returned-refunded' => 'Partially Returned & Refunded',
        'ordered' => 'Ordered',
        'partially-received' => 'Partially Received',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
        'pending' => 'Pending',
        'partially-paid' => 'Partially Paid',
        'paid' => 'Paid',
    ];
    return isset($map[$status]) ? $map[$status] : ucfirst($status ?? '');
}

function paymentMethodLabel($method) {
    $map = ['cash' => 'Cash', 'transfer' => 'Transfer', 'wallet' => 'Wallet'];
    return isset($map[$method]) ? $map[$method] : ucfirst($method ?? '');
}

$today = date('Y-m-d');
$filename = 'Financial_Report_' . $today . '.xlsx';

$xlsx = Shuchkin\SimpleXLSXGen::create('GammaVET Financial Report');
$xlsx->setAuthor('GammaVET System');
$xlsx->setCompany('GammaVET');

// ============================================================
// SHEET 0: INDEX
// ============================================================
$indexData = [];
$indexData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="30" font-size="14">GammaVET ERP - Financial Workbook</style></b>'
];
$indexData[] = ['Scope', 'Only records available to your account are included.'];
$indexData[] = ['Reading the registers', 'One row per product. Order totals, discounts and shipping appear only on the first row of each order.'];
$indexData[] = ['Currency', 'Sales and cash totals are grouped by currency. Purchase amounts use the system currency (EGP).'];
$indexData[] = ['Inventory', 'Current stock snapshot; the date filter applies to orders and payment dates, not stock. Quantities may use different units.'];
$indexData[] = ['Transfers', 'Internal transfers are shown separately and excluded from inflow, outflow and net flow totals.'];
$indexData[] = [''];
$indexData[] = ['<b><style color="1F4E79" font-size="12">Export Date</style></b>', date('Y-m-d H:i:s')];
if ($dateFrom !== null || $dateTo !== null) {
    $indexData[] = ['<b><style color="1F4E79" font-size="12">Date Range</style></b>', ($dateFrom ?? 'Start') . ' to ' . ($dateTo ?? 'End')];
}
$indexData[] = [''];
$indexData[] = ['<b><style color="FFFFFF" bgcolor="1F4E79" font-size="11">Sheet</style></b>', '<b><style color="FFFFFF" bgcolor="1F4E79" font-size="11">Description</style></b>'];

$sheets = [
    ['1. Accounts Receivable', 'Customer payments and outstanding balances'],
    ['2. Accounts Payable', 'Vendor payments and outstanding balances'],
    ['3. Cash & Bank', 'Inflows, outflows, and transfers'],
    ['4. Inventory', 'Stock levels, values, prices, and stock alerts'],
    ['5. Purchasing', 'Purchasing details'],
    ['6. Sales & Billing', 'Sales details'],
];

foreach ($sheets as $s) {
    $indexData[] = $s;
}

foreach ($indexData as &$indexRow) {
    if (isset($indexRow[1]) && strpos($indexRow[1], '<b>') !== 0) $indexRow[1] = exportText($indexRow[1]);
}
unset($indexRow);
$xlsx->addSheet($indexData, 'INDEX')->setColWidth(1, 30)->setColWidth(2, 110);

// ============================================================
// SHEET 1: Accounts Receivable
// ============================================================
$arData = [];
$arData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Accounts Receivable</style></b>'
];
$arData[] = [''];

$arWhere = dateFilterClause('o.order_date', $dateFrom, $dateTo);
$arWhere[] = getCustomerChannelScopeSql('c', 'customer_factory');
$arSql = "SELECT o.id, o.internal_id, o.notes, o.order_date, o.status, o.total_amount, o.paid_amount, o.currency,
                 c.name as customer_name, ct.name as customer_type
          FROM orders o
          LEFT JOIN customers c ON o.customer_id = c.id
          LEFT JOIN customer_types ct ON c.type = ct.id
          LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id";
if (!empty($arWhere)) {
    $arSql .= ' WHERE ' . implode(' AND ', $arWhere);
}
$arSql .= ' ORDER BY o.order_date DESC';
$arResult = $conn->query($arSql);
if (!$arResult) { $arResult = null; }

$arHeaders = ['<b>Customer</b>', '<b>Type</b>', '<b>Order Date</b>', '<b>Currency</b>', '<b>Total</b>', '<b>Paid</b>', '<b>Balance</b>', '<b>Status</b>', '<b>Order Reference</b>', '<b>Notes</b>'];
$arData[] = $arHeaders;
if ($arResult === null) {
    appendQueryErrorRow($arData, $conn->error, count($arHeaders));
}

$arCurrencyTotals = [];


if ($arResult) {
    while ($row = $arResult->fetch_assoc()) {
        $total = fmtNum($row['total_amount']);
        $paid = fmtNum($row['paid_amount']);
        $balance = $total - $paid;
        $currency = $row['currency'] ?: 'EGP';

        $arData[] = [
            exportText($row['customer_name'] ?? ''),
            exportText(ucfirst($row['customer_type'] ?? '')),
            fmtDate($row['order_date']),
            $currency,
            $total,
            $paid,
            $balance,
            statusLabel($row['status']),
            exportText($row['internal_id'] ?: ('Order #' . $row['id'])),
            exportText($row['notes'] ?? ''),
        ];
        if (!isset($arCurrencyTotals[$currency])) $arCurrencyTotals[$currency] = [0, 0, 0];
        $arCurrencyTotals[$currency][0] += $total;
        $arCurrencyTotals[$currency][1] += $paid;
        $arCurrencyTotals[$currency][2] += $balance;

    }
}

$arData[] = [''];
foreach ($arCurrencyTotals as $currency => $totals) {
    $arData[] = ['<b>Totals by currency</b>', '', '', exportText($currency), $totals[0], $totals[1], $totals[2], '', '', ''];
}

addFinancialSheet($xlsx, $arData, '1. Accounts Receivable');

// ============================================================
// SHEET 2: Accounts Payable
// ============================================================
$apData = [];
$apData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Accounts Payable</style></b>'
];
$apData[] = [''];

$apWhere = dateFilterClause('po.order_date', $dateFrom, $dateTo);
$apSql = "SELECT po.id, po.order_date, po.status, po.total_amount, po.paid_amount, po.notes,
                 v.name as vendor_name, vt.name as vendor_type
          FROM purchase_orders po
          LEFT JOIN vendors v ON po.vendor_id = v.id
          LEFT JOIN vendor_types vt ON v.type = vt.id";
if (!empty($apWhere)) {
    $apSql .= ' WHERE ' . implode(' AND ', $apWhere);
}
$apSql .= ' ORDER BY po.order_date DESC';
$apResult = $conn->query($apSql);
if (!$apResult) { $apResult = null; }

$apHeaders = ['<b>Vendor</b>', '<b>Vendor Type</b>', '<b>Order Date</b>', '<b>Total</b>', '<b>Paid</b>', '<b>Balance</b>', '<b>Status</b>', '<b>Notes</b>', '<b>Purchase Order Reference</b>'];
$apData[] = $apHeaders;
if ($apResult === null) {
    appendQueryErrorRow($apData, $conn->error, count($apHeaders));
}

$apTotalAmount = 0;
$apTotalPaid = 0;
$apTotalBalance = 0;
$apRowCount = 0;

if ($apResult) {
    while ($row = $apResult->fetch_assoc()) {
        $total = fmtNum($row['total_amount']);
        $paid = fmtNum($row['paid_amount']);
        $balance = $total - $paid;

        $apData[] = [
            exportText($row['vendor_name'] ?? ''),
            exportText($row['vendor_type'] ?? ''),
            fmtDate($row['order_date']),
            $total,
            $paid,
            $balance,
            statusLabel($row['status']),
            exportText($row['notes'] ?? ''),
            exportText('PO #' . $row['id']),
        ];
        $apTotalAmount += $total;
        $apTotalPaid += $paid;
        $apTotalBalance += $balance;
        $apRowCount++;
    }
}

$apData[] = [''];
$apData[] = [
    '<b>Totals (' . $apRowCount . ' POs)</b>', '', '',
    $apTotalAmount, $apTotalPaid, $apTotalBalance, '', ''
];

addFinancialSheet($xlsx, $apData, '2. Accounts Payable');

// ============================================================
// SHEET 3: Cash & Bank
// ============================================================
$cbData = [];
$cbData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Cash & Bank Register</style></b>'
];
$cbData[] = [''];

$cbDateFrom = $dateFrom;
$cbDateTo = $dateTo;

$cbHeaders = ['<b>Date</b>', '<b>Source</b>', '<b>Account</b>', '<b>Inflow</b>', '<b>Outflow</b>', '<b>Note</b>', '<b>Payment Reference</b>', '<b>Internal Transfer</b>', '<b>Currency</b>'];
$cbData[] = $cbHeaders;

$allTransactions = [];

// 1. Order payments (inflows)
$opWhere = dateFilterClause('op.transaction_date', $cbDateFrom, $cbDateTo);
$opWhere[] = getSafeReferenceScopeSql('op.safe_id');
$opWhere[] = getCustomerChannelScopeSql('c', 'customer_factory');
$opSql = "SELECT o.currency, op.amount, op.payment_method, op.reference, op.notes, op.transaction_date,
                 c.name as customer_name
          FROM order_payments op
          LEFT JOIN orders o ON op.order_id = o.id
          LEFT JOIN customers c ON o.customer_id = c.id
          LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id";
if (!empty($opWhere)) {
    $opSql .= ' WHERE ' . implode(' AND ', $opWhere);
}
$opSql .= ' ORDER BY op.transaction_date DESC, op.created_at DESC';
$opResult = $conn->query($opSql);
if (!$opResult) {
    appendQueryErrorRow($cbData, 'Order payments query failed: ' . $conn->error, 6);
    $opResult = null;
}
if ($opResult) {
    while ($row = $opResult->fetch_assoc()) {
        $allTransactions[] = [
            'currency' => $row['currency'] ?? 'EGP',
            'date' => fmtDate($row['transaction_date']),
            'source' => 'Sales Receipt',
            'account' => paymentMethodLabel($row['payment_method']),
            'inflow' => fmtNum($row['amount']),
            'outflow' => 0,
            'reference' => exportText($row['reference'] ?? ''),
            'transfer' => 0,
            'note' => (exportText($row['customer_name'] ?? '')) . ($row['notes'] ? ' - ' . $row['notes'] : ''),
        ];
    }
}

// 2. Purchase order payments (outflows)
$popWhere = dateFilterClause('pop.transaction_date', $cbDateFrom, $cbDateTo);
$popWhere[] = getSafeReferenceScopeSql('pop.safe_id');
$poSourceSafeScope = getSafeReferenceScopeSql('pop.payment_source_id');
$popWhere[] = "(pop.payment_source_type IS NULL OR pop.payment_source_type <> 'safe' OR $poSourceSafeScope)";
$popSql = "SELECT pop.amount, pop.payment_method, pop.reference, pop.notes, pop.transaction_date,
                  v.name as vendor_name
           FROM purchase_order_payments pop
           LEFT JOIN purchase_orders po ON pop.purchase_order_id = po.id
           LEFT JOIN vendors v ON po.vendor_id = v.id";
if (!empty($popWhere)) {
    $popSql .= ' WHERE ' . implode(' AND ', $popWhere);
}
$popSql .= ' ORDER BY pop.transaction_date DESC, pop.created_at DESC';
$popResult = $conn->query($popSql);
if (!$popResult) {
    appendQueryErrorRow($cbData, 'Purchase payments query failed: ' . $conn->error, 6);
    $popResult = null;
}
if ($popResult) {
    while ($row = $popResult->fetch_assoc()) {
        $allTransactions[] = [
            'currency' => $row['currency'] ?? 'EGP',
            'date' => fmtDate($row['transaction_date']),
            'source' => 'PO Payment',
            'account' => paymentMethodLabel($row['payment_method']),
            'inflow' => 0,
            'outflow' => fmtNum($row['amount']),
            'reference' => exportText($row['reference'] ?? ''),
            'transfer' => 0,
            'note' => (exportText($row['vendor_name'] ?? '')) . ($row['notes'] ? ' - ' . $row['notes'] : ''),
        ];
    }
}

// 3. Expense payments (outflows)
$epWhere = dateFilterClause('ep.transaction_date', $cbDateFrom, $cbDateTo);
$exportSafeScope = getSafeScopeSql('s');
$epWhere[] = "(ep.safe_id IS NULL OR EXISTS (SELECT 1 FROM safes s WHERE s.id = ep.safe_id AND $exportSafeScope))";
$epSql = "SELECT ep.amount, ep.payment_method, ep.reference, ep.notes, ep.transaction_date,
                 e.name as expense_name, e.category_id, e.currency,
                 s.name as safe_name, ba.bank_name
          FROM expense_payments ep
          LEFT JOIN expenses e ON ep.expense_id = e.id
          LEFT JOIN safes s ON ep.safe_id = s.id
          LEFT JOIN bank_accounts ba ON ep.bank_account_id = ba.id";
if (!empty($epWhere)) {
    $epSql .= ' WHERE ' . implode(' AND ', $epWhere);
}
$epSql .= ' ORDER BY ep.transaction_date DESC, ep.created_at DESC';
$epResult = $conn->query($epSql);
if (!$epResult) {
    appendQueryErrorRow($cbData, 'Expense payments query failed: ' . $conn->error, 6);
    $epResult = null;
}
if ($epResult) {
    while ($row = $epResult->fetch_assoc()) {
        $accountParts = [];
        $accountParts[] = paymentMethodLabel($row['payment_method']);
        if (!empty($row['safe_name'])) $accountParts[] = $row['safe_name'];
        if (!empty($row['bank_name'])) $accountParts[] = $row['bank_name'];
        $allTransactions[] = [
            'currency' => $row['currency'] ?? 'EGP',
            'date' => fmtDate($row['transaction_date']),
            'source' => 'Expense',
            'account' => implode(' / ', $accountParts),
            'inflow' => 0,
            'outflow' => fmtNum($row['amount']),
            'reference' => exportText($row['reference'] ?? ''),
            'transfer' => 0,
            'note' => ($row['expense_name'] ?? 'Expense') . ($row['notes'] ? ' - ' . $row['notes'] : ''),
        ];
    }
}

// 4. Finance transfers
$ftWhere = dateFilterClause('ft.transaction_date', $cbDateFrom, $cbDateTo);
$ftWhere[] = "ft.status = 'approved'";
require_once __DIR__ . '/../finance/transfer_helpers.php';
$ftWhere[] = financeTransferScopeSql('ft');
$ftSql = "SELECT COALESCE(from_safe.currency, from_bank.currency, 'EGP') AS currency, ft.amount, ft.from_type, ft.from_id, ft.to_type, ft.to_id, ft.reason, ft.notes, ft.transaction_date
          FROM finance_transfers ft
          LEFT JOIN safes from_safe ON ft.from_type = 'safe' AND from_safe.id = ft.from_id
          LEFT JOIN bank_accounts from_bank ON ft.from_type = 'bank' AND from_bank.id = ft.from_id";
if (!empty($ftWhere)) {
    $ftSql .= ' WHERE ' . implode(' AND ', $ftWhere);
}
$ftSql .= ' ORDER BY ft.transaction_date DESC, ft.created_at DESC';
$ftResult = $conn->query($ftSql);
if (!$ftResult) {
    appendQueryErrorRow($cbData, 'Finance transfers query failed: ' . $conn->error, 6);
    $ftResult = null;
}
if ($ftResult) {
    while ($row = $ftResult->fetch_assoc()) {
        $fromLabel = ucfirst($row['from_type']) . ' account';
        $toLabel = ucfirst($row['to_type']) . ' account';

        if ($row['from_type'] === 'safe') {
            $r = $conn->query("SELECT name FROM safes WHERE id = " . (int)$row['from_id']);
            if ($r && $rr = $r->fetch_assoc()) $fromLabel = 'Safe: ' . $rr['name'];
        } elseif ($row['from_type'] === 'bank') {
            $r = $conn->query("SELECT bank_name FROM bank_accounts WHERE id = " . (int)$row['from_id']);
            if ($r && $rr = $r->fetch_assoc()) $fromLabel = 'Bank: ' . $rr['bank_name'];
        } elseif ($row['from_type'] === 'personal') {
            $r = $conn->query("SELECT name FROM personal_accounts WHERE id = " . (int)$row['from_id']);
            if ($r && $rr = $r->fetch_assoc()) $fromLabel = 'Personal: ' . $rr['name'];
        }

        if ($row['to_type'] === 'safe') {
            $r = $conn->query("SELECT name FROM safes WHERE id = " . (int)$row['to_id']);
            if ($r && $rr = $r->fetch_assoc()) $toLabel = 'Safe: ' . $rr['name'];
        } elseif ($row['to_type'] === 'bank') {
            $r = $conn->query("SELECT bank_name FROM bank_accounts WHERE id = " . (int)$row['to_id']);
            if ($r && $rr = $r->fetch_assoc()) $toLabel = 'Bank: ' . $rr['bank_name'];
        } elseif ($row['to_type'] === 'personal') {
            $r = $conn->query("SELECT name FROM personal_accounts WHERE id = " . (int)$row['to_id']);
            if ($r && $rr = $r->fetch_assoc()) $toLabel = 'Personal: ' . $rr['name'];
        }

        $allTransactions[] = [
            'currency' => $row['currency'] ?? 'EGP',
            'date' => fmtDate($row['transaction_date']),
            'source' => 'Internal Transfer',
            'account' => $fromLabel . ' -> ' . $toLabel,
            'inflow' => 0,
            'outflow' => 0,
            'reference' => '',
            'transfer' => fmtNum($row['amount']),
            'note' => implode(' - ', array_filter([$row['reason'] ?? '', $row['notes'] ?? ''])),
        ];
    }
}

// Sort all transactions by date descending
usort($allTransactions, function($a, $b) {
    return strcmp($b['date'], $a['date']);
});

$cashCurrencyTotals = [];
$cbRowCount = 0;

foreach ($allTransactions as $t) {
    $cbData[] = [
        $t['date'],
        $t['source'],
        exportText($t['account']),
        $t['inflow'],
        $t['outflow'],
        exportText($t['note']),
        $t['reference'],
        $t['transfer'],
        exportText($t['currency']),
    ];
    $currency = $t['currency'] ?: 'EGP';
    if (!isset($cashCurrencyTotals[$currency])) $cashCurrencyTotals[$currency] = [0, 0, 0];
    $cashCurrencyTotals[$currency][0] += $t['inflow'];
    $cashCurrencyTotals[$currency][1] += $t['outflow'];
    $cashCurrencyTotals[$currency][2] += $t['transfer'];
    $cbRowCount++;
}

$cbData[] = [''];
foreach ($cashCurrencyTotals as $currency => $totals) {
    $cbData[] = ['<b>Totals by currency</b>', '', '', $totals[0], $totals[1], '', '', $totals[2], exportText($currency)];
    $cbData[] = ['<b>Net Flow</b>', '', '', $totals[0] - $totals[1], '', '', '', '', exportText($currency)];
}

addFinancialSheet($xlsx, $cbData, '3. Cash & Bank');

// ============================================================
// SHEET 4: Inventory
// ============================================================
$invData = [];
$invData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Inventory Valuation</style></b>'
];
$invData[] = [''];

$priceAverageJoin = tableExists('product_price_logs')
    ? "LEFT JOIN (
            SELECT product_id,
                   SUM(CASE WHEN price_type = 'unit' AND price > 0 AND quantity > 0 THEN price * quantity ELSE 0 END)
                       / NULLIF(SUM(CASE WHEN price_type = 'unit' AND price > 0 AND quantity > 0 THEN quantity ELSE 0 END), 0) AS avg_unit_price,
                   SUM(CASE WHEN price_type = 'sell' AND price > 0 AND quantity > 0 THEN price * quantity ELSE 0 END)
                       / NULLIF(SUM(CASE WHEN price_type = 'sell' AND price > 0 AND quantity > 0 THEN quantity ELSE 0 END), 0) AS avg_sell_price
            FROM product_price_logs
            GROUP BY product_id
       ) price_avg ON price_avg.product_id = p.id"
    : "LEFT JOIN (
            SELECT p2.id AS product_id,
                   (
                       SELECT SUM(poi.unit_price * CASE WHEN COALESCE(poi.received_quantity, 0) > 0 THEN poi.received_quantity ELSE poi.quantity END)
                              / NULLIF(SUM(CASE WHEN COALESCE(poi.received_quantity, 0) > 0 THEN poi.received_quantity ELSE poi.quantity END), 0)
                       FROM purchase_order_items poi
                       WHERE poi.product_id = p2.id
                         AND poi.unit_price > 0
                         AND (CASE WHEN COALESCE(poi.received_quantity, 0) > 0 THEN poi.received_quantity ELSE poi.quantity END) > 0
                   ) AS avg_unit_price,
                   (
                       SELECT SUM(oi.unit_price * oi.quantity) / NULLIF(SUM(oi.quantity), 0)
                       FROM order_items oi
                       WHERE oi.product_id = p2.id
                         AND oi.unit_price > 0
                         AND oi.quantity > 0
                         AND COALESCE(oi.is_free_sample, 0) = 0
                   ) AS avg_sell_price
            FROM products p2
       ) price_avg ON price_avg.product_id = p.id";

$invSql = "SELECT p.id, p.sku, p.name as product_name, p.type, p.unit, p.cost_price, p.min_stock_level,
                   c1.name as category_name, c2.name as subcategory_name,
                   price_avg.avg_unit_price, price_avg.avg_sell_price,
                   COALESCE((SELECT SUM(ip.quantity) FROM inventory_products ip
                             JOIN inventories inv ON ip.inventory_id = inv.id
                             WHERE ip.product_id = p.id AND inv.is_active = 1 AND " . getInventoryChannelScopeSql('inv') . "), 0) AS total_quantity
            FROM products p
            LEFT JOIN categories c1 ON p.category_id = c1.id
            LEFT JOIN categories c2 ON p.subcategory_id = c2.id
            LEFT JOIN customers inv_customer ON inv_customer.id = p.customer_id
            LEFT JOIN factories inv_factory ON inv_factory.id = inv_customer.factory_id
            $priceAverageJoin
            WHERE " . getProductChannelScopeSql('p', 'inv_customer', 'inv_factory') . "
            ORDER BY p.name";
$invResult = $conn->query($invSql);
if (!$invResult) { $invResult = null; }

$invHeaders = ['<b>SKU</b>', '<b>Product</b>', '<b>Category</b>', '<b>Subcategory</b>', '<b>Type</b>', '<b>Unit</b>', '<b>Quantity</b>', '<b>Unit Cost</b>', '<b>Avg Unit Price</b>', '<b>Avg Sell Price</b>', '<b>Total Value</b>', '<b>Min Stock</b>', '<b>Low Stock</b>'];
$invData[] = $invHeaders;
if ($invResult === null) {
    appendQueryErrorRow($invData, $conn->error, count($invHeaders));
}

$inventoryRows = $invResult ? $invResult->fetch_all(MYSQLI_ASSOC) : [];
$inventoryCosts = getCalculatedProductCostDetails(array_column($inventoryRows, 'id'));

$invTotalValue = 0;
$invRowCount = 0;
$lowStockCount = 0;

if ($invResult) {
    foreach ($inventoryRows as $row) {
        $qty = fmtNum($row['total_quantity']);
        $cost = fmtNum($inventoryCosts[(int)$row['id']]['value'] ?? $row['cost_price']);
        $avgUnitPrice = $row['avg_unit_price'] !== null ? fmtNum($row['avg_unit_price']) : '';
        $avgSellPrice = $row['avg_sell_price'] !== null ? fmtNum($row['avg_sell_price']) : '';
        $totalValue = $qty * $cost;
        $minStock = (int)($row['min_stock_level'] ?? 0);
        $lowStock = ($qty <= $minStock && $minStock > 0) ? 'Yes' : 'No';

        if ($lowStock === 'Yes') $lowStockCount++;

        $invData[] = [
            exportText($row['sku'] ?? ''),
            exportText($row['product_name'] ?? ''),
            exportText($row['category_name'] ?? ''),
            exportText($row['subcategory_name'] ?? ''),
            ucfirst($row['type'] ?? ''),
            getProductUnitLabel($row['unit'] ?? ''),
            $qty,
            $cost,
            $avgUnitPrice,
            $avgSellPrice,
            $totalValue,
            $minStock,
            $lowStock,
        ];

        $invTotalValue += $totalValue;
        $invRowCount++;
    }
}

$invData[] = [''];
$invData[] = [
    '<b>Stock value (' . $invRowCount . ' products)</b>', '', '', '', '', '',
    '', '', '', '', $invTotalValue, '', $lowStockCount . ' low-stock items'
];

addFinancialSheet($xlsx, $invData, '4. Inventory');

// ============================================================
// SHEET 5: Purchasing
// ============================================================
$purData = [];
$purData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Purchasing Register</style></b>'
];
$purData[] = [''];

$purWhere = dateFilterClause('po.order_date', $dateFrom, $dateTo);
$purSql = "SELECT po.id, po.order_date, po.status, po.total_amount, po.paid_amount, po.notes,
                  v.name as vendor_name,
                  poi.product_id, poi.quantity, poi.unit_price, poi.total_price as line_total, poi.received_quantity,
                  p.name as product_name, p.sku
           FROM purchase_orders po
           LEFT JOIN vendors v ON po.vendor_id = v.id
           LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
           LEFT JOIN products p ON poi.product_id = p.id";
if (!empty($purWhere)) {
    $purSql .= ' WHERE ' . implode(' AND ', $purWhere);
}
$purSql .= ' ORDER BY po.order_date DESC, po.id DESC, poi.id ASC';
$purResult = $conn->query($purSql);
if (!$purResult) { $purResult = null; }

$purHeaders = ['<b>Vendor</b>', '<b>Order Date</b>', '<b>Product</b>', '<b>SKU</b>', '<b>Qty Ordered</b>', '<b>Qty Received</b>', '<b>Unit Price</b>', '<b>Line Total</b>', '<b>PO Total</b>', '<b>PO Paid</b>', '<b>PO Balance</b>', '<b>Status</b>', '<b>Purchase Order Reference</b>', '<b>Notes</b>'];
$purData[] = $purHeaders;
if ($purResult === null) {
    appendQueryErrorRow($purData, $conn->error, count($purHeaders));
}

$purTotalOrdered = 0;
$purTotalPaid = 0;
$purRowCount = 0;
$prevPoId = null;

if ($purResult) {
    while ($row = $purResult->fetch_assoc()) {
        $firstLine = $prevPoId !== $row['id'];
        $poBalance = fmtNum($row['total_amount']) - fmtNum($row['paid_amount']);

        $purData[] = [
            exportText($row['vendor_name'] ?? ''),
            fmtDate($row['order_date']),
            exportText($row['product_name'] ?? ''),
            exportText($row['sku'] ?? ''),
            fmtNum($row['quantity']),
            fmtNum($row['received_quantity']),
            fmtNum($row['unit_price']),
            fmtNum($row['line_total']),
            $firstLine ? fmtNum($row['total_amount']) : '',
            $firstLine ? fmtNum($row['paid_amount']) : '',
            $firstLine ? $poBalance : '',
            statusLabel($row['status']),
            exportText('PO #' . $row['id']),
            exportText($row['notes'] ?? ''),
        ];

        if ($prevPoId !== $row['id']) {
            $purTotalOrdered += fmtNum($row['total_amount']);
            $purTotalPaid += fmtNum($row['paid_amount']);
            $prevPoId = $row['id'];
        }
        $purRowCount++;
    }
}

$purData[] = [''];
$purData[] = [
    '<b>Totals (' . $purRowCount . ' lines)</b>', '', '', '', '', '', '', '',
    $purTotalOrdered, $purTotalPaid, ($purTotalOrdered - $purTotalPaid), ''
];

addFinancialSheet($xlsx, $purData, '5. Purchasing');

// ============================================================
// SHEET 6: Sales & Billing
// ============================================================
$salesData = [];
$salesData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Sales & Billing Register</style></b>'
];
$salesData[] = [''];

$salesWhere = dateFilterClause('o.order_date', $dateFrom, $dateTo);
$salesWhere[] = getCustomerChannelScopeSql('c', 'customer_factory');
$salesSql = "SELECT o.id, o.internal_id, o.notes, o.order_date, o.status, o.total_amount, o.paid_amount, o.discount_amount, o.shipping_cost, o.currency,
                    c.name as customer_name, ct.name as customer_type,
                    oi.product_id, oi.quantity, oi.unit_price, oi.total_price as line_total, oi.is_free_sample,
                    p.name as product_name, p.sku
             FROM orders o
             LEFT JOIN customers c ON o.customer_id = c.id
             LEFT JOIN customer_types ct ON c.type = ct.id
          LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             LEFT JOIN products p ON oi.product_id = p.id";
if (!empty($salesWhere)) {
    $salesSql .= ' WHERE ' . implode(' AND ', $salesWhere);
}
$salesSql .= ' ORDER BY o.order_date DESC, o.id DESC, oi.id ASC';
$salesResult = $conn->query($salesSql);
if (!$salesResult) { $salesResult = null; }

$salesHeaders = ['<b>Customer</b>', '<b>Type</b>', '<b>Order Date</b>', '<b>Product</b>', '<b>SKU</b>', '<b>Qty</b>', '<b>Unit Price</b>', '<b>Line Total</b>', '<b>Discount</b>', '<b>Shipping</b>', '<b>Order Total</b>', '<b>Paid</b>', '<b>Balance</b>', '<b>Currency</b>', '<b>Status</b>', '<b>Order Reference</b>', '<b>Free Sample</b>', '<b>Notes</b>'];
$salesData[] = $salesHeaders;
if ($salesResult === null) {
    appendQueryErrorRow($salesData, $conn->error, count($salesHeaders));
}

$salesCurrencyTotals = [];

$prevOrderId = null;

if ($salesResult) {
    while ($row = $salesResult->fetch_assoc()) {
        $firstLine = $prevOrderId !== $row['id'];
        $orderBalance = fmtNum($row['total_amount']) - fmtNum($row['paid_amount']);
        $isSample = ($row['is_free_sample'] == 1) ? 'Yes' : 'No';

        $salesData[] = [
            exportText($row['customer_name'] ?? ''),
            exportText(ucfirst($row['customer_type'] ?? '')),
            fmtDate($row['order_date']),
            exportText($row['product_name'] ?? ''),
            exportText($row['sku'] ?? ''),
            fmtNum($row['quantity']),
            fmtNum($row['unit_price']),
            fmtNum($row['line_total']),
            $firstLine ? fmtNum($row['discount_amount']) : '',
            $firstLine ? fmtNum($row['shipping_cost']) : '',
            $firstLine ? fmtNum($row['total_amount']) : '',
            $firstLine ? fmtNum($row['paid_amount']) : '',
            $firstLine ? $orderBalance : '',
            $row['currency'] ?? 'EGP',
            statusLabel($row['status']),
            exportText($row['internal_id'] ?: ('Order #' . $row['id'])),
            $isSample,
            exportText($row['notes'] ?? ''),
        ];

        if ($firstLine) {
            $currency = $row['currency'] ?: 'EGP';
            if (!isset($salesCurrencyTotals[$currency])) $salesCurrencyTotals[$currency] = [0, 0, 0];
            $salesCurrencyTotals[$currency][0] += fmtNum($row['total_amount']);
            $salesCurrencyTotals[$currency][1] += fmtNum($row['paid_amount']);
            $salesCurrencyTotals[$currency][2] += $orderBalance;

            $prevOrderId = $row['id'];
        }

    }
}

$salesData[] = [''];
foreach ($salesCurrencyTotals as $currency => $totals) {
    $salesData[] = ['<b>Totals by currency</b>', '', '', '', '', '', '', '', '', '',
        $totals[0], $totals[1], $totals[2], exportText($currency), '', '', '', ''];
}

addFinancialSheet($xlsx, $salesData, '6. Sales & Billing');

// ============================================================
// DOWNLOAD
// ============================================================
$xlsx->downloadAs($filename);
exit();
