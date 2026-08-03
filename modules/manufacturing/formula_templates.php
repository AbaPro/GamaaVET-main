<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

if (!hasPermission('manufacturing.view') || !hasPermission('manufacturing.formula.view_all')) {
    setAlert('danger', 'Access denied. You do not have permission to view formula templates.');
    redirect('../../dashboard.php');
}
if (empty($_SESSION['formula_unlocked'])) {
    setAlert('info', 'Please unlock formulas first.');
    redirect('formulas.php');
}
if (!manufacturing_table_exists($conn, 'manufacturing_formula_templates')) {
    setAlert('warning', 'Formula templates are not installed yet. Apply migration 20260803_create_manufacturing_formula_templates.sql.');
    redirect('formulas.php');
}

$search = sanitize($_GET['search'] ?? '');
$whereSql = '';
$params = [];
if ($search !== '') {
    $whereSql = 'WHERE name LIKE ? OR description LIKE ?';
    $searchLike = '%' . $search . '%';
    $params = [$searchLike, $searchLike];
}

$stmt = $pdo->prepare("
    SELECT id, name, description, components_json, is_active, created_at, updated_at
    FROM manufacturing_formula_templates
    {$whereSql}
    ORDER BY name ASC
");
$stmt->execute($params);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Formula Templates';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
    <div>
        <h2>Formula Templates</h2>
        <p class="text-muted mb-0">Save a reusable ingredient list, then copy it into as many formulas as you need.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="formulas.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Formulas
        </a>
        <a href="formula_template_edit.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i> New Template
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3" method="get">
            <div class="col-md-9">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" placeholder="Search templates" value="<?= htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                <a href="formula_templates.php" class="btn btn-outline-secondary flex-grow-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="formulaTemplatesTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Ingredients</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($templates): ?>
                        <?php foreach ($templates as $template): ?>
                            <?php
                            $components = json_decode($template['components_json'] ?? '[]', true);
                            $components = is_array($components) ? $components : [];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($template['name']); ?></strong>
                                    <?php if (!empty($template['description'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($template['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= count($components); ?> item<?= count($components) === 1 ? '' : 's'; ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= $template['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?= $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($template['updated_at']))); ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <?php if ($template['is_active']): ?>
                                            <a href="formula_edit.php?template_id=<?= (int)$template['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-copy me-1"></i> New Formula
                                            </a>
                                        <?php endif; ?>
                                        <a href="formula_template_edit.php?id=<?= (int)$template['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </a>
                                        <?php if (hasPermission('manufacturing.delete')): ?>
                                            <form method="post" action="formula_template_delete.php" class="d-inline" onsubmit="return confirm('Delete this formula template? Existing formulas will not be changed.');">
                                                <input type="hidden" name="id" value="<?= (int)$template['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash me-1"></i> Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No formula templates found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function () {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#formulaTemplatesTable')) {
        $('#formulaTemplatesTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']]
        });
    }
});
</script>
