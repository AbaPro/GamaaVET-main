<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.transfer')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Transfer Items Between Inventories';
require_once '../../includes/header.php';

// Handle transfer submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_inventory_id = (int)sanitize($_POST['from_inventory_id']);
    $to_inventory_id = (int)sanitize($_POST['to_inventory_id']);
    $receiver_id = (int)sanitize($_POST['receiver_id'] ?? 0);
    $notes = sanitize($_POST['notes']);
    $user_id = $_SESSION['user_id'];
    $uploadedTransferImages = [];

    if ($from_inventory_id === $to_inventory_id) {
        setAlert('danger', 'Source and destination inventories must be different.');
        redirect('transfer.php');
    }

    if (!canAccessInventory($from_inventory_id) || !canAccessInventory($to_inventory_id)) {
        setAlert('danger', 'Both inventories must belong to the currently selected region.');
        redirect('transfer.php');
    }

    // Never trust the posted receiver id: confirm the user really holds the
    // receive permission rather than relying on the rendered dropdown.
    if ($receiver_id <= 0) {
        setAlert('danger', 'Please select the user who will receive this transfer.');
        redirect('transfer.php');
    }

    if (!userHasPermissionKey($receiver_id, 'inventories.transfer.receive')) {
        setAlert('danger', 'The selected receiver is not allowed to receive inventory transfers.');
        redirect('transfer.php');
    }

    if (empty($_POST['product_id']) || !is_array($_POST['product_id'])) {
        setAlert('danger', 'Please add at least one item to transfer.');
        redirect('transfer.php');
    }

    // Generate transfer reference
    $transfer_reference = 'TR-' . date('Ymd') . '-' . generateRandomString(6);

    // Start transaction
    $conn->begin_transaction();

    try {
        $transferImageError = null;
        $uploadedTransferImages = uploadImageAttachments(
            'transfer_image',
            'assets/uploads/inventory_transfers',
            'inventory_transfer_' . $transfer_reference,
            1, // required
            $transferImageError
        );

        if ($transferImageError !== null) {
            throw new Exception($transferImageError);
        }

        // Created as 'pending': stock does NOT move here. The assigned receiver
        // moves it by confirming in receive_transfer.php.
        $transfer_sql = "INSERT INTO inventory_transfers
                         (transfer_reference, from_inventory_id, to_inventory_id, status, requested_by, received_by, notes)
                         VALUES (?, ?, ?, 'pending', ?, ?, ?)";
        $transfer_stmt = $conn->prepare($transfer_sql);
        $transfer_stmt->bind_param("siiiis", $transfer_reference, $from_inventory_id, $to_inventory_id, $user_id, $receiver_id, $notes);
        $transfer_stmt->execute();
        $transfer_id = $transfer_stmt->insert_id;
        $transfer_stmt->close();

        if (!empty($uploadedTransferImages)) {
            $imgStmt = $conn->prepare("INSERT INTO inventory_transfer_images (inventory_transfer_id, file_path, original_name, created_by) VALUES (?, ?, ?, ?)");
            foreach ($uploadedTransferImages as $file) {
                $imgStmt->bind_param("issi", $transfer_id, $file['path'], $file['original_name'], $user_id);
                $imgStmt->execute();
            }
            $imgStmt->close();
        }

        // Record the requested lines. Availability is checked again (and enforced)
        // when the receiver confirms, since stock can move in the meantime.
        $itemCount = 0;
        foreach ($_POST['product_id'] as $key => $product_id) {
            $product_id = (int)sanitize($product_id);
            $quantity = (float)sanitize($_POST['quantity'][$key]);

            if ($quantity > 0) {
                $item_sql = "INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)";
                $item_stmt = $conn->prepare($item_sql);
                $item_stmt->bind_param("iid", $transfer_id, $product_id, $quantity);
                $item_stmt->execute();
                $item_stmt->close();
                $itemCount++;
            }
        }

        if ($itemCount === 0) {
            throw new Exception('Please add at least one item with a quantity greater than zero.');
        }

        // Commit transaction
        $conn->commit();

        logTransferHistory(
            $transfer_id,
            'created',
            null,
            null,
            null,
            'Transfer created with ' . $itemCount . ' item(s) and ' . count($uploadedTransferImages) . ' image(s)'
        );

        createNotification(
            'inventory_transfer_pending',
            'Transfer awaiting your confirmation',
            'Transfer ' . $transfer_reference . ' has been assigned to you. Confirm it once you receive the items.',
            'inventories',
            'inventory_transfer',
            $transfer_id,
            'info',
            null,
            $receiver_id,
            $user_id
        );

        setAlert('success', 'Transfer created and sent to the receiver for confirmation. Reference: ' . $transfer_reference);
        logActivity("Created inventory transfer: $transfer_reference (ID: $transfer_id) awaiting receiver ID $receiver_id");
        redirect('transfers_list.php');
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        foreach ($uploadedTransferImages as $file) {
            $full = ROOT_PATH . '/' . $file['path'];
            if (is_file($full)) {
                unlink($full);
            }
        }
        setAlert('danger', 'Error creating transfer: ' . $e->getMessage());
        redirect('transfer.php');
    }
}

