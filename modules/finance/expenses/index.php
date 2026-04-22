<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../../dashboard.php');
}

$page_title = 'Expenses Tracking';
require_once '../../../includes/header.php';

// Fetch filter data
$categories = $pdo->query("SELECT * FROM expense_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$vendors    = $pdo->query("SELECT id, name FROM vendors ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_users  = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$accounts   = $pdo->query("SELECT * FROM accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build Filter Query
$where  = [];
$params = [];

// ── Account auto-filter based on login_region ──────────────────────────────
$region_slug = $_SESSION['login_region'] ?? 'factory';
$acc_stmt = $pdo->prepare("SELECT id FROM accounts WHERE slug = ?");
$acc_stmt->execute([$region_slug]);
$session_account_id = $acc_stmt->fetchColumn();

if (!hasPermission('finance.expenses.all_accounts')) {
    if ($region_slug === 'factory') {
        $where[]  = "(e.account_id IS NULL OR e.account_id = ?)";
        $params[] = $session_account_id ?: 1;
    } else {
        $where[]  = "e.account_id = ?";
        $params[] = $session_account_id;
    }
} elseif (!empty($_GET['account_id'])) {
    $where[]  = "e.account_id = ?";
    $params[] = (int)$_GET['account_id'];
}

// ── Standard filters ───────────────────────────────────────────────────────
if (!empty($_GET['category_id'])) {
    $where[]  = "e.category_id = ?";
    $params[] = (int)$_GET['category_id'];
}
if (!empty($_GET['status'])) {
    $where[]  = "e.status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['date_from'])) {
    $where[]  = "e.expense_date >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[]  = "e.expense_date <= ?";
    $params[] = $_GET['date_to'];
}

// ── New filters ────────────────────────────────────────────────────────────
if (!empty($_GET['vendor_id'])) {
    $where[]  = "e.vendor_id = ?";
    $params[] = (int)$_GET['vendor_id'];
}
if (!empty($_GET['payment_method'])) {
    if ($_GET['payment_method'] === 'none') {
        $where[] = "e.paid_amount = 0";
    } else {
        $where[]  = "EXISTS (SELECT 1 FROM expense_payments ep2 WHERE ep2.expense_id = e.id AND ep2.payment_method = ?)";
        $params[] = $_GET['payment_method'];
    }
}
if (!empty($_GET['recurring'])) {
    if ($_GET['recurring'] === 'recurring') {
        $where[] = "e.is_recurring = 1";
    } elseif ($_GET['recurring'] === 'non-recurring') {
        $where[] = "e.is_recurring = 0";
    }
}
if (isset($_GET['min_amount']) && $_GET['min_amount'] !== '') {
    $where[]  = "e.amount >= ?";
    $params[] = (float)$_GET['min_amount'];
}
if (isset($_GET['max_amount']) && $_GET['max_amount'] !== '') {
    $where[]  = "e.amount <= ?";
    $params[] = (float)$_GET['max_amount'];
}
if (!empty($_GET['created_by'])) {
    $where[]  = "e.created_by = ?";
    $params[] = (int)$_GET['created_by'];
}
if (!empty($_GET['currency'])) {
    $where[]  = "e.currency = ?";
    $params[] = $_GET['currency'];
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ── Per-currency summary cards ─────────────────────────────────────────────
$summary_sql = "SELECT e.currency,
    SUM(e.amount) as total_amount,
    SUM(e.paid_amount) as total_paid,
    SUM(e.amount - e.paid_amount) as total_pending
    FROM expenses e $where_sql
    GROUP BY e.currency
    ORDER BY e.currency ASC";
$stmt = $pdo->prepare($summary_sql);
$stmt->execute($params);
$summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Main expense listing ───────────────────────────────────────────────────
$sql = "SELECT e.*, ec.name as category_name, u.name as creator_name, v.name as vendor_name,
               a.name as account_name,
               (SELECT MAX(ep.created_at) FROM expense_payments ep WHERE ep.expense_id = e.id) as last_payment_date
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        JOIN users u ON e.created_by = u.id
        LEFT JOIN vendors v ON e.vendor_id = v.id
        LEFT JOIN accounts a ON e.account_id = a.id
        $where_sql
        ORDER BY e.expense_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currency_symbols = ['EGP' => 'ج.م', 'USD' => '$', 'EUR' => '€', 'SAR' => 'ر.س'];
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
        <h1>Expense Tracking
            <?php if (!hasPermission('finance.expenses.all_accounts') && $region_slug !== 'factory'): ?>
                <span class="badge bg-secondary fs-6 ms-2"><?= htmlspecialchars(ucfirst($region_slug)) ?></span>
            <?php endif; ?>
        </h1>
        <div>
            <a href="categories.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-tags me-1"></i> Categories
            </a>
            <a href="recurring.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-redo me-1"></i> Recurring
            </a>
            <a href="create.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Record Expense
            </a>
        </div>
    </div>

    <?php include '../../../includes/messages.php'; ?>

    <!-- Summary Cards (per currency) -->
    <?php if (empty($summaries)): ?>
    <div class="row mb-4">
        <div class="col-12 text-muted">No expenses found for the current filters.</div>
    </div>
    <?php else: ?>
    <?php foreach ($summaries as $sum):
        $cur = htmlspecialchars($sum['currency'] ?? 'EGP');
        $sym = $currency_symbols[$sum['currency']] ?? $sum['currency'];
    ?>
    <div class="row mb-2">
        <div class="col-12"><h6 class="text-muted text-uppercase fw-bold mb-2"><i class="fas fa-coins me-1"></i> <?= $cur ?></h6></div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-primary text-white mb-3 shadow-sm">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Total Expenses</div>
                    <div class="h3 mb-0"><?= $sym ?> <?= number_format($sum['total_amount'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-success text-white mb-3 shadow-sm">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Paid Amount</div>
                    <div class="h3 mb-0"><?= $sym ?> <?= number_format($sum['total_paid'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-danger text-white mb-3 shadow-sm">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Pending Balance</div>
                    <div class="h3 mb-0"><?= $sym ?> <?= number_format($sum['total_pending'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <span><i class="fas fa-filter me-1"></i> Filters</span>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                Toggle
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
        <div class="card-body">
            <form method="get" class="row g-3">
                <!-- Row 1 -->
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= ($_GET['status'] ?? '') == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="partially-paid" <?= ($_GET['status'] ?? '') == 'partially-paid' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="paid" <?= ($_GET['status'] ?? '') == 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Vendor</label>
                    <select name="vendor_id" class="form-select">
                        <option value="">All Vendors</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= ($_GET['vendor_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Row 2 -->
                <div class="col-md-2">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="">Any</option>
                        <option value="none" <?= ($_GET['payment_method'] ?? '') == 'none' ? 'selected' : '' ?>>No Payment</option>
                        <option value="cash" <?= ($_GET['payment_method'] ?? '') == 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="transfer" <?= ($_GET['payment_method'] ?? '') == 'transfer' ? 'selected' : '' ?>>Transfer</option>
                        <option value="wallet" <?= ($_GET['payment_method'] ?? '') == 'wallet' ? 'selected' : '' ?>>Wallet</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Recurring</label>
                    <select name="recurring" class="form-select">
                        <option value="">All</option>
                        <option value="recurring" <?= ($_GET['recurring'] ?? '') == 'recurring' ? 'selected' : '' ?>>Recurring Only</option>
                        <option value="non-recurring" <?= ($_GET['recurring'] ?? '') == 'non-recurring' ? 'selected' : '' ?>>Non-Recurring</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Min Amount</label>
                    <input type="number" name="min_amount" class="form-control" step="0.01" value="<?= htmlspecialchars($_GET['min_amount'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Max Amount</label>
                    <input type="number" name="max_amount" class="form-control" step="0.01" value="<?= htmlspecialchars($_GET['max_amount'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-select">
                        <option value="">All</option>
                        <?php foreach (['EGP', 'USD', 'EUR', 'SAR'] as $c): ?>
                            <option value="<?= $c ?>" <?= ($_GET['currency'] ?? '') == $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Created By</label>
                    <select name="created_by" class="form-select">
                        <option value="">Anyone</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($_GET['created_by'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (hasPermission('finance.expenses.all_accounts')): ?>
                <div class="col-md-2">
                    <label class="form-label">Account</label>
                    <select name="account_id" class="form-select">
                        <option value="">All Accounts</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= ($_GET['account_id'] ?? '') == $acc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($acc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Apply Filters</button>
                    <a href="index.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
        </div>
    </div>

    <!-- Expense List -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover js-datatable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Expense Name</th>
                            <th>Category</th>
                            <th>Account</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Last Payment</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                            <?php
                            $balance = $exp['amount'] - $exp['paid_amount'];
                            $cur     = $exp['currency'] ?? 'EGP';
                            $sym     = $currency_symbols[$cur] ?? $cur;
                            $status_class = [
                                'pending'        => 'bg-danger',
                                'partially-paid' => 'bg-warning text-dark',
                                'paid'           => 'bg-success'
                            ];
                            ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($exp['expense_date'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($exp['name']) ?></strong>
                                    <?php if ($exp['vendor_name']): ?>
                                        <br><small class="text-muted"><i class="fas fa-truck me-1"></i><?= htmlspecialchars($exp['vendor_name']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($exp['is_recurring']): ?>
                                        <br><small class="text-primary"><i class="fas fa-redo me-1"></i><?= ucfirst($exp['recurrence_interval']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($exp['category_name']) ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars($exp['account_name'] ?? 'Gamma Vet') ?></td>
                                <td class="fw-bold"><?= $sym ?> <?= number_format($exp['amount'], 2) ?></td>
                                <td class="text-success"><?= $sym ?> <?= number_format($exp['paid_amount'], 2) ?></td>
                                <td class="<?= $balance > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                    <?= $sym ?> <?= number_format($balance, 2) ?>
                                </td>
                                <td class="small text-muted">
                                    <?= $exp['last_payment_date'] ? date('M j, Y', strtotime($exp['last_payment_date'])) : '—' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $status_class[$exp['status']] ?? 'bg-secondary' ?>">
                                        <?= ucwords(str_replace('-', ' ', $exp['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="details.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($balance > 0 && hasPermission('finance.expenses.manage')): ?>
                                            <a href="pay.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-success" title="Record Payment">
                                                <i class="fas fa-credit-card"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('finance.expenses.manage')): ?>
                                        <a href="create.php?edit=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">No expenses found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>
