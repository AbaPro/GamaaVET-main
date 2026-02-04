<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('sales.orders.view')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get order ID
$order_id = $_GET['id'] ?? 0;

// Fetch order details
$stmt = $pdo->prepare("
    SELECT o.*, c.name AS customer_name, cc.name AS contact_name, 
           cc.phone AS contact_phone, u.name AS created_by_name,
           f.name AS factory_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    JOIN customer_contacts cc ON o.contact_id = cc.id
    JOIN users u ON o.created_by = u.id
    LEFT JOIN factories f ON o.factory_id = f.id
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['error'] = "Order not found";
    header("Location: order_list.php");
    exit();
}

// Fetch order items
$stmt = $pdo->prepare("
    SELECT oi.*, p.name AS product_name, p.sku, p.barcode, p.type
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canViewFinalPrices = canViewProductPrice('final');

// Fetch payments
$stmt = $pdo->prepare("
    SELECT op.*, u.name AS created_by_name
    FROM order_payments op
    JOIN users u ON op.created_by = u.id
    WHERE op.order_id = ?
    ORDER BY op.created_at DESC
");
$stmt->execute([$order_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$returns = [];
$returnsByItem = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_return'])) {
    try {
        $order_item_id = (int)($_POST['order_item_id'] ?? 0);
        $returned_quantity = max(0, (int)($_POST['returned_quantity'] ?? 0));
        $return_reason = trim($_POST['return_reason'] ?? '');

        if ($order_item_id <= 0 || $returned_quantity <= 0) {
            throw new Exception('Please provide a valid product and quantity to register a return.');
        }

        if ($return_reason === '') {
            throw new Exception('Please provide a reason for this refund.');
        }

        $itemStmt = $pdo->prepare("
            SELECT oi.*, p.name AS product_name
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.id = ? AND oi.order_id = ?
        ");
        $itemStmt->execute([$order_item_id, $order_id]);
        $returnItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$returnItem) {
            throw new Exception('Unable to locate the selected product for this order.');
        }

        $returnedStmt = $pdo->prepare("SELECT COALESCE(SUM(returned_quantity), 0) FROM order_returns WHERE order_item_id = ?");
        $returnedStmt->execute([$order_item_id]);
        $alreadyReturned = (int)$returnedStmt->fetchColumn();
        $availableQuantity = (int)$returnItem['quantity'] - $alreadyReturned;

        if ($availableQuantity <= 0) {
            throw new Exception('All available quantities for this product have already been returned.');
        }

        if ($returned_quantity > $availableQuantity) {
            throw new Exception('Return quantity cannot exceed what remains in the order.');
        }

        $insertReturn = $pdo->prepare("
            INSERT INTO order_returns (order_id, order_item_id, product_id, returned_quantity, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insertReturn->execute([
            $order_id,
            $order_item_id,
            $returnItem['product_id'],
            $returned_quantity,
            $return_reason,
            $_SESSION['user_id']
        ]);

        $_SESSION['success'] = 'Return registered successfully.';
        header("Location: order_details.php?id=" . $order_id);
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: order_details.php?id=" . $order_id);
        exit();
    }
}

$stmt = $pdo->prepare("
    SELECT r.*, p.name AS product_name, u.name AS created_by_name
    FROM order_returns r
    JOIN products p ON r.product_id = p.id
    JOIN users u ON r.created_by = u.id
    WHERE r.order_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$order_id]);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($returns as $returnItem) {
    $returnsByItem[$returnItem['order_item_id']] = ($returnsByItem[$returnItem['order_item_id']] ?? 0) + $returnItem['returned_quantity'];
}

$itemsSubtotal = array_reduce($items, function ($carry, $item) {
    return $carry + (float)$item['total_price'];
}, 0);
$shippingAmount = $order['shipping_cost_type'] === 'manual' ? (float)$order['shipping_cost'] : 0;
$discountAmount = (float)$order['discount_amount'];
$balance = $order['total_amount'] - $order['paid_amount'];
$discountBasisMap = [
    'none' => 'No Discount (لا يوجد)',
    'product_quantity' => 'Product Count (عدد منتجات)',
    'cash' => 'Cash Discount (خصم فلوس)',
    'free_sample' => 'Free Samples (عينات مجانية)',
    'mixed' => 'Mixed (مزيج)'
];
$discountBasisLabel = $discountBasisMap[$order['discount_basis']] ?? ucwords(str_replace('-', ' ', $order['discount_basis']));

// Handle status update
$canUpdateOrderStatus = hasPermission('sales.orders.update_status');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    if (!$canUpdateOrderStatus) {
        setAlert('danger', "You don't have permission to update the order status.");
        redirect('order_details.php?id=' . $order_id);
    }
    $new_status = $_POST['status'];

    try {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);
        
        $_SESSION['success'] = "Order status updated successfully!";
        header("Location: order_details.php?id=" . $order_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating order status: " . $e->getMessage();
    }
}

// Handle shipping update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_shipping'])) {
    try {
        if (!in_array($_SESSION['user_role'], ['admin', 'salesman', 'accountant'])) {
            throw new Exception('You are not allowed to update shipping.');
        }

        $newShippingType = $_POST['shipping_cost_type'] === 'manual' ? 'manual' : 'none';
        $newShippingAmount = $newShippingType === 'manual' ? max(0, (float)($_POST['shipping_cost'] ?? 0)) : 0;

        $currentStmt = $pdo->prepare("SELECT shipping_cost_type, shipping_cost, total_amount FROM orders WHERE id = ?");
        $currentStmt->execute([$order_id]);
        $currentSnapshot = $currentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentSnapshot) {
            throw new Exception('Order snapshot missing, please refresh and try again.');
        }

        $oldShipping = $currentSnapshot['shipping_cost_type'] === 'manual'
            ? (float)$currentSnapshot['shipping_cost']
            : 0;
        $newTotal = max(0, ((float)$currentSnapshot['total_amount'] - $oldShipping) + $newShippingAmount);

        $updateStmt = $pdo->prepare("UPDATE orders SET shipping_cost_type = ?, shipping_cost = ?, total_amount = ? WHERE id = ?");
        $updateStmt->execute([$newShippingType, $newShippingAmount, $newTotal, $order_id]);

        logActivity('Updated order shipping', [
            'order_id' => $order_id,
            'shipping_cost_type' => $newShippingType,
            'shipping_cost' => $newShippingAmount
        ]);

        $_SESSION['success'] = 'Shipping cost updated successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header("Location: order_details.php?id=" . $order_id);
    exit();
}
require_once '../../includes/header.php';
?>

<div class="container mt-4">
    <h2>Order Details</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Order #<?= htmlspecialchars($order['internal_id']) ?></h4>
            <div>
                <a href="order_list.php" class="btn btn-sm btn-secondary">Back to List</a>
                <a href="generate_invoice.php?id=<?= $order_id ?>" class="btn btn-sm btn-primary ms-2" target="_blank">Print Invoice</a>
                <a href="generate_invoice.php?id=<?= $order_id ?>&view=statement" class="btn btn-sm btn-outline-primary ms-2" target="_blank">بيان</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h5>Customer Information</h5>
                    <p><strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
                    <p><strong>Contact Person:</strong> <?= htmlspecialchars($order['contact_name']) ?></p>
                    <p><strong>Contact Phone:</strong> <?= htmlspecialchars($order['contact_phone']) ?></p>
                    <p><strong>Factory:</strong> <?= $order['factory_name'] ? htmlspecialchars($order['factory_name']) : 'Not assigned' ?></p>
                </div>
                <div class="col-md-6">
                    <h5>Order Information</h5>
                    <p><strong>Order Date:</strong> <?= date('M d, Y', strtotime($order['order_date'])) ?></p>
                    <p><strong>Created By:</strong> <?= htmlspecialchars($order['created_by_name']) ?></p>
                    <p><strong>Discount %:</strong> <?= number_format($order['discount_percentage'], 2) ?>%</p>
                    <p><strong>Discount Type:</strong> <?= htmlspecialchars($discountBasisLabel) ?></p>
                    <p><strong>Discount Amount:</strong> <?= number_format($discountAmount, 2) ?></p>
                    <p><strong>Discounted Products:</strong> <?= (int)$order['discount_product_count'] ?></p>
                    <p><strong>Free Samples:</strong> <?= (int)$order['free_sample_count'] ?></p>
                    <p><strong>Shipping:</strong> <?= $order['shipping_cost_type'] === 'manual' ? number_format($shippingAmount, 2) . ' (Manual)' : 'No Shipping' ?></p>
                    <?php if (in_array($_SESSION['user_role'], ['admin', 'salesman', 'accountant'])) : ?>
                        <form method="post" class="mt-3 border-top pt-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-sm-6">
                                    <label for="shipping_cost_type_edit" class="form-label">Shipping Mode</label>
                                    <select class="form-select" id="shipping_cost_type_edit" name="shipping_cost_type">
                                        <option value="none" <?= $order['shipping_cost_type'] !== 'manual' ? 'selected' : '' ?>>No Shipping</option>
                                        <option value="manual" <?= $order['shipping_cost_type'] === 'manual' ? 'selected' : '' ?>>Manual Amount</option>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label for="shipping_cost_edit" class="form-label">Amount</label>
                                    <input type="number" class="form-control" name="shipping_cost" id="shipping_cost_edit" min="0" step="0.01" value="<?= htmlspecialchars(number_format($shippingAmount, 2, '.', '')) ?>">
                                </div>
                                <div class="col-sm-2 d-grid">
                                    <input type="hidden" name="update_shipping" value="1">
                                    <button type="submit" class="btn btn-outline-primary mt-3 mt-sm-0">Save</button>
                                </div>
                            </div>
                            <small class="text-muted">Manual amount only applies when shipping mode is manual.</small>
                        </form>
                    <?php endif; ?>
                    <p>
                        <strong>Status:</strong> 
                        <?php 
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
                        <span class="badge <?= $status_class[$order['status']] ?>">
                            <?= ucwords(str_replace('-', ' ', $order['status'])) ?>
                        </span>
                    </p>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <h5>Order Items</h5>
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
                                    <td>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <?php if (!empty($item['is_free_sample'])) : ?>
                                            <span class="badge bg-info ms-2">Free Sample</span>
                                        <?php endif; ?>
                                    </td>
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
                                <td colspan="4" class="text-end"><strong>Items Subtotal:</strong></td>
                                <td><?= $canViewFinalPrices ? number_format($itemsSubtotal, 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Discount:</strong></td>
                                <td class="text-danger"><?= $canViewFinalPrices ? '-' . number_format($discountAmount, 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Shipping:</strong></td>
                                <td>
                                    <?php if ($canViewFinalPrices): ?>
                                        <?= $order['shipping_cost_type'] === 'manual'
                                            ? number_format($shippingAmount, 2) . ' (Manual)'
                                            : '0.00 (No Shipping)' ?>
                                    <?php else: ?>
                                        <span class="text-muted">Hidden</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                <td><?= $canViewFinalPrices ? number_format($order['total_amount'], 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Paid Amount:</strong></td>
                                <td><?= $canViewFinalPrices ? number_format($order['paid_amount'], 2) : '<span class="text-muted">Hidden</span>' ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Balance:</strong></td>
                                <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= $canViewFinalPrices ? number_format($balance, 2) : '<span class="text-muted">Hidden</span>' ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-12">
                    <h5>Returns</h5>
                    <?php if (empty($returns)) : ?>
                        <p class="text-muted">No returns recorded for this order.</p>
                    <?php else : ?>
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Reason</th>
                                    <th>Recorded By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returns as $return) : ?>
                                    <tr>
                                        <td><?= htmlspecialchars($return['product_name']) ?></td>
                                        <td><?= (int)$return['returned_quantity'] ?></td>
                                        <td><?= htmlspecialchars($return['reason'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($return['created_by_name']) ?></td>
                                        <td><?= date('M d, Y H:i', strtotime($return['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php 
                    $returnableItems = array_filter($items, function ($item) use ($returnsByItem) {
                        $already = $returnsByItem[$item['id']] ?? 0;
                        return $item['quantity'] > $already;
                    });
                    ?>
                    <?php if (in_array($_SESSION['user_role'], ['admin', 'salesman', 'accountant'])) : ?>
                        <hr>
                        <h6>Add Return</h6>
                        <?php if (empty($returnableItems)) : ?>
                            <p class="text-muted">All line items have been fully returned.</p>
                        <?php else : ?>
                            <form method="post" class="row g-3 align-items-end">
                                <input type="hidden" name="add_return" value="1">
                                <div class="col-md-4">
                                    <label for="order_item_id" class="form-label">Product</label>
                                    <select class="form-select" id="order_item_id" name="order_item_id" required>
                                        <?php foreach ($returnableItems as $item) :
                                            $already = $returnsByItem[$item['id']] ?? 0;
                                            $available = $item['quantity'] - $already;
                                        ?>
                                            <option value="<?= $item['id'] ?>">
                                                <?= htmlspecialchars($item['product_name']) ?> (Available: <?= $available ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="returned_quantity" class="form-label">Qty</label>
                                    <input type="number" class="form-control" id="returned_quantity" name="returned_quantity" min="1" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="return_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="return_reason" name="return_reason" placeholder="Explain why the refund is needed" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-danger w-100">Add Return</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
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
                    <p><?= nl2br(htmlspecialchars($order['notes'] ?? 'No notes available')) ?></p>
                    
                    <?php if ($canUpdateOrderStatus) : ?>
                        <hr>
                        <h5>Update Status</h5>
                        <form method="post">
                            <div class="input-group mb-3">
                                <select class="form-select" name="status">
                                    <option value="new" <?= $order['status'] == 'new' ? 'selected' : '' ?>>New</option>
                                    <option value="in-production" <?= $order['status'] == 'in-production' ? 'selected' : '' ?>>In Production</option>
                                    <option value="in-packing" <?= $order['status'] == 'in-packing' ? 'selected' : '' ?>>In Packing</option>
                                    <option value="delivering" <?= $order['status'] == 'delivering' ? 'selected' : '' ?>>Delivering</option>
                                    <option value="delivered" <?= $order['status'] == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                    <option value="returned" <?= $order['status'] == 'returned' ? 'selected' : '' ?>>Returned</option>
                                </select>
                                <button type="submit" name="update_status" class="btn btn-primary">Update</button>
                            </div>
                        </form>
                        
                        <?php if (($order['total_amount'] - $order['paid_amount']) > 0) : ?>
                            <a href="process_payment.php?order_id=<?= $order_id ?>" class="btn btn-success">Record Payment</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (in_array($_SESSION['user_role'], ['admin', 'salesman', 'accountant'])) : ?>
<script>
    (function() {
        const typeSelect = document.getElementById('shipping_cost_type_edit');
        const amountInput = document.getElementById('shipping_cost_edit');
        if (!typeSelect || !amountInput) return;

        const toggleShippingAmount = () => {
            const manual = typeSelect.value === 'manual';
            amountInput.disabled = !manual;
            if (!manual) {
                amountInput.value = '0.00';
            }
        };

        toggleShippingAmount();
        typeSelect.addEventListener('change', toggleShippingAmount);
    })();
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
