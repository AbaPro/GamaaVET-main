<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.create')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $location = sanitize($_POST['location']);
    $description = sanitize($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Check if inventory already exists
    $check_sql = "SELECT id FROM inventories WHERE name = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        setAlert('danger', 'Inventory with this name already exists.');
        $check_stmt->close();
        redirect('index.php');
    }
    $check_stmt->close();
    
    // Insert new inventory
    $insert_sql = "INSERT INTO inventories (name, location, description, is_active) VALUES (?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("sssi", $name, $location, $description, $is_active);
    
    if ($insert_stmt->execute()) {
        $inventory_id = $insert_stmt->insert_id;
        setAlert('success', 'Inventory added successfully.');
        logActivity("Added new inventory: $name (ID: $inventory_id)");
    } else {
        setAlert('danger', 'Error adding inventory: ' . $conn->error);
    }
    $insert_stmt->close();
}

redirect('index.php');
?>
