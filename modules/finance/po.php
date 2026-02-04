<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.po_payment.process')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Purchase Order Payments';
require_once '../../includes/header.php';

$sql = "SELECT po.id, v.name as vendor, po.total_amount, po.paid_amount, po.status, po.order_date
        FROM purchase_orders po 
        JOIN vendors v ON po.vendor_id=v.id
        ORDER BY po.order_date DESC";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Purchase Orders</h2>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-hover">
            <thead><tr><th>ID</th><th>Vendor</th><th>Date</th><th>Total</th><th>Paid</th><th>Status</th><th>Payment</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= htmlspecialchars($row['vendor']); ?></td>
                        <td><?= $row['order_date']; ?></td>
                        <td><?= number_format($row['total_amount'],2); ?></td>
                        <td><?= number_format($row['paid_amount'],2); ?></td>
                        <td><span class="badge bg-info"><?= $row['status']; ?></span></td>
                        <td>
                            <a href="../../modules/purchases/process_payment.php?po_id=<?= $row['id']; ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-credit-card"></i> Pay
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
