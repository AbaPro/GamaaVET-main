<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/request.php';

inventoryValidateRequest();

if (!hasPermission('inventories.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);

    if (!canAccessInventory($inventory_id)) {
        setAlert('danger', 'Inventory not found in the currently selected region.');
        redirect('index.php');
    }

    $product_id = sanitize($_POST['product_id']);
    $quantity = $_POST['quantity'] ?? '';
    if (!is_scalar($quantity) || !is_numeric($quantity) || !is_finite((float)$quantity) || (float)$quantity < 0 || (float)$quantity > 9999999999.99) {
        setAlert('danger', 'Enter a valid non-negative stock quantity.');
        redirect("view.php?id=$inventory_id");
    }
    $quantity = (float)$quantity;
    if (!canAddProductToInventory($inventory_id, $product_id)) {
        setAlert('danger', 'This product is not available in the selected channel.');
        redirect("view.php?id=$inventory_id");
    }
    $quantity_before = getInventoryProductQuantity($inventory_id, $product_id);
    
    // Update product quantity in inventory
    $update_sql = "UPDATE inventory_products SET quantity = ? WHERE inventory_id = ? AND product_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("dii", $quantity, $inventory_id, $product_id);
    
    if ($update_stmt->execute()) {
        logInventoryStockChange($inventory_id, $product_id, ((float)$quantity - $quantity_before), $quantity_before, $quantity, 'inventory_update', null, null, null, 'Manual quantity update');
        setAlert('success', 'Product quantity updated successfully.');
        logActivity("Updated product ID: $product_id quantity to $quantity in inventory ID: $inventory_id");
    } else {
        setAlert('danger', 'Error updating product quantity: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect("view.php?id=$inventory_id");
?>
