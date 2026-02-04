<?php
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('purchases.receive')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get PO ID
$po_id = $_GET['po_id'] ?? 0;

// Fetch PO details
$stmt = $pdo->prepare("
    SELECT po.*, v.name AS vendor_name
    FROM purchase_orders po
    JOIN vendors v ON po.vendor_id = v.id
    WHERE po.id = ? AND po.status IN ('new', 'ordered', 'partially-received')
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    $_SESSION['error'] = "Purchase order not found or cannot receive items";
    header("Location: po_list.php");
    exit();
}

// Fetch PO items
$stmt = $pdo->prepare("
    SELECT poi.*, p.name AS product_name, p.sku, p.barcode
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.purchase_order_id = ?
");
$stmt->execute([$po_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle item receipt
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        $all_received = true;
        $some_received = false;
        
        // Update received quantities
        foreach ($items as $item) {
            $received_qty = (int)$_POST['received_qty'][$item['product_id']] ?? 0;
            $max_qty = $item['quantity'] - ($item['received_quantity'] ?? 0);
            
            if ($received_qty > 0 && $received_qty <= $max_qty) {
                $some_received = true;
                
                // Update PO item received quantity
                $stmt = $pdo->prepare("
                    UPDATE purchase_order_items 
                    SET received_quantity = received_quantity + ? 
                    WHERE purchase_order_id = ? AND product_id = ?
                ");
                $stmt->execute([$received_qty, $po_id, $item['product_id']]);
                
                // Update inventory
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_products (inventory_id, product_id, quantity)
                    VALUES (1, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ");
                $stmt->execute([$item['product_id'], $received_qty, $received_qty]);
            }
            
            if (($item['received_quantity'] + $received_qty) < $item['quantity']) {
                $all_received = false;
            }
        }
        
        if (!$some_received) {
            throw new Exception("No items were received. Please enter quantities.");
        }
        
        // Update PO status
        $new_status = $all_received ? 'received' : 'partially-received';
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $po_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Items received successfully! PO status updated to " . $new_status;
        header("Location: po_details.php?id=" . $po_id);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error receiving items: " . $e->getMessage();
    }
}
?>

<div class="container mt-4">
    <h2>Receive Items Against PO</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h4>Purchase Order #<?= $po['id'] ?></h4>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Vendor:</strong> <?= htmlspecialchars($po['vendor_name']) ?></p>
                    <p><strong>Order Date:</strong> <?= date('M d, Y', strtotime($po['order_date'])) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Current Status:</strong> <?= ucwords(str_replace('-', ' ', $po['status'])) ?></p>
                    <p><strong>Total Amount:</strong> <?= number_format($po['total_amount'], 2) ?></p>
                </div>
            </div>
            
            <form method="post">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Ordered Qty</th>
                                <th>Previously Received</th>
                                <th>Pending</th>
                                <th>Receiving Now</th>
                                <th>Inventory Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item) : 
                                $pending_qty = $item['quantity'] - ($item['received_quantity'] ?? 0);
                            ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <input type="hidden" name="product_ids[]" value="<?= $item['product_id'] ?>">
                                    </td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= $item['received_quantity'] ?? 0 ?></td>
                                    <td><?= $pending_qty ?></td>
                                    <td>
                                        <input type="number" class="form-control" 
                                               name="received_qty[<?= $item['product_id'] ?>]" 
                                               min="0" max="<?= $pending_qty ?>" value="0">
                                    </td>
                                    <td>Main Warehouse</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4">
                    <div class="form-group">
                        <label for="notes" class="form-label">Receiving Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-3">
                        <a href="po_details.php?id=<?= $po_id ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Receive Items</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
