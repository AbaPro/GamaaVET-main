<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('purchases.orders.delete')) {
    setAlert('danger', "You don't have permission to delete Purchase Orders.");
    header("Location: po_list.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
    $order_ids = array_map('intval', $_POST['order_ids']);
    $deleted_count = 0;
    $errors = [];

    foreach ($order_ids as $id) {
        try {
            $pdo->beginTransaction();

            // 1. Check for payments
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_payments WHERE purchase_order_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("PO #$id has associated payments and cannot be deleted.");
            }

            // 2. Check for received items (Inventory Integrity)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ? AND received_quantity > 0");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("PO #$id has items already received in inventory and cannot be deleted.");
            }

            // 3. Delete items
            $stmt = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
            $stmt->execute([$id]);

            // 4. Delete PO
            $stmt = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            $deleted_count++;
            logActivity("Deleted Purchase Order #$id and its items.");

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }

    if ($deleted_count > 0) {
        setAlert('success', "$deleted_count Purchase Order(s) deleted successfully.");
    }
    if (!empty($errors)) {
        setAlert('danger', "Some orders could not be deleted: " . implode('<br>', $errors));
    }
} else {
    setAlert('warning', "No orders selected for deletion.");
}

header("Location: po_list.php");
exit();
