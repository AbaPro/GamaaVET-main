<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$reports = [
    'revenue' => ['title' => 'Revenue Collected', 'allowed' => hasPermission('sales.orders.price.view')],
    'cogs' => ['title' => 'COGS', 'allowed' => canViewProductCost('final')],
    'material' => ['title' => 'Material Cost', 'allowed' => ($_SESSION['login_region'] ?? 'factory') === 'factory' && canViewProductCost('material')],
];
$metric = is_string($_GET['metric'] ?? null) ? $_GET['metric'] : '';
if (!isset($reports[$metric]) || !$reports[$metric]['allowed']) {
    setAlert('danger', 'Access denied.');
    redirect('index.php');
    exit;
}

require_once __DIR__ . '/dashboard_reporting.php';
$rows = [];
$totals = [];
$missingProducts = [];
$units = 0;
$purchaseOrders = [];
if ($metric === 'revenue') {
    $result = $conn->query("
        SELECT o.id, o.internal_id, op.transaction_date AS order_date, op.payment_method, op.reference,
               fd_customer.name AS customer_name, COALESCE(o.currency, 'EGP') AS currency, op.amount
        FROM $revenuePaymentSql
        ORDER BY op.transaction_date DESC, op.id DESC
    ");
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $totals[$row['currency']]['total'] = ($totals[$row['currency']]['total'] ?? 0) + (float)$row['amount'];
    }
    ksort($totals);
} elseif ($metric === 'cogs') {
    // Match the card: all returns against these lines, valued at current product cost.
    $returnsJoin = tableExists('order_returns')
        ? 'LEFT JOIN (SELECT order_item_id, SUM(returned_quantity) AS qty FROM order_returns GROUP BY order_item_id) ret ON ret.order_item_id = oi.id'
        : 'LEFT JOIN (SELECT NULL AS order_item_id, 0 AS qty) ret ON 1=0';
    $result = $conn->query("
        SELECT oi.id AS item_id, oi.product_id, oi.quantity, COALESCE(ret.qty, 0) AS returned_quantity,
               GREATEST(oi.quantity - COALESCE(ret.qty, 0), 0) AS net_quantity,
               o.id, o.internal_id, o.order_date, fd_customer.name AS customer_name, p.name AS product_name
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        $orderJoins $returnsJoin
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE $orderScope AND $orderDateSql
        ORDER BY o.order_date DESC, o.id DESC, oi.id
    ");
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $costDetails = getCalculatedProductCostDetails(array_column($rows, 'product_id'));
    $total = 0;
    foreach ($rows as &$row) {
        $row['unit_cost'] = $costDetails[(int)$row['product_id']]['value'] ?? null;
        $qty = (float)$row['net_quantity'];
        $units += $qty;
        $row['cost'] = $row['unit_cost'] === null ? null : $qty * (float)$row['unit_cost'];
        if ($qty > 0 && $row['unit_cost'] === null) {
            $missingProducts[(int)$row['product_id']] = true;
        }
        $total += $row['cost'] ?? 0;
    }
    unset($row);
    $totals['EGP'] = ['total' => $total];
} else {
    $poDateSql = financeDashboardDateSql('po.order_date', $dateFrom, $dateTo);
    $result = $conn->query("
        SELECT po.id, po.order_date, po.status, v.name AS vendor_name, p.name AS product_name,
               poi.received_quantity, poi.unit_price, poi.received_quantity * poi.unit_price AS cost
        FROM purchase_order_items poi
        JOIN purchase_orders po ON po.id = poi.purchase_order_id
        JOIN products p ON p.id = poi.product_id
        LEFT JOIN vendors v ON v.id = po.vendor_id
        WHERE p.type = 'material' AND po.status <> 'cancelled'
          AND poi.received_quantity > 0 AND $poDateSql
        ORDER BY po.order_date DESC, po.id DESC, poi.id
    ");
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $total = 0;
    foreach ($rows as $row) {
        $total += (float)$row['cost'];
        $purchaseOrders[(int)$row['id']] = true;
    }
    $totals['EGP'] = ['total' => $total];
}

$page_title = $reports[$metric]['title'] . ' Details';
$canOpenSource = hasPermission($metric === 'material' ? 'purchases.view' : 'sales.orders.view');
require_once '../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h2 class="mb-1"><?= e($page_title); ?></h2><div class="text-muted"><?= e($periodLabel); ?> · <?= number_format(count($rows)); ?> record<?= count($rows) === 1 ? '' : 's'; ?></div></div>
    <a href="index.php?<?= e($reportQuery); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Finance Dashboard</a>
</div>

<?php require __DIR__ . '/period_filter.php'; ?>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <?php if (!$totals): ?><div class="h3 mb-0"><?= e(formatCurrency(0)); ?></div><?php endif; ?>
    <?php foreach ($totals as $currency => $summary): ?>
        <div class="h3 mb-1"><?= e(formatCurrency($summary['total'], $currency)); ?></div>
    <?php endforeach; ?>
    <div class="small text-muted mt-2">
        <?php if ($metric === 'revenue'): ?>Customer payments recorded against orders, by payment date, within the selected period. Unpaid balances are not revenue until collected; see Accounts Receivable.
        <?php elseif ($metric === 'cogs'): ?><?= number_format($units); ?> units sold, net of all recorded returns. Net quantity × current calculated product cost; costs can change when product costing changes.
        <?php else: ?>Received material quantities × purchase unit price, from <?= number_format(count($purchaseOrders)); ?> purchase orders dated within the selected period. Cancelled orders are excluded.
        <?php endif; ?>
    </div>
    <?php if ($missingProducts): ?><div class="text-danger small mt-2"><?= count($missingProducts); ?> products without cost are excluded from the total and marked below.</div><?php endif; ?>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body">
    <label for="reportSearch" class="form-label">Search records</label>
    <input type="search" id="reportSearch" class="form-control mb-3" placeholder="Search by order, name or date…">
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead><tr>
                <th><?= $metric === 'material' ? 'Purchase Order' : 'Order'; ?></th><th><?= $metric === 'revenue' ? 'Payment Date' : 'Date'; ?></th><th><?= $metric === 'material' ? 'Vendor' : 'Customer'; ?></th>
                <?php if ($metric === 'revenue'): ?>
                    <th>Method</th><th>Reference</th><th>Currency</th><th class="text-end">Amount</th>
                <?php elseif ($metric === 'cogs'): ?>
                    <th>Product</th><th class="text-end">Sold</th><th class="text-end">Returned</th><th class="text-end">Net Quantity</th><th class="text-end">Current Unit Cost</th><th class="text-end">Cost</th>
                <?php else: ?>
                    <th>Status</th><th>Material</th><th class="text-end">Received Quantity</th><th class="text-end">Unit Price</th><th class="text-end">Cost</th>
                <?php endif; ?>
            </tr></thead>
            <tbody id="reportRows">
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="text-nowrap">
                        <?php $label = $metric === 'material' ? 'PO #' . $row['id'] : ($row['internal_id'] ?: '#' . $row['id']); ?>
                        <?php if ($canOpenSource): ?>
                            <a href="<?= $metric === 'material' ? '../purchases/po_details.php' : '../sales/order_details.php'; ?>?id=<?= (int)$row['id']; ?>"><?= e($label); ?></a>
                        <?php else: ?><?= e($label); ?><?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= e($row['order_date']); ?></td>
                    <td><?= e(($metric === 'material' ? $row['vendor_name'] : $row['customer_name']) ?? ''); ?></td>
                    <?php if ($metric === 'revenue'): ?>
                        <td><?= e(ucfirst($row['payment_method'])); ?></td><td><?= e($row['reference'] ?? ''); ?></td><td><?= e($row['currency']); ?></td>
                        <td class="text-end text-nowrap"><?= e(formatCurrency($row['amount'], $row['currency'])); ?></td>
                    <?php elseif ($metric === 'cogs'): ?>
                        <td><?= e($row['product_name'] ?? 'Product #' . $row['product_id']); ?></td>
                        <td class="text-end"><?= number_format((float)$row['quantity']); ?></td>
                        <td class="text-end"><?= number_format((float)$row['returned_quantity']); ?></td>
                        <td class="text-end"><?= number_format((float)$row['net_quantity']); ?></td>
                        <td class="text-end text-nowrap"><?= $row['unit_cost'] === null ? '<span class="text-danger">Missing cost</span>' : e(formatCurrency($row['unit_cost'])); ?></td>
                        <td class="text-end text-nowrap"><?= $row['cost'] === null ? '<span class="text-muted">Excluded</span>' : e(formatCurrency($row['cost'])); ?></td>
                    <?php else: ?>
                        <td><?= e($row['status']); ?></td><td><?= e($row['product_name']); ?></td>
                        <td class="text-end"><?= number_format((float)$row['received_quantity']); ?></td>
                        <td class="text-end text-nowrap"><?= e(formatCurrency($row['unit_price'])); ?></td>
                        <td class="text-end text-nowrap"><?= e(formatCurrency($row['cost'])); ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="reportEmpty" class="text-muted py-3 <?= $rows ? 'd-none' : ''; ?>">No matching records.</div>
</div></div>
<script>
document.getElementById('reportSearch').addEventListener('input', function () {
    const search = this.value.trim().toLocaleLowerCase();
    let visible = 0;
    document.querySelectorAll('#reportRows tr').forEach(function (row) {
        const matches = row.textContent.toLocaleLowerCase().includes(search);
        row.hidden = !matches;
        if (matches) visible++;
    });
    document.getElementById('reportEmpty').classList.toggle('d-none', visible > 0);
});
</script>
<?php require_once '../../includes/footer.php'; ?>
