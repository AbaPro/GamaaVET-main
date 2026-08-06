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
$sourceScope = getInventoryChannelScopeSql('i1');
$destinationScope = getInventoryChannelScopeSql('i2');
$sql = "SELECT it.*,
               i1.name as from_inventory,
               i2.name as to_inventory,
               u.name as requested_by_name,
               ru.name as received_by_name,
               vu.name as verified_by_name,
               ju.name as rejected_by_name,
               COALESCE(items.item_count, 0) as item_count,
               COALESCE(items.total_quantity, 0) as total_quantity
        FROM inventory_transfers it
        JOIN inventories i1 ON it.from_inventory_id = i1.id
        JOIN inventories i2 ON it.to_inventory_id = i2.id
        LEFT JOIN users u ON it.requested_by = u.id
        LEFT JOIN users ru ON it.received_by = ru.id
        LEFT JOIN users vu ON it.verified_by = vu.id
        LEFT JOIN users ju ON it.rejected_by = ju.id
        LEFT JOIN (
            SELECT transfer_id, COUNT(*) as item_count, SUM(quantity) as total_quantity
            FROM transfer_items
            GROUP BY transfer_id
        ) items ON items.transfer_id = it.id
        WHERE $sourceScope AND $destinationScope
        ORDER BY it.created_at DESC";
$result = $conn->query($sql);

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canReceive = hasPermission('inventories.transfer.receive');
$canVerify = hasPermission('inventories.transfer.verify');
$isAdmin = isAdminUser();

// Bootstrap contextual colour per workflow state.
$statusColors = [
    'pending' => 'warning',
    'transferred' => 'info',
    'verified' => 'success',
    'rejected' => 'danger',
];

$transferImagesByTransfer = [];
$tiRes = $conn->query("SELECT images.inventory_transfer_id, images.file_path, images.original_name
                       FROM inventory_transfer_images images
                       JOIN inventory_transfers it ON it.id = images.inventory_transfer_id
                       JOIN inventories i1 ON i1.id = it.from_inventory_id
                       JOIN inventories i2 ON i2.id = it.to_inventory_id
                       WHERE $sourceScope AND $destinationScope
                       ORDER BY images.created_at ASC");
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
                        <th>Sender</th>
                        <th>Receiver</th>
                        <th>Verified By</th>
                        <th>Last Action</th>
                        <th>Image</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <?php
                            $status = $row['status'];
                            $isAssignedReceiver = (int)$row['received_by'] === $currentUserId;
                            // Only the assigned receiver may confirm; admins can step in.
                            $showReceiveActions = $status === 'pending' && $canReceive && ($isAssignedReceiver || $isAdmin);
                            // A verifier who received it cannot sign off on their own work.
                            $ownAction = $isAssignedReceiver || (int)$row['transferred_by'] === $currentUserId;
                            $showVerifyActions = $status === 'transferred' && $canVerify && (!$ownAction || $isAdmin);
                            $canEdit = $status !== 'rejected'
                                && ((int)$row['requested_by'] === $currentUserId || $isAdmin);
                            $canDelete = in_array($status, ['pending', 'rejected'], true) || $isAdmin;

                            $lastAction = $row['created_at'];
                            foreach (['rejected_at', 'verified_at', 'transferred_at', 'received_at'] as $tsField) {
                                if (!empty($row[$tsField])) {
                                    $lastAction = $row[$tsField];
                                    break;
                                }
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['transfer_reference']) ?></td>
                            <td><?= htmlspecialchars($row['from_inventory']) ?></td>
                            <td><?= htmlspecialchars($row['to_inventory']) ?></td>
                            <td>
                                <span class="badge bg-<?= $statusColors[$status] ?? 'secondary' ?>">
                                    <?= ucfirst($status) ?>
                                </span>
                                <?php if ($showReceiveActions): ?>
                                    <span class="badge bg-dark">Waiting on you</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$row['item_count'] ?></td>
                            <td><?= (float)$row['total_quantity'] ?></td>
                            <td><?= htmlspecialchars($row['requested_by_name'] ?? 'System') ?></td>
                            <td><?= htmlspecialchars($row['received_by_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['verified_by_name'] ?? '-') ?></td>
                            <td><?= date('M d, Y H:i', strtotime($lastAction)) ?></td>
                            <td>
                                <?php echo renderAttachmentThumbnails($transferImagesByTransfer[$row['id']] ?? []); ?>
                            </td>
                            <td>
                                <a href="transfer_details.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </td>
                            <td>
                                <?php if ($showReceiveActions): ?>
                                    <a href="receive_transfer.php?id=<?= $row['id'] ?>"
                                       class="btn btn-sm btn-outline-success"
                                       onclick="return confirm('Confirm you received these items? This moves the stock from the source to the destination inventory.')">
                                        <i class="fas fa-check"></i> Receive
                                    </a>
                                <?php endif; ?>
                                <?php if ($showVerifyActions): ?>
                                    <a href="transfer_details.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-clipboard-check"></i> Verify
                                    </a>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <a href="edit_transfer.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-pen"></i> Edit
                                    </a>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <a href="delete_transfer.php?id=<?= $row['id'] ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('<?= in_array($status, ['transferred','verified'], true)
                                           ? 'This transfer has already moved stock. Deleting it will reverse the movement. Continue?'
                                           : 'Delete this transfer? No stock has been moved.' ?>')">
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
