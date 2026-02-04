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

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';

if ($name === '') {
    setAlert('danger', 'Type name is required.');
    redirect('types.php');
}

$name = sanitize($name);
$description = sanitize($description);

$stmt = $conn->prepare("INSERT INTO customer_types (name, description) VALUES (?, ?)");
$stmt->bind_param('ss', $name, $description);

if ($stmt->execute()) {
    setAlert('success', 'Customer type added successfully.');
    logActivity("Created customer type: $name");
} else {
    setAlert('danger', 'Error adding customer type: ' . $conn->error);
}

$stmt->close();
redirect('types.php');
