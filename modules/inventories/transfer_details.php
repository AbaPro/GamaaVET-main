<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.transfer')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid transfer.');
    redirect('transfers_list.php');
}

$transfer_id = (int)$_GET['id'];
$sourceScope = getInventoryChannelScopeSql('i1');
$destinationScope = getInventoryChannelScopeSql('i2');

$transfer_sql = "SELECT it.*,
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
                 WHERE it.id = ? AND $sourceScope AND $destinationScope";
$transfer_stmt = $conn->prepare($transfer_sql);
$transfer_stmt->bind_param("i", $transfer_id);
$transfer_stmt->execute();
$transfer = $transfer_stmt->get_result()->fetch_assoc();
$transfer_stmt->close();

if (!$transfer) {
    setAlert('danger', 'Transfer not found.');
    redirect('transfers_list.php');
}

$transferImages = [];
$tiStmt = $conn->prepare("SELECT file_path, original_name FROM inventory_transfer_images WHERE inventory_transfer_id = ? ORDER BY created_at ASC");
$tiStmt->bind_param("i", $transfer_id);
$tiStmt->execute();
$transferImages = $tiStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tiStmt->close();

$items_sql = "SELECT ti.*, p.name as product_name, p.sku, p.barcode, p.type, p.unit,
                     c.name as category_name, sc.name as subcategory_name,
                     source_stock.quantity as source_quantity,
                     destination_stock.quantity as destination_quantity
              FROM transfer_items ti
              JOIN products p ON ti.product_id = p.id
              LEFT JOIN categories c ON p.category_id = c.id
              LEFT JOIN categories sc ON p.subcategory_id = sc.id
              LEFT JOIN inventory_products source_stock
                ON source_stock.product_id = ti.product_id AND source_stock.inventory_id = ?
              LEFT JOIN inventory_products destination_stock
                ON destination_stock.product_id = ti.product_id AND destination_stock.inventory_id = ?
              WHERE ti.transfer_id = ?
              ORDER BY p.name";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("iii", $transfer['from_inventory_id'], $transfer['to_inventory_id'], $transfer_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

$page_title = 'Transfer Details';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Transfer <?= htmlspecialchars($transfer['transfer_reference']) ?></h2>
        <span class="badge bg-<?php echo ($transfer['status'] == 'pending' ? 'warning' : ($transfer['status'] == 'accepted' ? 'success' : 'danger')); ?>">
            <?= ucfirst($transfer['status']) ?>
        </span>
    </div>
    <div>
        <?php if ($transfer['status'] === 'pending'): ?>
            <a href="accept_transfer.php?id=<?= $transfer_id ?>"
               class="btn btn-outline-success"
               onclick="return confirm('Accept this transfer and add stock to the destination inventory?')">
                <i class="fas fa-check"></i> Accept
            </a>
            <a href="delete_transfer.php?id=<?= $transfer_id ?>"
               class="btn btn-outline-danger"
               onclick="return confirm('Are you sure? This will return stock to the source inventory.')">
                <i class="fas fa-trash"></i> Delete
            </a>
        <?php endif; ?>
        <a href="transfers_list.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Transfers
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Transfer Information</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted small">From Inventory</div>
                        <div class="fw-semibold"><?= htmlspecialchars($transfer['from_inventory']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">To Inventory</div>
                        <div class="fw-semibold"><?= htmlspecialchars($transfer['to_inventory']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Items</div>
                        <div><?= (int)$transfer['item_count'] ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Total Quantity</div>
                        <div><?= (float)$transfer['total_quantity'] ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Created At</div>
                        <div><?= date('M d, Y H:i', strtotime($transfer['created_at'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Updated At</div>
                        <div><?= !empty($transfer['updated_at']) ? date('M d, Y H:i', strtotime($transfer['updated_at'])) : '-' ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Requested By</div>
                        <div><?= htmlspecialchars($transfer['requested_by_name'] ?? 'System') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Accepted By</div>
                        <div><?= htmlspecialchars($transfer['accepted_by_name'] ?? ($transfer['status'] === 'accepted' ? ($transfer['transferred_by_name'] ?? '-') : '-')) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Transferred By</div>
                        <div><?= htmlspecialchars($transfer['transferred_by_name'] ?? $transfer['requested_by_name'] ?? 'System') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Transferred At</div>
                        <div><?= !empty($transfer['transferred_at']) ? date('M d, Y H:i', strtotime($transfer['transferred_at'])) : '-' ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Notes</div>
                        <div><?= !empty($transfer['notes']) ? nl2br(htmlspecialchars($transfer['notes'])) : '<span class="text-muted">No notes</span>' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Transfer Image<?= count($transferImages) > 1 ? 's' : '' ?></h5>
            </div>
            <div class="card-body">
                <?php if (!empty($transferImages)): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($transferImages as $img): ?>
                            <a href="../../<?= htmlspecialchars($img['file_path']) ?>" target="_blank" rel="noopener">
                                <img src="../../<?= htmlspecialchars($img['file_path']) ?>" alt="Transfer image" class="img-fluid rounded border" style="max-height:200px;max-width:200px;object-fit:contain;">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted">No image attached</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Transferred Items</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Barcode</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Unit</th>
                        <th>Source Now</th>
                        <th>Destination Now</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($item = $items_result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= htmlspecialchars($item['sku']) ?></td>
                            <td><?= htmlspecialchars($item['barcode'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(ucfirst($item['type'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars(trim(($item['category_name'] ?? '-') . (!empty($item['subcategory_name']) ? ' / ' . $item['subcategory_name'] : ''))) ?></td>
                            <td><?= (float)$item['quantity'] ?></td>
                            <td><?= htmlspecialchars($item['unit'] ?: '-') ?></td>
                            <td><?= $item['source_quantity'] !== null ? (float)$item['source_quantity'] : 0 ?></td>
                            <td><?= $item['destination_quantity'] !== null ? (float)$item['destination_quantity'] : 0 ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$items_stmt->close();
require_once '../../includes/footer.php';
?>
