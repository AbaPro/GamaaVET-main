<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.customer_payment.process')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Income Dashboard';
require_once '../../includes/header.php';

// Build Filter Query
$where = [];
$params = [];

if (!empty($_GET['customer_id'])) {
    $where[] = "o.customer_id = ?";
    $params[] = $_GET['customer_id'];
}
if (!empty($_GET['status'])) {
    $where[] = "o.status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['date_from'])) {
    $where[] = "o.order_date >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "o.order_date <= ?";
    $params[] = $_GET['date_to'];
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Summary Statistics
$stats_sql = "SELECT 
    SUM(total_amount) as total_sales,
    SUM(paid_amount) as total_received,
    SUM(total_amount - paid_amount) as total_pending
    FROM orders o $where_sql";
$stmt = $pdo->prepare($stats_sql);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Paginated Orders
$sql = "SELECT o.id, o.internal_id, c.name as customer, o.total_amount, o.paid_amount, o.status, o.order_date
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id 
        $where_sql
        ORDER BY o.order_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Customers for filter
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
        <h1>Income & Receivables</h1>
    </div>

    <?php include '../../includes/messages.php'; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card bg-primary text-white mb-4 shadow-sm border-0">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Total Sales Value</div>
                    <div class="h3 mb-0"><?= number_format($stats['total_sales'] ?? 0, 2) ?> EGP</div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-success text-white mb-4 shadow-sm border-0">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Total Received</div>
                    <div class="h3 mb-0"><?= number_format($stats['total_received'] ?? 0, 2) ?> EGP</div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-danger text-white mb-4 shadow-sm border-0">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75">Pending Receivables</div>
                    <div class="h3 mb-0"><?= number_format($stats['total_pending'] ?? 0, 2) ?> EGP</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-muted"><i class="fas fa-filter me-2"></i>Filter Income Records</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Customer</label>
                    <select name="customer_id" class="form-select select2">
                        <option value="">All Customers</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($_GET['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="delivered" <?= ($_GET['status'] ?? '') == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="delivering" <?= ($_GET['status'] ?? '') == 'delivering' ? 'selected' : '' ?>>Delivering</option>
                        <option value="cancelled" <?= ($_GET['status'] ?? '') == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= $_GET['date_from'] ?? '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= $_GET['date_to'] ?? '' ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2 shadow-sm">Apply Filters</button>
                    <a href="bills.php" class="btn btn-outline-secondary w-50 shadow-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle js-datatable">
                    <thead class="table-light">
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Order Date</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $row): ?>
                            <?php $balance = $row['total_amount'] - $row['paid_amount']; ?>
                            <tr>
                                <td class="fw-bold">
                                    <a href="../../modules/sales/order_details.php?id=<?= $row['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($row['internal_id']); ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($row['customer']); ?></td>
                                <td><?= date('M j, Y', strtotime($row['order_date'])); ?></td>
                                <td class="text-end fw-semibold"><?= number_format($row['total_amount'], 2); ?></td>
                                <td class="text-end text-success"><?= number_format($row['paid_amount'], 2); ?></td>
                                <td class="text-end <?= $balance > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                    <?= number_format($balance, 2); ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $color = getStatusColor($row['status']);
                                    echo "<span class='badge bg-{$color}'>" . ucwords($row['status']) . "</span>";
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($balance > 0): ?>
                                        <a href="../../modules/sales/process_payment.php?order_id=<?= $row['id']; ?>" class="btn btn-sm btn-success px-3 shadow-sm">
                                            <i class="fas fa-credit-card me-1"></i> Pay
                                        </a>
                                    <?php else: ?>
                                        <span class="text-success fw-bold small"><i class="fas fa-check-circle me-1"></i> Paid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap-5'
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>


