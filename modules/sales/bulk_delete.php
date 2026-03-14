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
    $skipped_count = 0;
    $errors = [];
    
    foreach ($order_ids as $id) {
        $id = (int)$id;
        
        try {
            // Check for payments
            $pay_sql = "SELECT COUNT(*) as count FROM order_payments WHERE order_id = ?";
            $pay_stmt = $conn->prepare($pay_sql);
            $pay_stmt->bind_param("i", $id);
            $pay_stmt->execute();
            $has_payments = $pay_stmt->get_result()->fetch_assoc()['count'] > 0;
            $pay_stmt->close();

            if ($has_payments) {
                $errors[] = "Order ID $id has associated payments.";
                $skipped_count++;
                continue;
            }

            // Check for returns
            $ret_sql = "SELECT COUNT(*) as count FROM order_returns WHERE order_id = ?";
            $ret_stmt = $conn->prepare($ret_sql);
            $ret_stmt->bind_param("i", $id);
            $ret_stmt->execute();
            $has_returns = $ret_stmt->get_result()->fetch_assoc()['count'] > 0;
            $ret_stmt->close();

            if ($has_returns) {
                $errors[] = "Order ID $id has associated returns.";
                $skipped_count++;
                continue;
            }

            // Check for manufacturing orders
            $man_sql = "SELECT COUNT(*) as count FROM manufacturing_orders WHERE sales_order_id = ?";
            $man_stmt = $conn->prepare($man_sql);
            $man_stmt->bind_param("i", $id);
            $man_stmt->execute();
            $has_man = $man_stmt->get_result()->fetch_assoc()['count'] > 0;
            $man_stmt->close();

            if ($has_man) {
                $errors[] = "Order ID $id is linked to manufacturing orders.";
                $skipped_count++;
                continue;
            }

            // Check for quotations
            $quot_sql = "SELECT COUNT(*) as count FROM quotations WHERE order_id = ?";
            $quot_stmt = $conn->prepare($quot_sql);
            $quot_stmt->bind_param("i", $id);
            $quot_stmt->execute();
            $has_quot = $quot_stmt->get_result()->fetch_assoc()['count'] > 0;
            $quot_stmt->close();

            if ($has_quot) {
                $errors[] = "Order ID $id is linked to a quotation.";
                $skipped_count++;
                continue;
            }

            $conn->begin_transaction();

            // Delete order items
            $stmt_items = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
            if (!$stmt_items) throw new Exception("Error preparing items deletion: " . $conn->error);
            $stmt_items->bind_param("i", $id);
            $stmt_items->execute();
            $stmt_items->close();

            // Delete order
            $stmt_order = $conn->prepare("DELETE FROM orders WHERE id = ?");
            if (!$stmt_order) throw new Exception("Error preparing order deletion: " . $conn->error);
            $stmt_order->bind_param("i", $id);
            if ($stmt_order->execute()) {
                $success_count++;
                $conn->commit();
            } else {
                throw new Exception("Error deleting order ID $id: " . $stmt_order->error);
            }
            $stmt_order->close();

        } catch (Throwable $e) {
            if ($conn->connect_errno === 0 && $conn->in_transaction) {
                $conn->rollback();
            }
            $errors[] = "System error for Order ID $id: " . $e->getMessage();
            $skipped_count++;
        }
    }

    if ($success_count > 0) {
        setAlert('success', "Successfully deleted $success_count orders.");
        logActivity("Bulk deleted orders IDs: " . implode(', ', $order_ids));
    }
    
    if ($skipped_count > 0) {
        $error_msg = "Skipped $skipped_count orders due to constraints: <br>• " . implode("<br>• ", array_unique($errors));
        setAlert('warning', $error_msg);
    }
}

header("Location: order_list.php");
exit();
