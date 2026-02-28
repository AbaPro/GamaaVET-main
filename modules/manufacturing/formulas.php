<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}
$canViewFormula = hasPermission('manufacturing.formula.view_all');
if (!$canViewFormula) {
    setAlert('danger', 'Access denied. You do not have permission to view formulas.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_formula_password'])) {
    if ($_POST['unlock_formula_password'] === '123456') {
        $_SESSION['formula_unlocked'] = true;
        setAlert('success', 'Formulas unlocked.');
    } else {
        setAlert('danger', 'Incorrect password.');
    }
    // Redirect to clear POST
    header("Location: formulas.php");
    exit;
}

if (empty($_SESSION['formula_unlocked'])) {
    $page_title = 'Unlock Formulas';
    require_once '../../includes/header.php';
    ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header border-bottom">
                    <h5 class="mb-0">Unlock Formulas</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="unlock_formula_password" class="form-control" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Unlock</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

$page_title = 'Manufacturing Formulas';
require_once '../../includes/header.php';

$providerFilter = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;
$productFilter = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$search = sanitize($_GET['search'] ?? '');

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($customerRow = $customerResult->fetch_assoc()) {
        $customers[] = $customerRow;
    }
}

$allProducts = [];
$productResult = $conn->query("SELECT id, name, sku FROM products ORDER BY name");
if ($productResult) {
    while ($pRow = $productResult->fetch_assoc()) {
        $allProducts[] = $pRow;
    }
}

$whereClauses = [];
if ($providerFilter > 0) {
    $whereClauses[] = "f.customer_id = " . (int)$providerFilter;
}
if ($productFilter > 0) {
    // Search within JSON for the product_id
    $whereClauses[] = "JSON_CONTAINS(f.components_json, JSON_OBJECT('product_id', " . (int)$productFilter . "))";
}
if ($search) {
    $whereClauses[] = "(f.name LIKE '%" . $conn->real_escape_string($search) . "%' OR f.description LIKE '%" . $conn->real_escape_string($search) . "%')";
}

$query = "
    SELECT f.*, c.name AS customer_name, p.name AS product_name, p.sku AS product_sku
    FROM manufacturing_formulas f
    JOIN customers c ON c.id = f.customer_id
    LEFT JOIN products p ON p.id = f.product_id
";
if (!empty($whereClauses)) {
    $query .= ' WHERE ' . implode(' AND ', $whereClauses);
}
$query .= ' ORDER BY f.name ASC';

$formulas = [];
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $formulas[] = $row;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2>Manufacturing Formulas</h2>
        <p class="text-muted mb-0">Manage product recipes and components for each provider.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Orders
        </a>
        <a href="formula_edit.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i> New Formula
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3" method="get">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select select2" name="provider_id">
                    <option value="">All providers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $providerFilter === (int)$customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Contains Product</label>
                <select class="form-select select2" name="product_id">
                    <option value="">Any product</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $productFilter === (int)$p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']) . ($p['sku'] ? " ({$p['sku']})" : ""); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" placeholder="Search by name or description" value="<?= htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                <a href="formulas.php" class="btn btn-outline-secondary flex-grow-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="formulasTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Provider</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Type</th>
                        <th>Batch Size</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($formulas)): ?>
                        <?php foreach ($formulas as $formula): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($formula['name']); ?></strong>
                                    <?php if ($formula['description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($formula['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($formula['customer_name']); ?></td>
                                <td>
                                    <?php if ($formula['product_name']): ?>
                                        <?= htmlspecialchars($formula['product_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($formula['product_sku']): ?>
                                        <?= htmlspecialchars($formula['product_sku']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($formula['type']): ?>
                                        <span class="badge <?= $formula['type'] === 'liquid' ? 'bg-info' : 'bg-warning text-dark'; ?>">
                                            <?= ucfirst($formula['type']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= number_format($formula['batch_size'], 2); ?>
                                    <?php if (!empty($formula['batch_unit'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($formula['batch_unit']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($formula['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('Y-m-d', strtotime($formula['created_at'])); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="formula_edit.php?id=<?= $formula['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </a>
                                        <?php if (hasPermission('manufacturing.delete')): ?>
                                            <a href="javascript:void(0)" class="btn btn-sm btn-outline-danger" 
                                               onclick="confirmDelete(<?= $formula['id']; ?>, '<?= htmlspecialchars($formula['name']); ?>')">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No formulas found matching your criteria.</td>
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
        if (confirm('Are you sure you want to delete formula "' + name + '"? This might fail if it is referenced by existing orders.')) {
            window.location.href = 'formula_delete.php?id=' + id;
        }
    }

    $(document).ready(function() {
        if ($.fn.select2) {
            $('.select2').select2({
                width: '100%'
            });
        }
        
        if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#formulasTable')) {
            $('#formulasTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    emptyTable: 'No formulas found.'
                }
            });
        }
    });
</script>
