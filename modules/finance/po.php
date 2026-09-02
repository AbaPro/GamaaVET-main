<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.po_payment.process')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Purchase Order Payments';
require_once '../../includes/header.php';

$canViewPODetails = hasPermission('purchases.view');

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
        <table class="table js-datatable table-hover align-middle">
            <thead><tr><th>PO Number</th><th>Vendor</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <?php $balance = max(round((float)$row['total_amount'] - (float)$row['paid_amount'], 2), 0); ?>
                    <tr>
                        <td class="fw-semibold">
                            <?php if ($canViewPODetails): ?>
                                <a href="../purchases/po_details.php?id=<?= (int)$row['id']; ?>" class="text-decoration-none">
                                    PO-<?= (int)$row['id']; ?>
                                </a>
                            <?php else: ?>
                                PO-<?= (int)$row['id']; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['vendor']); ?></td>
                        <td><?= $row['order_date']; ?></td>
                        <td><?= number_format($row['total_amount'],2); ?></td>
                        <td><?= number_format($row['paid_amount'],2); ?></td>
                        <td class="<?= $balance > 0 ? 'text-danger fw-semibold' : 'text-success'; ?>"><?= number_format($balance, 2); ?></td>
                        <td><span class="badge bg-info"><?= $row['status']; ?></span></td>
                        <td>
                            <?php if ($canViewPODetails): ?>
                                <a href="../purchases/po_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i> View PO
                                </a>
                            <?php endif; ?>
                            <?php if ($balance > 0): ?>
                                <a href="../purchases/process_payment.php?po_id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-credit-card"></i> Pay
                                </a>
                            <?php else: ?>
                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Paid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
