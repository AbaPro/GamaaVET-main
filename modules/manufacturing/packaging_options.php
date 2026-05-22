<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Packaging Options';
require_once '../../includes/header.php';

$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$productFilter = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$search = sanitize($_GET['search'] ?? '');
$hasPackagingProductColumn = $conn->query("SHOW COLUMNS FROM packaging_options LIKE 'product_id'")->num_rows > 0;

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[] = $row;
    }
}

$finalProducts = [];
$finalProductResult = $conn->query("SELECT id, customer_id, name, sku FROM products WHERE type = 'final' ORDER BY name");
if ($finalProductResult) {
    while ($row = $finalProductResult->fetch_assoc()) {
        $finalProducts[] = $row;
    }
}

$whereClauses = ['1=1'];
if ($customerFilter > 0) {
    $whereClauses[] = "po.customer_id = " . (int)$customerFilter;
}
if ($hasPackagingProductColumn && $productFilter > 0) {
    $whereClauses[] = "po.product_id = " . (int)$productFilter;
}
if ($search !== '') {
    $whereClauses[] = "(po.name LIKE '%" . $conn->real_escape_string($search) . "%' OR po.description LIKE '%" . $conn->real_escape_string($search) . "%')";
}

$productSelect = $hasPackagingProductColumn ? ", p.name AS product_name, p.sku AS product_sku" : "";
$productJoin = $hasPackagingProductColumn ? "LEFT JOIN products p ON p.id = po.product_id" : "";

$query = "
    SELECT po.*, c.name AS customer_name{$productSelect},
           COUNT(poi.id) AS item_count
    FROM packaging_options po
    JOIN customers c ON c.id = po.customer_id
    {$productJoin}
    LEFT JOIN packaging_option_items poi ON poi.packaging_option_id = po.id
    WHERE " . implode(' AND ', $whereClauses) . "
    GROUP BY po.id
    ORDER BY c.name ASC, po.name ASC
";

$options = [];
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $options[] = $row;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2>Packaging Options</h2>
        <p class="text-muted mb-0">Per-customer packaging kits used in manufacturing orders.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Orders
        </a>
        <a href="packaging_option_edit.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i> New Packaging Option
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3" method="get">
            <div class="col-md-3">
                <label class="form-label">Customer</label>
                <select class="form-select select2" name="customer_id">
                    <option value="">All customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id']; ?>" <?= $customerFilter === (int)$customer['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($hasPackagingProductColumn): ?>
            <div class="col-md-3">
                <label class="form-label">Final Product</label>
                <select class="form-select select2" name="product_id">
                    <option value="">All final products</option>
                    <?php foreach ($finalProducts as $product): ?>
                        <option value="<?= $product['id']; ?>"
                                data-customer-id="<?= (int)$product['customer_id']; ?>"
                                <?= $productFilter === (int)$product['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($product['name']) . ($product['sku'] ? ' (' . htmlspecialchars($product['sku']) . ')' : ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="<?= $hasPackagingProductColumn ? 'col-md-3' : 'col-md-4'; ?>">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" placeholder="Search by name or description" value="<?= htmlspecialchars($search); ?>">
            </div>
            <div class="<?= $hasPackagingProductColumn ? 'col-md-3' : 'col-md-4'; ?> d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                <a href="packaging_options.php" class="btn btn-outline-secondary flex-grow-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="packagingOptionsTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Customer</th>
                        <?php if ($hasPackagingProductColumn): ?>
                            <th>Final Product</th>
                        <?php endif; ?>
                        <th>Items</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($options)): ?>
                        <?php foreach ($options as $opt): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($opt['name']); ?></strong></td>
                                <td><?= htmlspecialchars($opt['customer_name']); ?></td>
                                <?php if ($hasPackagingProductColumn): ?>
                                    <td>
                                        <?php if (!empty($opt['product_name'])): ?>
                                            <?= htmlspecialchars($opt['product_name']); ?>
                                            <?= $opt['product_sku'] ? '<small class="text-muted">(' . htmlspecialchars($opt['product_sku']) . ')</small>' : ''; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><span class="badge bg-secondary"><?= (int)$opt['item_count']; ?> items</span></td>
                                <td>
                                    <?php if ($opt['description']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($opt['description']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($opt['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('Y-m-d', strtotime($opt['created_at'])); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="packaging_option_edit.php?id=<?= $opt['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </a>
                                        <?php if (hasPermission('manufacturing.delete')): ?>
                                            <a href="javascript:void(0)" class="btn btn-sm btn-outline-danger"
                                               onclick="confirmDelete(<?= $opt['id']; ?>, '<?= htmlspecialchars($opt['name']); ?>')">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $hasPackagingProductColumn ? 8 : 7; ?>" class="text-center py-4 text-muted">No packaging options found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
    function confirmDelete(id, name) {
        if (confirm('Delete packaging option "' + name + '"? This will fail if it is used by existing orders.')) {
            window.location.href = 'packaging_option_delete.php?id=' + id;
        }
    }

    $(document).ready(function () {
        if ($.fn.select2) {
            $('.select2').select2({ width: '100%' });
        }
        if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#packagingOptionsTable')) {
            $('#packagingOptionsTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[1, 'asc'], [0, 'asc']],
                language: { emptyTable: 'No packaging options found.' }
            });
        }
    });
</script>
