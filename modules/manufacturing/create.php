<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

$old = $_POST;

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
    $selectedFormulaId = isset($_POST['formula_id']) ? (int)$_POST['formula_id'] : 0;
    $priority = $_POST['priority'] ?? 'normal';
    $batchSize = isset($_POST['batch_size']) ? floatval($_POST['batch_size']) : 0;
    $dueDate = $_POST['due_date'] ?? null;
    $orderNotes = trim($_POST['notes'] ?? '');

    $allowedPriorities = ['normal', 'rush', 'critical'];
    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'normal';
    }

    if ($customerId <= 0) {
        setAlert('danger', 'Please select the customer/provider for this manufacturing order.');
    } else {
        try {
            $pdo->beginTransaction();

            $formulaId = $selectedFormulaId;
            if (!$formulaId) {
                $formulaName = sanitize($_POST['new_formula_name'] ?? '');
                $formulaDescription = sanitize($_POST['new_formula_description'] ?? '');
                $formulaInstructions = sanitize($_POST['new_formula_instructions'] ?? '');
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

                if ($formulaName === '' || empty($components)) {
                    throw new Exception('When creating a new formula, provide a name plus at least one component.');
                }

                $componentsJson = json_encode($components, JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare("
                    INSERT INTO manufacturing_formulas 
                        (customer_id, name, description, batch_size, components_json, instructions) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $customerId,
                    $formulaName,
                    $formulaDescription,
                    $batchSize,
                    $componentsJson,
                    $formulaInstructions
                ]);
                $formulaId = $pdo->lastInsertId();
            }

            if (!$formulaId) {
                throw new Exception('A provider formula is required.');
            }

            $orderNumber = generateUniqueId('MAN');
            $createdBy = $_SESSION['user_id'] ?? null;
            $orderStmt = $pdo->prepare("
                INSERT INTO manufacturing_orders 
                    (order_number, customer_id, formula_id, batch_size, due_date, priority, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $orderStmt->execute([
                $orderNumber,
                $customerId,
                $formulaId,
                $batchSize,
                $dueDate ?: null,
                $priority,
                $orderNotes,
                $createdBy
            ]);
            $orderId = $pdo->lastInsertId();

            $stepStmt = $pdo->prepare("
                INSERT INTO manufacturing_order_steps (manufacturing_order_id, step_key, label) 
                VALUES (?, ?, ?)
            ");
            foreach (manufacturing_get_step_definitions() as $stepKey => $stepMeta) {
                $stepStmt->execute([$orderId, $stepKey, $stepMeta['label']]);
            }

            $pdo->commit();
            setAlert('success', "Manufacturing order {$orderNumber} created and staged for getting, preparing, and delivery.");
            logActivity("Created manufacturing order {$orderNumber}", ['order_id' => $orderId]);
            header("Location: order.php?id={$orderId}");
            exit;
        } catch (Exception $exception) {
            $pdo->rollBack();
            setAlert('danger', 'Unable to create order: ' . $exception->getMessage());
        }
    }
}

$page_title = 'Create Manufacturing Order';
require_once '../../includes/header.php';

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[] = $row;
    }
}

$priorities = ['normal' => 'Normal', 'rush' => 'Rush', 'critical' => 'Critical'];
$selectedProvider = $old['customer_id'] ?? '';
$selectedFormulaId = $old['formula_id'] ?? '';

?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2>Create Manufacturing Order</h2>
        <p class="text-muted mb-0">Choose a provider formula, stage the multi-phase manufacturing workflow, and automatically emit Excel/PDF handover files for every team in the chain.</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to manufacturing dashboard
    </a>
</div>

<div class="alert alert-info mb-4">
    <strong>Workflow complexity</strong>: every order travels through sourcing → receipt → preparation → quality → packaging → dispatch → delivery. Use this form to select a catalog-based formula, capture components for each step, and rely on the Excel/PDF handoff generated at each save to inform the downstream teams.
    <div class="mt-2 small">
        <a href="instructions.html" target="_blank" class="text-decoration-underline text-info">Click here</a> for a full user guide and examples of the exported documents.
    </div>
</div>

