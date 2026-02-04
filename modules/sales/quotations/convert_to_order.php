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
    SELECT q.*, c.name AS customer_name, c.factory_id
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    WHERE q.id = ? AND q.status = 'accepted' AND q.order_id IS NULL
");
$stmt->execute([$quotation_id]);
$quotation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    $_SESSION['error'] = "Quotation not found or cannot be converted";
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

// Handle conversion to order
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Generate internal ID
        $internal_id = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        // Create order
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                internal_id, customer_id, factory_id, contact_id, order_date, status, 
                total_amount, paid_amount, discount_percentage, discount_basis, discount_amount,
                discount_product_count, free_sample_count, shipping_cost_type, shipping_cost,
                notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $internal_id,
            $quotation['customer_id'],
            $quotation['factory_id'],
            $quotation['contact_id'],
            date('Y-m-d'),
            'new',
            $quotation['total_amount'],
            0.00,
            0,
            'none',
            0,
            0,
            0,
            'none',
            0,
            "Converted from quotation #" . $quotation['id'] . "\n" . $quotation['notes'],
            $_SESSION['user_id']
        ]);
        
        $order_id = $pdo->lastInsertId();
        
        // Insert order items
        $stmt = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, is_free_sample)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($items as $item) {
            $stmt->execute([
                $order_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
                0
            ]);
            
            // Update inventory (if final product)
            $product_type = $pdo->query("SELECT type FROM products WHERE id = " . $item['product_id'])->fetchColumn();
            if ($product_type == 'final') {
                $pdo->exec("
                    UPDATE inventory_products 
                    SET quantity = quantity - " . $item['quantity'] . " 
                    WHERE product_id = " . $item['product_id'] . " 
                    AND inventory_id = 1" // Assuming main inventory is ID 1
                );
            }
        }
        
        // Update quotation with order ID
        $stmt = $pdo->prepare("UPDATE quotations SET order_id = ?, status = 'converted' WHERE id = ?");
        $stmt->execute([$order_id, $quotation_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Quotation converted to order successfully!";
        header("Location: ../order_details.php?id=" . $order_id);
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error converting quotation to order: " . $e->getMessage();
        header("Location: quotation_details.php?id=" . $quotation_id);
        exit();
    }
}
?>

<div class="container mt-4">
    <h2>Convert Quotation to Order</h2>
    
    <?php include '../../../includes/messages.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h4>Quotation #<?= $quotation['id'] ?></h4>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Customer:</strong> <?= htmlspecialchars($quotation['customer_name']) ?></p>
                    <p><strong>Quotation Date:</strong> <?= date('M d, Y', strtotime($quotation['quotation_date'])) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Total Amount:</strong> <?= $canViewFinalPrices ? number_format($quotation['total_amount'], 2) : '<span class="text-muted">Hidden</span>' ?></p>
                    <p><strong>Expiry Date:</strong> <?= date('M d, Y', strtotime($quotation['expiry_date'])) ?></p>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <h5>Quotation Items</h5>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item) : ?>
                                <tr>
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
                    </table>
                </div>
            </div>
            
            <form method="post">
                <div class="alert alert-info">
                    <h5>Are you sure you want to convert this quotation to an order?</h5>
                    <p>This action cannot be undone. The quotation will be marked as converted and linked to the new order.</p>
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="quotation_details.php?id=<?= $quotation_id ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Confirm Conversion</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>
