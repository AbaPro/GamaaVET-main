<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('analysis.view_reports')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

require_once '../../includes/libs/SimpleXLSXGen.php';

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
$dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;

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
$indexData[] = [''];
$indexData[] = ['<b><style color="1F4E79" font-size="12">Export Date</style></b>', date('Y-m-d H:i:s')];
if ($dateFrom !== null || $dateTo !== null) {
    $indexData[] = ['<b><style color="1F4E79" font-size="12">Date Range</style></b>', ($dateFrom ?? 'Start') . ' to ' . ($dateTo ?? 'End')];
}
$indexData[] = [''];
$indexData[] = ['<b><style color="FFFFFF" bgcolor="1F4E79" font-size="11">Sheet</style></b>', '<b><style color="FFFFFF" bgcolor="1F4E79" font-size="11">Description</style></b>', '<b><style color="FFFFFF" bgcolor="1F4E79" font-size="11">Status</style></b>'];

$sheets = [
    ['2. Accounts Receivable', 'Customer orders, payments, and outstanding balances', 'Included'],
    ['3. Accounts Payable', 'Vendor purchase orders, payments, and outstanding balances', 'Included'],
    ['4. Cash & Bank', 'Combined cash, bank, wallet inflows/outflows and transfers', 'Included'],
    ['5. Inventory', 'Stock levels, cost values, and low-stock alerts', 'Included'],
    ['6. Purchasing', 'Purchase order register with line items', 'Included'],
    ['7. Sales & Billing', 'Sales order register with line items', 'Included'],
];

foreach ($sheets as $s) {
    $indexData[] = $s;
}

$indexData[] = [''];
$indexData[] = ['<b><style color="C00000" font-size="11">Excluded Modules (data not modeled in current system)</style></b>'];
$excluded = [
    '1. General Ledger',
    '8. Fixed Assets',
    '9. Payroll',
    '10. Budgeting',
    '11. Cost Centers',
    '12. Consolidation',
];
foreach ($excluded as $ex) {
    $indexData[] = [$ex, 'No chart of accounts, journal entries, or ledger tables available'];
}

$indexData[] = [''];
$indexData[] = ['<i><style color="808080" font-size="10">This is a management-report workbook, not a statutory accounting workbook.</style></i>'];

$xlsx->addSheet($indexData, 'INDEX');

// ============================================================
// SHEET 1: Accounts Receivable
// ============================================================
$arData = [];
$arData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Accounts Receivable</style></b>'
];
$arData[] = [''];

$arWhere = dateFilterClause('o.order_date', $dateFrom, $dateTo);
$arSql = "SELECT o.id, o.internal_id, o.order_date, o.status, o.total_amount, o.paid_amount, o.currency,
                 c.name as customer_name, ct.name as customer_type
          FROM orders o
          LEFT JOIN customers c ON o.customer_id = c.id
          LEFT JOIN customer_types ct ON c.type = ct.id";
if (!empty($arWhere)) {
    $arSql .= ' WHERE ' . implode(' AND ', $arWhere);
}
$arSql .= ' ORDER BY o.order_date DESC, o.id DESC';
$arResult = $conn->query($arSql);
if (!$arResult) { $arResult = null; }

$arHeaders = ['<b>Order No</b>', '<b>Customer</b>', '<b>Type</b>', '<b>Order Date</b>', '<b>Currency</b>', '<b>Total</b>', '<b>Paid</b>', '<b>Balance</b>', '<b>Status</b>'];
$arData[] = $arHeaders;
if ($arResult === null) {
    appendQueryErrorRow($arData, $conn->error, count($arHeaders));
}

$arTotalAmount = 0;
$arTotalPaid = 0;
$arTotalBalance = 0;
$arRowCount = 0;

if ($arResult) {
    while ($row = $arResult->fetch_assoc()) {
        $total = fmtNum($row['total_amount']);
        $paid = fmtNum($row['paid_amount']);
        $balance = $total - $paid;
        $currency = $row['currency'] ?? 'EGP';

        $arData[] = [
            $row['internal_id'] ?? ('ORD-' . $row['id']),
            $row['customer_name'] ?? '',
            ucfirst($row['customer_type'] ?? ''),
            fmtDate($row['order_date']),
            $currency,
            $total,
            $paid,
            $balance,
            statusLabel($row['status']),
        ];
        $arTotalAmount += $total;
        $arTotalPaid += $paid;
        $arTotalBalance += $balance;
        $arRowCount++;
    }
}