<form method="post">
    <div class="card mb-4">
        <div class="card-header">Order Fundamentals</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Provider / Customer</label>
                    <select class="form-select" name="customer_id" id="customer_id" required>
                        <option value="">Select provider</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" <?php echo $selectedProvider == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Priority</label>
                    <select class="form-select" name="priority">
                        <?php foreach ($priorities as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo (isset($old['priority']) && $old['priority'] === $value) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Due date</label>
                    <input type="date" class="form-control" name="due_date" value="<?php echo htmlspecialchars($old['due_date'] ?? ''); ?>">
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-6">
                    <label class="form-label">Batch size</label>
                    <input step="0.01" min="0" type="number" class="form-control" name="batch_size" value="<?php echo htmlspecialchars($old['batch_size'] ?? ''); ?>" placeholder="Total units to produce">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Order notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header">Provider Formula</div>
                <div class="card-body">
                    <p class="text-muted small">Each provider maintains unique formulas for their orders. Pick one to reuse their ratios, or build a bespoke version using the builder on the right.</p>
                    <div class="mb-3">
                        <label class="form-label">Existing formula</label>
                        <select class="form-select" name="formula_id" id="formula_id" disabled>
                            <option value="">Select provider first</option>
                        </select>
                    </div>
                    <div id="formulaPreview" class="border rounded p-3 bg-light text-muted">
                        <small>Select a provider to preview their formulas here.</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header">New formula builder (optional)</div>
                <div class="card-body">
                    <p class="text-muted small">Define a tailored formula for this order. Components can be reused later by saving and assigning the resulting formula.</p>
                    <?php if (empty($products)): ?>
                        <div class="alert alert-warning small mb-3">
                            No catalog products exist yet. Create the raw materials or finished goods under <strong>Products</strong> before building a formula.
                        </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-2" id="componentsTable">
                            <thead>
                                <tr>
                                    <th>Component (Product)</th>
                                    <th>Quantity / Ratio</th>
                                    <th>Unit</th>
                                    <th>Notes</th>
                                    <th style="width:48px;"></th>
                                </tr>
                            </thead>
                            <tbody id="componentsBody"></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addComponentRow" <?php echo empty($products) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plus me-1"></i> Add component
                    </button>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">Formula description</label>
                            <textarea class="form-control" name="new_formula_description" rows="2"><?php echo htmlspecialchars($old['new_formula_description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mixing instructions</label>
                            <textarea class="form-control" name="new_formula_instructions" rows="2"><?php echo htmlspecialchars($old['new_formula_instructions'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Formula name</label>
                        <input type="text" class="form-control" name="new_formula_name" value="<?php echo htmlspecialchars($old['new_formula_name'] ?? ''); ?>" placeholder="E.g. Provider X - Dry Blend">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-flask me-1"></i>
            Stage manufacturing order
        </button>
    </div>
</form>

<?php require_once '../../includes/footer.php'; ?>

<script>
    const providerSelect = $('#customer_id');
    const formulaSelect = $('#formula_id');
    const formulaPreview = $('#formulaPreview');
    const componentsBody = $('#componentsBody');
    const oldComponents = <?php echo json_encode($old['components'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const availableProducts = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
    const addComponentButton = $('#addComponentRow');
    let componentIndex = 0;
    let loadedFormulas = [];
    let pendingFormulaSelection = <?php echo json_encode($selectedFormulaId ?: ''); ?>;

    function escapeForAttr(value) {
        if (!value) {
            return '';
        }
        return value.replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
    }

    function renderProductOptions(selectedId = '') {
        if (!availableProducts.length) {
            return '<option value="">No catalog products</option>';
        }

        let html = '<option value="">Select product</option>';
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
        if (!select || hiddenName.length === 0) {
            return;
        }
        const selectedOption = select.options[select.selectedIndex];
        if (select.value && selectedOption) {
            hiddenName.val((selectedOption.dataset.name || selectedOption.textContent || '').trim());
        } else if (!hiddenName.val()) {
            hiddenName.val('');
        }
    }

    function addComponentRow(data = {}) {
        if (!availableProducts.length) {
            return;
        }

        const idx = componentIndex++;
        const quantity = escapeForAttr(data.quantity || '');
        const unit = escapeForAttr(data.unit || '');
        const notes = escapeForAttr(data.notes || '');
        const optionsHtml = renderProductOptions(data.product_id || '');
        const nameValue = escapeForAttr(data.name || '');
        const selectDisabled = availableProducts.length ? '' : 'disabled';

        const row = `
            <tr data-index="${idx}">
                <td>
                    <select class="form-select form-select-sm component-product" name="components[${idx}][product_id]" required ${selectDisabled}>
                        ${optionsHtml}
                    </select>
                    <input type="hidden" class="component-name" name="components[${idx}][name]" value="${nameValue}">
                </td>
                <td><input type="text" class="form-control form-control-sm" name="components[${idx}][quantity]" value="${quantity}" placeholder="e.g. 5 kg"></td>
                <td><input type="text" class="form-control form-control-sm" name="components[${idx}][unit]" value="${unit}" placeholder="Unit"></td>
                <td><input type="text" class="form-control form-control-sm" name="components[${idx}][notes]" value="${notes}" placeholder="Notes"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-component">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        componentsBody.append(row);
        const addedRow = componentsBody.find(`tr[data-index="${idx}"]`);
        syncComponentName(addedRow);
    }

    function renderFormulaPreview(formula) {
        if (!formula) {
            formulaPreview.html('<small class="text-muted">Choose a formula to preview its components and instructions.</small>');
            return;
        }
        let html = '<div class="fw-bold mb-1">' + escapeForAttr(formula.name || 'Untitled formula') + '</div>';
        html += formula.description ? '<p class="text-muted mb-1">' + escapeForAttr(formula.description) + '</p>' : '';
        html += '<div class="text-muted small mb-1">Batch size: ' + (formula.batch_size || 'N/A') + '</div>';
        if (formula.components && formula.components.length) {
            html += '<div class="table-responsive mb-1"><table class="table table-sm table-borderless mb-0">';
            html += '<tbody>';
            formula.components.forEach(component => {
                html += '<tr><td class="text-dark fw-semibold">' + escapeForAttr(component.name || 'Component') + '</td>';
                html += '<td>' + escapeForAttr(component.quantity || '') + '</td>';
                html += '<td>' + escapeForAttr(component.unit || '') + '</td>';
                html += '<td>' + escapeForAttr(component.notes || '') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted small mb-1">No components saved for this formula.</p>';
        }
        formulaPreview.html(html);
    }

    function loadFormulasForProvider(providerId) {
        if (!providerId) {
            formulaSelect.html('<option value="">Select provider first</option>');
            formulaSelect.prop('disabled', true);
            formulaPreview.html('<small class="text-muted">Choose a provider to reveal their saved formulas.</small>');
            return;
        }
        formulaSelect.prop('disabled', true).html('<option>Loading formulas…</option>');
        $.getJSON('../../ajax/get_manufacturing_formulas.php', { provider_id: providerId })
            .done(function (resp) {
                if (!resp.success) {
                    formulaSelect.html('<option value="">Unable to load formulas</option>');
                    formulaPreview.html('<small class="text-danger">Unable to load formulas for this provider.</small>');
                    return;
                }
                loadedFormulas = resp.formulas || [];
                let options = '<option value="">Use provider formula (optional)</option>';
                loadedFormulas.forEach(formula => {
                    options += `<option value="${formula.id}">${escapeForAttr(formula.name)}</option>`;
                });
                formulaSelect.html(options);
                formulaSelect.prop('disabled', loadedFormulas.length === 0);
                if (pendingFormulaSelection) {
                    formulaSelect.val(pendingFormulaSelection);
                    const match = loadedFormulas.find(f => String(f.id) === String(pendingFormulaSelection));
                    if (match) {
                        renderFormulaPreview(match);
                    }
                } else {
                    formulaPreview.html('<small class="text-muted">Select a formula to preview components.</small>');
                }
            })
            .fail(function () {
                formulaSelect.html('<option value="">Unable to load formulas</option>');
                formulaPreview.html('<small class="text-danger">Unable to load formulas for this provider.</small>');
            });
    }

    $(document).on('click', '.remove-component', function () {
        $(this).closest('tr').remove();
    });

    $(document).on('change', '.component-product', function () {
        const row = $(this).closest('tr');
        syncComponentName(row);
    });

    $(document).ready(function () {
        addComponentButton.on('click', function () {
            if (!availableProducts.length) {
                return;
            }
            addComponentRow();
        });

        providerSelect.on('change', function () {
            pendingFormulaSelection = '';
            loadFormulasForProvider($(this).val());
        });

        formulaSelect.on('change', function () {
            const selectedId = $(this).val();
            const selectedFormula = loadedFormulas.find(formula => String(formula.id) === String(selectedId));
            renderFormulaPreview(selectedFormula);
        });

        if (availableProducts.length) {
            if (oldComponents.length) {
                oldComponents.forEach(component => addComponentRow(component));
            } else {
                addComponentRow();
                addComponentRow();
            }
        } else {
            componentsBody.html('<tr><td colspan="5" class="text-center text-muted small py-3">Add catalog products before building or customizing a formula.</td></tr>');
        }

        if (providerSelect.val()) {
            loadFormulasForProvider(providerSelect.val());
        }
    });
</script>
