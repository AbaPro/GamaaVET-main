<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('products.delete')) {
    setAlert('danger', "You don't have permission to delete products");
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['product_ids'])) {
    $product_ids = $_POST['product_ids'];
    $success_count = 0;
    $skipped_count = 0;
    $errors = [];

    foreach ($product_ids as $id) {
        $id = (int)$id;

        // Check if product exists in any inventory
        $check_sql = "SELECT COUNT(*) as count FROM inventory_products WHERE product_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $in_inventory = $check_stmt->get_result()->fetch_assoc()['count'] > 0;
        $check_stmt->close();

        if ($in_inventory) {
            $skipped_count++;
            continue;
        }

        // Check if product is used as a component
        $component_sql = "SELECT COUNT(*) as count FROM product_components WHERE component_id = ?";
        $component_stmt = $conn->prepare($component_sql);
        $component_stmt->bind_param("i", $id);
        $component_stmt->execute();
        $is_component = $component_stmt->get_result()->fetch_assoc()['count'] > 0;
        $component_stmt->close();

        if ($is_component) {
            $skipped_count++;
            continue;
        }

        // Delete product
        $delete_sql = "DELETE FROM products WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        if ($delete_stmt->execute()) {
            $success_count++;
        }
        $delete_stmt->close();
    }

    if ($success_count > 0) {
        setAlert('success', "Successfully deleted $success_count products.");
        logActivity("Bulk deleted products IDs: " . implode(', ', $product_ids));
    }
    if ($skipped_count > 0) {
        setAlert('warning', "Skipped $skipped_count products because they are in inventory or used as components.");
    }
}

header("Location: index.php");
exit();
