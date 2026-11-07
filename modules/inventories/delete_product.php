<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/request.php';

inventoryValidateRequest();

if (!hasPermission('inventories.delete')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_POST['inventory_id']) || !is_numeric($_POST['inventory_id']) ||
    !isset($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
    setAlert('danger', 'Invalid request parameters.');
    redirect('index.php');
}

$inventory_id = (int)$_POST['inventory_id'];
$product_id = sanitize($_POST['product_id']);

if (!canAccessInventory($inventory_id) || !canAccessProduct($product_id)) {
    setAlert('danger', 'Inventory not found in the currently selected region.');
    redirect('index.php');
}

$quantity_before = getInventoryProductQuantity($inventory_id, $product_id);

// Remove product from inventory
$delete_sql = "DELETE FROM inventory_products WHERE inventory_id = ? AND product_id = ?";
$delete_stmt = $conn->prepare($delete_sql);
$delete_stmt->bind_param("ii", $inventory_id, $product_id);

if ($delete_stmt->execute()) {
    logInventoryStockChange($inventory_id, $product_id, -$quantity_before, $quantity_before, 0, 'inventory_delete', null, null, null, 'Removed product from inventory');
    setAlert('success', 'Product removed from inventory successfully.');
    logActivity("Removed product ID: $product_id from inventory ID: $inventory_id");
} else {
    setAlert('danger', 'Error removing product from inventory: ' . $conn->error);
}
$delete_stmt->close();

redirect("view.php?id=$inventory_id");
?>
