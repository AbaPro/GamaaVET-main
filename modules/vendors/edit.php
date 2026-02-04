<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('vendors.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = sanitize($_POST['id']);
    $name = sanitize($_POST['name']);
    $type = sanitize($_POST['type']);
    $email = !empty($_POST['email']) ? sanitize($_POST['email']) : NULL;
    $phone = sanitize($_POST['phone']);
    $tax_number = !empty($_POST['tax_number']) ? sanitize($_POST['tax_number']) : NULL;
    
    $update_sql = "UPDATE vendors SET 
                   name = ?, type = ?, email = ?, phone = ?, tax_number = ? 
                   WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sisssi", $name, $type, $email, $phone, $tax_number, $id);
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Vendor updated successfully.');
        logActivity("Updated vendor ID: $id");
    } else {
        setAlert('danger', 'Error updating vendor: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect('index.php');
?>