$arData[] = [''];
$lastRow = count($arData) + 1;
$arData[] = [
    '<b>Totals (' . $arRowCount . ' orders)</b>', '', '', '', '',
    $arTotalAmount, $arTotalPaid, $arTotalBalance, ''
];

$xlsx->addSheet($arData, '2. Accounts Receivable');

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
$apSql .= ' ORDER BY po.order_date DESC, po.id DESC';
$apResult = $conn->query($apSql);
if (!$apResult) { $apResult = null; }

$apHeaders = ['<b>PO Ref</b>', '<b>Vendor</b>', '<b>Vendor Type</b>', '<b>Order Date</b>', '<b>Total</b>', '<b>Paid</b>', '<b>Balance</b>', '<b>Status</b>', '<b>Notes</b>'];
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
            'PO-' . $row['id'],
            $row['vendor_name'] ?? '',
            $row['vendor_type'] ?? '',
            fmtDate($row['order_date']),
            $total,
            $paid,
            $balance,
            statusLabel($row['status']),
            $row['notes'] ?? '',
        ];
        $apTotalAmount += $total;
        $apTotalPaid += $paid;
        $apTotalBalance += $balance;
        $apRowCount++;
    }
}

$apData[] = [''];
$apData[] = [
    '<b>Totals (' . $apRowCount . ' POs)</b>', '', '', '',
    $apTotalAmount, $apTotalPaid, $apTotalBalance, '', ''
];

$xlsx->addSheet($apData, '3. Accounts Payable');

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

$allTransactions = [];

// 1. Order payments (inflows)
$opWhere = timestampFilterClause('op.created_at', $cbDateFrom, $cbDateTo);
$opSql = "SELECT op.id, op.amount, op.payment_method, op.reference, op.notes, op.created_at,
                 o.internal_id as order_ref, c.name as customer_name
          FROM order_payments op
          LEFT JOIN orders o ON op.order_id = o.id
          LEFT JOIN customers c ON o.customer_id = c.id";
if (!empty($opWhere)) {
    $opSql .= ' WHERE ' . implode(' AND ', $opWhere);
}
$opSql .= ' ORDER BY op.created_at DESC';
$opResult = $conn->query($opSql);
if (!$opResult) {
    appendQueryErrorRow($cbData, 'Order payments query failed: ' . $conn->error, 7);
    $opResult = null;
}
if ($opResult) {
    while ($row = $opResult->fetch_assoc()) {
        $allTransactions[] = [
            'date' => fmtDate($row['created_at']),
            'source' => 'Sales Receipt',
            'ref' => $row['order_ref'] ?? ('ORD-' . ($row['id'] ?? '')),
            'account' => paymentMethodLabel($row['payment_method']),
            'inflow' => fmtNum($row['amount']),
            'outflow' => 0,
            'note' => ($row['customer_name'] ?? '') . ($row['notes'] ? ' - ' . $row['notes'] : ''),
        ];
    }
}

// 2. Purchase order payments (outflows)
$popWhere = timestampFilterClause('pop.created_at', $cbDateFrom, $cbDateTo);
$popSql = "SELECT pop.id, pop.amount, pop.payment_method, pop.reference, pop.notes, pop.created_at,
                  v.name as vendor_name
           FROM purchase_order_payments pop
           LEFT JOIN purchase_orders po ON pop.purchase_order_id = po.id
           LEFT JOIN vendors v ON po.vendor_id = v.id";
if (!empty($popWhere)) {
    $popSql .= ' WHERE ' . implode(' AND ', $popWhere);
}
$popSql .= ' ORDER BY pop.created_at DESC';
$popResult = $conn->query($popSql);
if (!$popResult) {
    appendQueryErrorRow($cbData, 'Purchase payments query failed: ' . $conn->error, 7);
    $popResult = null;
}
if ($popResult) {
    while ($row = $popResult->fetch_assoc()) {
        $allTransactions[] = [
            'date' => fmtDate($row['created_at']),
            'source' => 'PO Payment',
            'ref' => 'PO-PAY-' . $row['id'],
            'account' => paymentMethodLabel($row['payment_method']),
            'inflow' => 0,
            'outflow' => fmtNum($row['amount']),
            'note' => ($row['vendor_name'] ?? '') . ($row['notes'] ? ' - ' . $row['notes'] : ''),
        ];
    }
}

