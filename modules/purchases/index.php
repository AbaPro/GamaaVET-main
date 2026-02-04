<?php
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('purchases.view_all')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get statistics for dashboard
$today = date('Y-m-d');
$month_start = date('Y-m-01');

$stats = [
    'month_purchases' => 0,
    'pending_pos' => 0,
    'unpaid_pos' => 0,
    'partially_received' => 0
];

// Month's purchases
$stmt = $pdo->prepare("SELECT SUM(total_amount) FROM purchase_orders WHERE DATE(order_date) BETWEEN ? AND ?");
$stmt->execute([$month_start, $today]);
$stats['month_purchases'] = $stmt->fetchColumn();

// Pending POs
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('new', 'ordered', 'partially-received')");
$stmt->execute();
$stats['pending_pos'] = $stmt->fetchColumn();

// Unpaid POs
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE paid_amount < total_amount");
$stmt->execute();
$stats['unpaid_pos'] = $stmt->fetchColumn();

// Partially received
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE status = 'partially-received'");
$stmt->execute();
$stats['partially_received'] = $stmt->fetchColumn();
?>

<div class="container mt-4">
    <h2>Purchases Dashboard</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h5 class="card-title">Month's Purchases</h5>
                    <p class="card-text h4"><?= number_format($stats['month_purchases'], 2) ?> USD</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h5 class="card-title">Pending POs</h5>
                    <p class="card-text h4"><?= $stats['pending_pos'] ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <h5 class="card-title">Unpaid POs</h5>
                    <p class="card-text h4"><?= $stats['unpaid_pos'] ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h5 class="card-title">Partially Received</h5>
                    <p class="card-text h4"><?= $stats['partially_received'] ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Recent Purchase Orders</h4>
            <div>
                <a href="create_po.php" class="btn btn-primary btn-sm">New PO</a>
                <a href="po_list.php" class="btn btn-secondary btn-sm">View All</a>
            </div>
        </div>
        <div class="card-body">
            <table class="table js-datatable table-striped table-hover">
                <thead>
                    <tr>
                        <th>PO #</th>
                        <th>Vendor</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->prepare("
                        SELECT po.id, po.order_date, po.total_amount, po.paid_amount, 
                               po.status, v.name AS vendor_name 
                        FROM purchase_orders po
                        JOIN vendors v ON po.vendor_id = v.id
                        ORDER BY po.order_date DESC LIMIT 5
                    ");
                    $stmt->execute();
                    while ($po = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $status_class = [
                            'new' => 'bg-secondary',
                            'ordered' => 'bg-primary',
                            'partially-received' => 'bg-info',
                            'received' => 'bg-success',
                            'cancelled' => 'bg-danger'
                        ];
                        ?>
                        <tr>
                            <td>PO-<?= $po['id'] ?></td>
                            <td><?= htmlspecialchars($po['vendor_name']) ?></td>
                            <td><?= date('M d, Y', strtotime($po['order_date'])) ?></td>
                            <td><?= number_format($po['total_amount'], 2) ?></td>
                            <td>
                                <span class="badge <?= $status_class[$po['status']] ?>">
                                    <?= ucwords(str_replace('-', ' ', $po['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="po_details.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-info">View</a>
                                <?php if ($po['status'] == 'new' || $po['status'] == 'ordered') : ?>
                                    <a href="receive_items.php?po_id=<?= $po['id'] ?>" class="btn btn-sm btn-success">Receive</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
