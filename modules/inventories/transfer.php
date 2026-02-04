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
    $from_inventory_id = sanitize($_POST['from_inventory_id']);
    $to_inventory_id = sanitize($_POST['to_inventory_id']);
    $notes = sanitize($_POST['notes']);
    $user_id = $_SESSION['user_id'];
    
    // Generate transfer reference
    $transfer_reference = 'TR-' . date('Ymd') . '-' . generateRandomString(6);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create transfer record
        $transfer_sql = "INSERT INTO inventory_transfers 
                         (transfer_reference, from_inventory_id, to_inventory_id, requested_by, notes) 
                         VALUES (?, ?, ?, ?, ?)";
        $transfer_stmt = $conn->prepare($transfer_sql);
        $transfer_stmt->bind_param("siiis", $transfer_reference, $from_inventory_id, $to_inventory_id, $user_id, $notes);
        $transfer_stmt->execute();
        $transfer_id = $transfer_stmt->insert_id;
        $transfer_stmt->close();
        
        // Process transfer items
        foreach ($_POST['product_id'] as $key => $product_id) {
            $product_id = sanitize($product_id);
            $quantity = sanitize($_POST['quantity'][$key]);
            
            if ($quantity > 0) {
                // Add to transfer items
                $item_sql = "INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)";
                $item_stmt = $conn->prepare($item_sql);
                $item_stmt->bind_param("iid", $transfer_id, $product_id, $quantity);
                $item_stmt->execute();
                $item_stmt->close();
                
                // Deduct from source inventory
                $deduct_sql = "UPDATE inventory_products 
                               SET quantity = quantity - ? 
                               WHERE inventory_id = ? AND product_id = ? AND quantity >= ?";
                $deduct_stmt = $conn->prepare($deduct_sql);
                $deduct_stmt->bind_param("diii", $quantity, $from_inventory_id, $product_id, $quantity);
                $deduct_stmt->execute();
                
                if ($deduct_stmt->affected_rows === 0) {
                    throw new Exception("Insufficient quantity for product ID: $product_id");
                }
                $deduct_stmt->close();
            }
        }
        
        // Commit transaction
        $conn->commit();
        setAlert('success', 'Transfer created successfully. Reference: ' . $transfer_reference);
        logActivity("Created inventory transfer: $transfer_reference (ID: $transfer_id)");
        redirect('index.php');
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        setAlert('danger', 'Error creating transfer: ' . $e->getMessage());
        redirect('transfer.php');
    }
}

// Get all inventories except the one we're transferring from
$inventories_sql = "SELECT id, name FROM inventories WHERE is_active = 1 ORDER BY name";
$inventories_result = $conn->query($inventories_sql);

// Get products for the first inventory (default selection)
$products_sql = "SELECT p.id, p.name, p.sku, ip.quantity 
                 FROM inventory_products ip 
                 JOIN products p ON ip.product_id = p.id 
                 WHERE ip.inventory_id = (SELECT id FROM inventories LIMIT 1)
                 ORDER BY p.name";
$products_result = $conn->query($products_sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transfer Items Between Inventories</h2>
    <a href="index.php" class="btn btn-secondary">Back to Inventories</a>
</div>

<div class="card">
    <div class="card-body">
        <form action="transfer.php" method="POST" id="transferForm">
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
            
            <div class="mb-3">
                <label for="notes" class="form-label">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
            </div>
            
            <div class="table-responsive mb-3">
                <table class="table" id="transferItemsTable">
                    <thead>
                        <tr>
                            <th>Product</th>
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
                <h5 class="modal-title" id="productSelectModalLabel">Select Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="productSearch" class="form-label">Search Products</label>
                    <input type="text" class="form-control" id="productSearch" placeholder="Search by name or SKU">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover" id="productSelectTable">
                        <thead>
                            <tr>
                                <th>Name</th>
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
                                    '<td>' + product.sku + '</td>' +
                                    '<td>' + product.quantity + '</td>' +
                                    '<td><button type="button" class="btn btn-sm btn-primary select-product" ' +
                                    'data-id="' + product.id + '" ' +
                                    'data-name="' + product.name + '" ' +
                                    'data-quantity="' + product.quantity + '">Select</button></td>' +
                                    '</tr>';
                        });
                    } else {
                        html = '<tr><td colspan="4" class="text-center">No products found in this inventory</td></tr>';
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
        }
    });
});
</script>

