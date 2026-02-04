<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.manage')) {
    setAlert('danger', 'You do not have permission to perform this action.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('types.php');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';

if ($id <= 0 || $name === '') {
    setAlert('danger', 'Type name is required.');
    redirect('types.php');
}

$name = sanitize($name);
$description = sanitize($description);

$stmt = $conn->prepare("UPDATE customer_types SET name = ?, description = ? WHERE id = ?");
$stmt->bind_param('ssi', $name, $description, $id);

if ($stmt->execute()) {
    setAlert('success', 'Customer type updated successfully.');
    logActivity("Updated customer type ID: $id");
} else {
    setAlert('danger', 'Error updating customer type: ' . $conn->error);
}

$stmt->close();
redirect('types.php');
