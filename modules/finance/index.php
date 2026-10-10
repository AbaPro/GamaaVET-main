<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_balance_adjustments.php';

$loginRegion = $_SESSION['login_region'] ?? 'factory';
$canSettle = canSettleFinanceBalances();
$canCreateTransfers = hasPermission('finance.transfers.create');
$canTransferAccess = $canCreateTransfers || hasPermission('finance.transfers.approve');

// Mirror the access checks of each details page so every card we render opens.
$canViewSafes = $canTransferAccess || $canSettle
    || hasPermission('finance.safes.create') || hasPermission('finance.safes.edit')
    || hasPermission('finance.safes.delete') || hasPermission('finance.safes.balance.edit');
$canViewBanks = $canTransferAccess || $canSettle
    || hasPermission('finance.bank_accounts.create') || hasPermission('finance.bank_accounts.edit')
    || hasPermission('finance.bank_accounts.delete') || hasPermission('finance.bank_accounts.balance.edit');
$canViewPersonal = $canTransferAccess || $canSettle
    || hasPermission('finance.personal_accounts.create') || hasPermission('finance.personal_accounts.delete');
$canViewRevenue = hasPermission('sales.orders.price.view');
$canViewCogs = canViewProductCost('final');
$canViewMaterialCost = $loginRegion === 'factory' && canViewProductCost('material');
$canCreateExpenses = hasPermission('finance.expenses.manage');

if (!$canViewSafes && !$canViewBanks && !$canViewPersonal && !$canViewRevenue
    && !$canViewCogs && !$canViewMaterialCost && !$canCreateExpenses) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

// ---- Reporting period -------------------------------------------------------
$periods = [
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'this_year' => 'This Year',
    'all' => 'All Time',
    'custom' => 'Custom',
];
$period = (string)($_GET['period'] ?? 'this_month');
if (!isset($periods[$period])) {
    $period = 'this_month';
}
$today = new DateTimeImmutable('today');
$dateFrom = null;
$dateTo = null;
switch ($period) {
    case 'this_month':
        $dateFrom = $today->modify('first day of this month')->format('Y-m-d');
        $dateTo = $today->format('Y-m-d');
        break;
    case 'last_month':
        $dateFrom = $today->modify('first day of last month')->format('Y-m-d');
        $dateTo = $today->modify('last day of last month')->format('Y-m-d');
        break;
    case 'this_year':
        $dateFrom = $today->format('Y-01-01');
        $dateTo = $today->format('Y-m-d');
        break;
    case 'custom':
        $dateFrom = normalizeTransactionDate($_GET['from'] ?? '');
        $dateTo = normalizeTransactionDate($_GET['to'] ?? '');
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        break;
}

function financeDashboardDateSql($column, $dateFrom, $dateTo) {
    global $conn;
    $parts = [];
    if ($dateFrom) $parts[] = "$column >= '" . $conn->real_escape_string($dateFrom) . "'";
    if ($dateTo) $parts[] = "$column <= '" . $conn->real_escape_string($dateTo) . "'";
    return $parts ? implode(' AND ', $parts) : '1=1';
}

function financeDashboardTotalsByCurrency(array $rows) {
    $totals = [];
    foreach ($rows as $row) {
        $currency = $row['currency'] ?: 'EGP';
        $totals[$currency] = ($totals[$currency] ?? 0) + (float)$row['balance'];
    }
    ksort($totals);
    return $totals;
}

$orderScope = getCustomerChannelScopeSql('fd_customer', 'fd_factory');
$orderJoins = 'JOIN customers fd_customer ON fd_customer.id = o.customer_id
               LEFT JOIN factories fd_factory ON fd_factory.id = fd_customer.factory_id';
$orderDateSql = financeDashboardDateSql('o.order_date', $dateFrom, $dateTo);

