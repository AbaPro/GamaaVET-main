<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('categories.manage')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $parent_id = !empty($_POST['parent_id']) ? sanitize($_POST['parent_id']) : NULL;
    $description = sanitize($_POST['description']);
    
    // Check if category already exists
    $check_sql = "SELECT id FROM categories WHERE name = ? AND (parent_id IS NULL OR parent_id = ?)";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $name, $parent_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        setAlert('danger', 'Category with this name already exists in this parent category.');
        $check_stmt->close();
        redirect('index.php');
    }
    $check_stmt->close();
    
    // Insert new category
    $insert_sql = "INSERT INTO categories (name, parent_id, description) VALUES (?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("sis", $name, $parent_id, $description);
    
    if ($insert_stmt->execute()) {
        $category_id = $insert_stmt->insert_id;
        setAlert('success', 'Category added successfully.');
        logActivity("Added new category: $name (ID: $category_id)");
    } else {
        setAlert('danger', 'Error adding category: ' . $conn->error);
    }
    $insert_stmt->close();
}

redirect('index.php');
?>
