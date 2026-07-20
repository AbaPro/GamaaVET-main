<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.transfer')) {
    setAlert('danger', 'You do not have permission to accept transfers.');
    redirect('transfers_list.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid transfer.');
    redirect('transfers_list.php');
}

$transfer_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'] ?? null;
$accepted_at = date('Y-m-d H:i:s');

if (!canAccessInventoryTransfer($transfer_id)) {
    setAlert('danger', 'Transfer not found in the currently selected region.');
    redirect('transfers_list.php');
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("SELECT * FROM inventory_transfers WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $transfer_id);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transfer) {
        throw new Exception('Transfer not found.');
    }

    if ($transfer['status'] !== 'pending') {
        throw new Exception('Only pending transfers can be accepted.');
    }

    $items_stmt = $conn->prepare("SELECT product_id, quantity FROM transfer_items WHERE transfer_id = ?");
    $items_stmt->bind_param("i", $transfer_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    $stock_logs = [];
    while ($item = $items_result->fetch_assoc()) {
        $product_id = (int)$item['product_id'];
        $quantity = (float)$item['quantity'];

        if ($quantity <= 0) {
            continue;
        }

        $destination_before = getInventoryProductQuantity($transfer['to_inventory_id'], $product_id);
        $add_stmt = $conn->prepare("
            INSERT INTO inventory_products (inventory_id, product_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        $add_stmt->bind_param("iid", $transfer['to_inventory_id'], $product_id, $quantity);
        $add_stmt->execute();
        $add_stmt->close();

        $stock_logs[] = [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'quantity_before' => $destination_before,
        ];
    }
    $items_stmt->close();

    $update_stmt = $conn->prepare("
        UPDATE inventory_transfers
        SET status = 'accepted',
            accepted_by = ?,
            transferred_by = COALESCE(transferred_by, ?),
            transferred_at = COALESCE(transferred_at, ?)
        WHERE id = ?
    ");
    $update_stmt->bind_param("iisi", $user_id, $user_id, $accepted_at, $transfer_id);
    $update_stmt->execute();
    $update_stmt->close();

    $conn->commit();

    foreach ($stock_logs as $stock_log) {
        logInventoryStockChange(
            $transfer['to_inventory_id'],
            $stock_log['product_id'],
            $stock_log['quantity'],
            $stock_log['quantity_before'],
            $stock_log['quantity_before'] + $stock_log['quantity'],
            'inventory_transfer_accept',
            $transfer_id,
            null,
            null,
            'Accepted transfer in from inventory ID: ' . $transfer['from_inventory_id']
        );
    }

    setAlert('success', 'Transfer accepted and stock added to destination inventory.');
    logActivity("Accepted inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']})");
} catch (Exception $e) {
    $conn->rollback();
    setAlert('danger', 'Error accepting transfer: ' . $e->getMessage());
}

redirect('transfers_list.php');
exit;
