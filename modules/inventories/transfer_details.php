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
                        ru.name as received_by_name,
                        tu.name as transferred_by_name,
                        vu.name as verified_by_name,
                        ju.name as rejected_by_name,
                        COALESCE(items.item_count, 0) as item_count,
                        COALESCE(items.total_quantity, 0) as total_quantity
                 FROM inventory_transfers it
                 JOIN inventories i1 ON it.from_inventory_id = i1.id
                 JOIN inventories i2 ON it.to_inventory_id = i2.id
                 LEFT JOIN users u ON it.requested_by = u.id
                 LEFT JOIN users ru ON it.received_by = ru.id
                 LEFT JOIN users tu ON it.transferred_by = tu.id
                 LEFT JOIN users vu ON it.verified_by = vu.id
                 LEFT JOIN users ju ON it.rejected_by = ju.id
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

// Full audit trail: state changes and field-level edits.
$history = [];
if (tableExists('inventory_transfer_history')) {
    $histStmt = $conn->prepare("
        SELECT h.*, hu.name AS actor_name
        FROM inventory_transfer_history h
        LEFT JOIN users hu ON hu.id = h.created_by
        WHERE h.inventory_transfer_id = ?
        ORDER BY h.created_at ASC, h.id ASC
    ");
    $histStmt->bind_param('i', $transfer_id);
    $histStmt->execute();
    $history = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $histStmt->close();
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$status = $transfer['status'];
$isAdmin = isAdminUser();
$isAssignedReceiver = (int)$transfer['received_by'] === $currentUserId;
$ownAction = $isAssignedReceiver || (int)$transfer['transferred_by'] === $currentUserId;

$showReceiveActions = $status === 'pending'
    && hasPermission('inventories.transfer.receive')
    && ($isAssignedReceiver || $isAdmin);
$showVerifyActions = $status === 'transferred'
    && hasPermission('inventories.transfer.verify')
    && (!$ownAction || $isAdmin);
$canEdit = $status !== 'rejected'
    && ((int)$transfer['requested_by'] === $currentUserId || $isAdmin);
$canDelete = in_array($status, ['pending', 'rejected'], true) || $isAdmin;

$statusColors = [
    'pending' => 'warning',
    'transferred' => 'info',
    'verified' => 'success',
    'rejected' => 'danger',
];

$historyActionLabels = [
    'created' => ['Created', 'primary'],
    'edited' => ['Edited', 'secondary'],
    'transferred' => ['Received', 'info'],
    'verified' => ['Verified', 'success'],
    'rejected' => ['Rejected', 'danger'],
    'sent_back' => ['Sent back', 'warning'],
    'images_added' => ['Images added', 'secondary'],
];

$page_title = 'Transfer Details';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Transfer <?= htmlspecialchars($transfer['transfer_reference']) ?></h2>
        <span class="badge bg-<?= $statusColors[$status] ?? 'secondary' ?>">
            <?= ucfirst($status) ?>
        </span>
        <?php if ($status === 'pending'): ?>
            <span class="text-muted small ms-2">Stock has not moved yet — it moves when the receiver confirms.</span>
        <?php endif; ?>
    </div>
    <div>
        <?php if ($showReceiveActions): ?>
            <a href="receive_transfer.php?id=<?= $transfer_id ?>"
               class="btn btn-outline-success"
               onclick="return confirm('Confirm you received these items? This moves the stock from the source to the destination inventory.')">
                <i class="fas fa-check"></i> Confirm Receipt
            </a>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="fas fa-times"></i> Reject
            </button>
        <?php endif; ?>
        <?php if ($showVerifyActions): ?>
            <a href="verify_transfer.php?id=<?= $transfer_id ?>&action=verify"
               class="btn btn-outline-primary"
               onclick="return confirm('Verify this transfer?')">
                <i class="fas fa-clipboard-check"></i> Verify
            </a>
            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#sendBackModal">
                <i class="fas fa-undo"></i> Send Back
            </button>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <a href="edit_transfer.php?id=<?= $transfer_id ?>" class="btn btn-outline-secondary">
                <i class="fas fa-pen"></i> Edit
            </a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <a href="delete_transfer.php?id=<?= $transfer_id ?>"
               class="btn btn-outline-danger"
               onclick="return confirm('<?= in_array($status, ['transferred','verified'], true)
                   ? 'This transfer has already moved stock. Deleting it will reverse the movement. Continue?'
                   : 'Delete this transfer? No stock has been moved.' ?>')">
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
                        <div class="text-muted small">Sender</div>
                        <div><?= htmlspecialchars($transfer['requested_by_name'] ?? 'System') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Receiver <?= $status === 'pending' ? '(assigned)' : '' ?></div>
                        <div><?= htmlspecialchars($transfer['received_by_name'] ?? '-') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Received At</div>
                        <div><?= !empty($transfer['received_at']) ? date('M d, Y H:i', strtotime($transfer['received_at'])) : '-' ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Verified By</div>
                        <div><?= htmlspecialchars($transfer['verified_by_name'] ?? '-') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Verified At</div>
                        <div><?= !empty($transfer['verified_at']) ? date('M d, Y H:i', strtotime($transfer['verified_at'])) : '-' ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Stock Moved At</div>
                        <div><?= !empty($transfer['transferred_at']) ? date('M d, Y H:i', strtotime($transfer['transferred_at'])) : '<span class="text-muted">Not yet</span>' ?></div>
                    </div>
                    <?php if (!empty($transfer['rejected_by'])): ?>
                        <div class="col-md-4">
                            <div class="text-muted small">Rejected By</div>
                            <div><?= htmlspecialchars($transfer['rejected_by_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Rejected At</div>
                            <div><?= !empty($transfer['rejected_at']) ? date('M d, Y H:i', strtotime($transfer['rejected_at'])) : '-' ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Rejection Reason</div>
                            <div><?= !empty($transfer['rejection_reason']) ? nl2br(htmlspecialchars($transfer['rejection_reason'])) : '-' ?></div>
                        </div>
                    <?php endif; ?>
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

<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Activity &amp; Edit History</h5>
    </div>
    <div class="card-body">
        <?php if (empty($history)): ?>
            <span class="text-muted">No history recorded for this transfer.</span>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Who</th>
                            <th>Action</th>
                            <th>Field</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $index => $entry): ?>
                            <?php
                                $isLatest = $index === count($history) - 1;
                                [$actionLabel, $actionColor] = $historyActionLabels[$entry['action']]
                                    ?? [ucfirst(str_replace('_', ' ', $entry['action'])), 'secondary'];
                            ?>
                            <tr class="<?= $isLatest ? 'table-light fw-semibold' : '' ?>">
                                <td class="text-nowrap"><?= date('M d, Y H:i', strtotime($entry['created_at'])) ?></td>
                                <td><?= htmlspecialchars($entry['actor_name'] ?? 'System') ?></td>
                                <td><span class="badge bg-<?= $actionColor ?>"><?= htmlspecialchars($actionLabel) ?></span></td>
                                <td><?= !empty($entry['field_name']) ? htmlspecialchars($entry['field_name']) : '-' ?></td>
                                <td><?= ($entry['old_value'] !== null && $entry['old_value'] !== '') ? htmlspecialchars($entry['old_value']) : '-' ?></td>
                                <td><?= ($entry['new_value'] !== null && $entry['new_value'] !== '') ? htmlspecialchars($entry['new_value']) : '-' ?></td>
                                <td><?= !empty($entry['note']) ? htmlspecialchars($entry['note']) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($showReceiveActions): ?>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="reject_transfer.php" method="POST">
            <input type="hidden" name="transfer_id" value="<?= $transfer_id ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Transfer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">No stock has been moved, so rejecting simply closes this transfer.</p>
                    <label for="rejection_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Transfer</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($showVerifyActions): ?>
<div class="modal fade" id="sendBackModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="verify_transfer.php" method="POST">
            <input type="hidden" name="transfer_id" value="<?= $transfer_id ?>">
            <input type="hidden" name="action" value="send_back">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send Transfer Back</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small mb-3">
                        This transfer already moved stock. Sending it back returns the items to the
                        source inventory and puts the transfer back in the receiver's queue.
                    </div>
                    <label for="send_back_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="send_back_reason" name="send_back_reason" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Send Back</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$items_stmt->close();
require_once '../../includes/footer.php';
?>
