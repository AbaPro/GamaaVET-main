<?php
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('sales.orders.view_all')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get statistics for dashboard
$today = date('Y-m-d');
$month_start = date('Y-m-01');

$stats = [
    'today_sales' => 0,
    'month_sales' => 0,
    'pending_orders' => 0,
    'unpaid_orders' => 0
];

/* Use $conn (MySQLi) instead of $pdo (PDO) */

// Today's sales
$stmt = $conn->prepare("SELECT SUM(total_amount) FROM orders WHERE DATE(order_date) = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$stmt->bind_result($today_sales);
$stmt->fetch();
$stats['today_sales'] = $today_sales ?: 0;
$stmt->close();

// Month's sales
$stmt = $conn->prepare("SELECT SUM(total_amount) FROM orders WHERE DATE(order_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $month_start, $today);
$stmt->execute();
$stmt->bind_result($month_sales);
$stmt->fetch();
$stats['month_sales'] = $month_sales ?: 0;
$stmt->close();

// Pending orders
$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('new', 'in-production', 'in-packing', 'delivering')");
$stmt->execute();
$stmt->bind_result($pending_orders);
$stmt->fetch();
$stats['pending_orders'] = $pending_orders ?: 0;
$stmt->close();

// Unpaid orders
$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE paid_amount < total_amount");
$stmt->execute();
$stmt->bind_result($unpaid_orders);
$stmt->fetch();
$stats['unpaid_orders'] = $unpaid_orders ?: 0;
$stmt->close();

?>

<div class="container mt-4">
    <h2>Sales Dashboard</h2>

    <?php include '../../includes/messages.php'; ?>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h5 class="card-title">Today's Sales</h5>
                    <p class="card-text h4"><?= number_format($stats['today_sales'], 2) ?> USD</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <h5 class="card-title">Month's Sales</h5>
                    <p class="card-text h4"><?= number_format($stats['month_sales'], 2) ?> USD</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h5 class="card-title">Pending Orders</h5>
                    <p class="card-text h4"><?= $stats['pending_orders'] ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <h5 class="card-title">Unpaid Orders</h5>
                    <p class="card-text h4"><?= $stats['unpaid_orders'] ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Recent Orders</h4>
            <div>
                <a href="create_order.php" class="btn btn-primary btn-sm">New Order</a>
                <a href="order_list.php" class="btn btn-secondary btn-sm">View All</a>
            </div>
        </div>
        <div class="card-body">
            <table class="table js-datatable table-striped table-hover">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php

                    $query = "
    SELECT o.id, o.internal_id, o.order_date, o.total_amount, o.status, c.name AS customer_name 
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    ORDER BY o.order_date DESC 
    LIMIT 5
";

                    $stmt = $conn->prepare($query);
                    if ($stmt) {
                        $stmt->execute();
                        $result = $stmt->get_result();

                        while ($order = $result->fetch_assoc()) {
                            $status_class = [
                                'new' => 'bg-primary',
                                'in-production' => 'bg-info',
                                'in-packing' => 'bg-info',
                                'delivering' => 'bg-warning',
                                'delivered' => 'bg-success',
                                'returned' => 'bg-danger',
                                'returned-refunded' => 'bg-secondary',
                                'partially-returned' => 'bg-danger',
                                'partially-returned-refunded' => 'bg-secondary'
                            ];
                    ?>
                            <tr>
                                <td><?= htmlspecialchars($order['internal_id']) ?></td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($order['order_date'])) ?></td>
                                <td><?= number_format($order['total_amount'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $status_class[$order['status']] ?>">
                                        <?= ucwords(str_replace('-', ' ', $order['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="order_details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-info">View</a>
                                    <?php if ($order['status'] == 'new') : ?>
                                        <a href="process_payment.php?order_id=<?= $order['id'] ?>" class="btn btn-sm btn-success">Payment</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                    <?php
                        }

                        $stmt->close();
                    } else {
                        echo "<tr><td colspan='6'>Error preparing statement: " . $conn->error . "</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
