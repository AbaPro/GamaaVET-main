<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

if (!hasPermission('manufacturing.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

// Get the order ID from URL
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    setAlert('danger', 'Invalid manufacturing order ID.');
    redirect('index.php');
}

// Fetch the order
$orderStmt = $conn->prepare("
    SELECT mo.*, c.name AS customer_name
    FROM manufacturing_orders mo
    JOIN customers c ON c.id = mo.customer_id
    LEFT JOIN factories f ON f.id = c.factory_id
    WHERE mo.id = ?
      AND " . getCustomerChannelScopeSql('c', 'f') . "
");
$orderStmt->bind_param("i", $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();
$orderStmt->close();

if (!$order) {
    setAlert('danger', 'Manufacturing order not found.');
    redirect('index.php');
}

if ($order['status'] === 'completed') {
    setAlert('danger', 'Completed manufacturing orders are locked and cannot be edited.');
    redirect('order.php?id=' . $orderId);
}

// Fetch products for this customer (type final)
$products = [];
$productStmt = $conn->prepare("SELECT id, name, sku FROM products p WHERE customer_id = ? AND type = 'final' AND " . getActiveProductSql('p') . " ORDER BY name");
$productStmt->bind_param("i", $order['customer_id']);
$productStmt->execute();
$productResult = $productStmt->get_result();
while ($pRow = $productResult->fetch_assoc()) {
    $products[] = $pRow;
}
$productStmt->close();

$old = $_POST;

$locations = [];
$locationsResult = $conn->query("SELECT id, name, address FROM locations WHERE is_active = 1 ORDER BY name");
if ($locationsResult) {
    while ($locationRow = $locationsResult->fetch_assoc()) {
        $locations[] = $locationRow;
    }
}

$bottleSizes = [];
$bottleSizesResult = $conn->query("SELECT id, name, size, unit, type FROM bottle_sizes WHERE is_active = 1 ORDER BY type, name");
if ($bottleSizesResult) {
    while ($bsRow = $bottleSizesResult->fetch_assoc()) {
        $bottleSizes[] = $bsRow;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId   = isset($_POST['location_id'])   ? (int)$_POST['location_id']   : 0;
    $productId    = isset($_POST['product_id'])    ? (int)$_POST['product_id']    : 0;
    $bottleSizeId = isset($_POST['bottle_size_id']) && $_POST['bottle_size_id'] !== '' ? (int)$_POST['bottle_size_id'] : null;
    $priority     = $_POST['priority'] ?? 'normal';
    $batchSize    = isset($_POST['batch_size'])    ? floatval($_POST['batch_size']) : 0;
    $dueDate      = $_POST['due_date'] ?? null;
    $orderNotes   = trim($_POST['notes'] ?? '');
    $status       = $_POST['status'] ?? 'getting';

    $allowedPriorities = ['normal', 'rush', 'critical'];
    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'normal';
    }

    $allowedStatuses = ['getting', 'preparing', 'delivering', 'completed', 'cancelled'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'getting';
    }

    $productIsValid = false;
    if ($productId > 0 && canAccessProduct($productId)) {
        $orderCustomerId = (int)$order['customer_id'];
        $productCheckStmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE id = ? AND customer_id = ? AND type = 'final'");
        $productCheckStmt->bind_param('ii', $productId, $orderCustomerId);
        $productCheckStmt->execute();
        $productCheckStmt->bind_result($productMatchCount);
        $productCheckStmt->fetch();
        $productCheckStmt->close();
        $productIsValid = (int)$productMatchCount === 1;
    }

    if ($locationId <= 0) {
        setAlert('danger', 'Please select a location for this manufacturing order.');
    } elseif (!$productIsValid) {
        setAlert('danger', 'Please select a final product for this Factory customer.');
    } else {
        try {
            $dueDateValue = $dueDate ?: null;
            $productIdValue = $productId ?: null;
            $previousStatus = $order['status'];

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE manufacturing_orders
                SET location_id = ?, product_id = ?, bottle_size_id = ?, batch_size = ?, due_date = ?, priority = ?, notes = ?, status = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$locationId, $productIdValue, $bottleSizeId, $batchSize, $dueDateValue, $priority, $orderNotes, $status, $orderId]);

            // Restock inventory when order transitions to cancelled
            $stockLogs = [];
            if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
                $deductedStmt = $pdo->prepare("
                    SELECT id, product_id, required_quantity
                    FROM manufacturing_sourcing_components
                    WHERE manufacturing_order_id = ? AND product_id IS NOT NULL AND deducted_at IS NOT NULL
                ");
                $deductedStmt->execute([$orderId]);
                $deductedRows = $deductedStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($deductedRows as $dc) {
                    $stockBeforeStmt = $conn->prepare("
                        SELECT ip.inventory_id, ip.quantity
                        FROM inventory_products ip
                        JOIN inventories inv ON inv.id = ip.inventory_id
                        WHERE ip.product_id = ? AND inv.location_id = ?
                    ");
                    $stockBeforeStmt->bind_param("ii", $dc['product_id'], $order['location_id']);
                    $stockBeforeStmt->execute();
                    $stockBeforeRows = $stockBeforeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stockBeforeStmt->close();

                    $pdo->prepare("
                        UPDATE inventory_products ip
                        JOIN inventories inv ON inv.id = ip.inventory_id
                        SET ip.quantity = ip.quantity + ?
                        WHERE ip.product_id = ? AND inv.location_id = ?
                    ")->execute([$dc['required_quantity'], $dc['product_id'], $order['location_id']]);

                    $pdo->prepare("UPDATE manufacturing_sourcing_components SET deducted_at = NULL WHERE id = ?")->execute([$dc['id']]);
                    foreach ($stockBeforeRows as $stockBeforeRow) {
                        $stockLogs[] = [
                            'inventory_id' => (int)$stockBeforeRow['inventory_id'],
                            'product_id' => (int)$dc['product_id'],
                            'change_quantity' => (float)$dc['required_quantity'],
                            'quantity_before' => (float)$stockBeforeRow['quantity'],
                            'quantity_after' => (float)$stockBeforeRow['quantity'] + (float)$dc['required_quantity'],
                        ];
                    }
                }
            }

            // Batch size / bottle size may have changed, so re-scale required quantities
            // for any sourcing rows that haven't already had stock deducted.
            $rescaleStmt = $pdo->prepare("
                SELECT mo.*, f.components_json, f.batch_size AS formula_batch_size, f.batch_unit,
                       bs.size AS bottle_size_value, bs.unit AS bottle_size_unit
                FROM manufacturing_orders mo
                JOIN manufacturing_formulas f ON f.id = mo.formula_id
                LEFT JOIN bottle_sizes bs ON bs.id = mo.bottle_size_id
                WHERE mo.id = ?
                LIMIT 1
            ");
            $rescaleStmt->execute([$orderId]);
            $rescaledOrder = $rescaleStmt->fetch(PDO::FETCH_ASSOC);

            $hasSourceCol = $conn->query("SHOW COLUMNS FROM manufacturing_sourcing_components LIKE 'source'")->num_rows > 0;

            $rescaledComponentsRaw = json_decode($rescaledOrder['components_json'] ?? '[]', true);
            if (!is_array($rescaledComponentsRaw)) {
                $rescaledComponentsRaw = [];
            }
            $rescaledComponents = manufacturing_recalculate_components($rescaledOrder, $rescaledComponentsRaw);

            $updateReqStmt = $pdo->prepare($hasSourceCol
                ? "UPDATE manufacturing_sourcing_components SET required_quantity = ? WHERE manufacturing_order_id = ? AND source = 'formula' AND formula_component_index = ? AND deducted_at IS NULL"
                : "UPDATE manufacturing_sourcing_components SET required_quantity = ? WHERE manufacturing_order_id = ? AND formula_component_index = ? AND deducted_at IS NULL");
            foreach ($rescaledComponents as $index => $component) {
                $newRequiredQty = $component['quantity'] ?? $component['ratio'] ?? 0;
                $updateReqStmt->execute([$newRequiredQty, $orderId, $index]);
            }
            $updateReqStmt->closeCursor();

            // Packaging rows scale directly with number_of_bottles (unchanged by this form),
            // but still need to be re-derived from the packaging option's per-bottle quantity.
            if ($hasSourceCol && !empty($rescaledOrder['number_of_bottles']) && !empty($rescaledOrder['packaging_option_id'])) {
                $pkgItemsStmt = $pdo->prepare("
                    SELECT poi.quantity
                    FROM packaging_option_items poi
                    WHERE poi.packaging_option_id = ?
                    ORDER BY poi.id
                ");
                $pkgItemsStmt->execute([$rescaledOrder['packaging_option_id']]);
                $pkgItems = $pkgItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                $updatePkgReqStmt = $pdo->prepare("
                    UPDATE manufacturing_sourcing_components
                    SET required_quantity = ?
                    WHERE manufacturing_order_id = ? AND source = 'packaging' AND formula_component_index = ? AND deducted_at IS NULL
                ");
                foreach ($pkgItems as $pkgIdx => $pkgItem) {
                    $pkgRequiredQty = round((float)$pkgItem['quantity'] * (int)$rescaledOrder['number_of_bottles'], 4);
                    $updatePkgReqStmt->execute([$pkgRequiredQty, $orderId, $pkgIdx]);
                }
                $updatePkgReqStmt->closeCursor();
            }

            $pdo->commit();
            foreach ($stockLogs as $stockLog) {
                logInventoryStockChange(
                    $stockLog['inventory_id'],
                    $stockLog['product_id'],
                    $stockLog['change_quantity'],
                    $stockLog['quantity_before'],
                    $stockLog['quantity_after'],
                    'manufacturing_cancel',
                    $orderId,
                    null,
                    null,
                    'Manufacturing order cancelled and deducted stock restored'
                );
            }
            setAlert('success', 'Manufacturing order updated successfully.');
            logActivity("Updated manufacturing order ID: $orderId", ['order_id' => $orderId]);
            redirect('order.php?id=' . $orderId);
        } catch (Exception $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setAlert('danger', 'Unable to update order: ' . $exception->getMessage());
        }
    }
}

$page_title = 'Edit Manufacturing Order';
require_once '../../includes/header.php';

$customers = [];
$customerScope = getCustomerChannelScopeSql('c', 'f');
$customerResult = $conn->query("SELECT c.id, c.name FROM customers c LEFT JOIN factories f ON f.id = c.factory_id WHERE $customerScope ORDER BY c.name");
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[] = $row;
    }
}

$priorities = ['normal' => 'Normal', 'rush' => 'Rush', 'critical' => 'Critical'];
$statuses = ['getting' => 'Getting', 'preparing' => 'Preparing', 'delivering' => 'Delivering', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2>Edit Manufacturing Order #<?php echo e($order['order_number']); ?></h2>
        <p class="text-muted mb-0">Update order details and timeline.</p>
    </div>
    <a href="order.php?id=<?php echo $orderId; ?>" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to order
    </a>
</div>

<form method="post">
    <div class="card mb-4">
        <div class="card-header">Order Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Provider / Customer</label>
                    <input type="text" class="form-control" value="<?php echo e($order['customer_name']); ?>" disabled>
                    <small class="text-muted">Cannot change customer for existing order</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <select class="form-select select2" name="location_id" required>
                        <option value="">Select location</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo (isset($old['location_id']) ? $old['location_id'] : $order['location_id']) == $location['id'] ? 'selected' : ''; ?>>
                                <?php echo e($location['name']); ?> (<?php echo e($location['address']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Final Product</label>
                    <select class="form-select select2" name="product_id">
                        <option value="">Select product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" <?php echo (isset($old['product_id']) ? $old['product_id'] : $order['product_id']) == $product['id'] ? 'selected' : ''; ?>>
                                <?php echo e($product['name']); ?> <?= $product['sku'] ? '(' . e($product['sku']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-0">
                <div class="col-md-4">
                    <label class="form-label">Bottle Size</label>
                    <?php
                    $selBsId    = isset($old['bottle_size_id']) ? $old['bottle_size_id'] : ($order['bottle_size_id'] ?? '');
                    $bsLiquid   = array_filter($bottleSizes, fn($b) => $b['type'] === 'liquid');
                    $bsPowder   = array_filter($bottleSizes, fn($b) => $b['type'] === 'powder');
                    ?>
                    <select class="form-select select2" name="bottle_size_id">
                        <option value="">— No bottle size —</option>
                        <?php if ($bsLiquid): ?>
                            <optgroup label="Liquid">
                                <?php foreach ($bsLiquid as $bs): ?>
                                    <option value="<?= $bs['id']; ?>" data-type="liquid"
                                            <?= (string)$selBsId === (string)$bs['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($bs['name']); ?> — <?= number_format($bs['size'], 3); ?> <?= htmlspecialchars($bs['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if ($bsPowder): ?>
                            <optgroup label="Powder">
                                <?php foreach ($bsPowder as $bs): ?>
                                    <option value="<?= $bs['id']; ?>" data-type="powder"
                                            <?= (string)$selBsId === (string)$bs['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($bs['name']); ?> — <?= number_format($bs['size'], 3); ?> <?= htmlspecialchars($bs['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select class="form-select" name="priority">
                        <?php foreach ($priorities as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo (isset($old['priority']) ? $old['priority'] : $order['priority']) === $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo (isset($old['status']) ? $old['status'] : $order['status']) === $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Due Date</label>
                    <input type="date" class="form-control" name="due_date" value="<?php echo e(isset($old['due_date']) ? $old['due_date'] : $order['due_date']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Batch Size</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="batch_size" value="<?php echo e(isset($old['batch_size']) ? $old['batch_size'] : $order['batch_size']); ?>" placeholder="Units to produce">
                </div>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-12">
                    <label class="form-label">Order Notes</label>
                    <textarea class="form-control" name="notes" rows="3"><?php echo e(isset($old['notes']) ? $old['notes'] : $order['notes']); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Save Changes
        </button>
        <a href="order.php?id=<?php echo $orderId; ?>" class="btn btn-secondary">
            <i class="fas fa-times me-1"></i> Cancel
        </a>
    </div>
</form>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function () {
    if ($.fn.select2) {
        $('.select2').select2({
            width: '100%'
        });
    }
});
</script>
