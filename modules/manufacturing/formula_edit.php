<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}
if (!hasPermission('manufacturing.formula.view_all')) {
    setAlert('danger', 'Access denied. You do not have permission to view formulas.');
    redirect('../../dashboard.php');
}
if (empty($_SESSION['formula_unlocked'])) {
    setAlert('info', 'Please unlock formulas first.');
    redirect('formulas.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$formula = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM manufacturing_formulas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $formula = $result->fetch_assoc();
    if (!$formula) {
        setAlert('danger', 'Formula not found.');
        redirect('formulas.php');
    }
}

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($customerRow = $customerResult->fetch_assoc()) {
        $customers[] = $customerRow;
    }
}

$products = [];
$productResult = $conn->query("SELECT id, name, sku FROM products ORDER BY name");
$productMap = [];
if ($productResult) {
    while ($productRow = $productResult->fetch_assoc()) {
        $products[] = $productRow;
        $productMap[$productRow['id']] = $productRow['name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $productId  = isset($_POST['product_id']) && $_POST['product_id'] !== '' ? (int)$_POST['product_id'] : null;
    $name = sanitize($_POST['name'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['liquid', 'powder']) ? $_POST['type'] : null;
    $description = sanitize($_POST['description'] ?? '');
    $batchSize = isset($_POST['batch_size']) ? floatval($_POST['batch_size']) : 0;
    $batchUnit = sanitize($_POST['batch_unit'] ?? '');
    $instructions = sanitize($_POST['instructions'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    // Preparation fields
    $prepFieldNames  = $_POST['prep_field_names']  ?? [];
    $prepFieldValues = $_POST['prep_field_values'] ?? [];
    $prepFields = [];
    if (is_array($prepFieldNames)) {
        foreach ($prepFieldNames as $i => $fn) {
            $fn = trim(sanitize($fn));
            if ($fn !== '') {
                $prepFields[] = [
                    'field_name'  => $fn,
                    'field_value' => sanitize($prepFieldValues[$i] ?? ''),
                ];
            }
        }
    }
    $preparationFieldsJson = !empty($prepFields) ? json_encode($prepFields, JSON_UNESCAPED_UNICODE) : null;

    $rawComponents = $_POST['components'] ?? [];
    $components = [];
    if (!empty($rawComponents) && is_array($rawComponents)) {
        foreach ($rawComponents as $componentRow) {
            $componentProductId = isset($componentRow['product_id']) ? (int)$componentRow['product_id'] : 0;
            $componentName = '';
            if ($componentProductId > 0 && isset($productMap[$componentProductId])) {
                $componentName = $productMap[$componentProductId];
            } else {
                $componentName = sanitize($componentRow['name'] ?? '');
            }

            if ($componentName === '') {
                continue;
            }

            $components[] = [
                'product_id' => $componentProductId > 0 ? $componentProductId : null,
                'name' => $componentName,
                'quantity' => sanitize($componentRow['quantity'] ?? ''),
                'unit' => sanitize($componentRow['unit'] ?? ''),
                'notes' => sanitize($componentRow['notes'] ?? ''),
            ];
        }
    }

    if ($customerId <= 0) {
        setAlert('danger', 'Please select a provider.');
    } elseif ($name === '') {
        setAlert('danger', 'Please enter a formula name.');
    } elseif (empty($components)) {
        setAlert('danger', 'Please add at least one component.');
    } else {
        $componentsJson = json_encode($components, JSON_UNESCAPED_UNICODE);

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE manufacturing_formulas
                    SET customer_id = ?, product_id = ?, name = ?, type = ?, description = ?, batch_size = ?, batch_unit = ?,
                        components_json = ?, instructions = ?, preparation_fields_json = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$customerId, $productId, $name, $type, $description, $batchSize, $batchUnit,
                                $componentsJson, $instructions, $preparationFieldsJson, $isActive, $id]);
                setAlert('success', 'Formula updated successfully.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO manufacturing_formulas
                        (customer_id, product_id, name, type, description, batch_size, batch_unit,
                         components_json, instructions, preparation_fields_json, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$customerId, $productId, $name, $type, $description, $batchSize, $batchUnit,
                                $componentsJson, $instructions, $preparationFieldsJson, $isActive]);
                setAlert('success', 'Formula created successfully.');
            }
            redirect('formulas.php');
        } catch (Exception $e) {
            setAlert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

$page_title = $id > 0 ? 'Edit Formula' : 'New Formula';
require_once '../../includes/header.php';

$currentComponents = [];
if ($formula && !empty($formula['components_json'])) {
    $currentComponents = json_decode($formula['components_json'], true) ?: [];
} elseif (isset($_POST['components'])) {
    $currentComponents = $_POST['components'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $page_title; ?></h2>
    <a href="formulas.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to List
    </a>
</div>

<form method="post">
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="mb-0">General Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Provider <span class="text-danger">*</span></label>
                            <select class="form-select select2" name="customer_id" required>
                                <option value="">Select provider</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id']; ?>" <?= (isset($formula['customer_id']) && $formula['customer_id'] == $customer['id']) || (isset($_POST['customer_id']) && $_POST['customer_id'] == $customer['id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Linked Product</label>
                            <select class="form-select select2" name="product_id">
                                <option value="">— No linked product —</option>
                                <?php
                                $selProductId = $formula['product_id'] ?? $_POST['product_id'] ?? '';
                                foreach ($products as $p): ?>
                                    <option value="<?= $p['id']; ?>" <?= (string)$selProductId === (string)$p['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($p['name']) . ($p['sku'] ? ' (' . $p['sku'] . ')' : ''); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Formula Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($formula['name'] ?? $_POST['name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="type">
                                <option value="">— Select type —</option>
                                <?php
                                $selType = $formula['type'] ?? $_POST['type'] ?? '';
                                foreach (['liquid' => 'Liquid', 'powder' => 'Powder'] as $val => $label): ?>
                                    <option value="<?= $val; ?>" <?= $selType === $val ? 'selected' : ''; ?>><?= $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($formula['description'] ?? $_POST['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Default Batch Size</label>
                            <input type="number" step="0.01" class="form-control" name="batch_size" value="<?= htmlspecialchars($formula['batch_size'] ?? $_POST['batch_size'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Batch Unit</label>
                            <input type="text" class="form-control" name="batch_unit" placeholder="e.g. kg, L, pcs"
                                   value="<?= htmlspecialchars($formula['batch_unit'] ?? $_POST['batch_unit'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= (!isset($formula) || (isset($formula['is_active']) && $formula['is_active'])) || (isset($_POST['is_active'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isActive">
                                    Active Formula
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Ingredients / Components</h5>
                    <button type="button" class="btn btn-sm btn-primary" id="addComponent">
                        <i class="fas fa-plus me-1"></i> Add Item
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product (from Catalog)</th>
                                    <th style="width: 150px;">Quantity</th>
                                    <th style="width: 120px;">Unit</th>
                                    <th>Notes</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="componentsBody">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Preparation &amp; Mixing Fields</h5>
                    <button type="button" class="btn btn-sm btn-outline-success" id="addPrepField">
                        <i class="fas fa-plus me-1"></i> Add Custom Field
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="prepFieldsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 35%;">Field Name</th>
                                    <th>Value</th>
                                    <th style="width: 80px;"></th>
                                </tr>
                            </thead>
                            <tbody id="prepFieldsBody">
                                <?php
                                $standardPrepFields = ['pH', 'TDS', 'Temperature', 'Humidity'];
                                // Build saved map: field_name => field_value
                                $savedPrepMap = [];
                                $savedPrepOrder = [];
                                if (!empty($formula['preparation_fields_json'])) {
                                    $decoded = json_decode($formula['preparation_fields_json'], true);
                                    if (is_array($decoded)) {
                                        foreach ($decoded as $pf) {
                                            $savedPrepMap[$pf['field_name']] = $pf['field_value'] ?? '';
                                            $savedPrepOrder[] = $pf['field_name'];
                                        }
                                    }
                                }
                                // Use saved order if available, otherwise defaults
                                $displayPrepFields = !empty($savedPrepOrder) ? $savedPrepOrder : $standardPrepFields;
                                foreach ($displayPrepFields as $fieldName):
                                    $isStandard = in_array($fieldName, $standardPrepFields);
                                    $fieldValue = $savedPrepMap[$fieldName] ?? '';
                                ?>
                                <tr class="prep-field-row <?= $isStandard ? 'standard-field' : 'custom-field'; ?>">
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-light px-2">
                                                <i class="fas fa-flask text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control form-control-sm"
                                                   name="prep_field_names[]"
                                                   value="<?= htmlspecialchars($fieldName); ?>"
                                                   placeholder="Field name"
                                                   <?= $isStandard ? 'readonly' : ''; ?>>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm"
                                               name="prep_field_values[]"
                                               value="<?= htmlspecialchars($fieldValue); ?>"
                                               placeholder="Enter value">
                                    </td>
                                    <td class="text-center">
                                        <?php if (!$isStandard): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-prep-field">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Standard</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-2">
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Standard fields</strong> (pH, TDS, Temperature, Humidity) are always included. Add custom fields for any additional measurements specific to this formula.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Instructions</h5>
                </div>
                <div class="card-body">
                    <textarea class="form-control" name="instructions" rows="6" placeholder="Preparation steps, safety notes, etc."><?= htmlspecialchars($formula['instructions'] ?? $_POST['instructions'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="mb-5">
                <button type="submit" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-save me-2"></i> Save Formula
                </button>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-light p-3 position-sticky" style="top: 20px;">
                <h6>Formula Logic</h6>
                <p class="small text-muted mb-0">
                    A formula defines the recipe for a product. It helps in:
                </p>
                <hr>
                <ul class="small">
                    <li><strong>Consistency:</strong> Ensures every batch is made the same way.</li>
                    <li><strong>Inventory:</strong> Linking components to catalog products allows the system to track usage.</li>
                    <li><strong>Batch Sizes:</strong> Set a default size that can be adjusted when creating an order.</li>
                </ul>
            </div>
        </div>
    </div>
</form>

<?php require_once '../../includes/footer.php'; ?>

<script>
    const availableProducts = <?= json_encode($products); ?>;
    const oldComponents = <?= json_encode($currentComponents); ?>;
    const componentsBody = $('#componentsBody');
    let componentIndex = 0;

    function escapeForAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderProductOptions(selectedId = '') {
        let html = '<option value="">Manual Entry (No track)</option>';
        availableProducts.forEach(product => {
            const label = escapeForAttr(product.name) + (product.sku ? ' (' + escapeForAttr(product.sku) + ')' : '');
            const selected = selectedId && String(product.id) === String(selectedId) ? 'selected' : '';
            html += `<option value="${product.id}" data-name="${escapeForAttr(product.name)}" ${selected}>${label}</option>`;
        });
        return html;
    }

    function syncComponentName(row) {
        const select = row.find('.component-product')[0];
        const hiddenName = row.find('.component-name');
        if (!select || hiddenName.length === 0) return;
        
        const selectedOption = select.options[select.selectedIndex];
        if (select.value && selectedOption) {
            hiddenName.val((selectedOption.dataset.name || selectedOption.textContent || '').trim());
        }
    }

    function addComponentRow(data = {}) {
        const idx = componentIndex++;
        const quantity = escapeForAttr(data.quantity || '');
        const unit = escapeForAttr(data.unit || '');
        const notes = escapeForAttr(data.notes || '');
        const nameValue = escapeForAttr(data.name || '');
        const optionsHtml = renderProductOptions(data.product_id || '');

        const row = `
            <tr data-index="${idx}">
                <td>
                    <select class="form-select form-select-sm component-product select2-ingredients" name="components[${idx}][product_id]">
                        ${optionsHtml}
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1 component-name ${data.product_id ? 'd-none' : ''}" 
                           name="components[${idx}][name]" value="${nameValue}" placeholder="Custom item name">
                </td>
                <td><input type="text" class="form-control form-control-sm" name="components[${idx}][quantity]" value="${quantity}" placeholder="0.00"></td>
                <td><input type="text" class="form-control form-control-sm" name="components[${idx}][unit]" value="${unit}" placeholder="kg, L, pcs"></td>
                <td><input type="text" class="form-control form-control-sm" name="components[${idx}][notes]" value="${notes}"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-component">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        componentsBody.append(row);
        
        const addedRow = componentsBody.find(`tr[data-index="${idx}"]`);
        
        // Initialize Select2 for the new row
        if ($.fn.select2) {
            addedRow.find('.select2-ingredients').select2({
                width: '100%',
                placeholder: 'Search product...'
            });
        }

        syncComponentName(addedRow);
    }

    $(document).ready(function () {
        if ($.fn.select2) {
            $('.select2').select2({
                width: '100%'
            });
        }

        $('#addComponent').on('click', function () {
            addComponentRow();
        });

        $(document).on('click', '.remove-component', function () {
            $(this).closest('tr').remove();
        });

        $(document).on('change', '.component-product', function () {
            const row = $(this).closest('tr');
            const nameInput = row.find('.component-name');
            if ($(this).val()) {
                nameInput.addClass('d-none');
                syncComponentName(row);
            } else {
                nameInput.removeClass('d-none').val('');
            }
        });

        if (oldComponents.length) {
            oldComponents.forEach(component => addComponentRow(component));
        } else {
            addComponentRow();
        }

        // --- Preparation Fields ---
        $('#addPrepField').on('click', function () {
            const row = $(`
                <tr class="prep-field-row custom-field">
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light px-2">
                                <i class="fas fa-flask text-muted"></i>
                            </span>
                            <input type="text" class="form-control form-control-sm"
                                   name="prep_field_names[]"
                                   placeholder="Field name (e.g. Viscosity)">
                        </div>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                               name="prep_field_values[]"
                               placeholder="Enter value">
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-prep-field">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>
                </tr>
            `);
            $('#prepFieldsBody').append(row);
            row.find('input').first().focus();
        });

        $(document).on('click', '.remove-prep-field', function () {
            $(this).closest('.prep-field-row').remove();
        });
    });
</script>
