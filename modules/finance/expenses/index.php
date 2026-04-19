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

// Build Filter Query
$where = [];
$params = [];

if (!empty($_GET['category_id'])) {
    $where[] = "e.category_id = ?";
    $params[] = $_GET['category_id'];
}
if (!empty($_GET['status'])) {
    $where[] = "e.status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['date_from'])) {
    $where[] = "e.expense_date >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "e.expense_date <= ?";
    $params[] = $_GET['date_to'];
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Summary
$summary_sql = "SELECT 
    SUM(amount) as total_amount, 
    SUM(paid_amount) as total_paid, 
    SUM(amount - paid_amount) as total_pending 
    FROM expenses e $where_sql";
$stmt = $pdo->prepare($summary_sql);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Expenses
$sql = "SELECT e.*, ec.name as category_name, u.name as creator_name, v.name as vendor_name
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        JOIN users u ON e.created_by = u.id
        LEFT JOIN vendors v ON e.vendor_id = v.id
        $where_sql
        ORDER BY e.expense_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Categories for filter
$categories = $pdo->query("SELECT * FROM expense_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
        <h1>Expense Tracking</h1>
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

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card bg-primary text-white mb-4 shadow-sm">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Total Expenses</div>
                    <div class="h3 mb-0"><?= number_format($summary['total_amount'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-success text-white mb-4 shadow-sm">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Paid Amount</div>
                    <div class="h3 mb-0"><?= number_format($summary['total_paid'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-danger text-white mb-4 shadow-sm">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Pending Balance</div>
                    <div class="h3 mb-0"><?= number_format($summary['total_pending'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-light">
            <i class="fas fa-filter me-1"></i> Filters
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">
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
                    <input type="date" name="date_from" class="form-control" value="<?= $_GET['date_from'] ?? '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= $_GET['date_to'] ?? '' ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                    <a href="index.php" class="btn btn-secondary ms-2">Reset</a>
                </div>
            </form>
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
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                            <?php $balance = $exp['amount'] - $exp['paid_amount']; ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($exp['expense_date'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($exp['name']) ?></strong>
                                    <?php if ($exp['vendor_name']): ?>
                                        <br><small class="text-muted"><i class="fas fa-truck me-1"></i><?= htmlspecialchars($exp['vendor_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($exp['category_name']) ?></span></td>
                                <td class="fw-bold"><?= number_format($exp['amount'], 2) ?></td>
                                <td class="text-success"><?= number_format($exp['paid_amount'], 2) ?></td>
                                <td class="<?= $balance > 0 ? 'text-danger' : 'text-muted' ?>">
                                    <?= number_format($balance, 2) ?>
                                </td>
                                <td>
                                    <?php
                                    $status_class = [
                                        'pending' => 'bg-danger',
                                        'partially-paid' => 'bg-warning text-dark',
                                        'paid' => 'bg-success'
                                    ];
                                    ?>
                                    <span class="badge <?= $status_class[$exp['status']] ?>">
                                        <?= ucwords(str_replace('-', ' ', $exp['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="details.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($balance > 0): ?>
                                            <a href="pay.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-success" title="Record Payment">
                                                <i class="fas fa-credit-card"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="create.php?edit=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>
