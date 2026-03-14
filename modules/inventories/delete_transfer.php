<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.transfer')) {
    setAlert('danger', 'You do not have permission to delete transfers.');
    redirect('transfers_list.php');
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $transfer_id = (int)$_GET['id'];

    try {
        $pdo->beginTransaction();

        // 1. Fetch Transfer info
        $stmt = $pdo->prepare("SELECT * FROM inventory_transfers WHERE id = ?");
        $stmt->execute([$transfer_id]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfer) {
            throw new Exception("Transfer not found.");
        }

        if ($transfer['status'] !== 'pending') {
            throw new Exception("Only pending transfers can be deleted.");
        }

        // 2. Fetch items and return stock to source
        $stmt = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id = ?");
        $stmt->execute([$transfer_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $from_inventory_id = $transfer['from_inventory_id'];

        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $quantity = (float)$item['quantity'];

            // Update source inventory
            $upd = $pdo->prepare("UPDATE inventory_products SET quantity = quantity + ? WHERE inventory_id = ? AND product_id = ?");
            $upd->execute([$quantity, $from_inventory_id, $product_id]);
            
            // If row didn't exist (unlikely if they transferred it, but safe), we won't create it here 
            // since we assume it came from there.
        }

        // 3. Delete items and the transfer
        $stmt = $pdo->prepare("DELETE FROM transfer_items WHERE transfer_id = ?");
        $stmt->execute([$transfer_id]);

        $stmt = $pdo->prepare("DELETE FROM inventory_transfers WHERE id = ?");
        $stmt->execute([$transfer_id]);

        $pdo->commit();
        setAlert('success', "Transfer deleted and stock returned to source inventory.");
        logActivity("Deleted inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']}) and reversed stock.");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setAlert('danger', "Error deleting transfer: " . $e->getMessage());
    }
}

redirect('transfers_list.php');
exit();
