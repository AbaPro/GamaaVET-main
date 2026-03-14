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
    
    try {
        $conn->begin_transaction();
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $types = str_repeat('i', count($order_ids));
        
        // Delete order items first
        $stmt_items = $conn->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
        if (!$stmt_items) {
            throw new Exception("Error preparing items deletion: " . $conn->error);
        }
        $stmt_items->bind_param($types, ...$order_ids);
        if (!$stmt_items->execute()) {
            throw new Exception("Error deleting order items: " . $stmt_items->error);
        }
        $stmt_items->close();

        // Delete orders
        $stmt_orders = $conn->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
        if (!$stmt_orders) {
            throw new Exception("Error preparing orders deletion: " . $conn->error);
        }
        $stmt_orders->bind_param($types, ...$order_ids);
        if (!$stmt_orders->execute()) {
            throw new Exception("Error deleting orders: " . $stmt_orders->error);
        }
        $success_count = $stmt_orders->affected_rows;
        $stmt_orders->close();

        $conn->commit();
        setAlert('success', "Successfully deleted $success_count orders.");
        logActivity("Bulk deleted orders: " . implode(', ', $order_ids));
    } catch (Throwable $e) {
        if ($conn->connect_errno === 0) {
            $conn->rollback();
        }
        setAlert('danger', "System error during bulk deletion: " . $e->getMessage());
    }
}

header("Location: order_list.php");
exit();