// 3. Expense payments (outflows)
$epWhere = timestampFilterClause('ep.created_at', $cbDateFrom, $cbDateTo);
$epSql = "SELECT ep.id, ep.amount, ep.payment_method, ep.reference, ep.notes, ep.created_at,
                 e.name as expense_name, e.category_id,
                 s.name as safe_name, ba.bank_name
          FROM expense_payments ep
          LEFT JOIN expenses e ON ep.expense_id = e.id
          LEFT JOIN safes s ON ep.safe_id = s.id
          LEFT JOIN bank_accounts ba ON ep.bank_account_id = ba.id";
if (!empty($epWhere)) {
    $epSql .= ' WHERE ' . implode(' AND ', $epWhere);
}
$epSql .= ' ORDER BY ep.created_at DESC';
$epResult = $conn->query($epSql);
if (!$epResult) {
    appendQueryErrorRow($cbData, 'Expense payments query failed: ' . $conn->error, 7);
    $epResult = null;
}
if ($epResult) {
    while ($row = $epResult->fetch_assoc()) {
        $accountParts = [];
        $accountParts[] = paymentMethodLabel($row['payment_method']);
        if (!empty($row['safe_name'])) $accountParts[] = $row['safe_name'];
        if (!empty($row['bank_name'])) $accountParts[] = $row['bank_name'];
        $allTransactions[] = [
            'date' => fmtDate($row['created_at']),
            'source' => 'Expense',
            'ref' => 'EXP-PAY-' . $row['id'],
            'account' => implode(' / ', $accountParts),
            'inflow' => 0,
            'outflow' => fmtNum($row['amount']),
            'note' => ($row['expense_name'] ?? 'Expense') . ($row['notes'] ? ' - ' . $row['notes'] : ''),
        ];
    }
}

// 4. Finance transfers
$ftWhere = timestampFilterClause('ft.created_at', $cbDateFrom, $cbDateTo);
$ftSql = "SELECT ft.id, ft.amount, ft.from_type, ft.from_id, ft.to_type, ft.to_id, ft.notes, ft.created_at
          FROM finance_transfers ft";
if (!empty($ftWhere)) {
    $ftSql .= ' WHERE ' . implode(' AND ', $ftWhere);
}
$ftSql .= ' ORDER BY ft.created_at DESC';
$ftResult = $conn->query($ftSql);
if (!$ftResult) {
    appendQueryErrorRow($cbData, 'Finance transfers query failed: ' . $conn->error, 7);
    $ftResult = null;
}
if ($ftResult) {
    while ($row = $ftResult->fetch_assoc()) {
        $fromLabel = ucfirst($row['from_type']) . ' #' . $row['from_id'];
        $toLabel = ucfirst($row['to_type']) . ' #' . $row['to_id'];

        if ($row['from_type'] === 'safe') {
            $r = $conn->query("SELECT name FROM safes WHERE id = " . (int)$row['from_id']);
            if ($r && $rr = $r->fetch_assoc()) $fromLabel = 'Safe: ' . $rr['name'];
        } elseif ($row['from_type'] === 'bank') {
            $r = $conn->query("SELECT bank_name FROM bank_accounts WHERE id = " . (int)$row['from_id']);
            if ($r && $rr = $r->fetch_assoc()) $fromLabel = 'Bank: ' . $rr['bank_name'];
        }

        if ($row['to_type'] === 'safe') {
            $r = $conn->query("SELECT name FROM safes WHERE id = " . (int)$row['to_id']);
            if ($r && $rr = $r->fetch_assoc()) $toLabel = 'Safe: ' . $rr['name'];
        } elseif ($row['to_type'] === 'bank') {
            $r = $conn->query("SELECT bank_name FROM bank_accounts WHERE id = " . (int)$row['to_id']);
            if ($r && $rr = $r->fetch_assoc()) $toLabel = 'Bank: ' . $rr['bank_name'];
        }

        $allTransactions[] = [
            'date' => fmtDate($row['created_at']),
            'source' => 'Transfer',
            'ref' => 'TRF-' . $row['id'],
            'account' => $fromLabel . ' -> ' . $toLabel,
            'inflow' => 0,
            'outflow' => fmtNum($row['amount']),
            'note' => $row['notes'] ?? '',
        ];
    }
}

// Sort all transactions by date descending
usort($allTransactions, function($a, $b) {
    return strcmp($b['date'], $a['date']);
});

