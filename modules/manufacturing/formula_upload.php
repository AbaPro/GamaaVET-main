<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/formula_access.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}
if (!hasPermission('manufacturing.formula.view_all')) {
    setAlert('danger', 'Access denied. You do not have permission to view formulas.');
    redirect('../../dashboard.php');
}
if (!areFormulasUnlocked()) {
    setAlert('info', 'Please unlock formulas first.');
    redirect('formulas.php');
}

// Recognised header names (lowercase) for each logical column.
$formulaUploadColumns = [
    'formula_name'  => ['formula name', 'formula', 'name'],
    'customer'      => ['customer', 'customer name'],
    'final_product' => ['final product', 'linked product', 'product'],
    'type'          => ['type'],
    'batch_size'    => ['batch size'],
    'batch_unit'    => ['batch unit'],
    'description'   => ['description'],
    'instructions'  => ['instructions'],
    'component'     => ['component', 'component name', 'ingredient', 'material'],
    'quantity'      => ['quantity', 'qty'],
    'unit'          => ['unit', 'component unit'],
    'notes'         => ['notes', 'component notes'],
];

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="formula_upload_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Formula Name', 'Customer', 'Final Product', 'Type', 'Batch Size', 'Batch Unit', 'Description', 'Instructions', 'Component', 'Quantity', 'Unit', 'Notes']);
    fputcsv($out, ['Example Formula', 'Customer Name', 'Final product name or SKU', 'liquid', '100', 'L', 'Optional', 'Optional', 'Raw material name or SKU', '2.5', 'kg', 'Optional']);
    fputcsv($out, ['Example Formula', 'Customer Name', '', '', '', '', '', '', 'Second raw material', '500', 'g', '']);
    fclose($out);
    exit;
}

if (isset($_POST['back_to_upload'])) {
    unset($_SESSION['formula_bulk_upload']);
    redirect('formula_upload.php');
}

// Comparison key for names/SKUs: stored values are HTML-encoded by sanitize().
function formulaUploadKey($value) {
    return mb_strtolower(trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8')));
}

/**
 * Read an uploaded CSV/XLSX into a list of rows keyed by logical column name.
 * Returns null and sets $error on failure.
 */
function formulaUploadReadRows(array $file, array $columns, &$error) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        $error = 'Please upload a valid CSV or Excel (.xlsx) file.';
        return null;
    }

    $rows = [];
    if ($ext === 'xlsx') {
        require_once '../../includes/libs/SimpleXLSX.php';
        $xlsx = Shuchkin\SimpleXLSX::parse($file['tmp_name']);
        if (!$xlsx) {
            $error = 'Error parsing Excel file: ' . Shuchkin\SimpleXLSX::parseError();
            return null;
        }
        $rows = $xlsx->rows();
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    }

    if (count($rows) === 0) {
        $error = 'The uploaded file is empty.';
        return null;
    }

    $header = array_map(function ($h) {
        return strtolower(trim((string)$h, " \t\n\r\0\x0B\xEF\xBB\xBF"));
    }, array_shift($rows));

    $indexes = [];
    foreach ($columns as $key => $aliases) {
        foreach ($aliases as $alias) {
            $idx = array_search($alias, $header, true);
            if ($idx !== false) {
                $indexes[$key] = $idx;
                break;
            }
        }
    }

    foreach (['formula_name' => 'Formula Name', 'customer' => 'Customer', 'component' => 'Component', 'quantity' => 'Quantity'] as $key => $label) {
        if (!isset($indexes[$key])) {
            $error = 'File must contain a "' . $label . '" column. Download the template to see the expected layout.';
            return null;
        }
    }

    $result = [];
    foreach ($rows as $lineOffset => $row) {
        $mapped = ['line' => $lineOffset + 2];
        $hasValue = false;
        foreach ($columns as $key => $aliases) {
            $value = isset($indexes[$key], $row[$indexes[$key]]) ? trim((string)$row[$indexes[$key]]) : '';
            $mapped[$key] = $value;
            $hasValue = $hasValue || $value !== '';
        }
        if ($hasValue) {
            $result[] = $mapped;
        }
    }

    return $result;
}

