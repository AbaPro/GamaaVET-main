<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.products.add')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = sanitize($_POST['inventory_id']);
    $product_id = sanitize($_POST['product_id']);
    $quantity = sanitize($_POST['quantity']);
    
    // Check if product already exists in inventory
    $check_sql = "SELECT id FROM inventory_products WHERE inventory_id = ? AND product_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $inventory_id, $product_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        setAlert('danger', 'This product already exists in the inventory. Please update quantity instead.');
        $check_stmt->close();
        redirect("view.php?id=$inventory_id");
    }
    $check_stmt->close();
    
    // Insert new product to inventory
    $insert_sql = "INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("iid", $inventory_id, $product_id, $quantity);
    
    if ($insert_stmt->execute()) {
        setAlert('success', 'Product added to inventory successfully.');
        logActivity("Added product ID: $product_id to inventory ID: $inventory_id with quantity: $quantity");
    } else {
        setAlert('danger', 'Error adding product to inventory: ' . $conn->error);
    }
    $insert_stmt->close();
}

redirect("view.php?id=$inventory_id");
?>
