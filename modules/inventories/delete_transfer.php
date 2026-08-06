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

        // Stock only moves when the receiver confirms, so what needs undoing
        // depends on the state: pending/rejected never moved anything.
        $stockAlreadyMoved = in_array($transfer['status'], ['transferred', 'verified'], true);

        if ($stockAlreadyMoved && !isAdminUser()) {
            throw new Exception("Only an administrator can delete a transfer whose stock has already moved.");
        }

        // 2. Fetch items and, when stock had moved, reverse it (destination -> source)
        $stmt = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id = ?");
        $stmt->execute([$transfer_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $from_inventory_id = (int)$transfer['from_inventory_id'];
        $to_inventory_id = (int)$transfer['to_inventory_id'];
        $stockLogs = [];

        if ($stockAlreadyMoved) {
            foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                // Take it back out of the destination first, refusing to go negative.
                $destBeforeStmt = $pdo->prepare("SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? LIMIT 1");
                $destBeforeStmt->execute([$to_inventory_id, $product_id]);
                $destBefore = (float)($destBeforeStmt->fetchColumn() ?: 0);

                $deduct = $pdo->prepare("
                    UPDATE inventory_products
                    SET quantity = quantity - ?
                    WHERE inventory_id = ? AND product_id = ? AND quantity >= ?
                ");
                $deduct->execute([$quantity, $to_inventory_id, $product_id, $quantity]);
                if ($deduct->rowCount() === 0) {
                    throw new Exception("Cannot delete: the destination inventory no longer holds enough stock to reverse this transfer.");
                }

                $srcBeforeStmt = $pdo->prepare("SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? LIMIT 1");
                $srcBeforeStmt->execute([$from_inventory_id, $product_id]);
                $srcBefore = (float)($srcBeforeStmt->fetchColumn() ?: 0);

                // Upsert, not a bare UPDATE: the source row may have been removed
                // since the transfer, which would otherwise silently lose stock.
                $upd = $pdo->prepare("
                    INSERT INTO inventory_products (inventory_id, product_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                ");
                $upd->execute([$from_inventory_id, $product_id, $quantity]);

                $stockLogs[] = [
                    'inventory_id' => $to_inventory_id,
                    'product_id' => $product_id,
                    'change' => -$quantity,
                    'before' => $destBefore,
                    'after' => $destBefore - $quantity,
                    'note' => 'Deleted transfer: removed from destination',
                ];
                $stockLogs[] = [
                    'inventory_id' => $from_inventory_id,
                    'product_id' => $product_id,
                    'change' => $quantity,
                    'before' => $srcBefore,
                    'after' => $srcBefore + $quantity,
                    'note' => 'Deleted transfer: returned to source',
                ];
            }
        }

        // 3. Delete transfer images (fetch paths first for post-commit file cleanup)
        $imgStmt = $pdo->prepare("SELECT file_path FROM inventory_transfer_images WHERE inventory_transfer_id = ?");
        $imgStmt->execute([$transfer_id]);
        $filesToDelete = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM inventory_transfer_images WHERE inventory_transfer_id = ?")->execute([$transfer_id]);

        // 4. Delete items, history and the transfer
        $stmt = $pdo->prepare("DELETE FROM transfer_items WHERE transfer_id = ?");
        $stmt->execute([$transfer_id]);

        $pdo->prepare("DELETE FROM inventory_transfer_history WHERE inventory_transfer_id = ?")->execute([$transfer_id]);

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
                $stockLog['inventory_id'],
                $stockLog['product_id'],
                $stockLog['change'],
                $stockLog['before'],
                $stockLog['after'],
                'inventory_transfer_delete',
                $transfer_id,
                null,
                null,
                $stockLog['note']
            );
        }

        setAlert('success', $stockAlreadyMoved
            ? "Transfer deleted and the stock movement was reversed."
            : "Transfer deleted. No stock had been moved.");
        logActivity("Deleted inventory transfer #$transfer_id (Ref: {$transfer['transfer_reference']})"
            . ($stockAlreadyMoved ? ' and reversed stock.' : '.'));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setAlert('danger', "Error deleting transfer: " . $e->getMessage());
    }
}

redirect('transfers_list.php');
exit();
