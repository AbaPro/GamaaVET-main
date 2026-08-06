<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Editing rules follow where the stock is:
//   pending   -> nothing has moved yet, so items/quantities/receiver/notes are all editable
//   otherwise -> stock has already moved, so only extra images and notes are allowed
if (!hasPermission('inventories.transfer')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$transfer_id = (int)($_GET['id'] ?? $_POST['transfer_id'] ?? 0);
$user_id = $_SESSION['user_id'] ?? null;

if ($transfer_id <= 0) {
    setAlert('danger', 'Invalid transfer.');
    redirect('transfers_list.php');
}

if (!canAccessInventoryTransfer($transfer_id)) {
    setAlert('danger', 'Transfer not found in the currently selected region.');
    redirect('transfers_list.php');
}

$transfer_stmt = $conn->prepare("SELECT * FROM inventory_transfers WHERE id = ?");
$transfer_stmt->bind_param('i', $transfer_id);
$transfer_stmt->execute();
$transfer = $transfer_stmt->get_result()->fetch_assoc();
$transfer_stmt->close();

if (!$transfer) {
    setAlert('danger', 'Transfer not found.');
    redirect('transfers_list.php');
}

// The sender owns the edit; admins may always step in.
$isOwner = (int)$transfer['requested_by'] === (int)$user_id;
if (!$isOwner && !isAdminUser()) {
    setAlert('danger', 'Only the sender or an administrator can edit this transfer.');
    redirect('transfer_details.php?id=' . $transfer_id);
}

if ($transfer['status'] === 'rejected') {
    setAlert('danger', 'Rejected transfers cannot be edited.');
    redirect('transfer_details.php?id=' . $transfer_id);
}

$isFullyEditable = $transfer['status'] === 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = sanitize($_POST['notes'] ?? '');
    $receiver_id = (int)sanitize($_POST['receiver_id'] ?? 0);
    $uploadedImages = [];
    $notifyNewReceiver = null;

    $conn->begin_transaction();

    try {
        $newImageError = null;
        $uploadedImages = uploadImageAttachments(
            'transfer_image',
            'assets/uploads/inventory_transfers',
            'inventory_transfer_' . $transfer['transfer_reference'],
            0, // additional images are optional on edit
            $newImageError
        );

        if ($newImageError !== null) {
            throw new Exception($newImageError);
        }

        $historyEntries = [];

        // --- Notes (editable in every non-rejected state) ---
        if ($notes !== (string)$transfer['notes']) {
            $noteStmt = $conn->prepare("UPDATE inventory_transfers SET notes = ? WHERE id = ?");
            $noteStmt->bind_param('si', $notes, $transfer_id);
            $noteStmt->execute();
            $noteStmt->close();
            $historyEntries[] = ['edited', 'notes', (string)$transfer['notes'], $notes, null];
        }

        if ($isFullyEditable) {
            // --- Receiver ---
            if ($receiver_id > 0 && $receiver_id !== (int)$transfer['received_by']) {
                if (!userHasPermissionKey($receiver_id, 'inventories.transfer.receive')) {
                    throw new Exception('The selected receiver is not allowed to receive inventory transfers.');
                }

                $oldReceiverName = null;
                if (!empty($transfer['received_by'])) {
                    $rStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                    $rStmt->bind_param('i', $transfer['received_by']);
                    $rStmt->execute();
                    $oldReceiverName = $rStmt->get_result()->fetch_assoc()['name'] ?? null;
                    $rStmt->close();
                }

                $nStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                $nStmt->bind_param('i', $receiver_id);
                $nStmt->execute();
                $newReceiverName = $nStmt->get_result()->fetch_assoc()['name'] ?? ('User #' . $receiver_id);
                $nStmt->close();

                $recStmt = $conn->prepare("UPDATE inventory_transfers SET received_by = ? WHERE id = ?");
                $recStmt->bind_param('ii', $receiver_id, $transfer_id);
                $recStmt->execute();
                $recStmt->close();

                $historyEntries[] = ['edited', 'receiver', $oldReceiverName, $newReceiverName, null];
                $notifyNewReceiver = $receiver_id;
            }

            // --- Items: replace the line set, recording a compact before/after ---
            if (!empty($_POST['product_id']) && is_array($_POST['product_id'])) {
                $existingStmt = $conn->prepare("
                    SELECT p.name, ti.quantity
                    FROM transfer_items ti
                    JOIN products p ON p.id = ti.product_id
                    WHERE ti.transfer_id = ?
                    ORDER BY p.name
                ");
                $existingStmt->bind_param('i', $transfer_id);
                $existingStmt->execute();
                $existingRows = $existingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $existingStmt->close();

                $oldSummary = [];
                foreach ($existingRows as $row) {
                    $oldSummary[] = $row['name'] . ' x' . (float)$row['quantity'];
                }

                $delStmt = $conn->prepare("DELETE FROM transfer_items WHERE transfer_id = ?");
                $delStmt->bind_param('i', $transfer_id);
                $delStmt->execute();
                $delStmt->close();

                $insStmt = $conn->prepare("INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)");
                $itemCount = 0;
                foreach ($_POST['product_id'] as $key => $productId) {
                    $productId = (int)sanitize($productId);
                    $quantity = (float)sanitize($_POST['quantity'][$key] ?? 0);
                    if ($productId > 0 && $quantity > 0) {
                        $insStmt->bind_param('iid', $transfer_id, $productId, $quantity);
                        $insStmt->execute();
                        $itemCount++;
                    }
                }
                $insStmt->close();

                if ($itemCount === 0) {
                    throw new Exception('A transfer must keep at least one item with a quantity greater than zero.');
                }

                $newStmt = $conn->prepare("
                    SELECT p.name, ti.quantity
                    FROM transfer_items ti
                    JOIN products p ON p.id = ti.product_id
                    WHERE ti.transfer_id = ?
                    ORDER BY p.name
                ");
                $newStmt->bind_param('i', $transfer_id);
                $newStmt->execute();
                $newRows = $newStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $newStmt->close();

                $newSummary = [];
                foreach ($newRows as $row) {
                    $newSummary[] = $row['name'] . ' x' . (float)$row['quantity'];
                }

                $oldText = implode(', ', $oldSummary);
                $newText = implode(', ', $newSummary);
                if ($oldText !== $newText) {
                    $historyEntries[] = ['edited', 'items', $oldText, $newText, null];
                }
            }
        }

        // --- Images (always appendable) ---
        if (!empty($uploadedImages)) {
            $imgStmt = $conn->prepare("INSERT INTO inventory_transfer_images (inventory_transfer_id, file_path, original_name, created_by) VALUES (?, ?, ?, ?)");
            foreach ($uploadedImages as $file) {
                $imgStmt->bind_param('issi', $transfer_id, $file['path'], $file['original_name'], $user_id);
                $imgStmt->execute();
            }
            $imgStmt->close();
            $historyEntries[] = ['images_added', 'images', null, count($uploadedImages) . ' image(s) added', null];
        }

        if (empty($historyEntries)) {
            throw new Exception('Nothing was changed.');
        }

        $conn->commit();

        // One history row per changed field.
        foreach ($historyEntries as $entry) {
            logTransferHistory($transfer_id, $entry[0], $entry[1], $entry[2], $entry[3], $entry[4]);
        }

        logActivity("Edited inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']})");

        if (!empty($notifyNewReceiver)) {
            createNotification(
                'inventory_transfer_pending',
                'Transfer assigned to you',
                'Transfer ' . $transfer['transfer_reference'] . ' has been assigned to you for confirmation.',
                'inventories',
                'inventory_transfer',
                $transfer_id,
                'info',
                null,
                (int)$notifyNewReceiver,
                $user_id
            );
        }

        setAlert('success', 'Transfer updated.');
        redirect('transfer_details.php?id=' . $transfer_id);
    } catch (Exception $e) {
        $conn->rollback();
        foreach ($uploadedImages as $file) {
            $full = ROOT_PATH . '/' . $file['path'];
            if (is_file($full)) {
                unlink($full);
            }
        }
        setAlert('danger', 'Error updating transfer: ' . $e->getMessage());
        redirect('edit_transfer.php?id=' . $transfer_id);
    }
}

// --- Render ---
$items_stmt = $conn->prepare("
    SELECT ti.product_id, ti.quantity, p.name AS product_name, p.sku,
           COALESCE(ip.quantity, 0) AS available
    FROM transfer_items ti
    JOIN products p ON p.id = ti.product_id
    LEFT JOIN inventory_products ip ON ip.product_id = ti.product_id AND ip.inventory_id = ?
    WHERE ti.transfer_id = ?
    ORDER BY p.name
");
$items_stmt->bind_param('ii', $transfer['from_inventory_id'], $transfer_id);
$items_stmt->execute();
$currentItems = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

$receiverCandidates = getUsersWithPermission('inventories.transfer.receive');

$invStmt = $conn->prepare("
    SELECT i1.name AS from_inventory, i2.name AS to_inventory
    FROM inventory_transfers it
    JOIN inventories i1 ON i1.id = it.from_inventory_id
    JOIN inventories i2 ON i2.id = it.to_inventory_id
    WHERE it.id = ?
");
$invStmt->bind_param('i', $transfer_id);
$invStmt->execute();
$inventoryNames = $invStmt->get_result()->fetch_assoc() ?: ['from_inventory' => '-', 'to_inventory' => '-'];
$invStmt->close();

$page_title = 'Edit Transfer';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Transfer <?= htmlspecialchars($transfer['transfer_reference']) ?></h2>
    <a href="transfer_details.php?id=<?= $transfer_id ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Details
    </a>
</div>

<?php if (!$isFullyEditable): ?>
    <div class="alert alert-info">
        This transfer has already been received, so its items and quantities are locked.
        You can still add images and update the notes.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form action="edit_transfer.php" method="POST" id="editTransferForm" enctype="multipart/form-data">
            <input type="hidden" name="transfer_id" value="<?= $transfer_id ?>">

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Route</label>
                    <input type="text" class="form-control"
                           value="<?= htmlspecialchars($inventoryNames['from_inventory'] . '  →  ' . $inventoryNames['to_inventory']) ?>" disabled>
                    <small class="text-muted">Inventories cannot be changed after creation.</small>
                </div>
                <div class="col-md-6">
                    <label for="receiver_id" class="form-label">Receiver</label>
                    <select class="form-select" id="receiver_id" name="receiver_id" <?= $isFullyEditable ? '' : 'disabled' ?>>
                        <option value="">-- Select Receiving User --</option>
                        <?php foreach ($receiverCandidates as $candidate): ?>
                            <option value="<?= (int)$candidate['id'] ?>" <?= ((int)$candidate['id'] === (int)$transfer['received_by']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($candidate['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label for="notes" class="form-label">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars($transfer['notes'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label for="transfer_image" class="form-label">Add More Images</label>
                <input type="file" class="form-control" id="transfer_image" name="transfer_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                <small class="text-muted">Existing images are kept. JPG, PNG, GIF, WEBP, max 5MB each.</small>
            </div>

            <?php if ($isFullyEditable): ?>
                <div class="table-responsive mb-3">
                    <table class="table" id="transferItemsTable">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Available</th>
                                <th>Quantity</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="transferItemsBody">
                            <?php foreach ($currentItems as $item): ?>
                                <tr data-id="<?= (int)$item['product_id'] ?>">
                                    <td>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <input type="hidden" name="product_id[]" value="<?= (int)$item['product_id'] ?>">
                                    </td>
                                    <td><span class="available-quantity"><?= (float)$item['available'] ?></span></td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm quantity" name="quantity[]"
                                               min="0" step="0.01" value="<?= (float)$item['quantity'] ?>" required>
                                    </td>
                                    <td><button type="button" class="btn btn-sm btn-danger remove-item"><i class="fas fa-trash"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">
                                    <button type="button" class="btn btn-sm btn-primary" id="addTransferItem">
                                        <i class="fas fa-plus"></i> Add Item
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>

            <div class="text-end">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php if ($isFullyEditable): ?>
<!-- Product Select Modal -->
<div class="modal fade" id="productSelectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="productSearch" class="form-label">Search Products</label>
                    <input type="text" class="form-control" id="productSearch" placeholder="Search by product name or SKU">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>SKU</th>
                                <th>Available</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="productSelectBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

<?php if ($isFullyEditable): ?>
<script>
$(document).ready(function() {
    var sourceInventoryId = <?= (int)$transfer['from_inventory_id'] ?>;

    function loadProducts() {
        $.ajax({
            url: '../../ajax/get_inventory_products.php',
            type: 'GET',
            data: { inventory_id: sourceInventoryId },
            dataType: 'json',
            success: function(response) {
                var products = response.products;
                var html = '';

                if (products.length > 0) {
                    $.each(products, function(index, product) {
                        html += '<tr>' +
                                '<td>' + product.name + '</td>' +
                                '<td>' + (product.type === 'material' ? 'Raw Material' : 'Final Product') + '</td>' +
                                '<td>' + product.sku + '</td>' +
                                '<td>' + product.quantity + '</td>' +
                                '<td><button type="button" class="btn btn-sm btn-primary select-product" ' +
                                'data-id="' + product.id + '" ' +
                                'data-name="' + product.name + '" ' +
                                'data-quantity="' + product.quantity + '">Select</button></td>' +
                                '</tr>';
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No items found in this inventory</td></tr>';
                }

                $('#productSelectBody').html(html);
            }
        });
    }

    loadProducts();

    $('#addTransferItem').click(function() {
        $('#productSelectModal').modal('show');
    });

    $('#productSearch').keyup(function() {
        var search = $(this).val().toLowerCase();
        $('#productSelectBody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(search) > -1);
        });
    });

    $(document).on('click', '.select-product', function() {
        var product_id = $(this).data('id');
        var product_name = $(this).data('name');
        var available = $(this).data('quantity');

        if ($('#transferItemsBody tr[data-id="' + product_id + '"]').length > 0) {
            alert('This product is already added to the transfer.');
            return;
        }

        var row = '<tr data-id="' + product_id + '">' +
                  '<td>' + product_name + '<input type="hidden" name="product_id[]" value="' + product_id + '"></td>' +
                  '<td><span class="available-quantity">' + available + '</span></td>' +
                  '<td><input type="number" class="form-control form-control-sm quantity" name="quantity[]" min="0" step="0.01" required></td>' +
                  '<td><button type="button" class="btn btn-sm btn-danger remove-item"><i class="fas fa-trash"></i></button></td>' +
                  '</tr>';

        $('#transferItemsBody').append(row);
        $('#productSelectModal').modal('hide');
    });

    $(document).on('click', '.remove-item', function() {
        $(this).closest('tr').remove();
    });

    $('#editTransferForm').submit(function(e) {
        if ($('#transferItemsBody tr').length === 0) {
            e.preventDefault();
            alert('A transfer must keep at least one item.');
        }
    });
});
</script>
<?php endif; ?>
