<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('purchases.receive')) {
    setAlert('danger', "You don't have permission to undo receipts.");
    header("Location: po_list.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_id'])) {
    $po_id = (int)$_POST['po_id'];
    
    try {
        $pdo->beginTransaction();

        // 1. Fetch PO details
        $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) {
            throw new Exception("Purchase Order not found.");
        }

        if ($po['status'] === 'new' || $po['status'] === 'cancelled') {
            throw new Exception("Cannot undo receipt for a PO in status: " . $po['status']);
        }

        // 2. Identify Inventory ID
        $inventory_id = 1; // Default
        if (!empty($po['warehouse_location'])) {
            $stmt = $pdo->prepare("SELECT id FROM inventories WHERE name = ? LIMIT 1");
            $stmt->execute([$po['warehouse_location']]);
            $found_id = $stmt->fetchColumn();
            if ($found_id) {
                $inventory_id = $found_id;
            }
        }

        // 3. Fetch PO items with received quantity
        $stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE purchase_order_id = ? AND received_quantity > 0");
        $stmt->execute([$po_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $received_qty = (float)$item['received_quantity'];
            $product_id = (int)$item['product_id'];

            // Subtract from inventory
            $stmt = $pdo->prepare("UPDATE inventory_products SET quantity = quantity - ? WHERE inventory_id = ? AND product_id = ?");
            $stmt->execute([$received_qty, $inventory_id, $product_id]);

            // Reset item received quantity and total_price
            $stmt = $pdo->prepare("UPDATE purchase_order_items SET received_quantity = 0, total_price = 0 WHERE id = ?");
            $stmt->execute([$item['id']]);
        }

        // 4. Update PO status and total_amount
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'ordered', total_amount = 0 WHERE id = ?");
        $stmt->execute([$po_id]);

        $pdo->commit();
        
        logActivity("Undid receipt for Purchase Order #$po_id. Inventory stock reversed and PO status reset to 'ordered'.");
        setAlert('success', "Receipt reversed successfully. Inventory stock has been updated, and the PO is now back to 'Ordered' status.");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setAlert('danger', "System error undoing receipt: " . $e->getMessage());
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?: 'po_list.php';
header("Location: $redirect");
exit();
