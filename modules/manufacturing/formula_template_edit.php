<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

if (!hasPermission('manufacturing.view') || !hasPermission('manufacturing.formula.view_all')) {
    setAlert('danger', 'Access denied. You do not have permission to manage formula templates.');
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$template = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM manufacturing_formula_templates WHERE id = ?');
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        setAlert('danger', 'Formula template not found.');
        redirect('formula_templates.php');
    }
}

$products = [];
$productMap = [];
$productStmt = $pdo->query("SELECT id, name, sku FROM products WHERE type = 'material' ORDER BY name");
while ($row = $productStmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
    $productMap[(int)$row['id']] = $row['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $components = [];
    $seenProductIds = [];

    foreach (($_POST['components'] ?? []) as $component) {
        $productId = isset($component['product_id']) ? (int)$component['product_id'] : 0;
        if ($productId <= 0 || !isset($productMap[$productId]) || isset($seenProductIds[$productId])) {
            continue;
        }
        $seenProductIds[$productId] = true;
        $quantity = trim((string)($component['quantity'] ?? ''));
        if ($quantity !== '' && (!is_numeric($quantity) || (float)$quantity < 0)) {
            continue;
        }
        $components[] = [
            'product_id' => $productId,
            'name' => $productMap[$productId],
            'quantity' => $quantity === '' ? '' : sanitize($quantity),
            'unit' => sanitize($component['unit'] ?? ''),
            'notes' => sanitize($component['notes'] ?? ''),
        ];
    }

    if ($name === '') {
        setAlert('danger', 'Please enter a template name.');
    } elseif (!$components) {
        setAlert('danger', 'Please select at least one ingredient.');
    } else {
        try {
            $componentsJson = json_encode($components, JSON_UNESCAPED_UNICODE);
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE manufacturing_formula_templates SET name = ?, description = ?, components_json = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $description ?: null, $componentsJson, $isActive, $id]);
                setAlert('success', 'Formula template updated. Existing formulas were not changed.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO manufacturing_formula_templates (name, description, components_json, is_active, created_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description ?: null, $componentsJson, $isActive, $_SESSION['user_id'] ?? null]);
                setAlert('success', 'Formula template created.');
            }
            redirect('formula_templates.php');
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                setAlert('danger', 'A formula template with this name already exists.');
            } else {
                setAlert('danger', 'Unable to save formula template: ' . $e->getMessage());
            }
        }
    }
}

$currentComponents = [];
if (isset($_POST['components'])) {
    $currentComponents = $_POST['components'];
} elseif ($template && !empty($template['components_json'])) {
    $decoded = json_decode($template['components_json'], true);
    $currentComponents = is_array($decoded) ? $decoded : [];
}

$page_title = $id > 0 ? 'Edit Formula Template' : 'New Formula Template';
$isActiveChecked = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? isset($_POST['is_active'])
    : ($id === 0 || !empty($template['is_active']));
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2><?= htmlspecialchars($page_title); ?></h2>
        <p class="text-muted mb-0">Choose ingredients once. Default quantities are optional and can be changed after applying the template to a formula.</p>
    </div>
    <a href="formula_templates.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Templates
    </a>
</div>

<form method="post">
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-light"><h5 class="mb-0">Template Details</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Template Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? $template['name'] ?? ''); ?>" placeholder="e.g. Liquid Vitamins Base">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $isActiveChecked ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="isActive">Available when creating formulas</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($_POST['description'] ?? $template['description'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Template Ingredients</h5>
            <button type="button" class="btn btn-sm btn-primary" id="addTemplateComponent">
                <i class="fas fa-plus me-1"></i> Add Ingredient
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ingredient</th>
                            <th style="width:170px">Default Quantity</th>
                            <th style="width:130px">Unit</th>
                            <th>Notes</th>
                            <th style="width:55px"></th>
                        </tr>
                    </thead>
                    <tbody id="templateComponentsBody"></tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">Leave quantity blank when it always changes from one formula to another.</div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg px-5">
        <i class="fas fa-save me-2"></i> Save Template
    </button>
</form>

<?php require_once '../../includes/footer.php'; ?>

<script>
const availableTemplateProducts = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const savedTemplateComponents = <?= json_encode($currentComponents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const templateUnits = ['kg', 'g', 'L', 'ml', 'pcs'];
const templateComponentsBody = $('#templateComponentsBody');
let templateComponentIndex = 0;

function escapeTemplateValue(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function templateProductOptions(selectedId) {
    let html = '<option value="">Select ingredient</option>';
    availableTemplateProducts.forEach(function (product) {
        const selected = String(product.id) === String(selectedId || '') ? 'selected' : '';
        const label = escapeTemplateValue(product.name) + (product.sku ? ' (' + escapeTemplateValue(product.sku) + ')' : '');
        html += `<option value="${product.id}" ${selected}>${label}</option>`;
    });
    return html;
}

function templateUnitOptions(selectedUnit) {
    let html = '<option value="">— unit —</option>';
    templateUnits.forEach(function (unit) {
        html += `<option value="${unit}" ${unit === selectedUnit ? 'selected' : ''}>${unit}</option>`;
    });
    if (selectedUnit && !templateUnits.includes(selectedUnit)) {
        html += `<option value="${escapeTemplateValue(selectedUnit)}" selected>${escapeTemplateValue(selectedUnit)}</option>`;
    }
    return html;
}

function addTemplateComponent(data) {
    data = data || {};
    const idx = templateComponentIndex++;
    templateComponentsBody.append(`
        <tr data-index="${idx}">
            <td><select class="form-select form-select-sm template-product-select" name="components[${idx}][product_id]" required>${templateProductOptions(data.product_id)}</select></td>
            <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="components[${idx}][quantity]" value="${escapeTemplateValue(data.quantity)}" placeholder="Optional"></td>
            <td><select class="form-select form-select-sm" name="components[${idx}][unit]">${templateUnitOptions(data.unit || '')}</select></td>
            <td><input type="text" class="form-control form-control-sm" name="components[${idx}][notes]" value="${escapeTemplateValue(data.notes)}"></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-template-component"><i class="fas fa-trash"></i></button></td>
        </tr>
    `);
    const select = templateComponentsBody.find(`tr[data-index="${idx}"] .template-product-select`);
    if ($.fn.select2) select.select2({ width: '100%', placeholder: 'Search ingredients' });
}

$(document).ready(function () {
    $('#addTemplateComponent').on('click', function () { addTemplateComponent({}); });
    $(document).on('click', '.remove-template-component', function () { $(this).closest('tr').remove(); });
    if (savedTemplateComponents.length) {
        savedTemplateComponents.forEach(addTemplateComponent);
    } else {
        addTemplateComponent({});
    }
});
</script>