// Transfers are allowed only between inventories in the selected login channel.
$inventoryScope = getInventoryChannelScopeSql('i');
$inventories_sql = "SELECT i.id, i.name FROM inventories i WHERE i.is_active = 1 AND $inventoryScope ORDER BY i.name";
$inventories_result = $conn->query($inventories_sql);

// Only users who can actually act on the transfer may be assigned as receiver.
$receiverCandidates = getUsersWithPermission('inventories.transfer.receive');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transfer Items Between Inventories</h2>
    <a href="index.php" class="btn btn-secondary">Back to Inventories</a>
</div>

<div class="card">
    <div class="card-body">
        <form action="transfer.php" method="POST" id="transferForm" enctype="multipart/form-data">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="from_inventory_id" class="form-label">From Inventory</label>
                    <select class="form-select" id="from_inventory_id" name="from_inventory_id" required>
                        <option value="">-- Select Source Inventory --</option>
                        <?php while ($inventory = $inventories_result->fetch_assoc()): ?>
                            <option value="<?php echo $inventory['id']; ?>"><?php echo htmlspecialchars($inventory['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="to_inventory_id" class="form-label">To Inventory</label>
                    <select class="form-select" id="to_inventory_id" name="to_inventory_id" required>
                        <option value="">-- Select Destination Inventory --</option>
                        <?php 
                        $inventories_result->data_seek(0); // Reset pointer
                        while ($inventory = $inventories_result->fetch_assoc()): ?>
                            <option value="<?php echo $inventory['id']; ?>"><?php echo htmlspecialchars($inventory['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="receiver_id" class="form-label">Receiver <span class="text-danger">*</span></label>
                    <select class="form-select" id="receiver_id" name="receiver_id" required>
                        <option value="">-- Select Receiving User --</option>
                        <?php foreach ($receiverCandidates as $candidate): ?>
                            <option value="<?php echo (int)$candidate['id']; ?>"><?php echo htmlspecialchars($candidate['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">This user is notified and must confirm the transfer. Stock moves only after they confirm.</small>
                    <?php if (empty($receiverCandidates)): ?>
                        <div class="text-danger small mt-1">No users hold the "Receive Transfers" permission yet. Grant it in Roles &amp; Permissions first.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="notes" class="form-label">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
            </div>

            <div class="mb-3">
                <label for="transfer_image" class="form-label">Transfer Image <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="transfer_image" name="transfer_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
                <small class="text-muted">Attach the transfer image or receipt. JPG, PNG, GIF, WEBP, max 5MB.</small>
            </div>
            
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
                        <!-- Items will be added dynamically -->
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
            
            <div class="text-end">
                <button type="submit" class="btn btn-primary">Submit Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- Product Select Modal -->
<div class="modal fade" id="productSelectModal" tabindex="-1" aria-labelledby="productSelectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productSelectModalLabel">Select Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="productSearch" class="form-label">Search Products</label>
                    <input type="text" class="form-control" id="productSearch" placeholder="Search by product name or SKU">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover" id="productSelectTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>SKU</th>
                                <th>Available</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="productSelectBody">
                            <!-- Products will be loaded dynamically -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Get products for selected inventory
    $('#from_inventory_id').change(function() {
        var inventory_id = $(this).val();
        if (inventory_id) {
            $.ajax({
                url: '../../ajax/get_inventory_products.php',
                type: 'GET',
                data: { inventory_id: inventory_id },
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
    });
    
    // Add item button
    $('#addTransferItem').click(function() {
        $('#productSelectModal').modal('show');
    });
    
    // Product search
    $('#productSearch').keyup(function() {
        var search = $(this).val().toLowerCase();
        $('#productSelectBody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(search) > -1);
        });
    });
    
    // Select product
    $(document).on('click', '.select-product', function() {
        var product_id = $(this).data('id');
        var product_name = $(this).data('name');
        var available = $(this).data('quantity');
        
        // Check if product already added
        if ($('#transferItemsBody tr[data-id="' + product_id + '"]').length > 0) {
            alert('This product is already added to the transfer.');
            return;
        }
        
        // Add to transfer items table
        var row = '<tr data-id="' + product_id + '">' +
                  '<td>' + product_name + '<input type="hidden" name="product_id[]" value="' + product_id + '"></td>' +
                  '<td><span class="available-quantity">' + available + '</span></td>' +
                  '<td><input type="number" class="form-control form-control-sm quantity" name="quantity[]" min="0" max="' + available + '" step="0.01" required></td>' +
                  '<td><button type="button" class="btn btn-sm btn-danger remove-item"><i class="fas fa-trash"></i></button></td>' +
                  '</tr>';
        
        $('#transferItemsBody').append(row);
        $('#productSelectModal').modal('hide');
    });
    
    // Remove item
    $(document).on('click', '.remove-item', function() {
        $(this).closest('tr').remove();
    });
    
    // Form submission validation
    $('#transferForm').submit(function(e) {
        if ($('#transferItemsBody tr').length === 0) {
            e.preventDefault();
            alert('Please add at least one item to transfer.');
            return;
        }

        if (!$('#receiver_id').val()) {
            e.preventDefault();
            alert('Please select the user who will receive this transfer.');
            return;
        }

        if (!$('#transfer_image').val()) {
            e.preventDefault();
            alert('Please upload a transfer image.');
        }
    });
});
</script>
