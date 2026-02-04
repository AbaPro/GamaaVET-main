<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = sanitize($_POST['inventory_id']);
    $product_id = sanitize($_POST['product_id']);
    $quantity = sanitize($_POST['quantity']);
    
    // Update product quantity in inventory
    $update_sql = "UPDATE inventory_products SET quantity = ? WHERE inventory_id = ? AND product_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("dii", $quantity, $inventory_id, $product_id);
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Product quantity updated successfully.');
        logActivity("Updated product ID: $product_id quantity to $quantity in inventory ID: $inventory_id");
    } else {
        setAlert('danger', 'Error updating product quantity: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect("view.php?id=$inventory_id");
?>
