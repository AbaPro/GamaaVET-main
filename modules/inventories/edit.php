<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = sanitize($_POST['id']);
    $name = sanitize($_POST['name']);
    $location = sanitize($_POST['location']);
    $description = sanitize($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Check if inventory already exists (excluding current one)
    $check_sql = "SELECT id FROM inventories WHERE name = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $name, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        setAlert('danger', 'Inventory with this name already exists.');
        $check_stmt->close();
        redirect('index.php');
    }
    $check_stmt->close();
    
    // Update inventory
    $update_sql = "UPDATE inventories SET name = ?, location = ?, description = ?, is_active = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sssii", $name, $location, $description, $is_active, $id);
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Inventory updated successfully.');
        logActivity("Updated inventory ID: $id");
    } else {
        setAlert('danger', 'Error updating inventory: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect('index.php');
?>
