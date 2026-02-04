<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.delete')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['inventory_id']) || !is_numeric($_GET['inventory_id']) || 
    !isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
    setAlert('danger', 'Invalid request parameters.');
    redirect('index.php');
}

$inventory_id = sanitize($_GET['inventory_id']);
$product_id = sanitize($_GET['product_id']);

// Remove product from inventory
$delete_sql = "DELETE FROM inventory_products WHERE inventory_id = ? AND product_id = ?";
$delete_stmt = $conn->prepare($delete_sql);
$delete_stmt->bind_param("ii", $inventory_id, $product_id);

if ($delete_stmt->execute()) {
    setAlert('success', 'Product removed from inventory successfully.');
    logActivity("Removed product ID: $product_id from inventory ID: $inventory_id");
} else {
    setAlert('danger', 'Error removing product from inventory: ' . $conn->error);
}
$delete_stmt->close();

redirect("view.php?id=$inventory_id");
?>
