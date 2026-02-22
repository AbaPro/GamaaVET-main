<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('purchases.view')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

$canUpdatePOStatus = hasPermission('purchases.update_status');

// Get PO ID
$po_id = $_GET['id'] ?? 0;

// Fetch PO details
$stmt = $pdo->prepare("
    SELECT po.*, v.name AS vendor_name, vc.name AS contact_name, 
           vc.phone AS contact_phone, u.name AS created_by_name
    FROM purchase_orders po
    JOIN vendors v ON po.vendor_id = v.id
    JOIN vendor_contacts vc ON po.contact_id = vc.id
    JOIN users u ON po.created_by = u.id
    WHERE po.id = ?
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    $_SESSION['error'] = "Purchase order not found";
    header("Location: po_list.php");
    exit();
}

// Fetch PO items
$stmt = $pdo->prepare("
    SELECT poi.*, p.name AS product_name, p.sku, p.barcode, p.type,
           (SELECT SUM(quantity) FROM inventory_products WHERE product_id = p.id) as current_stock
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.purchase_order_id = ?
");
$stmt->execute([$po_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canViewMaterialCosts = canViewProductCost('material');

// Fetch payments
$stmt = $pdo->prepare("
    SELECT pop.*, u.name AS created_by_name
    FROM purchase_order_payments pop
    JOIN users u ON pop.created_by = u.id
    WHERE pop.purchase_order_id = ?
    ORDER BY pop.created_at DESC
");
$stmt->execute([$po_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    if (!$canUpdatePOStatus) {
        setAlert('danger', "You don't have permission to update PO status.");
        redirect('po_details.php?id=' . $po_id);
    }
    $new_status = $_POST['status'];
    
    // Validate status change
    if ($new_status === 'new' && $po['status'] !== 'new') {
        $_SESSION['error'] = "Status cannot be changed back to New.";
        header("Location: po_details.php?id=" . $po_id);
        exit();
    }

    if (in_array($po['status'], ['received', 'cancelled'])) {
        $_SESSION['error'] = "Status cannot be updated for Received or Cancelled orders.";
        header("Location: po_details.php?id=" . $po_id);
        exit();
    }
    
    try {
        
        $_SESSION['success'] = "Purchase order status updated successfully!";
        header("Location: po_details.php?id=" . $po_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating purchase order status: " . $e->getMessage();
    }
}

$page_title = 'Purchase Order Details';
require_once '../../includes/header.php';

?>

<div class="container mt-4">
    <h2>Purchase Order Details</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Purchase Order #<?= $po['id'] ?></h4>
            <div>
                <a href="po_list.php" class="btn btn-sm btn-secondary">Back to List</a>
                <?php if ($po['status'] == 'new') : ?>
                    <a href="create_po.php?edit=<?= $po_id ?>" class="btn btn-sm btn-warning">Edit</a>
                <?php endif; ?>
                <?php if (in_array($po['status'], ['new', 'ordered', 'partially-received']) && hasPermission('purchases.receive')) : ?>
                    <a href="receive_items.php?po_id=<?= $po_id ?>" class="btn btn-sm btn-success">Receive Items</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h5>Vendor Information</h5>
                    <p><strong>Vendor:</strong> <?= htmlspecialchars($po['vendor_name']) ?></p>
                    <?php if (hasPermission('contacts.view')): ?>
                        <p><strong>Contact Person:</strong> <?= htmlspecialchars($po['contact_name']) ?></p>
                        <p><strong>Contact Phone:</strong> <?= htmlspecialchars($po['contact_phone']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <h5>Order Information</h5>
                    <p><strong>Order Date:</strong> <?= date('M d, Y', strtotime($po['order_date'])) ?></p>
                    <p><strong>Created By:</strong> <?= htmlspecialchars($po['created_by_name']) ?></p>
                    <p>
                        <strong>Status:</strong> 
                        <?php 
                        $status_class = [
                            'new' => 'bg-secondary',
                            'ordered' => 'bg-primary',
                            'partially-received' => 'bg-info',
                            'received' => 'bg-success',
                            'cancelled' => 'bg-danger'
                        ];
                        ?>
                        <span class="badge <?= $status_class[$po['status']] ?>">
                            <?= ucwords(str_replace('-', ' ', $po['status'])) ?>
                        </span>
                    </p>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <h5>Purchase Order Items</h5>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Ordered Qty</th>
                                <th>Received Qty</th>
                                <th>Current Stock</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['sku']) ?></td>
                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= $item['received_quantity'] ?? 0 ?></td>
                                    <td><?= number_format($item['current_stock'] ?? 0) ?></td>
                                    <td>
                                        <?php if (canViewProductCost($item['type'])): ?>
                                            <?= number_format($item['unit_price'], 2) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Hidden</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (canViewProductCost($item['type'])): ?>
                                            <?= number_format($item['total_price'], 2) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Hidden</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="text-end"><strong>Subtotal:</strong></td>
                                <td><?= $canViewMaterialCosts ? number_format($po['total_amount'], 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-end"><strong>Paid Amount:</strong></td>
                                <td><?= $canViewMaterialCosts ? number_format($po['paid_amount'], 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                            <tr>
                                <td colspan="6" class="text-end"><strong>Balance:</strong></td>
                                <td class="<?= ($po['total_amount'] - $po['paid_amount']) > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= $canViewMaterialCosts ? number_format($po['total_amount'] - $po['paid_amount'], 2) : '<span class="text-muted">Hidden</span>' ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h5>Payment History</h5>
                    <?php if (empty($payments)) : ?>
                        <p>No payments recorded yet.</p>
                    <?php else : ?>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment) : ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($payment['created_at'])) ?></td>
                                        <td><?= number_format($payment['amount'], 2) ?></td>
                                        <td><?= ucfirst($payment['payment_method']) ?></td>
                                        <td><?= htmlspecialchars($payment['created_by_name']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-6">
                    <h5>Order Notes</h5>
                    <p><?= nl2br(htmlspecialchars($po['notes'] ?? 'No notes available')) ?></p>
                    
                    <?php if ($canUpdatePOStatus && !in_array($po['status'], ['received', 'cancelled'])) : ?>
                        <hr>
                        <h5>Update Status</h5>
                        <form method="post">
                            <div class="input-group mb-3">
                                <select class="form-select" name="status">
                                    <?php if ($po['status'] == 'new'): ?>
                                        <option value="new" selected>New (Draft)</option>
                                        <option value="ordered">Mark as Ordered</option>
                                    <?php elseif ($po['status'] == 'ordered'): ?>
                                        <option value="ordered" selected>Ordered</option>
                                    <?php elseif ($po['status'] == 'partially-received'): ?>
                                        <option value="partially-received" selected disabled>Partially Received</option>
                                    <?php endif; ?>
                                    <option value="cancelled" <?= $po['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                                <button type="submit" name="update_status" class="btn btn-primary">Update</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($po['status'] != 'cancelled' && ($po['total_amount'] - $po['paid_amount']) > 0) : ?>
                        <a href="process_payment.php?po_id=<?= $po_id ?>" class="btn btn-success">Record Payment</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
