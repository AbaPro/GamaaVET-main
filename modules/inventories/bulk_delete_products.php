<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('inventories.view')) {
    setAlert('danger', "You don't have permission to manage inventories");
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['product_ids']) && !empty($_POST['inventory_id'])) {
    $product_ids = $_POST['product_ids'];
    $inventory_id = (int)$_POST['inventory_id'];
    $success_count = 0;

    try {
        $conn->begin_transaction();
        
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $stmt = $conn->prepare("DELETE FROM inventory_products WHERE inventory_id = ? AND product_id IN ($placeholders)");
        
        if (!$stmt) {
            throw new Exception("Error preparing statement: " . $conn->error);
        }

        $types = 'i' . str_repeat('i', count($product_ids));
        $params = array_merge([$inventory_id], $product_ids);
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception("Error executing deletion: " . $stmt->error);
        }

        $success_count = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();
        setAlert('success', "Successfully removed $success_count products from inventory.");
        logActivity("Bulk removed $success_count products from inventory ID: $inventory_id");
    } catch (Throwable $e) {
        if ($conn->connect_errno === 0) {
            $conn->rollback();
        }
        setAlert('danger', "System error removing products: " . $e->getMessage());
    }
    
    header("Location: view.php?id=$inventory_id");
} else {
    header("Location: index.php");
}
exit();
