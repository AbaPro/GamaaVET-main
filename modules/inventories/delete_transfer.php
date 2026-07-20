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

    if (!canAccessInventoryTransfer($transfer_id)) {
        setAlert('danger', 'Transfer not found in the currently selected region.');
        redirect('transfers_list.php');
    }

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
        $stockLogs = [];

        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $quantity = (float)$item['quantity'];
            $beforeStmt = $pdo->prepare("SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? LIMIT 1");
            $beforeStmt->execute([$from_inventory_id, $product_id]);
            $quantityBefore = (float)($beforeStmt->fetchColumn() ?: 0);

            // Update source inventory
            $upd = $pdo->prepare("UPDATE inventory_products SET quantity = quantity + ? WHERE inventory_id = ? AND product_id = ?");
            $upd->execute([$quantity, $from_inventory_id, $product_id]);
            $stockLogs[] = [
                'product_id' => (int)$product_id,
                'quantity' => $quantity,
                'quantity_before' => $quantityBefore,
            ];
            
            // If row didn't exist (unlikely if they transferred it, but safe), we won't create it here 
            // since we assume it came from there.
        }

        // 3. Delete transfer images (fetch paths first for post-commit file cleanup)
        $imgStmt = $pdo->prepare("SELECT file_path FROM inventory_transfer_images WHERE inventory_transfer_id = ?");
        $imgStmt->execute([$transfer_id]);
        $filesToDelete = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM inventory_transfer_images WHERE inventory_transfer_id = ?")->execute([$transfer_id]);

        // 4. Delete items and the transfer
        $stmt = $pdo->prepare("DELETE FROM transfer_items WHERE transfer_id = ?");
        $stmt->execute([$transfer_id]);

        $stmt = $pdo->prepare("DELETE FROM inventory_transfers WHERE id = ?");
        $stmt->execute([$transfer_id]);

        $pdo->commit();

        foreach ($filesToDelete as $path) {
            $full = ROOT_PATH . '/' . $path;
            if (is_file($full)) {
                unlink($full);
            }
        }

        foreach ($stockLogs as $stockLog) {
            logInventoryStockChange(
                $from_inventory_id,
                $stockLog['product_id'],
                $stockLog['quantity'],
                $stockLog['quantity_before'],
                $stockLog['quantity_before'] + $stockLog['quantity'],
                'inventory_transfer_delete',
                $transfer_id,
                null,
                null,
                'Deleted pending transfer and returned stock'
            );
        }
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
