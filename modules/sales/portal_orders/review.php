<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';

if (!hasPermission('sales.portal_orders.manage')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../../dashboard.php");
    exit();
}

$portalOrderId = (int)($_GET['id'] ?? 0);

function fetchPortalOrder(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT po.*, c.name AS customer_name, c.factory_id AS customer_factory_id
        FROM portal_orders po
        JOIN customers c ON c.id = po.customer_id
        LEFT JOIN factories f ON f.id = c.factory_id
        WHERE po.id = ? AND " . getCustomerChannelScopeSql('c', 'f') . "
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$portalOrder = fetchPortalOrder($pdo, $portalOrderId);
if (!$portalOrder) {
    $_SESSION['error'] = 'Portal order request not found.';
    header('Location: list.php');
    exit();
}

if (empty($_SESSION['portal_order_delete_token'])) {
    $_SESSION['portal_order_delete_token'] = bin2hex(random_bytes(32));
}

// Delete the request outright. Converted requests can never be deleted (a real
// order already exists). Approved requests require the force_delete permission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $isForceDelete = $portalOrder['status'] === 'approved';
    $requiredPermission = $isForceDelete ? 'sales.portal_orders.force_delete' : 'sales.portal_orders.delete';

    if (!hasPermission($requiredPermission)) {
        $_SESSION['error'] = "You don't have permission to delete this portal order request.";
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['portal_order_delete_token'], $token)) {
        $_SESSION['error'] = 'Invalid request. Refresh the page and try again.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    if (!in_array($portalOrder['status'], ['pending_review', 'priced', 'rejected', 'approved'], true)) {
        $_SESSION['error'] = 'This request can no longer be deleted.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $deleteItems = $pdo->prepare("DELETE FROM portal_order_items WHERE portal_order_id = ?");
        $deleteItems->execute([$portalOrderId]);

        $deleteOrder = $pdo->prepare("DELETE FROM portal_orders WHERE id = ?");
        $deleteOrder->execute([$portalOrderId]);

        $pdo->commit();

        logActivity(
            $isForceDelete ? 'Force-deleted portal order request' : 'Deleted portal order request',
            ['portal_order_id' => $portalOrderId, 'status' => $portalOrder['status']],
            'delete',
            'portal_order',
            $portalOrderId
        );
        $_SESSION['success'] = 'Portal order request deleted.';
        header('Location: list.php');
        exit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Failed to delete request: ' . $e->getMessage();
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }
}

// Save pricing (unit price / quantity per line) — allowed while still under review.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pricing') {
    if (!in_array($portalOrder['status'], ['pending_review', 'priced'], true)) {
        $_SESSION['error'] = 'This request can no longer be priced.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    $lines = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

    try {
        $pdo->beginTransaction();

        $itemStmt = $pdo->prepare("SELECT id FROM portal_order_items WHERE id = ? AND portal_order_id = ?");
        $updateStmt = $pdo->prepare("
            UPDATE portal_order_items
            SET quantity = ?, unit_price = ?, total_price = ?, is_priced = 1
            WHERE id = ? AND portal_order_id = ?
        ");

        $total = 0.0;
        foreach ($lines as $itemId => $line) {
            $itemId = (int)$itemId;
            $itemStmt->execute([$itemId, $portalOrderId]);
            if (!$itemStmt->fetchColumn()) {
                continue;
            }

            $quantity = max(1, (int)($line['quantity'] ?? 0));
            $unitPrice = max(0, (float)($line['unit_price'] ?? 0));
            $lineTotal = round($quantity * $unitPrice, 2);
            $total += $lineTotal;

            $updateStmt->execute([$quantity, $unitPrice, $lineTotal, $itemId, $portalOrderId]);
        }

        $reviewNote = trim((string)($_POST['review_note'] ?? ''));
        $updateOrder = $pdo->prepare("
            UPDATE portal_orders
            SET status = 'priced', total_amount = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");
        $updateOrder->execute([
            round($total, 2),
            $reviewNote !== '' ? $reviewNote : null,
            $_SESSION['user_id'],
            $portalOrderId,
        ]);

        $pdo->commit();
        $_SESSION['success'] = 'Pricing saved. The request is ready for approval.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Failed to save pricing: ' . $e->getMessage();
    }

    header('Location: review.php?id=' . $portalOrderId);
    exit();
}

// Reject the request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    if (in_array($portalOrder['status'], ['converted', 'rejected'], true)) {
        $_SESSION['error'] = 'This request cannot be rejected.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    $reviewNote = trim((string)($_POST['review_note'] ?? ''));
    $stmt = $pdo->prepare("
        UPDATE portal_orders
        SET status = 'rejected', review_note = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$reviewNote !== '' ? $reviewNote : null, $_SESSION['user_id'], $portalOrderId]);

    $_SESSION['success'] = 'Request rejected.';
    header('Location: review.php?id=' . $portalOrderId);
    exit();
}

