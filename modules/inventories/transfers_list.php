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
               u.name as requested_by_name,
               au.name as accepted_by_name,
               tu.name as transferred_by_name,
               COALESCE(items.item_count, 0) as item_count,
               COALESCE(items.total_quantity, 0) as total_quantity
        FROM inventory_transfers it
        JOIN inventories i1 ON it.from_inventory_id = i1.id
        JOIN inventories i2 ON it.to_inventory_id = i2.id
        LEFT JOIN users u ON it.requested_by = u.id
        LEFT JOIN users au ON it.accepted_by = au.id
        LEFT JOIN users tu ON it.transferred_by = tu.id
        LEFT JOIN (
            SELECT transfer_id, COUNT(*) as item_count, SUM(quantity) as total_quantity
            FROM transfer_items
            GROUP BY transfer_id
        ) items ON items.transfer_id = it.id
        ORDER BY it.created_at DESC";
$result = $conn->query($sql);

$transferImagesByTransfer = [];
$tiRes = $conn->query("SELECT inventory_transfer_id, file_path, original_name FROM inventory_transfer_images ORDER BY created_at ASC");
if ($tiRes) {
    while ($tiRow = $tiRes->fetch_assoc()) {
        $transferImagesByTransfer[$tiRow['inventory_transfer_id']][] = $tiRow;
    }
}
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
                        <th>Items</th>
                        <th>Total Qty</th>
                        <th>Requested By</th>
                        <th>Accepted By</th>
                        <th>Transferred By</th>
                        <th>Transferred At</th>
                        <th>Image</th>
                        <th>Details</th>
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
                            <td><?= (int)$row['item_count'] ?></td>
                            <td><?= (float)$row['total_quantity'] ?></td>
                            <td><?= htmlspecialchars($row['requested_by_name'] ?? 'System') ?></td>
                            <td><?= htmlspecialchars($row['accepted_by_name'] ?? ($row['status'] === 'accepted' ? ($row['transferred_by_name'] ?? '-') : '-')) ?></td>
                            <td><?= htmlspecialchars($row['transferred_by_name'] ?? $row['requested_by_name'] ?? 'System') ?></td>
                            <td><?= !empty($row['transferred_at']) ? date('M d, Y H:i', strtotime($row['transferred_at'])) : date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                            <td>
                                <?php echo renderAttachmentThumbnails($transferImagesByTransfer[$row['id']] ?? []); ?>
                            </td>
                            <td>
                                <a href="transfer_details.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'pending'): ?>
                                    <a href="accept_transfer.php?id=<?= $row['id'] ?>"
                                       class="btn btn-sm btn-outline-success"
                                       onclick="return confirm('Accept this transfer and add stock to the destination inventory?')">
                                        <i class="fas fa-check"></i> Accept
                                    </a>
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

<?php require_once '../../includes/footer.php'; ?>
