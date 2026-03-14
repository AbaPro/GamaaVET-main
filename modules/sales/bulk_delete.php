<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('sales.orders.delete')) {
    setAlert('danger', "You don't have permission to delete orders");
    header("Location: order_list.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['order_ids'])) {
    $order_ids = $_POST['order_ids'];
    $success_count = 0;
    $error_count = 0;
    $errors = [];

    foreach ($order_ids as $id) {
        $id = (int)$id;
        
        // Start transaction for each order delete if necessary, or do it in bulk
        // For simplicity and better error reporting, we can do it individually or in a single transaction
    }

    // Single transaction approach is better
    $conn->begin_transaction();
    try {
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        
        // Check if any order is not in 'new' status (might want to restrict deletion)
        // For now, let's assume we can delete if they have permission, but usually 'new' is safer
        
        // Delete order items first
        $stmt = $conn->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
        $stmt->execute();
        $stmt->close();

        // Delete orders
        $stmt = $conn->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
        $stmt->execute();
        $success_count = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();
        setAlert('success', "Successfully deleted $success_count orders.");
        logActivity("Bulk deleted orders: " . implode(', ', $order_ids));
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', "Error deleting orders: " . $e->getMessage());
    }
}

header("Location: order_list.php");
exit();
