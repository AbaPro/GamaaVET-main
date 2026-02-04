<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.customer_payment.process')) {
    setAlert('danger', 'No access to this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Customer Bills & Payments';
require_once '../../includes/header.php';

$sql = "SELECT o.id, o.internal_id, c.name as customer, o.total_amount, o.paid_amount, o.status, o.order_date
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id 
        ORDER BY o.order_date DESC";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customer Bills & Payments</h2>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['internal_id']); ?></td>
                            <td><?= htmlspecialchars($row['customer']); ?></td>
                            <td><?= $row['order_date']; ?></td>
                            <td><?= number_format($row['total_amount'], 2); ?></td>
                            <td><?= number_format($row['paid_amount'], 2); ?></td>
                            <td><span class="badge bg-info"><?= $row['status']; ?></span></td>
                            <td>
                                <a href="../../modules/sales/process_payment.php?order_id=<?= $row['id']; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-credit-card"></i> Pay
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>


