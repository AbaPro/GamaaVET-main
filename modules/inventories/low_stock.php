<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Low Stock Items';
require_once '../../includes/header.php';

// Fetch low stock items across inventories
$sql = "SELECT ip.inventory_id, i.name AS inventory_name, p.id AS product_id, p.name AS product_name, p.sku, p.min_stock_level, ip.quantity
        FROM inventory_products ip
        JOIN products p ON ip.product_id = p.id
        JOIN inventories i ON ip.inventory_id = i.id
        WHERE ip.quantity <= p.min_stock_level
        ORDER BY i.name, p.name";

$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Low Stock Items</h2>
    <div>
        <a href="../inventories/" class="btn btn-secondary"><i class="fas fa-chevron-left"></i> Back to Inventories</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>Inventory</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Quantity</th>
                        <th>Min Level</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['inventory_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                                <td><?php echo (int)$row['quantity']; ?></td>
                                <td><?php echo (int)$row['min_stock_level']; ?></td>
                                <td><span class="badge bg-danger">Low Stock</span></td>
                                <td>
                                    <a href="../products/view.php?id=<?php echo (int)$row['product_id']; ?>" class="btn btn-sm btn-outline-primary">Product</a>
                                    <a href="view.php?id=<?php echo (int)$row['inventory_id']; ?>" class="btn btn-sm btn-outline-secondary">Inventory</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No low stock items found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