// Step 1: parse the file and build a validated preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['formula_file'])) {
    if ($_FILES['formula_file']['error'] !== UPLOAD_ERR_OK) {
        setAlert('danger', 'Error uploading file.');
        redirect('formula_upload.php');
    }

    $readError = null;
    $rows = formulaUploadReadRows($_FILES['formula_file'], $formulaUploadColumns, $readError);
    if ($rows === null) {
        setAlert('danger', $readError);
        redirect('formula_upload.php');
    }
    if (empty($rows)) {
        setAlert('danger', 'No data rows found in the uploaded file.');
        redirect('formula_upload.php');
    }

    $updateExisting = isset($_POST['update_existing']);

    // Customers in the current channel, by lowercase name.
    $customersByName = [];
    $customerScope = getCustomerChannelScopeSql('c', 'f');
    $customerResult = $conn->query("SELECT c.id, c.name FROM customers c LEFT JOIN factories f ON f.id = c.factory_id WHERE $customerScope");
    while ($customerResult && $c = $customerResult->fetch_assoc()) {
        $customersByName[formulaUploadKey($c['name'])] = $c;
    }

    // Component catalog: same set formula_edit.php offers (materials, else all products).
    $productScope = getProductChannelScopeSql('p', 'c', 'f');
    $componentProducts = [];
    $productResult = $conn->query("SELECT p.id, p.name, p.sku, p.unit FROM products p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN factories f ON f.id = c.factory_id WHERE p.type = 'material' AND " . getActiveProductSql('p') . " AND $productScope");
    if (!$productResult || $productResult->num_rows === 0) {
        $productResult = $conn->query("SELECT p.id, p.name, p.sku, p.unit FROM products p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN factories f ON f.id = c.factory_id WHERE " . getActiveProductSql('p') . " AND $productScope");
    }
    $componentsByKey = [];
    while ($productResult && $p = $productResult->fetch_assoc()) {
        $componentProducts[$p['id']] = $p;
        $componentsByKey[formulaUploadKey($p['name'])] = $p['id'];
        if (!empty($p['sku'])) {
            $componentsByKey[formulaUploadKey($p['sku'])] = $p['id'];
        }
    }

    // Final products per customer, matched by name or SKU.
    $finalProducts = [];
    $finalResult = $conn->query("SELECT p.id, p.name, p.sku, p.customer_id FROM products p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN factories f ON f.id = c.factory_id WHERE p.type = 'final' AND " . getActiveProductSql('p') . " AND $productScope");
    while ($finalResult && $p = $finalResult->fetch_assoc()) {
        $finalProducts[(int)$p['customer_id']][formulaUploadKey($p['name'])] = $p;
        if (!empty($p['sku'])) {
            $finalProducts[(int)$p['customer_id']][formulaUploadKey($p['sku'])] = $p;
        }
    }

    // Group rows into formulas by customer + formula name.
    $formulas = [];
    foreach ($rows as $row) {
        $groupKey = formulaUploadKey($row['customer']) . '|' . formulaUploadKey($row['formula_name']);
        if (!isset($formulas[$groupKey])) {
            $formulas[$groupKey] = [
                'name' => $row['formula_name'],
                'customer_name' => $row['customer'],
                'customer_id' => null,
                'final_product' => '',
                'product_id' => null,
                'product_label' => '',
                'type' => '',
                'batch_size' => '',
                'batch_unit' => '',
                'description' => '',
                'instructions' => '',
                'components' => [],
                'errors' => [],
                'warnings' => [],
                'existing_id' => null,
                'lines' => [],
            ];
        }
        $formula = &$formulas[$groupKey];
        $formula['lines'][] = $row['line'];

        // Formula-level fields come from the first row that fills them in.
        foreach (['final_product', 'type', 'batch_size', 'batch_unit', 'description', 'instructions'] as $field) {
            if ($formula[$field] === '' && $row[$field] !== '') {
                $formula[$field] = $row[$field];
            }
        }

        $line = 'Row ' . $row['line'] . ': ';
        $componentName = $row['component'];
        if ($componentName === '') {
            $formula['errors'][] = $line . 'component is empty.';
            continue;
        }

        $quantity = str_replace(',', '', $row['quantity']);
        if ($quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) {
            $formula['errors'][] = $line . 'quantity for "' . $componentName . '" must be a number greater than zero.';
            continue;
        }

        $unit = '';
        if ($row['unit'] !== '') {
            $unit = getProductFormulaUnit($row['unit']);
            if ($unit === null) {
                $formula['errors'][] = $line . 'unknown unit "' . $row['unit'] . '" for "' . $componentName . '". Use kg, g, L, ml or pcs.';
                continue;
            }
        }

        $productId = $componentsByKey[formulaUploadKey($componentName)] ?? null;
        if ($productId !== null) {
            $product = $componentProducts[$productId];
            $catalogUnit = getProductFormulaUnit($product['unit'] ?? '');
            if ($catalogUnit === null) {
                $formula['errors'][] = $line . 'set the catalog unit for "' . $product['name'] . '" before using it in a formula.';
                continue;
            }
            if ($unit !== '') {
                $converted = convertProductUnitQuantity($quantity, $unit, $catalogUnit);
                if ($converted === null) {
                    $formula['errors'][] = $line . 'unit "' . $unit . '" cannot convert to the catalog unit "' . $catalogUnit . '" for "' . $product['name'] . '".';
                    continue;
                }
                $quantity = rtrim(rtrim(number_format($converted, 6, '.', ''), '0'), '.');
            }
            $unit = $catalogUnit;
            $componentName = $product['name'];
        } else {
            $componentName = sanitize($componentName);
            if ($unit === '') {
                $formula['errors'][] = $line . 'unit is required for "' . $componentName . '" (not found in catalog).';
                continue;
            }
            $formula['warnings'][] = $line . '"' . $componentName . '" was not found in the catalog and will be saved as a manual (untracked) ingredient.';
        }

        $formula['components'][] = [
            'product_id' => $productId !== null ? (int)$productId : null,
            'name' => $componentName,
            'quantity' => $quantity,
            'unit' => $unit,
            'notes' => sanitize($row['notes']),
        ];
    }
    unset($formula);

    $existingStmt = $conn->prepare("SELECT id FROM manufacturing_formulas WHERE customer_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
    foreach ($formulas as &$formula) {
        if ($formula['name'] === '') {
            $formula['errors'][] = 'Formula name is empty (rows ' . implode(', ', $formula['lines']) . ').';
        }

        $customer = $customersByName[formulaUploadKey($formula['customer_name'])] ?? null;
        if (!$customer) {
            $formula['errors'][] = 'Customer "' . $formula['customer_name'] . '" was not found in the current channel.';
        } else {
            $formula['customer_id'] = (int)$customer['id'];
            $formula['customer_name'] = $customer['name'];

            if ($formula['final_product'] !== '') {
                $final = $finalProducts[$formula['customer_id']][formulaUploadKey($formula['final_product'])] ?? null;
                if ($final) {
                    $formula['product_id'] = (int)$final['id'];
                    $formula['product_label'] = $final['name'] . ($final['sku'] ? ' (' . $final['sku'] . ')' : '');
                } else {
                    $formula['errors'][] = 'Final product "' . $formula['final_product'] . '" was not found for customer "' . $customer['name'] . '".';
                }
            }

            if ($formula['name'] !== '') {
                $storedName = sanitize($formula['name']);
                $existingStmt->bind_param('is', $formula['customer_id'], $storedName);
                $existingStmt->execute();
                $existing = $existingStmt->get_result()->fetch_assoc();
                if ($existing) {
                    if ($updateExisting) {
                        $formula['existing_id'] = (int)$existing['id'];
                    } else {
                        $formula['errors'][] = 'A formula with this name already exists for this customer. Tick "Update existing formulas" to replace it.';
                    }
                }
            }
        }

        $typeValue = strtolower($formula['type']);
        if ($typeValue !== '' && !in_array($typeValue, ['liquid', 'powder'], true)) {
            $formula['errors'][] = 'Type must be "liquid" or "powder".';
        }
        $formula['type'] = $typeValue;

        $batchSize = str_replace(',', '', $formula['batch_size']);
        if ($batchSize !== '' && !is_numeric($batchSize)) {
            $formula['errors'][] = 'Batch size must be a number.';
        }
        $formula['batch_size'] = $batchSize === '' ? 0 : (float)$batchSize;

        if ($formula['batch_unit'] !== '') {
            $batchUnit = getProductFormulaUnit($formula['batch_unit']);
            if ($batchUnit === null) {
                $formula['errors'][] = 'Unknown batch unit "' . $formula['batch_unit'] . '". Use kg, g, L, ml or pcs.';
            } else {
                $formula['batch_unit'] = $batchUnit;
            }
        }

        if (empty($formula['components']) && empty($formula['errors'])) {
            $formula['errors'][] = 'Formula has no valid components.';
        }
    }
    unset($formula);
    $existingStmt->close();

    $_SESSION['formula_bulk_upload'] = [
        'formulas' => array_values($formulas),
        'update_existing' => $updateExisting,
    ];
    redirect('formula_upload.php');
}