$cbHeaders = ['<b>Date</b>', '<b>Source</b>', '<b>Reference</b>', '<b>Account</b>', '<b>Inflow</b>', '<b>Outflow</b>', '<b>Note</b>'];
$cbData[] = $cbHeaders;

$totalInflow = 0;
$totalOutflow = 0;
$cbRowCount = 0;

foreach ($allTransactions as $t) {
    $cbData[] = [
        $t['date'],
        $t['source'],
        $t['ref'],
        $t['account'],
        $t['inflow'],
        $t['outflow'],
        $t['note'],
    ];
    $totalInflow += $t['inflow'];
    $totalOutflow += $t['outflow'];
    $cbRowCount++;
}

$cbData[] = [''];
$cbData[] = [
    '<b>Totals (' . $cbRowCount . ' transactions)</b>', '', '', '',
    $totalInflow, $totalOutflow, ''
];
$cbData[] = ['<b>Net Flow</b>', '', '', '', ($totalInflow - $totalOutflow), '', ''];

$xlsx->addSheet($cbData, '4. Cash & Bank');

// ============================================================
// SHEET 4: Inventory
// ============================================================
$invData = [];
$invData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Inventory Valuation</style></b>'
];
$invData[] = [''];

$invSql = "SELECT p.sku, p.name as product_name, p.type, p.unit, p.cost_price, p.min_stock_level,
                   c1.name as category_name, c2.name as subcategory_name,
                   COALESCE((SELECT SUM(ip.quantity) FROM inventory_products ip
                             JOIN inventories inv ON ip.inventory_id = inv.id
                             WHERE ip.product_id = p.id AND inv.is_active = 1), 0) AS total_quantity
            FROM products p
            LEFT JOIN categories c1 ON p.category_id = c1.id
            LEFT JOIN categories c2 ON p.subcategory_id = c2.id
            ORDER BY p.name";
$invResult = $conn->query($invSql);
if (!$invResult) { $invResult = null; }

$invHeaders = ['<b>SKU</b>', '<b>Product</b>', '<b>Category</b>', '<b>Subcategory</b>', '<b>Type</b>', '<b>Unit</b>', '<b>Quantity</b>', '<b>Unit Cost</b>', '<b>Total Value</b>', '<b>Min Stock</b>', '<b>Low Stock</b>'];
$invData[] = $invHeaders;
if ($invResult === null) {
    appendQueryErrorRow($invData, $conn->error, count($invHeaders));
}

$invTotalQty = 0;
$invTotalValue = 0;
$invRowCount = 0;
$lowStockCount = 0;

if ($invResult) {
    while ($row = $invResult->fetch_assoc()) {
        $qty = fmtNum($row['total_quantity']);
        $cost = fmtNum($row['cost_price']);
        $totalValue = $qty * $cost;
        $minStock = (int)($row['min_stock_level'] ?? 0);
        $lowStock = ($qty <= $minStock && $minStock > 0) ? 'Yes' : 'No';

        if ($lowStock === 'Yes') $lowStockCount++;

        $invData[] = [
            $row['sku'] ?? '',
            $row['product_name'] ?? '',
            $row['category_name'] ?? '',
            $row['subcategory_name'] ?? '',
            ucfirst($row['type'] ?? ''),
            ucfirst($row['unit'] ?? ''),
            $qty,
            $cost,
            $totalValue,
            $minStock,
            $lowStock,
        ];
        $invTotalQty += $qty;
        $invTotalValue += $totalValue;
        $invRowCount++;
    }
}

$invData[] = [''];
$invData[] = [
    '<b>Totals (' . $invRowCount . ' products)</b>', '', '', '', '', '',
    $invTotalQty, '', $invTotalValue, '', $lowStockCount . ' low-stock items'
];

$xlsx->addSheet($invData, '5. Inventory');

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

$purHeaders = ['<b>PO Ref</b>', '<b>Vendor</b>', '<b>Order Date</b>', '<b>Product</b>', '<b>SKU</b>', '<b>Qty Ordered</b>', '<b>Qty Received</b>', '<b>Unit Price</b>', '<b>Line Total</b>', '<b>PO Total</b>', '<b>PO Paid</b>', '<b>PO Balance</b>', '<b>Status</b>'];
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
        $poBalance = fmtNum($row['total_amount']) - fmtNum($row['paid_amount']);

        $purData[] = [
            'PO-' . $row['id'],
            $row['vendor_name'] ?? '',
            fmtDate($row['order_date']),
            $row['product_name'] ?? '',
            $row['sku'] ?? '',
            fmtNum($row['quantity']),
            fmtNum($row['received_quantity']),
            fmtNum($row['unit_price']),
            fmtNum($row['line_total']),
            fmtNum($row['total_amount']),
            fmtNum($row['paid_amount']),
            $poBalance,
            statusLabel($row['status']),
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
    '<b>Totals (' . $purRowCount . ' lines)</b>', '', '', '', '', '', '', '', '',
    $purTotalOrdered, $purTotalPaid, ($purTotalOrdered - $purTotalPaid), ''
];

