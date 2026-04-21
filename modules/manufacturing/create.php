<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

if (!hasPermission('manufacturing.orders.create')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$old = $_POST;

$products = [];
$productMap = [];
$productResult = $conn->query("SELECT id, name, sku FROM products WHERE type = 'material' ORDER BY name");
if ($productResult) {
    while ($productRow = $productResult->fetch_assoc()) {
        $products[] = $productRow;
        $productMap[$productRow['id']] = $productRow['name'];
    }
}

// Full product map (all types) for resolving saved names on existing components
$allProductMapResult = $conn->query("SELECT id, name FROM products");
if ($allProductMapResult) {
    while ($row = $allProductMapResult->fetch_assoc()) {
        if (!isset($productMap[$row['id']])) {
            $productMap[$row['id']] = $row['name'];
        }
    }
}

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
    $customerId         = isset($_POST['customer_id'])        ? (int)$_POST['customer_id']        : 0;
    $productId          = isset($_POST['product_id'])         ? (int)$_POST['product_id']         : 0;
    $selectedFormulaId  = isset($_POST['formula_id'])         ? (int)$_POST['formula_id']         : 0;
    $locationId         = isset($_POST['location_id'])        ? (int)$_POST['location_id']        : 0;
    $bottleSizeId       = isset($_POST['bottle_size_id']) && $_POST['bottle_size_id'] !== '' ? (int)$_POST['bottle_size_id'] : null;
    $numberOfBottles    = isset($_POST['number_of_bottles'])  ? (int)$_POST['number_of_bottles']  : 0;
    $packagingOptionId  = isset($_POST['packaging_option_id']) && $_POST['packaging_option_id'] !== '' ? (int)$_POST['packaging_option_id'] : null;
    $priority           = $_POST['priority'] ?? 'normal';
    $batchSize          = isset($_POST['batch_size'])         ? floatval($_POST['batch_size'])     : 0;
    $dueDate            = $_POST['due_date'] ?? null;
    $orderNotes         = trim($_POST['notes'] ?? '');

    $allowedPriorities = ['normal', 'rush', 'critical'];
    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'normal';
    }

    if ($customerId <= 0) {
        setAlert('danger', 'Please select the customer for this manufacturing order.');
    } elseif ($productId <= 0) {
        setAlert('danger', 'Please select the final product for this manufacturing order.');
    } elseif ($locationId <= 0) {
        setAlert('danger', 'Please select a location for this manufacturing order.');
    } elseif (!$selectedFormulaId) {
        setAlert('danger', 'Please select a formula for this manufacturing order.');
    } elseif ($bottleSizeId === null) {
        setAlert('danger', 'Please select a bottle size.');
    } elseif ($numberOfBottles <= 0) {
        setAlert('danger', 'Please enter the number of bottles (must be at least 1).');
    } else {
        try {
            $pdo->beginTransaction();

            $formulaId = $selectedFormulaId;

            if (!$formulaId) {
                throw new Exception('A formula is required.');
            }

            // Fetch bottle size value to compute batch_size
            $bsStmt = $pdo->prepare("SELECT size, unit FROM bottle_sizes WHERE id = ?");
            $bsStmt->execute([$bottleSizeId]);
            $bsRow = $bsStmt->fetch(PDO::FETCH_ASSOC);
            $bottleSizeValue = $bsRow ? (float)$bsRow['size'] : 0;
            $computedBatchSize = $numberOfBottles * $bottleSizeValue;

            $orderNumber = generateUniqueId('MAN');
            $createdBy = $_SESSION['user_id'] ?? null;
            $orderStmt = $pdo->prepare("
                INSERT INTO manufacturing_orders
                    (order_number, customer_id, product_id, bottle_size_id, number_of_bottles, packaging_option_id,
                     formula_id, location_id, batch_size, due_date, priority, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $orderStmt->execute([
                $orderNumber,
                $customerId,
                $productId ?: null,
                $bottleSizeId,
                $numberOfBottles,
                $packagingOptionId,
                $formulaId,
                $locationId,
                $computedBatchSize,
                $dueDate ?: null,
                $priority,
                $orderNotes,
                $createdBy
            ]);
            $orderId = $pdo->lastInsertId();

            // Seed sourcing components from formula
            $formulaStmt = $pdo->prepare("SELECT components_json, batch_size AS formula_batch_size FROM manufacturing_formulas WHERE id = ?");
            $formulaStmt->execute([$formulaId]);
            $formulaRow = $formulaStmt->fetch(PDO::FETCH_ASSOC);
            $formulaComponents = json_decode($formulaRow['components_json'] ?? '[]', true) ?: [];
            $formulaBatchSize = (float)($formulaRow['formula_batch_size'] ?? 1);
            if ($formulaBatchSize <= 0) $formulaBatchSize = 1;
            $productionRatio = $computedBatchSize > 0 ? ($computedBatchSize / $formulaBatchSize) : 1;

            // Check if the 'source' column exists (added in migration 20260420)
            $hasSourceCol = $conn->query("SHOW COLUMNS FROM manufacturing_sourcing_components LIKE 'source'")->num_rows > 0;

            $sourcingStmt = $hasSourceCol
                ? $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id, source, formula_component_index, product_id, component_name, required_quantity, unit, notes) VALUES (?, 'formula', ?, ?, ?, ?, ?, ?)")
                : $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id, formula_component_index, product_id, component_name, required_quantity, unit, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");

            foreach ($formulaComponents as $idx => $component) {
                $baseQty = (float)($component['quantity'] ?? 0);
                $requiredQty = round($baseQty * $productionRatio, 4);
                $params = $hasSourceCol
                    ? [$orderId, $idx, $component['product_id'] ?? null, $component['name'] ?? '', $requiredQty, $component['unit'] ?? '', $component['notes'] ?? '']
                    : [$orderId, $idx, $component['product_id'] ?? null, $component['name'] ?? '', $requiredQty, $component['unit'] ?? '', $component['notes'] ?? ''];
                $sourcingStmt->execute($params);
            }

            // Seed sourcing components from packaging option (qty * number_of_bottles)
            if ($packagingOptionId) {
                $pkgItemsStmt = $pdo->prepare("
                    SELECT poi.product_id, p.name AS product_name, poi.quantity, poi.unit, poi.notes
                    FROM packaging_option_items poi
                    JOIN products p ON p.id = poi.product_id
                    WHERE poi.packaging_option_id = ?
                ");
                $pkgItemsStmt->execute([$packagingOptionId]);
                $pkgItems = $pkgItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                $pkgSourcingStmt = $hasSourceCol
                    ? $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id, source, formula_component_index, product_id, component_name, required_quantity, unit, notes) VALUES (?, 'packaging', ?, ?, ?, ?, ?, ?)")
                    : $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id, formula_component_index, product_id, component_name, required_quantity, unit, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");

                foreach ($pkgItems as $pkgIdx => $pkgItem) {
                    $pkgRequiredQty = round((float)$pkgItem['quantity'] * $numberOfBottles, 4);
                    $pkgSourcingStmt->execute([
                        $orderId,
                        $pkgIdx,
                        $pkgItem['product_id'],
                        $pkgItem['product_name'],
                        $pkgRequiredQty,
                        $pkgItem['unit'] ?? '',
                        $pkgItem['notes'] ?? '',
                    ]);
                }
            }

            $stepStmt = $pdo->prepare("
                INSERT INTO manufacturing_order_steps (manufacturing_order_id, step_key, label)
                VALUES (?, ?, ?)
            ");
            foreach (manufacturing_get_step_definitions() as $stepKey => $stepMeta) {
                $stepStmt->execute([$orderId, $stepKey, $stepMeta['label']]);
            }

            $pdo->commit();
            setAlert('success', "Manufacturing order {$orderNumber} created.");
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
$selectedCustomer = $old['customer_id'] ?? '';
$selectedFormulaId = $old['formula_id'] ?? '';

// Build bottle sizes JSON map for JS batch size calculation
$bottleSizesMap = [];
foreach ($bottleSizes as $bs) {
    $bottleSizesMap[$bs['id']] = ['size' => (float)$bs['size'], 'unit' => $bs['unit']];
}
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2>Create Manufacturing Order</h2>
        <p class="text-muted mb-0">Select a customer formula, define production quantities, and stage the multi-phase manufacturing workflow.</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to manufacturing dashboard
    </a>
</div>

<form method="post">
    <div class="card mb-4">
        <div class="card-header">Order Fundamentals</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Customer <span class="text-danger">*</span></label>
                    <select class="form-select select2" name="customer_id" id="customer_id" required>
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" <?php echo $selectedCustomer == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo e($customer['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location <span class="text-danger">*</span></label>
                    <select class="form-select" name="location_id" required>
                        <option value="">Select location</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>" <?php echo (isset($old['location_id']) && $old['location_id'] == $location['id']) ? 'selected' : ''; ?>>
                                <?php echo e($location['name']); ?> - <?php echo e($location['address']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select class="form-select" name="priority">
                        <?php foreach ($priorities as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo (isset($old['priority']) && $old['priority'] === $value) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Due date</label>
                    <input type="date" class="form-control" name="due_date" value="<?php echo e($old['due_date'] ?? ''); ?>">
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-3">
                    <label class="form-label">Final Product <span class="text-danger">*</span></label>
                    <select class="form-select select2" name="product_id" id="product_id" required disabled>
                        <option value="">Select customer first</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bottle Size <span class="text-danger">*</span></label>
                    <select class="form-select select2" name="bottle_size_id" id="bottle_size_id" required>
                        <option value="">Select bottle size</option>
                        <?php
                        $bsLiquid = array_filter($bottleSizes, fn($b) => $b['type'] === 'liquid');
                        $bsPowder = array_filter($bottleSizes, fn($b) => $b['type'] === 'powder');
                        $selBsId  = $old['bottle_size_id'] ?? '';
                        if ($bsLiquid): ?>
                            <optgroup label="Liquid">
                                <?php foreach ($bsLiquid as $bs): ?>
                                    <option value="<?= $bs['id']; ?>"
                                            data-size="<?= $bs['size']; ?>" data-unit="<?= htmlspecialchars($bs['unit']); ?>"
                                            <?= (string)$selBsId === (string)$bs['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($bs['name']); ?> — <?= number_format($bs['size'], 3); ?> <?= htmlspecialchars($bs['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif;
                        if ($bsPowder): ?>
                            <optgroup label="Powder">
                                <?php foreach ($bsPowder as $bs): ?>
                                    <option value="<?= $bs['id']; ?>"
                                            data-size="<?= $bs['size']; ?>" data-unit="<?= htmlspecialchars($bs['unit']); ?>"
                                            <?= (string)$selBsId === (string)$bs['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($bs['name']); ?> — <?= number_format($bs['size'], 3); ?> <?= htmlspecialchars($bs['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Number of Bottles <span class="text-danger">*</span></label>
                    <input type="number" step="1" min="1" class="form-control" name="number_of_bottles" id="number_of_bottles"
                           value="<?= htmlspecialchars($old['number_of_bottles'] ?? ''); ?>" required placeholder="e.g. 500">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Total Batch Size</label>
                    <div class="input-group">
                        <input type="text" class="form-control bg-light" id="batch_size_display" readonly placeholder="Auto-computed">
                        <input type="hidden" name="batch_size" id="batch_size_hidden" value="<?= htmlspecialchars($old['batch_size'] ?? '0'); ?>">
                        <span class="input-group-text" id="batch_unit_label">—</span>
                    </div>
                    <small class="text-muted">Bottles × bottle size</small>
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-6">
                    <label class="form-label">Packaging Option</label>
                    <select class="form-select select2" name="packaging_option_id" id="packaging_option_id" disabled>
                        <option value="">— Select customer first —</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Order notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?php echo e($old['notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Customer Formula <span class="text-danger">*</span></div>
        <div class="card-body">
            <p class="text-muted small">Each customer maintains unique formulas for their orders. Select one — components will be scaled to the batch size automatically.</p>
            <div class="mb-3">
                <label class="form-label">Formula</label>
                <select class="form-select select2" name="formula_id" id="formula_id" disabled required>
                    <option value="">Select customer first</option>
                </select>
            </div>
            <div id="formulaPreview" class="border rounded p-3 bg-light text-muted">
                <small>Select a customer to preview their formulas here.</small>
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
    const customerSelect    = $('#customer_id');
    const formulaSelect     = $('#formula_id');
    const productSelect     = $('#product_id');
    const packagingSelect   = $('#packaging_option_id');
    const formulaPreview    = $('#formulaPreview');
    const bottleSizeSelect  = $('#bottle_size_id');
    const numberOfBottlesInput = $('#number_of_bottles');
    const batchSizeDisplay  = $('#batch_size_display');
    const batchSizeHidden   = $('#batch_size_hidden');
    const batchUnitLabel    = $('#batch_unit_label');

    let loadedFormulas = [];
    let pendingFormulaSelection = <?php echo json_encode($selectedFormulaId ?: ''); ?>;
    let pendingProductSelection = <?php echo json_encode($_POST['product_id'] ?? ''); ?>;

    function escapeForAttr(value) {
        if (!value) return '';
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function updateBatchSizeDisplay() {
        const selectedOpt = bottleSizeSelect.find('option:selected');
        const bottleSize  = parseFloat(selectedOpt.data('size') || 0);
        const bottleUnit  = selectedOpt.data('unit') || '—';
        const numBottles  = parseInt(numberOfBottlesInput.val() || 0);

        if (bottleSize > 0 && numBottles > 0) {
            const total = bottleSize * numBottles;
            const totalFmt = total.toFixed(3).replace(/\.?0+$/, '');
            let altStr = '';
            const u = bottleUnit.toLowerCase();
            if (u === 'ml') {
                const l = (total / 1000).toFixed(3).replace(/\.?0+$/, '');
                altStr = ' (' + l + ' L)';
            } else if (u === 'l') {
                altStr = ' (' + (total * 1000).toFixed(0) + ' ml)';
            } else if (u === 'kg') {
                altStr = ' (' + (total * 1000).toFixed(0) + ' g)';
            } else if (u === 'g') {
                const kg = (total / 1000).toFixed(3).replace(/\.?0+$/, '');
                altStr = ' (' + kg + ' kg)';
            }
            batchSizeDisplay.val(totalFmt + altStr);
            batchSizeHidden.val(totalFmt);
            batchUnitLabel.text(bottleUnit);
        } else {
            batchSizeDisplay.val('');
            batchSizeHidden.val('0');
            batchUnitLabel.text('—');
        }
    }

    function renderFormulaPreview(formula) {
        if (!formula) {
            formulaPreview.html('<small class="text-muted">Choose a formula to preview its components and instructions.</small>');
            return;
        }
        let html = '<div class="fw-bold mb-1">' + escapeForAttr(formula.name || 'Untitled formula') + '</div>';
        html += formula.description ? '<p class="text-muted mb-1">' + escapeForAttr(formula.description) + '</p>' : '';
        html += '<div class="text-muted small mb-1">Standard batch size: ' + (formula.batch_size || 'N/A') + (formula.batch_unit ? ' ' + escapeForAttr(formula.batch_unit) : '') + '</div>';
        if (formula.components && formula.components.length) {
            html += '<div class="table-responsive mb-1"><table class="table table-sm table-borderless mb-0"><tbody>';
            formula.components.forEach(function (c) {
                html += '<tr><td class="text-dark fw-semibold">' + escapeForAttr(c.name || 'Component') + '</td>';
                html += '<td>' + escapeForAttr(c.quantity || '') + '</td>';
                html += '<td>' + escapeForAttr(c.unit || '') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted small mb-1">No components saved for this formula.</p>';
        }
        formulaPreview.html(html);
    }

    function loadForCustomer(customerId) {
        if (!customerId) {
            productSelect.html('<option value="">Select customer first</option>').prop('disabled', true);
            formulaSelect.html('<option value="">Select customer first</option>').prop('disabled', true);
            packagingSelect.html('<option value="">— Select customer first —</option>').prop('disabled', true);
            formulaPreview.html('<small class="text-muted">Choose a customer to reveal their saved formulas.</small>');
            [productSelect, formulaSelect, packagingSelect].forEach(function (s) {
                if ($.fn.select2) s.trigger('change.select2');
            });
            return;
        }

        // Load final products
        productSelect.prop('disabled', true).html('<option>Loading…</option>');
        $.getJSON('../../ajax/get_final_products.php', { provider_id: customerId })
            .done(function (resp) {
                let opts = '<option value="">Select final product</option>';
                if (resp.success && resp.products.length) {
                    resp.products.forEach(function (p) {
                        const label = escapeForAttr(p.name) + (p.sku ? ' (' + escapeForAttr(p.sku) + ')' : '');
                        const sel = pendingProductSelection && String(p.id) === String(pendingProductSelection) ? 'selected' : '';
                        opts += `<option value="${p.id}" ${sel}>${label}</option>`;
                    });
                    productSelect.prop('disabled', false);
                }
                productSelect.html(opts);
                if ($.fn.select2) productSelect.trigger('change.select2');
                pendingProductSelection = '';
            });

        // Load formulas
        formulaSelect.prop('disabled', true).html('<option>Loading…</option>');
        $.getJSON('../../ajax/get_manufacturing_formulas.php', { provider_id: customerId })
            .done(function (resp) {
                loadedFormulas = resp.formulas || [];
                let opts = '<option value="">Select a formula</option>';
                loadedFormulas.forEach(function (f) {
                    const sel = pendingFormulaSelection && String(f.id) === String(pendingFormulaSelection) ? 'selected' : '';
                    opts += `<option value="${f.id}" ${sel}>${escapeForAttr(f.name)}</option>`;
                });
                formulaSelect.html(opts).prop('disabled', loadedFormulas.length === 0);
                if ($.fn.select2) formulaSelect.trigger('change.select2');
                if (pendingFormulaSelection) {
                    const match = loadedFormulas.find(function (f) { return String(f.id) === String(pendingFormulaSelection); });
                    if (match) renderFormulaPreview(match);
                    pendingFormulaSelection = '';
                }
            });

        // Load packaging options
        packagingSelect.prop('disabled', true).html('<option>Loading…</option>');
        $.getJSON('../../ajax/get_packaging_options.php', { customer_id: customerId })
            .done(function (resp) {
                let opts = '<option value="">— No packaging option —</option>';
                if (resp.success && resp.options.length) {
                    resp.options.forEach(function (o) {
                        opts += `<option value="${o.id}">${escapeForAttr(o.name)}</option>`;
                    });
                    packagingSelect.prop('disabled', false);
                }
                packagingSelect.html(opts);
                if ($.fn.select2) packagingSelect.trigger('change.select2');
            });
    }

    $(document).ready(function () {
        if ($.fn.select2) {
            $('.select2').select2({ width: '100%' });
        }

        bottleSizeSelect.on('change', updateBatchSizeDisplay);
        numberOfBottlesInput.on('input', updateBatchSizeDisplay);
        updateBatchSizeDisplay();

        customerSelect.on('change', function () {
            pendingFormulaSelection = '';
            pendingProductSelection = '';
            loadForCustomer($(this).val());
        });

        formulaSelect.on('change', function () {
            const match = loadedFormulas.find(function (f) { return String(f.id) === String($(this).val()); }.bind(this));
            renderFormulaPreview(match || null);
        });

        if (customerSelect.val()) {
            loadForCustomer(customerSelect.val());
        }
    });
</script>
