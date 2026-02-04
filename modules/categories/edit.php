<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('categories.manage')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = sanitize($_POST['id']);
    $name = sanitize($_POST['name']);
    $parent_id = !empty($_POST['parent_id']) ? sanitize($_POST['parent_id']) : NULL;
    $description = sanitize($_POST['description']);
    
    // Check if category already exists (excluding current one)
    $check_sql = "SELECT id FROM categories WHERE name = ? AND (parent_id IS NULL OR parent_id = ?) AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("sii", $name, $parent_id, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        setAlert('danger', 'Category with this name already exists in this parent category.');
        $check_stmt->close();
        redirect('index.php');
    }
    $check_stmt->close();
    
    // Update category
    $update_sql = "UPDATE categories SET name = ?, parent_id = ?, description = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sisi", $name, $parent_id, $description, $id);
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Category updated successfully.');
        logActivity("Updated category ID: $id");
    } else {
        setAlert('danger', 'Error updating category: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect('index.php');
?>
