<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('locations.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = sanitize($_POST['id']);
    $name = sanitize($_POST['name']);
    $address = sanitize($_POST['address']);
    $description = sanitize($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Check if location already exists (excluding current one)
    $check_sql = "SELECT id FROM locations WHERE name = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $name, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        setAlert('danger', 'Location with this name already exists.');
        $check_stmt->close();
        redirect('index.php');
    }
    $check_stmt->close();
    
    // Update location
    $update_sql = "UPDATE locations SET name = ?, address = ?, description = ?, is_active = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sssii", $name, $address, $description, $is_active, $id);
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Location updated successfully.');
        logActivity("Updated location ID: $id");
    } else {
        setAlert('danger', 'Error updating location: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect('index.php');
?>
