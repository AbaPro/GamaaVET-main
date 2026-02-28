<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Bottle Sizes';
require_once '../../includes/header.php';

$typeFilter = isset($_GET['type']) && in_array($_GET['type'], ['liquid', 'powder']) ? $_GET['type'] : '';
$search     = sanitize($_GET['search'] ?? '');

$where = [];
if ($typeFilter) {
    $where[] = "type = '" . $conn->real_escape_string($typeFilter) . "'";
}
if ($search) {
    $where[] = "name LIKE '%" . $conn->real_escape_string($search) . "%'";
}

$query = "SELECT * FROM bottle_sizes";
if ($where) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}
$query .= ' ORDER BY type, name ASC';

$sizes = [];
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sizes[] = $row;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2>Bottle Sizes</h2>
        <p class="text-muted mb-0">Manage bottle/container sizes used in manufacturing orders.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Orders
        </a>
        <a href="bottle_size_edit.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i> New Bottle Size
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3" method="get">
            <div class="col-md-3">
                <label class="form-label">Type</label>
                <select class="form-select" name="type">
                    <option value="">All types</option>
                    <option value="liquid"  <?= $typeFilter === 'liquid'  ? 'selected' : ''; ?>>Liquid</option>
                    <option value="powder"  <?= $typeFilter === 'powder'  ? 'selected' : ''; ?>>Powder</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" placeholder="Search by name" value="<?= htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                <a href="bottle_sizes.php" class="btn btn-outline-secondary flex-grow-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="bottleSizesTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sizes)): ?>
                        <?php foreach ($sizes as $size): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($size['name']); ?></strong></td>
                                <td><?= number_format($size['size'], 3); ?></td>
                                <td><?= htmlspecialchars($size['unit']); ?></td>
                                <td>
                                    <span class="badge <?= $size['type'] === 'liquid' ? 'bg-info' : 'bg-warning text-dark'; ?>">
                                        <?= ucfirst($size['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($size['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="bottle_size_edit.php?id=<?= $size['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </a>
                                        <?php if (hasPermission('manufacturing.delete')): ?>
                                            <a href="javascript:void(0)" class="btn btn-sm btn-outline-danger"
                                               onclick="confirmDelete(<?= $size['id']; ?>, '<?= htmlspecialchars($size['name']); ?>')">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No bottle sizes found.</td>
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
        if (confirm('Delete bottle size "' + name + '"? This will unlink it from any existing orders.')) {
            window.location.href = 'bottle_size_delete.php?id=' + id;
        }
    }

    $(document).ready(function () {
        if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#bottleSizesTable')) {
            $('#bottleSizesTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[3, 'asc'], [0, 'asc']],
                language: { emptyTable: 'No bottle sizes found.' }
            });
        }
    });
</script>
