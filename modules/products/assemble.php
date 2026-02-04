<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('products.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Assemble Final Product';
require_once '../../includes/header.php';

// Handle assembly submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $final_product_id = sanitize($_POST['final_product_id']);
    $inventory_id = sanitize($_POST['inventory_id']);
    $quantity = sanitize($_POST['quantity']);
    $user_id = $_SESSION['user_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get all components for the final product
        $components_sql = "SELECT pc.component_id, pc.quantity as required_quantity, p.name 
                           FROM product_components pc 
                           JOIN products p ON pc.component_id = p.id 
                           WHERE pc.final_product_id = ?";
        $components_stmt = $conn->prepare($components_sql);
        $components_stmt->bind_param("i", $final_product_id);
        $components_stmt->execute();
        $components_result = $components_stmt->get_result();
        
        // Check inventory for each component
        $inventory_check = true;
        $inventory_errors = [];
        $components = [];
        
        while ($component = $components_result->fetch_assoc()) {
            $check_sql = "SELECT quantity FROM inventory_products 
                          WHERE inventory_id = ? AND product_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $inventory_id, $component['component_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                $inventory_check = false;
                $inventory_errors[] = "Component not found in inventory: " . $component['name'];
            } else {
                $inventory_qty = $check_result->fetch_assoc()['quantity'];
                $required_qty = $component['required_quantity'] * $quantity;
                
                if ($inventory_qty < $required_qty) {
                    $inventory_check = false;
                    $inventory_errors[] = "Insufficient quantity for component: " . $component['name'] . 
                                         " (Available: $inventory_qty, Required: $required_qty)";
                }
            }
            
            $check_stmt->close();
            $components[] = $component;
        }
        $components_stmt->close();
        
        if (!$inventory_check) {
            throw new Exception(implode("<br>", $inventory_errors));
        }
        
        // Deduct components from inventory
        foreach ($components as $component) {
            $deduct_qty = $component['required_quantity'] * $quantity;
            
            $deduct_sql = "UPDATE inventory_products 
                           SET quantity = quantity - ? 
                           WHERE inventory_id = ? AND product_id = ?";
            $deduct_stmt = $conn->prepare($deduct_sql);
            $deduct_stmt->bind_param("dii", $deduct_qty, $inventory_id, $component['component_id']);
            $deduct_stmt->execute();
            $deduct_stmt->close();
        }
        
        // Add final product to inventory
        $check_final_sql = "SELECT quantity FROM inventory_products 
                            WHERE inventory_id = ? AND product_id = ?";
        $check_final_stmt = $conn->prepare($check_final_sql);
        $check_final_stmt->bind_param("ii", $inventory_id, $final_product_id);
        $check_final_stmt->execute();
        $check_final_result = $check_final_stmt->get_result();
        
        if ($check_final_result->num_rows > 0) {
            // Update existing quantity
            $update_sql = "UPDATE inventory_products 
                           SET quantity = quantity + ? 
                           WHERE inventory_id = ? AND product_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("dii", $quantity, $inventory_id, $final_product_id);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            // Insert new record
            $insert_sql = "INSERT INTO inventory_products 
                           (inventory_id, product_id, quantity) 
                           VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iid", $inventory_id, $final_product_id, $quantity);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        $check_final_stmt->close();
        
        // Log assembly
        $log_sql = "INSERT INTO product_assemblies 
                    (final_product_id, inventory_id, quantity, assembled_by) 
                    VALUES (?, ?, ?, ?)";
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("iidi", $final_product_id, $inventory_id, $quantity, $user_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        setAlert('success', "Successfully assembled $quantity final product(s).");
        logActivity("Assembled final product ID: $final_product_id, Quantity: $quantity");
        redirect('index.php');
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        setAlert('danger', 'Error assembling product: ' . $e->getMessage());
        redirect('assemble.php');
    }
}

// Get all final products
$final_products_sql = "SELECT id, name, sku FROM products WHERE type = 'final' ORDER BY name";
$final_products_result = $conn->query($final_products_sql);

// Get all active inventories
$inventories_sql = "SELECT id, name FROM inventories WHERE is_active = 1 ORDER BY name";
$inventories_result = $conn->query($inventories_sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Assemble Final Product</h2>
    <a href="index.php" class="btn btn-secondary">Back to Products</a>
</div>

<div class="card">
    <div class="card-body">
        <form action="assemble.php" method="POST" id="assembleForm">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="final_product_id" class="form-label">Final Product</label>
                    <select class="form-select" id="final_product_id" name="final_product_id" required>
                        <option value="">-- Select Final Product --</option>
                        <?php while ($product = $final_products_result->fetch_assoc()): ?>
                            <option value="<?php echo $product['id']; ?>">
                                <?php echo htmlspecialchars($product['name']) . ' (' . htmlspecialchars($product['sku']) . ')'; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="inventory_id" class="form-label">Inventory</label>
                    <select class="form-select" id="inventory_id" name="inventory_id" required>
                        <option value="">-- Select Inventory --</option>
                        <?php while ($inventory = $inventories_result->fetch_assoc()): ?>
                            <option value="<?php echo $inventory['id']; ?>">
                                <?php echo htmlspecialchars($inventory['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="quantity" class="form-label">Quantity to Assemble</label>
                    <input type="number" class="form-control" id="quantity" name="quantity" min="1" value="1" required>
                </div>
            </div>
            
            <div class="mb-3">
                <div id="componentsList" class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Required Components</h5>
                    </div>
                    <div class="card-body">
                        <p>Select a final product to view required components</p>
                    </div>
                </div>
            </div>
            
            <div class="text-end">
                <button type="submit" class="btn btn-primary">Assemble Product</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Load components when final product is selected
    $('#final_product_id').change(function() {
        var product_id = $(this).val();
        var inventory_id = $('#inventory_id').val();
        
        if (product_id) {
            $.ajax({
                url: '../../ajax/get_product_components.php',
                type: 'GET',
                data: { product_id: product_id, inventory_id: inventory_id },
                dataType: 'json',
                success: function(response) {
                    var html = '<div class="table-responsive">' +
                               '<table class="table">' +
                               '<thead><tr><th>Component</th><th>Required Qty</th><th>Available</th><th>Status</th></tr></thead>' +
                               '<tbody>';
                    
                    if (response.components.length > 0) {
                        var allAvailable = true;
                        
                        $.each(response.components, function(index, component) {
                            var available = component.available !== null ? component.available : 0;
                            var required = component.required_quantity;
                            var status = '';
                            
                            if (inventory_id && component.available !== null) {
                                if (available >= required) {
                                    status = '<span class="badge bg-success">Available</span>';
                                } else {
                                    status = '<span class="badge bg-danger">Insufficient</span>';
                                    allAvailable = false;
                                }
                            } else if (inventory_id) {
                                status = '<span class="badge bg-warning">Not in Inventory</span>';
                                allAvailable = false;
                            } else {
                                status = '<span class="badge bg-secondary">Select Inventory</span>';
                            }
                            
                            html += '<tr>' +
                                    '<td>' + component.name + ' (' + component.sku + ')</td>' +
                                    '<td>' + required + '</td>' +
                                    '<td>' + (component.available !== null ? component.available : 'N/A') + '</td>' +
                                    '<td>' + status + '</td>' +
                                    '</tr>';
                        });
                        
                        // Enable/disable submit button based on availability
                        $('#assembleForm button[type="submit"]').prop('disabled', !allAvailable);
                        
                        if (!allAvailable && inventory_id) {
                            html += '<tr><td colspan="4" class="text-danger">Not all components are available in sufficient quantity</td></tr>';
                        }
                    } else {
                        html += '<tr><td colspan="4" class="text-center">No components found for this product</td></tr>';
                        $('#assembleForm button[type="submit"]').prop('disabled', true);
                    }
                    
                    html += '</tbody></table></div>';
                    $('#componentsList .card-body').html(html);
                }
            });
        } else {
            $('#componentsList .card-body').html('<p>Select a final product to view required components</p>');
            $('#assembleForm button[type="submit"]').prop('disabled', true);
        }
    });
    
    // Also check components when inventory changes
    $('#inventory_id').change(function() {
        $('#final_product_id').trigger('change');
    });
    
    // Update required quantities when quantity changes
    $('#quantity').change(function() {
        $('#final_product_id').trigger('change');
    });
});
</script>

