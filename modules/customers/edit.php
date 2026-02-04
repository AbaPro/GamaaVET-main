<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = sanitize($_POST['id']);
    $name = sanitize($_POST['name']);
    $type = sanitize($_POST['type']);
    $factory_id = !empty($_POST['factory_id']) ? intval($_POST['factory_id']) : NULL;
    $email = !empty($_POST['email']) ? sanitize($_POST['email']) : NULL;
    $phone = sanitize($_POST['phone']);
    $whatsapp_phone = !empty($_POST['whatsapp_phone']) ? sanitize($_POST['whatsapp_phone']) : NULL;
    $tax_number = !empty($_POST['tax_number']) ? sanitize($_POST['tax_number']) : NULL;
    
    $update_sql = "UPDATE customers SET 
                   name = ?, type = ?, factory_id = ?, email = ?, phone = ?, whatsapp_phone = ?, tax_number = ? 
                   WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "siissssi",
        $name,
        $type,
        $factory_id,
        $email,
        $phone,
        $whatsapp_phone,
        $tax_number,
        $id
    );
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Customer updated successfully.');
        logActivity("Updated customer ID: $id");
    } else {
        setAlert('danger', 'Error updating customer: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect('index.php');
?>