// ---- Revenue: invoiced order totals per currency -----------------------------
$revenue = [];
if ($canViewRevenue) {
    $result = $conn->query("
        SELECT COALESCE(o.currency, 'EGP') AS currency, COUNT(*) AS orders,
               SUM(o.total_amount) AS total, SUM(o.paid_amount) AS paid
        FROM orders o
        $orderJoins
        WHERE $orderScope AND $orderDateSql
        GROUP BY COALESCE(o.currency, 'EGP')
        ORDER BY currency
    ");
    $revenue = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ---- COGS: sold quantities (net of returns) x current calculated unit cost ---
$cogs = ['total' => 0.0, 'units' => 0, 'uncosted_products' => 0];
if ($canViewCogs) {
    $returnsJoin = tableExists('order_returns')
        ? 'LEFT JOIN (SELECT order_item_id, SUM(returned_quantity) AS qty FROM order_returns GROUP BY order_item_id) ret ON ret.order_item_id = oi.id'
        : 'LEFT JOIN (SELECT NULL AS order_item_id, 0 AS qty) ret ON 1=0';
    $result = $conn->query("
        SELECT oi.product_id, SUM(GREATEST(oi.quantity - COALESCE(ret.qty, 0), 0)) AS qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        $orderJoins
        $returnsJoin
        WHERE $orderScope AND $orderDateSql
        GROUP BY oi.product_id
    ");
    $soldQuantities = [];
    while ($result && $row = $result->fetch_assoc()) {
        if ((float)$row['qty'] > 0) {
            $soldQuantities[(int)$row['product_id']] = (float)$row['qty'];
        }
    }
    $costDetails = getCalculatedProductCostDetails(array_keys($soldQuantities));
    foreach ($soldQuantities as $productId => $qty) {
        $unitCost = $costDetails[$productId]['value'] ?? null;
        $cogs['units'] += $qty;
        if ($unitCost === null) {
            $cogs['uncosted_products']++;
            continue;
        }
        $cogs['total'] += $qty * (float)$unitCost;
    }
}

// ---- Material cost: received material purchases ------------------------------
$materialCost = ['total' => 0.0, 'orders' => 0];
if ($canViewMaterialCost) {
    $poDateSql = financeDashboardDateSql('po.order_date', $dateFrom, $dateTo);
    $result = $conn->query("
        SELECT COALESCE(SUM(poi.received_quantity * poi.unit_price), 0) AS total,
               COUNT(DISTINCT po.id) AS orders
        FROM purchase_order_items poi
        JOIN purchase_orders po ON po.id = poi.purchase_order_id
        JOIN products p ON p.id = poi.product_id
        WHERE p.type = 'material'
          AND po.status <> 'cancelled'
          AND poi.received_quantity > 0
          AND $poDateSql
    ");
    $row = $result ? $result->fetch_assoc() : null;
    $materialCost = ['total' => (float)($row['total'] ?? 0), 'orders' => (int)($row['orders'] ?? 0)];
}

// ---- Balances ----------------------------------------------------------------
$safes = [];
if ($canViewSafes) {
    $safeScope = getSafeScopeSql('s');
    $safes = $conn->query("
        SELECT s.id, s.name, s.balance, s.currency, l.name AS location_name
        FROM safes s
        LEFT JOIN locations l ON l.id = s.location_id
        WHERE $safeScope
        ORDER BY l.name, s.name
    ")->fetch_all(MYSQLI_ASSOC);
}

$banks = [];
if ($canViewBanks) {
    $bankScope = getAccountScopeSql('b');
    $banks = $conn->query("
        SELECT b.id, b.bank_name, b.account_number, b.balance, b.currency
        FROM bank_accounts b
        WHERE $bankScope
        ORDER BY b.bank_name, b.account_number
    ")->fetch_all(MYSQLI_ASSOC);
}

$personalAccounts = [];
if ($canViewPersonal) {
    $personalScope = getAccountScopeSql('pa');
    $personalAccounts = $conn->query("
        SELECT pa.id, pa.name, pa.email, pa.balance, 'EGP' AS currency
        FROM personal_accounts pa
        WHERE pa.is_active = 1 AND $personalScope
        ORDER BY pa.name
    ")->fetch_all(MYSQLI_ASSOC);
}

$pendingTransfers = 0;
if ($canTransferAccess) {
    $transferScope = financeTransferScopeSql('f');
    $result = $conn->query("SELECT COUNT(*) AS c FROM finance_transfers f WHERE f.status = 'pending' AND $transferScope");
    $pendingTransfers = (int)($result ? ($result->fetch_assoc()['c'] ?? 0) : 0);
}

$balanceSections = [
    [
        'key' => 'safe',
        'title' => 'Safes',
        'icon' => 'fa-vault',
        'list_url' => 'safes.php',
        'details_url' => 'safe_details.php',
        'rows' => $safes,
        'visible' => $canViewSafes,
    ],
    [
        'key' => 'bank',
        'title' => 'Bank Accounts',
        'icon' => 'fa-university',
        'list_url' => 'banks.php',
        'details_url' => 'bank_details.php',
        'rows' => $banks,
        'visible' => $canViewBanks,
    ],
    [
        'key' => 'personal',
        'title' => 'Personal Accounts',
        'icon' => 'fa-user-shield',
        'list_url' => 'personal.php',
        'details_url' => 'personal_details.php',
        'rows' => $personalAccounts,
        'visible' => $canViewPersonal,
    ],
];

// Normalise each row's display fields once so the list and card views stay identical.
foreach ($balanceSections as &$section) {
    foreach ($section['rows'] as &$row) {
        $row['currency'] = $row['currency'] ?: 'EGP';
        if ($section['key'] === 'safe') {
            $row['display_name'] = $row['name'];
            $row['display_meta'] = $row['location_name'] ?: '';
        } elseif ($section['key'] === 'bank') {
            $row['display_name'] = $row['bank_name'];
            $row['display_meta'] = '#' . $row['account_number'];
        } else {
            $row['display_name'] = $row['name'];
            $row['display_meta'] = $row['email'] ?: '';
        }
    }
    unset($row);
    $section['meta_label'] = ['safe' => 'Location', 'bank' => 'Account #', 'personal' => 'Email'][$section['key']];
    $section['totals'] = financeDashboardTotalsByCurrency($section['rows']);
}
unset($section);
$visibleSections = array_values(array_filter($balanceSections, static fn($section) => $section['visible']));

$transferUrl = 'transfers.php?new=1';
$periodLabel = $period === 'all'
    ? 'All time'
    : (($dateFrom ? date('d M Y', strtotime($dateFrom)) : 'Start') . ' – ' . ($dateTo ? date('d M Y', strtotime($dateTo)) : 'Today'));

$page_title = 'Finance Dashboard';
require_once '../../includes/header.php';
?>

<style>
    .fd-account-card { transition: box-shadow .15s ease; }
    .fd-account-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }
    .fd-account-card .fd-action { position: relative; z-index: 2; }
    /* Same column widths in every section so the tables line up on desktop. */
    @media (min-width: 768px) {
        .fd-table { table-layout: fixed; }
        .fd-table th:nth-child(1) { width: 30%; }
        .fd-table th:nth-child(2) { width: 25%; }
        .fd-table th:nth-child(3) { width: 10%; }
        .fd-table th:nth-child(4) { width: 15%; }
        .fd-table th:nth-child(5) { width: 20%; }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h2 class="mb-1">Finance Dashboard</h2><div class="text-muted">Cash position, revenue and costs at a glance.</div></div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canTransferAccess && $pendingTransfers > 0): ?>
            <a href="transfers.php" class="btn btn-outline-warning"><i class="fas fa-hourglass-half me-1"></i><?= number_format($pendingTransfers); ?> Pending Transfer<?= $pendingTransfers === 1 ? '' : 's'; ?></a>
        <?php endif; ?>
        <?php if ($canCreateExpenses): ?>
            <a href="expenses/create.php" class="btn btn-outline-danger"><i class="fas fa-money-bill-wave me-1"></i>Quick Expense</a>
        <?php endif; ?>
        <?php if ($canCreateTransfers): ?>
            <a href="<?= e($transferUrl); ?>" class="btn btn-primary"><i class="fas fa-right-left me-1"></i>Quick Transfer</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($canViewRevenue || $canViewCogs || $canViewMaterialCost): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label for="fdPeriod" class="form-label mb-1">Period</label>
            <select class="form-select" id="fdPeriod" name="period">
                <?php foreach ($periods as $key => $label): ?>
                    <option value="<?= e($key); ?>" <?= $period === $key ? 'selected' : ''; ?>><?= e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 fd-custom-range <?= $period === 'custom' ? '' : 'd-none'; ?>">
            <label for="fdFrom" class="form-label mb-1">From</label>
            <input type="date" class="form-control" id="fdFrom" name="from" value="<?= e($period === 'custom' ? ($dateFrom ?? '') : ''); ?>">
        </div>
        <div class="col-md-2 fd-custom-range <?= $period === 'custom' ? '' : 'd-none'; ?>">
            <label for="fdTo" class="form-label mb-1">To</label>
            <input type="date" class="form-control" id="fdTo" name="to" value="<?= e($period === 'custom' ? ($dateTo ?? '') : ''); ?>">
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button></div>
        <div class="col text-end text-muted small align-self-center"><i class="far fa-calendar me-1"></i><?= e($periodLabel); ?></div>
    </form>
</div></div>

<div class="row mb-3">
    <?php if ($canViewRevenue): ?>
    <div class="col-xl-4 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div class="small text-uppercase text-muted fw-bold">Total Revenue</div>
            <span class="badge bg-light text-dark border"><i class="fas fa-money-bill-trend-up"></i></span>
        </div>
        <?php if (!$revenue): ?>
            <div class="h3 mb-2 text-success"><?= e(formatCurrency(0)); ?></div>
            <div class="small text-muted">No orders in this period</div>
        <?php else: foreach ($revenue as $row): ?>
            <div class="h3 mb-1 text-success"><?= e(formatCurrency((float)$row['total'], $row['currency'])); ?></div>
            <div class="small text-muted mb-2"><?= number_format((int)$row['orders']); ?> order<?= (int)$row['orders'] === 1 ? '' : 's'; ?> · <?= e(formatCurrency((float)$row['paid'], $row['currency'])); ?> collected</div>
        <?php endforeach; endif; ?>
    </div></div></div>
    <?php endif; ?>

    <?php if ($canViewCogs): ?>
    <div class="col-xl-4 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div class="small text-uppercase text-muted fw-bold">COGS</div>
            <span class="badge bg-light text-dark border"><i class="fas fa-boxes-stacked"></i></span>
        </div>
        <div class="h3 mb-2"><?= e(formatCurrency($cogs['total'])); ?></div>
        <div class="small text-muted"><?= number_format($cogs['units']); ?> unit<?= $cogs['units'] == 1 ? '' : 's'; ?> sold (net of returns) · current product cost</div>
        <?php if ($cogs['uncosted_products'] > 0): ?>
            <div class="small text-danger mt-1"><i class="fas fa-triangle-exclamation me-1"></i><?= number_format($cogs['uncosted_products']); ?> product<?= $cogs['uncosted_products'] === 1 ? '' : 's'; ?> without cost excluded</div>
        <?php endif; ?>
    </div></div></div>
    <?php endif; ?>

    <?php if ($canViewMaterialCost): ?>
    <div class="col-xl-4 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div class="small text-uppercase text-muted fw-bold">Material Cost</div>
            <span class="badge bg-light text-dark border"><i class="fas fa-flask"></i></span>
        </div>
        <div class="h3 mb-2"><?= e(formatCurrency($materialCost['total'])); ?></div>
        <div class="small text-muted">Raw materials received · <?= number_format($materialCost['orders']); ?> purchase order<?= $materialCost['orders'] === 1 ? '' : 's'; ?></div>
    </div></div></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($visibleSections): ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0">Balances</h4>
    <div class="btn-group btn-group-sm" role="group" aria-label="Balance view">
        <button type="button" class="btn btn-outline-secondary js-fd-view active" data-view="list" aria-pressed="true"><i class="fas fa-list me-1"></i>List</button>
        <button type="button" class="btn btn-outline-secondary js-fd-view" data-view="cards" aria-pressed="false"><i class="fas fa-grip me-1"></i>Cards</button>
    </div>
</div>
<?php endif; ?>

<?php foreach ($visibleSections as $section): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="mb-0"><i class="fas <?= e($section['icon']); ?> me-2 text-muted"></i><?= e($section['title']); ?>
            <span class="badge bg-light text-dark border ms-1"><?= count($section['rows']); ?></span>
        </h5>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <?php foreach ($section['totals'] as $currency => $total): ?>
                <span class="small text-muted">Total <?= e($currency); ?>: <span class="fw-semibold <?= $total < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency($total, $currency)); ?></span></span>
            <?php endforeach; ?>
            <a href="<?= e($section['list_url']); ?>" class="btn btn-sm btn-outline-secondary">View All<i class="fas fa-arrow-right ms-1"></i></a>
        </div>
    </div>

    <?php if (!$section['rows']): ?>
        <div class="text-muted">No <?= e(strtolower($section['title'])); ?> found.</div>
    <?php else: ?>
    <div class="fd-view fd-view-list">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0 fd-table">
                <thead><tr><th>Name</th><th class="d-none d-md-table-cell"><?= e($section['meta_label']); ?></th><th class="d-none d-sm-table-cell">Currency</th><th class="text-end">Balance</th><th class="d-none d-sm-table-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($section['rows'] as $row): $detailsUrl = $section['details_url'] . '?id=' . (int)$row['id']; ?>
                    <tr>
                        <td>
                            <a href="<?= e($detailsUrl); ?>" class="fw-semibold text-decoration-none"><i class="fas <?= e($section['icon']); ?> me-1"></i><?= e($row['display_name']); ?></a>
                            <?php if ($row['display_meta'] !== ''): ?><div class="small text-muted text-break d-md-none"><?= e($row['display_meta']); ?></div><?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell text-break"><?= $row['display_meta'] !== '' ? e($row['display_meta']) : '<span class="text-muted">Unassigned</span>'; ?></td>
                        <td class="d-none d-sm-table-cell"><span class="badge bg-light text-dark border"><?= e($row['currency']); ?></span></td>
                        <td class="text-end text-nowrap fw-semibold <?= (float)$row['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency((float)$row['balance'], $row['currency'])); ?></td>
                        <td class="text-nowrap d-none d-sm-table-cell">
                            <a href="<?= e($detailsUrl); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-history me-1"></i>Details</a>
                            <?php if ($canCreateTransfers): ?>
                                <a href="<?= e($transferUrl . '&from_type=' . $section['key'] . '&from_id=' . (int)$row['id']); ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-right-left me-1"></i>Transfer</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="fd-view fd-view-cards d-none">
        <div class="row g-3">
        <?php foreach ($section['rows'] as $row): $balance = (float)$row['balance']; ?>
            <div class="col-xl-3 col-lg-4 col-sm-6">
                <div class="card fd-account-card border h-100 position-relative"><div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <a href="<?= e($section['details_url']); ?>?id=<?= (int)$row['id']; ?>" class="stretched-link fw-semibold text-decoration-none text-truncate" title="<?= e($row['display_name']); ?>"><i class="fas <?= e($section['icon']); ?> me-1"></i><?= e($row['display_name']); ?></a>
                        <span class="badge bg-light text-dark border ms-2"><?= e($row['currency']); ?></span>
                    </div>
                    <div class="small text-muted text-truncate mb-2"><?= $row['display_meta'] !== '' ? e($row['display_meta']) : 'Unassigned'; ?></div>
                    <div class="h4 mb-3 <?= $balance < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency($balance, $row['currency'])); ?></div>
                    <?php if ($canCreateTransfers): ?>
                        <div class="mt-auto">
                            <a href="<?= e($transferUrl . '&from_type=' . $section['key'] . '&from_id=' . (int)$row['id']); ?>" class="fd-action btn btn-sm btn-outline-secondary"><i class="fas fa-right-left me-1"></i>Transfer</a>
                        </div>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div></div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const period = document.getElementById('fdPeriod');
    if (period) {
        period.addEventListener('change', function () {
            document.querySelectorAll('.fd-custom-range').forEach(function (el) {
                el.classList.toggle('d-none', period.value !== 'custom');
            });
        });
    }

    // List is the default; the viewer's last choice is remembered when storage is available.
    const storageKey = 'financeDashboardView';
    function applyView(view) {
        document.querySelectorAll('.fd-view-list').forEach(function (el) { el.classList.toggle('d-none', view !== 'list'); });
        document.querySelectorAll('.fd-view-cards').forEach(function (el) { el.classList.toggle('d-none', view !== 'cards'); });
        document.querySelectorAll('.js-fd-view').forEach(function (btn) {
            const active = btn.dataset.view === view;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }
    let savedView = 'list';
    try { savedView = localStorage.getItem(storageKey) === 'cards' ? 'cards' : 'list'; } catch (e) {}
    applyView(savedView);
    document.querySelectorAll('.js-fd-view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyView(btn.dataset.view);
            try { localStorage.setItem(storageKey, btn.dataset.view); } catch (e) {}
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