// Step 2: import the formulas that passed validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && !empty($_SESSION['formula_bulk_upload'])) {
    $created = 0;
    $updated = 0;
    $skipped = 0;

    try {
        $pdo->beginTransaction();
        $insertStmt = $pdo->prepare("
            INSERT INTO manufacturing_formulas
                (customer_id, product_id, name, type, description, batch_size, batch_unit, components_json, instructions, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        // Updates keep preparation fields, sample images and status; blank
        // description/instructions in the file leave the saved text untouched.
        $updateStmt = $pdo->prepare("
            UPDATE manufacturing_formulas
            SET product_id = ?, type = ?, description = COALESCE(NULLIF(?, ''), description), batch_size = ?, batch_unit = ?,
                components_json = ?, instructions = COALESCE(NULLIF(?, ''), instructions)
            WHERE id = ? AND customer_id = ?
        ");

        foreach ($_SESSION['formula_bulk_upload']['formulas'] as $formula) {
            if (!empty($formula['errors']) || !canAccessCustomer($formula['customer_id'])) {
                $skipped++;
                continue;
            }

            $name = sanitize($formula['name']);
            $type = $formula['type'] !== '' ? $formula['type'] : null;
            $description = sanitize($formula['description']);
            $instructions = sanitize($formula['instructions']);
            $batchUnit = sanitize($formula['batch_unit']);
            $componentsJson = json_encode($formula['components'], JSON_UNESCAPED_UNICODE);

            if (!empty($formula['existing_id'])) {
                $updateStmt->execute([$formula['product_id'], $type, $description, $formula['batch_size'], $batchUnit,
                                      $componentsJson, $instructions, $formula['existing_id'], $formula['customer_id']]);
                logActivity("Updated formula ID: {$formula['existing_id']} via bulk upload", ['name' => $name, 'customer_id' => $formula['customer_id']], 'update', 'formula', $formula['existing_id']);
                $updated++;
            } else {
                $insertStmt->execute([$formula['customer_id'], $formula['product_id'], $name, $type, $description,
                                      $formula['batch_size'], $batchUnit, $componentsJson, $instructions]);
                $newId = (int)$pdo->lastInsertId();
                logActivity("Created formula: $name via bulk upload", ['customer_id' => $formula['customer_id']], 'create', 'formula', $newId);
                $created++;
            }
        }

        $pdo->commit();
        setAlert('success', "Formula upload complete. $created created, $updated updated, $skipped skipped.");
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setAlert('danger', 'Error importing formulas: ' . $e->getMessage() . ' No formulas were saved.');
        redirect('formula_upload.php');
    }

    unset($_SESSION['formula_bulk_upload']);
    redirect('formulas.php');
}

$page_title = 'Bulk Upload Formulas';
require_once '../../includes/header.php';

$preview = $_SESSION['formula_bulk_upload'] ?? null;
$canViewComponentName = hasPermission('manufacturing.component.name.view');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2>Bulk Upload Formulas</h2>
        <p class="text-muted mb-0">Create or update many formulas at once from a CSV or Excel file.</p>
    </div>
    <?= activityLogLink('formula'); ?>
    <a href="formulas.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Formulas
    </a>
</div>

<?php if (!$preview): ?>
<div class="row">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">Step 1: Upload File</h5>
            </div>
            <div class="card-body">
                <form action="formula_upload.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-bold">CSV or Excel (.xlsx) file</label>
                        <input type="file" class="form-control" name="formula_file" accept=".csv,.xlsx" required>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="update_existing" id="updateExisting">
                        <label class="form-check-label" for="updateExisting">
                            Update existing formulas with the same name &amp; customer
                            <?= helpTip('When unticked, rows for a formula that already exists are skipped, so nothing is overwritten by accident.'); ?>
                        </label>
                        <div class="form-text">Their ingredient list is replaced by the file. Preparation fields, sample images and status are kept.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Continue to Review</button>
                        <a href="formula_upload.php?template=1" class="btn btn-outline-secondary">
                            <i class="fas fa-download me-1"></i> Download Template
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm bg-light">
            <div class="card-body small">
                <h6>File layout</h6>
                <p class="mb-2">One row per ingredient. Rows with the same <strong>Formula Name</strong> and <strong>Customer</strong> become one formula.</p>
                <ul class="mb-2">
                    <li><strong>Formula Name</strong>, <strong>Customer</strong>, <strong>Component</strong>, <strong>Quantity</strong>: required</li>
                    <li><strong>Unit</strong>: kg, g, L, ml or pcs. It can be left empty for catalog items, which use their catalog unit.</li>
                    <li><strong>Component</strong> and <strong>Final Product</strong> match by product name or SKU.</li>
                    <li>Optional: Final Product, Type (liquid/powder), Batch Size, Batch Unit, Description, Instructions, Notes</li>
                </ul>
                <p class="mb-0 text-muted">Formula-level columns only need to be filled on the first row of each formula.</p>
            </div>
        </div>
    </div>
</div>

<?php else:
    $validCount = count(array_filter($preview['formulas'], function ($f) { return empty($f['errors']); }));
    $errorCount = count($preview['formulas']) - $validCount;
?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Step 2: Review</h5>
        <form method="POST"><button type="submit" name="back_to_upload" class="btn btn-sm btn-outline-dark">Start Over</button></form>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2">Nothing has been saved yet. Check the list below, then click <strong>Import</strong>. Formulas with errors are skipped, and the rest are imported together.</p>
        <p>
            <span class="badge bg-success"><?= $validCount; ?> ready</span>
            <?php if ($errorCount): ?>
                <span class="badge bg-danger"><?= $errorCount; ?> with errors (will be skipped)</span>
            <?php endif; ?>
        </p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Formula</th>
                        <th>Customer</th>
                        <th>Final Product</th>
                        <th>Batch</th>
                        <th>Ingredients</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview['formulas'] as $formula): ?>
                        <tr class="<?= !empty($formula['errors']) ? 'table-danger' : ''; ?>">
                            <td>
                                <strong><?= htmlspecialchars($formula['name']); ?></strong>
                                <?php if ($formula['type']): ?><br><small class="text-muted"><?= htmlspecialchars(ucfirst($formula['type'])); ?></small><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($formula['customer_name']); ?></td>
                            <td><?= $formula['product_label'] !== '' ? htmlspecialchars($formula['product_label']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?= $formula['batch_size'] ? htmlspecialchars($formula['batch_size'] . ' ' . $formula['batch_unit']) : '<span class="text-muted">—</span>'; ?></td>
                            <td>
                                <ul class="small mb-0 ps-3">
                                    <?php foreach ($formula['components'] as $component): ?>
                                        <li>
                                            <?php if ($component['product_id'] && !$canViewComponentName): ?>
                                                Product #<?= (int)$component['product_id']; ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($component['name']); ?>
                                            <?php endif; ?>
                                            : <?= htmlspecialchars($component['quantity'] . ' ' . $component['unit']); ?>
                                            <?php if (!$component['product_id']): ?><span class="badge bg-warning text-dark" data-bs-toggle="tooltip" title="Not found in the product catalog. It will be saved as a manual ingredient, so its stock is not tracked. Fix the name or SKU in the file if it should match a catalog item.">manual</span><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                            <td>
                                <?php if (!empty($formula['errors'])): ?>
                                    <span class="badge bg-danger mb-1" data-bs-toggle="tooltip" title="This formula will not be imported. Fix the problems listed below in your file and upload it again.">Skipped</span>
                                    <ul class="small text-danger mb-0 ps-3">
                                        <?php foreach ($formula['errors'] as $error): ?>
                                            <li><?= htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif (!empty($formula['existing_id'])): ?>
                                    <span class="badge bg-info" data-bs-toggle="tooltip" title="A formula with this name already exists for this customer. Its ingredient list will be replaced; preparation fields, sample images and status are kept.">Update existing</span>
                                <?php else: ?>
                                    <span class="badge bg-success" data-bs-toggle="tooltip" title="A new active formula will be created.">New</span>
                                <?php endif; ?>
                                <?php if (!empty($formula['warnings'])): ?>
                                    <ul class="small text-warning-emphasis mb-0 ps-3 mt-1">
                                        <?php foreach ($formula['warnings'] as $warning): ?>
                                            <li><?= htmlspecialchars($warning); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" class="d-grid mt-3">
            <button type="submit" name="confirm_import" class="btn btn-success" <?= $validCount === 0 ? 'disabled' : ''; ?>>
                Import <?= $validCount; ?> Formula<?= $validCount === 1 ? '' : 's'; ?>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