$xlsx->addSheet($purData, '6. Purchasing');

// ============================================================
// SHEET 6: Sales & Billing
// ============================================================
$salesData = [];
$salesData[] = [
    '<b><style color="FFFFFF" bgcolor="1F4E79" height="22" font-size="12">Sales & Billing Register</style></b>'
];
$salesData[] = [''];

$salesWhere = dateFilterClause('o.order_date', $dateFrom, $dateTo);
$salesSql = "SELECT o.id, o.internal_id, o.order_date, o.status, o.total_amount, o.paid_amount, o.discount_amount, o.shipping_cost, o.currency,
                    c.name as customer_name, ct.name as customer_type,
                    oi.product_id, oi.quantity, oi.unit_price, oi.total_price as line_total, oi.is_free_sample,
                    p.name as product_name, p.sku
             FROM orders o
             LEFT JOIN customers c ON o.customer_id = c.id
             LEFT JOIN customer_types ct ON c.type = ct.id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             LEFT JOIN products p ON oi.product_id = p.id";
if (!empty($salesWhere)) {
    $salesSql .= ' WHERE ' . implode(' AND ', $salesWhere);
}
$salesSql .= ' ORDER BY o.order_date DESC, o.id DESC, oi.id ASC';
$salesResult = $conn->query($salesSql);
if (!$salesResult) { $salesResult = null; }

$salesHeaders = ['<b>Order No</b>', '<b>Customer</b>', '<b>Type</b>', '<b>Order Date</b>', '<b>Product</b>', '<b>SKU</b>', '<b>Qty</b>', '<b>Unit Price</b>', '<b>Line Total</b>', '<b>Discount</b>', '<b>Shipping</b>', '<b>Order Total</b>', '<b>Paid</b>', '<b>Balance</b>', '<b>Currency</b>', '<b>Status</b>'];
$salesData[] = $salesHeaders;
if ($salesResult === null) {
    appendQueryErrorRow($salesData, $conn->error, count($salesHeaders));
}

$salesTotalAmount = 0;
$salesTotalPaid = 0;
$salesRowCount = 0;
$prevOrderId = null;

if ($salesResult) {
    while ($row = $salesResult->fetch_assoc()) {
        $orderBalance = fmtNum($row['total_amount']) - fmtNum($row['paid_amount']);
        $isSample = ($row['is_free_sample'] == 1) ? 'Yes' : '';

        $salesData[] = [
            $row['internal_id'] ?? ('ORD-' . $row['id']),
            $row['customer_name'] ?? '',
            ucfirst($row['customer_type'] ?? ''),
            fmtDate($row['order_date']),
            $row['product_name'] ?? '',
            $row['sku'] ?? '',
            fmtNum($row['quantity']),
            fmtNum($row['unit_price']),
            fmtNum($row['line_total']),
            fmtNum($row['discount_amount']),
            fmtNum($row['shipping_cost']),
            fmtNum($row['total_amount']),
            fmtNum($row['paid_amount']),
            $orderBalance,
            $row['currency'] ?? 'EGP',
            statusLabel($row['status']),
        ];

        if ($prevOrderId !== $row['id']) {
            $salesTotalAmount += fmtNum($row['total_amount']);
            $salesTotalPaid += fmtNum($row['paid_amount']);
            $prevOrderId = $row['id'];
        }
        $salesRowCount++;
    }
}

$salesData[] = [''];
$salesData[] = [
    '<b>Totals (' . $salesRowCount . ' lines)</b>', '', '', '', '', '', '', '', '', '', '',
    $salesTotalAmount, $salesTotalPaid, ($salesTotalAmount - $salesTotalPaid), '', ''
];

$xlsx->addSheet($salesData, '7. Sales & Billing');

// ============================================================
// DOWNLOAD
// ============================================================
$xlsx->downloadAs($filename);
exit();