// Approve pricing (locks it in, ready for conversion).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    if ($portalOrder['status'] !== 'priced') {
        $_SESSION['error'] = 'The request must be priced before it can be approved.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    $stmt = $pdo->prepare("
        UPDATE portal_orders
        SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $portalOrderId]);

    $_SESSION['success'] = 'Request approved. You can now convert it to a real order.';
    header('Location: review.php?id=' . $portalOrderId);
    exit();
}

// Send an approved-but-unconverted request back to pricing (e.g. it was approved with zero prices by mistake).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen') {
    if ($portalOrder['status'] !== 'approved' || $portalOrder['converted_order_id']) {
        $_SESSION['error'] = 'This request cannot be reopened for pricing.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    $stmt = $pdo->prepare("
        UPDATE portal_orders SET status = 'priced' WHERE id = ?
    ");
    $stmt->execute([$portalOrderId]);

    $_SESSION['success'] = 'Request reopened for pricing.';
    header('Location: review.php?id=' . $portalOrderId);
    exit();
}

// Convert to a real order — same pattern as quotations/convert_to_order.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert') {
    if ($portalOrder['status'] !== 'approved' || $portalOrder['converted_order_id']) {
        $_SESSION['error'] = 'This request is not ready for conversion.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    $itemsStmt = $pdo->prepare("
        SELECT poi.*, p.type, p.customer_id AS product_customer_id
        FROM portal_order_items poi
        JOIN products p ON poi.product_id = p.id
        WHERE poi.portal_order_id = ?
    ");
    $itemsStmt->execute([$portalOrderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        $_SESSION['error'] = 'This request has no items.';
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }

    foreach ($items as $item) {
        if ($item['type'] !== 'final'
            || (int)$item['product_customer_id'] !== (int)$portalOrder['customer_id']
            || !canAccessProduct($item['product_id'])
        ) {
            $_SESSION['error'] = 'This request contains a product that is no longer available for this customer.';
            header('Location: review.php?id=' . $portalOrderId);
            exit();
        }
        if ((float)$item['unit_price'] <= 0) {
            $_SESSION['error'] = 'Every line must have a confirmed price before conversion.';
            header('Location: review.php?id=' . $portalOrderId);
            exit();
        }
    }

    $orderFactoryId = ($_SESSION['login_region'] ?? 'factory') === 'factory'
        ? $portalOrder['factory_id']
        : null;

    try {
        $pdo->beginTransaction();

        $internalId = 'ORD-' . date('Ymd') . '-P' . strtoupper(bin2hex(random_bytes(3)));
        $insertOrder = $pdo->prepare("
            INSERT INTO orders (
                internal_id, customer_id, factory_id, contact_id, order_date, status,
                total_amount, paid_amount, discount_percentage, discount_basis, discount_amount,
                discount_product_count, free_sample_count, shipping_cost_type, shipping_cost,
                notes, created_by
            ) VALUES (?, ?, ?, ?, CURDATE(), 'new', ?, 0, 0, 'none', 0, 0, 0, 'none', 0, ?, ?)
        ");
        $orderNotes = trim((string)($portalOrder['customer_note'] ?? ''));
        $insertOrder->execute([
            $internalId,
            $portalOrder['customer_id'],
            $orderFactoryId,
            $portalOrder['contact_id'],
            $portalOrder['total_amount'],
            'Converted from portal order request #' . $portalOrderId . ($orderNotes !== '' ? "\n" . $orderNotes : ''),
            $_SESSION['user_id'],
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $insertItem = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, is_free_sample)
            VALUES (?, ?, ?, ?, ?, 0)
        ");

        $stockLogs = [];
        $priceLogs = [];

        foreach ($items as $item) {
            $insertItem->execute([
                $orderId,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
            ]);
            $orderItemId = (int)$pdo->lastInsertId();

            $beforeStmt = $pdo->prepare("SELECT quantity FROM inventory_products WHERE inventory_id = 1 AND product_id = ? LIMIT 1");
            $beforeStmt->execute([$item['product_id']]);
            $quantityBefore = $beforeStmt->fetchColumn();

            if ($quantityBefore !== false) {
                $quantityBefore = (float)$quantityBefore;
                $deductStmt = $pdo->prepare("UPDATE inventory_products SET quantity = quantity - ? WHERE inventory_id = 1 AND product_id = ?");
                $deductStmt->execute([$item['quantity'], $item['product_id']]);

                $stockLogs[] = [
                    'product_id' => (int)$item['product_id'],
                    'change_quantity' => -(float)$item['quantity'],
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityBefore - (float)$item['quantity'],
                    'source_id' => $orderItemId,
                    'sell_price' => (float)$item['unit_price'],
                ];
            }

            $priceLogs[] = [
                'product_id' => (int)$item['product_id'],
                'price' => (float)$item['unit_price'],
                'quantity' => (float)$item['quantity'],
                'source_id' => $orderItemId,
            ];
        }

        $updatePortalOrder = $pdo->prepare("
            UPDATE portal_orders SET status = 'converted', converted_order_id = ? WHERE id = ?
        ");
        $updatePortalOrder->execute([$orderId, $portalOrderId]);

        $pdo->commit();

        foreach ($stockLogs as $stockLog) {
            logInventoryStockChange(
                1,
                $stockLog['product_id'],
                $stockLog['change_quantity'],
                $stockLog['quantity_before'],
                $stockLog['quantity_after'],
                'portal_order_conversion',
                $stockLog['source_id'],
                null,
                $stockLog['sell_price'],
                'Portal order request #' . $portalOrderId . ' converted to order ' . $internalId
            );
        }
        foreach ($priceLogs as $priceLog) {
            logProductPrice(
                $priceLog['product_id'],
                'sell',
                $priceLog['price'],
                $priceLog['quantity'],
                'portal_order_conversion',
                $priceLog['source_id'],
                'Portal order request #' . $portalOrderId . ' converted to order ' . $internalId
            );
        }

        $prodRoles = $pdo->query("SELECT id FROM roles WHERE slug IN ('production_manager', 'production_supervisor')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prodRoles as $role) {
            createNotification(
                'sales_order_created',
                'New Order (from Portal): ' . $internalId,
                'A new sales order has been created from portal request #' . $portalOrderId . '. Order ID: ' . $internalId,
                'sales',
                'order',
                $orderId,
                'info',
                $role['id'],
                null,
                $_SESSION['user_id']
            );
        }

        $_SESSION['success'] = 'Portal order request converted to order ' . $internalId . '.';
        header('Location: ../order_details.php?id=' . $orderId);
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Failed to convert request: ' . $e->getMessage();
        header('Location: review.php?id=' . $portalOrderId);
        exit();
    }
}

// Refresh after any POST that didn't redirect (defensive — should not happen).
$portalOrder = fetchPortalOrder($pdo, $portalOrderId);

$itemsStmt = $pdo->prepare("
    SELECT poi.*, p.name AS product_name, p.sku, p.unit_price AS catalog_unit_price
    FROM portal_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.portal_order_id = ?
    ORDER BY poi.id
");
$itemsStmt->execute([$portalOrderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$isEditable = in_array($portalOrder['status'], ['pending_review', 'priced'], true);
$canApprove = $portalOrder['status'] === 'priced';
$canConvert = $portalOrder['status'] === 'approved' && !$portalOrder['converted_order_id'];
$canReject = in_array($portalOrder['status'], ['pending_review', 'priced'], true);
$canReopen = $portalOrder['status'] === 'approved' && !$portalOrder['converted_order_id'];
$canDelete = hasPermission('sales.portal_orders.delete')
    && in_array($portalOrder['status'], ['pending_review', 'priced', 'rejected'], true);
$canForceDelete = hasPermission('sales.portal_orders.force_delete')
    && $portalOrder['status'] === 'approved'
    && !$portalOrder['converted_order_id'];

require_once '../../../includes/header.php';
?>

<div class="container mt-4">
    <h2>Portal Order Request #<?= (int)$portalOrder['id'] ?></h2>

    <?php include '../../../includes/messages.php'; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Customer: <?= htmlspecialchars($portalOrder['customer_name']) ?></h4>
            <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($portalOrder['status']) ?></span>
        </div>
        <div class="card-body">
            <p><strong>Submitted:</strong> <?= date('M d, Y H:i', strtotime($portalOrder['created_at'])) ?></p>
            <?php if (!empty($portalOrder['customer_note'])): ?>
                <p><strong>Customer note:</strong> <?= nl2br(htmlspecialchars($portalOrder['customer_note'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($portalOrder['review_note'])): ?>
                <p><strong>Review note:</strong> <?= nl2br(htmlspecialchars($portalOrder['review_note'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="action" value="save_pricing">
        <div class="card mb-4">
            <div class="card-header">
                <h5>Items — set the confirmed price for each line</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($item['product_name']) ?>
                                    <?php if (!empty($item['sku'])): ?>
                                        <span class="text-muted">(<?= htmlspecialchars($item['sku']) ?>)</span>
                                    <?php endif; ?>
                                    <div class="text-muted small">Catalog price: <?= number_format($item['catalog_unit_price'], 2) ?></div>
                                </td>
                                <td style="max-width:120px">
                                    <input type="number" min="1" step="1" class="form-control"
                                           name="items[<?= (int)$item['id'] ?>][quantity]"
                                           value="<?= (int)$item['quantity'] ?>"
                                           <?= $isEditable ? '' : 'readonly' ?>>
                                </td>
                                <td style="max-width:140px">
                                    <input type="number" min="0" step="0.01" class="form-control"
                                           name="items[<?= (int)$item['id'] ?>][unit_price]"
                                           value="<?= number_format((float)$item['unit_price'], 2, '.', '') ?>"
                                           <?= $isEditable ? '' : 'readonly' ?>>
                                </td>
                                <td><?= number_format($item['total_price'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" class="text-end">Total</th>
                            <th><?= number_format($portalOrder['total_amount'], 2) ?></th>
                        </tr>
                    </tfoot>
                </table>

                <div class="mb-3">
                    <label for="review_note" class="form-label">Review note (optional, visible to customer if rejected)</label>
                    <textarea class="form-control" id="review_note" name="review_note" rows="2" <?= $isEditable ? '' : 'readonly' ?>><?= htmlspecialchars($portalOrder['review_note'] ?? '') ?></textarea>
                </div>

                <?php if ($isEditable): ?>
                    <button type="submit" class="btn btn-primary">Save Pricing</button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="d-flex gap-2">
        <a href="list.php" class="btn btn-secondary">Back to List</a>

        <?php if ($canReject): ?>
            <form method="post" onsubmit="return confirm('Reject this portal order request?');">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="review_note" value="<?= htmlspecialchars($portalOrder['review_note'] ?? '') ?>">
                <button type="submit" class="btn btn-danger">Reject</button>
            </form>
        <?php endif; ?>

        <?php if ($canApprove): ?>
            <form method="post" onsubmit="return confirm('Approve this request with the current pricing?');">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-success">Approve Pricing</button>
            </form>
        <?php endif; ?>

        <?php if ($canReopen): ?>
            <form method="post" onsubmit="return confirm('Reopen this request for pricing? You will need to approve it again before converting.');">
                <input type="hidden" name="action" value="reopen">
                <button type="submit" class="btn btn-outline-secondary">Return to Pricing</button>
            </form>
        <?php endif; ?>

        <?php if ($canConvert): ?>
            <form method="post" onsubmit="return confirm('Convert this request into a real order? This will deduct stock and cannot be undone.');">
                <input type="hidden" name="action" value="convert">
                <button type="submit" class="btn btn-primary">Convert to Order</button>
            </form>
        <?php endif; ?>

        <?php if ($canDelete): ?>
            <form method="post" class="ms-auto" onsubmit="return confirm('Permanently delete this portal order request? This cannot be undone.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portal_order_delete_token'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-outline-danger">Delete</button>
            </form>
        <?php endif; ?>

        <?php if ($canForceDelete): ?>
            <form method="post" class="<?= $canDelete ? '' : 'ms-auto' ?>" onsubmit="return confirm('This request has already been approved. Force delete it anyway? This cannot be undone.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portal_order_delete_token'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-danger">Force Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>
