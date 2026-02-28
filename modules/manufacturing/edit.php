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
    WHERE mo.id = ?
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

// Fetch products for this customer (type final)
$products = [];
$productStmt = $conn->prepare("SELECT id, name, sku FROM products WHERE customer_id = ? AND type = 'final' ORDER BY name");
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

    if ($locationId <= 0) {
        setAlert('danger', 'Please select a location for this manufacturing order.');
    } else {
        try {
            // Prepare null-safe date value
            $dueDateValue = $dueDate ?: null;
            
            $updateStmt = $conn->prepare("
                UPDATE manufacturing_orders
                SET location_id = ?, product_id = ?, bottle_size_id = ?, batch_size = ?, due_date = ?, priority = ?, notes = ?, status = ?
                WHERE id = ?
            ");
            $productIdValue = $productId ?: null;
            $updateStmt->bind_param("iiidssssi", $locationId, $productIdValue, $bottleSizeId, $batchSize, $dueDateValue, $priority, $orderNotes, $status, $orderId);
            
            if ($updateStmt->execute()) {
                setAlert('success', 'Manufacturing order updated successfully.');
                logActivity("Updated manufacturing order ID: $orderId", ['order_id' => $orderId]);
                redirect('order.php?id=' . $orderId);
            } else {
                setAlert('danger', 'Error updating order: ' . $conn->error);
            }
            $updateStmt->close();
        } catch (Exception $exception) {
            setAlert('danger', 'Unable to update order: ' . $exception->getMessage());
        }
    }
}

$page_title = 'Edit Manufacturing Order';
require_once '../../includes/header.php';

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
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
