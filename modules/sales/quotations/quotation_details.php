<?php
require_once '../../../includes/auth.php';
require_once '../../../includes/header.php';
require_once '../../../config/database.php';

// Permission check
if (!hasPermission('quotations.manage')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../../dashboard.php");
    exit();
}

// Get quotation ID
$quotation_id = $_GET['id'] ?? 0;

// Fetch quotation details
$stmt = $pdo->prepare("
    SELECT q.*, c.name AS customer_name, cc.name AS contact_name, 
           cc.phone AS contact_phone, u.name AS created_by_name
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    JOIN customer_contacts cc ON q.contact_id = cc.id
    JOIN users u ON q.created_by = u.id
    WHERE q.id = ?
");
$stmt->execute([$quotation_id]);
$quotation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    $_SESSION['error'] = "Quotation not found";
    header("Location: quotation_list.php");
    exit();
}

// Fetch quotation items
$stmt = $pdo->prepare("
    SELECT qi.*, p.name AS product_name, p.sku, p.barcode, p.type
    FROM quotation_items qi
    JOIN products p ON qi.product_id = p.id
    WHERE qi.quotation_id = ?
");
$stmt->execute([$quotation_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canViewFinalPrices = canViewProductPrice('final');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE quotations SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $quotation_id]);
        
        $_SESSION['success'] = "Quotation status updated successfully!";
        header("Location: quotation_details.php?id=" . $quotation_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating quotation status: " . $e->getMessage();
    }
}
?>

<div class="container mt-4">
    <h2>Quotation Details</h2>
    
    <?php include '../../../includes/messages.php'; ?>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Quotation #<?= $quotation['id'] ?></h4>
            <div>
                <a href="quotation_list.php" class="btn btn-sm btn-secondary">Back to List</a>
                <?php if ($quotation['status'] == 'accepted' && !$quotation['order_id']) : ?>
                    <a href="convert_to_order.php?id=<?= $quotation_id ?>" class="btn btn-sm btn-success">Convert to Order</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h5>Customer Information</h5>
                    <p><strong>Customer:</strong> <?= htmlspecialchars($quotation['customer_name']) ?></p>
                    <p><strong>Contact Person:</strong> <?= htmlspecialchars($quotation['contact_name']) ?></p>
                    <p><strong>Contact Phone:</strong> <?= htmlspecialchars($quotation['contact_phone']) ?></p>
                </div>
                <div class="col-md-6">
                    <h5>Quotation Information</h5>
                    <p><strong>Quotation Date:</strong> <?= date('M d, Y', strtotime($quotation['quotation_date'])) ?></p>
                    <p><strong>Expiry Date:</strong> <?= date('M d, Y', strtotime($quotation['expiry_date'])) ?></p>
                    <p><strong>Created By:</strong> <?= htmlspecialchars($quotation['created_by_name']) ?></p>
                    <p>
                        <strong>Status:</strong> 
                        <?php 
                        $status_class = [
                            'draft' => 'bg-secondary',
                            'sent' => 'bg-primary',
                            'accepted' => 'bg-success',
                            'rejected' => 'bg-danger',
                            'converted' => 'bg-info'
                        ];
                        ?>
                        <span class="badge <?= $status_class[$quotation['status']] ?>">
                            <?= ucfirst($quotation['status']) ?>
                        </span>
                    </p>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <h5>Quotation Items</h5>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Quantity</th>
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
                                    <td>
                                        <?php if (canViewProductPrice($item['type'])): ?>
                                            <?= number_format($item['unit_price'], 2) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Hidden</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (canViewProductPrice($item['type'])): ?>
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
                                <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                <td><?= $canViewFinalPrices ? number_format($quotation['total_amount'], 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12">
                    <h5>Notes</h5>
                    <p><?= nl2br(htmlspecialchars($quotation['notes'] ?? 'No notes available')) ?></p>
                    
                    <?php if ($quotation['order_id']) : ?>
                        <hr>
                        <h5>Related Order</h5>
                        <p>This quotation has been converted to order:</p>
                        <a href="../order_details.php?id=<?= $quotation['order_id'] ?>" class="btn btn-sm btn-info">
                            View Order #<?= $quotation['order_id'] ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($quotation['status'] != 'converted') : ?>
                        <hr>
                        <h5>Update Status</h5>
                        <form method="post">
                            <div class="input-group mb-3">
                                <select class="form-select" name="status">
                                    <option value="draft" <?= $quotation['status'] == 'draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="sent" <?= $quotation['status'] == 'sent' ? 'selected' : '' ?>>Sent</option>
                                    <option value="accepted" <?= $quotation['status'] == 'accepted' ? 'selected' : '' ?>>Accepted</option>
                                    <option value="rejected" <?= $quotation['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                                <button type="submit" name="update_status" class="btn btn-primary">Update</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>
