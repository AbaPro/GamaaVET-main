<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.transfer')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Inventory Transfers';
require_once '../../includes/header.php';

// Fetch transfers
$sql = "SELECT it.*, 
               i1.name as from_inventory, 
               i2.name as to_inventory,
               u.name as requested_by_name
        FROM inventory_transfers it
        JOIN inventories i1 ON it.from_inventory_id = i1.id
        JOIN inventories i2 ON it.to_inventory_id = i2.id
        LEFT JOIN users u ON it.requested_by = u.id
        ORDER BY it.created_at DESC";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Inventory Transfers</h2>
    <div>
        <a href="transfer.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Transfer
        </a>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Inventories
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>Ref #</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['transfer_reference']) ?></td>
                            <td><?= htmlspecialchars($row['from_inventory']) ?></td>
                            <td><?= htmlspecialchars($row['to_inventory']) ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo ($row['status'] == 'pending' ? 'warning' : ($row['status'] == 'accepted' ? 'success' : 'danger')); 
                                ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['requested_by_name'] ?? 'System') ?></td>
                            <td><?= date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#itemsModal<?= $row['id'] ?>">
                                    View Items
                                </button>
                                
                                <!-- Items Modal -->
                                <div class="modal fade" id="itemsModal<?= $row['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Items for <?= htmlspecialchars($row['transfer_reference']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <?php
                                                $items_sql = "SELECT ti.*, p.name as product_name, p.sku 
                                                             FROM transfer_items ti 
                                                             JOIN products p ON ti.product_id = p.id 
                                                             WHERE ti.transfer_id = ?";
                                                $stmt = $conn->prepare($items_sql);
                                                $stmt->bind_param("i", $row['id']);
                                                $stmt->execute();
                                                $items_result = $stmt->get_result();
                                                ?>
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Product</th>
                                                            <th>SKU</th>
                                                            <th>Qty</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php while ($item = $items_result->fetch_assoc()): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                                <td><?= htmlspecialchars($item['sku']) ?></td>
                                                                <td><?= (float)$item['quantity'] ?></td>
                                                            </tr>
                                                        <?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'pending'): ?>
                                    <a href="delete_transfer.php?id=<?= $row['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure? This will return stock to the source inventory.')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/header.php'; // Wait, header is already included. Should be footer. ?>
<?php require_once '../../includes/footer.php'; ?>
