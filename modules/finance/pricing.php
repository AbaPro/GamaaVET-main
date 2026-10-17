<?php
require_once '../../includes/auth.php';
require_once __DIR__ . '/pricing_helpers.php';

pricingRequireAccess('finance.pricing.view', 'pricing.php');
$canManage = hasPermission('finance.pricing.manage');
$storageReady = pricingStorageReady();

if ($storageReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pricing_id'])) {
    $deleteId = (int)$_POST['delete_pricing_id'];
    $pricing = $canManage && pricingValidFormToken() ? pricingLoadSaved($deleteId) : null;
    if (!$pricing) {
        setAlert('danger', 'Unable to delete this pricing sheet.');
    } else {
        $stmt = $pdo->prepare('DELETE FROM order_pricings WHERE id = ?');
        $stmt->execute([$deleteId]);
        logActivity('Deleted order pricing', ['order_id' => (int)$pricing['order_id']], 'delete', 'order_pricing', $deleteId);
        setAlert('success', 'Pricing sheet deleted.');
    }
    redirect('pricing.php');
}

$orderFilter = (int)($_GET['order_id'] ?? 0);
$rows = [];
if ($storageReady) {
    $scope = getCustomerChannelScopeSql('c', 'f');
    $where = $orderFilter > 0 ? ' AND op.order_id = ' . $orderFilter : '';
    $result = $conn->query("
        SELECT op.*, o.internal_id, c.name AS customer_name, u.name AS created_by_name,
               (SELECT COUNT(*) FROM order_pricing_lines l WHERE l.order_pricing_id = op.id) AS product_count
        FROM order_pricings op
        JOIN orders o ON o.id = op.order_id
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN factories f ON f.id = c.factory_id
        LEFT JOIN users u ON u.id = op.created_by
        WHERE $scope $where
        ORDER BY op.updated_at DESC, op.id DESC
    ");
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

$page_title = 'Order Pricing';
require_once '../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h2 class="mb-0">Order Pricing</h2>
        <div class="text-muted">Cost order products from their formulas and packaging, then apply a profit margin.</div>
    </div>
    <div class="d-flex gap-2">
        <?php if (isAdminUser()): ?>
            <a href="pricing_password.php" class="btn btn-outline-secondary"><i class="fas fa-key"></i> Change Password</a>
        <?php endif; ?>
        <?php if ($canManage && $storageReady): ?>
            <a href="pricing_edit.php<?= $orderFilter > 0 ? '?order_id=' . $orderFilter : ''; ?>" class="btn btn-primary"><i class="fas fa-plus"></i> New Pricing</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$storageReady): ?>
    <div class="alert alert-warning">Apply migration 20261016_order_pricing.sql before using order pricing.</div>
<?php else: ?>
    <?php if ($orderFilter > 0): ?>
        <div class="alert alert-info py-2">Showing pricing for one order. <a href="pricing.php">Show all</a></div>
    <?php endif; ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>Order</th><th>Customer</th><th class="text-end">Products</th><th class="text-end">Margin</th>
                        <th class="text-end">Total Cost</th><th class="text-end">Selling Price</th><th>Updated</th><th>By</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="pricing_edit.php?id=<?= (int)$row['id']; ?>"><?= (int)$row['id']; ?></a></td>
                            <td><?= e($row['internal_id'] ?: '#' . $row['order_id']); ?></td>
                            <td><?= e($row['customer_name']); ?></td>
                            <td class="text-end"><?= (int)$row['product_count']; ?></td>
                            <td class="text-end"><?= e(rtrim(rtrim(number_format((float)$row['margin_percent'], 2, '.', ''), '0'), '.')); ?>%</td>
                            <td class="text-end text-nowrap"><?= e(formatCurrency($row['total_cost'], $row['currency'])); ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= e(formatCurrency($row['total_selling_price'], $row['currency'])); ?></td>
                            <td class="text-nowrap"><?= e(formatDateTime($row['updated_at'])); ?></td>
                            <td><?= e($row['created_by_name'] ?? ''); ?></td>
                            <td class="text-end text-nowrap">
                                <a href="pricing_edit.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary"><?= $canManage ? 'Open' : 'View'; ?></a>
                                <?php if ($canManage): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this pricing sheet?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(pricingFormToken()); ?>">
                                        <input type="hidden" name="delete_pricing_id" value="<?= (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No pricing sheets yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
