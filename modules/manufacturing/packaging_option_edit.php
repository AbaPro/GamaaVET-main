<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$option = null;
$currentItems = [];

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM packaging_options WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $option = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$option) {
        setAlert('danger', 'Packaging option not found.');
        redirect('packaging_options.php');
    }

    $itemStmt = $conn->prepare("
        SELECT poi.*, p.name AS product_name, p.sku AS product_sku
        FROM packaging_option_items poi
        JOIN products p ON p.id = poi.product_id
        WHERE poi.packaging_option_id = ?
        ORDER BY poi.id ASC
    ");
    $itemStmt->bind_param("i", $id);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    while ($row = $itemResult->fetch_assoc()) {
        $currentItems[] = $row;
    }
    $itemStmt->close();
}

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[] = $row;
    }
}

$materialProducts = [];
$materialProductResult = $conn->query("SELECT id, name, sku FROM products WHERE type = 'material' ORDER BY name");
if ($materialProductResult) {
    while ($row = $materialProductResult->fetch_assoc()) {
        $materialProducts[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId  = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $name        = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $isActive    = isset($_POST['is_active']) ? 1 : 0;

    $rawItems = $_POST['items'] ?? [];
    $items = [];
    foreach ($rawItems as $item) {
        $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
        if ($productId <= 0) continue;
        $items[] = [
            'product_id' => $productId,
            'quantity'   => max(0.001, floatval($item['quantity'] ?? 1)),
            'unit'       => sanitize($item['unit'] ?? ''),
            'notes'      => sanitize($item['notes'] ?? ''),
        ];
    }

    if ($customerId <= 0) {
        setAlert('danger', 'Please select a customer.');
    } elseif ($name === '') {
        setAlert('danger', 'Please enter a name.');
    } elseif (empty($items)) {
        setAlert('danger', 'Please add at least one packaging item.');
    } else {
        try {
            $pdo->beginTransaction();

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE packaging_options SET customer_id = ?, name = ?, description = ?, is_active = ? WHERE id = ?
                ");
                $stmt->execute([$customerId, $name, $description ?: null, $isActive, $id]);
                $pdo->prepare("DELETE FROM packaging_option_items WHERE packaging_option_id = ?")->execute([$id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO packaging_options (customer_id, name, description, is_active) VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$customerId, $name, $description ?: null, $isActive]);
                $id = $pdo->lastInsertId();
            }

            $itemStmt = $pdo->prepare("
                INSERT INTO packaging_option_items (packaging_option_id, product_id, quantity, unit, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $itemStmt->execute([$id, $item['product_id'], $item['quantity'], $item['unit'] ?: null, $item['notes'] ?: null]);
            }

            $pdo->commit();
            setAlert('success', $id ? 'Packaging option updated.' : 'Packaging option created.');
            redirect('packaging_options.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            setAlert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

$page_title = $id > 0 ? 'Edit Packaging Option' : 'New Packaging Option';
require_once '../../includes/header.php';

$canonicalUnits = ['kg', 'g', 'L', 'ml', 'pcs'];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $page_title; ?></h2>
    <a href="packaging_options.php" class="btn btn-outline-secondary">
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
                            <label class="form-label">Customer <span class="text-danger">*</span></label>
                            <select class="form-select select2" name="customer_id" required>
                                <option value="">Select customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id']; ?>"
                                        <?= (isset($option['customer_id']) && $option['customer_id'] == $customer['id']) || (isset($_POST['customer_id']) && $_POST['customer_id'] == $customer['id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name"
                                   value="<?= htmlspecialchars($option['name'] ?? $_POST['name'] ?? ''); ?>"
                                   placeholder="e.g. 500ml Standard Kit" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($option['description'] ?? $_POST['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                       <?= (!isset($option) || !empty($option['is_active']) || isset($_POST['is_active'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Packaging Items</h5>
                    <button type="button" class="btn btn-sm btn-primary" id="addItem">
                        <i class="fas fa-plus me-1"></i> Add Item
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Material Product</th>
                                    <th style="width: 120px;">Qty per Bottle</th>
                                    <th style="width: 120px;">Unit</th>
                                    <th>Notes</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mb-5">
                <button type="submit" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-save me-2"></i> Save
                </button>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-light p-3 position-sticky" style="top: 20px;">
                <h6>About Packaging Options</h6>
                <p class="small text-muted">
                    A packaging option is a named kit of material products (bottle, cap, label, box…) used per unit produced.
                    When assigned to a manufacturing order, the system multiplies each item's quantity by the number of bottles
                    and adds them to the sourcing list.
                </p>
                <ul class="small">
                    <li>Only <strong>material</strong> products appear in the item list.</li>
                    <li>Quantities are <strong>per bottle</strong> (e.g. 1 bottle, 1 cap, 0.1 box).</li>
                    <li>All items are deducted from inventory when sourcing is completed.</li>
                </ul>
            </div>
        </div>
    </div>
</form>

<?php require_once '../../includes/footer.php'; ?>

<script>
    const materialProducts = <?= json_encode($materialProducts); ?>;
    const savedItems = <?= json_encode($currentItems); ?>;
    const canonicalUnits = <?= json_encode($canonicalUnits); ?>;
    const itemsBody = $('#itemsBody');
    let itemIndex = 0;

    function escapeForAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderProductOptions(selectedId) {
        let html = '<option value="">Select material product…</option>';
        materialProducts.forEach(function (p) {
            const label = escapeForAttr(p.name) + (p.sku ? ' (' + escapeForAttr(p.sku) + ')' : '');
            const sel = selectedId && String(p.id) === String(selectedId) ? 'selected' : '';
            html += `<option value="${p.id}" ${sel}>${label}</option>`;
        });
        return html;
    }

    function renderUnitOptions(selectedUnit) {
        let html = '<option value="">— unit —</option>';
        canonicalUnits.forEach(function (u) {
            const sel = selectedUnit === u ? 'selected' : '';
            html += `<option value="${u}" ${sel}>${u}</option>`;
        });
        if (selectedUnit && !canonicalUnits.includes(selectedUnit)) {
            html += `<option value="${escapeForAttr(selectedUnit)}" selected>${escapeForAttr(selectedUnit)} (existing)</option>`;
        }
        return html;
    }

    function addItemRow(data) {
        data = data || {};
        const idx = itemIndex++;
        const qty = data.quantity || 1;
        const notes = escapeForAttr(data.notes || '');

        const row = `
            <tr data-index="${idx}">
                <td>
                    <select class="form-select form-select-sm select2-item" name="items[${idx}][product_id]" required>
                        ${renderProductOptions(data.product_id || '')}
                    </select>
                </td>
                <td><input type="number" step="0.001" min="0.001" class="form-control form-control-sm" name="items[${idx}][quantity]" value="${qty}" required></td>
                <td>
                    <select class="form-select form-select-sm" name="items[${idx}][unit]">
                        ${renderUnitOptions(data.unit || '')}
                    </select>
                </td>
                <td><input type="text" class="form-control form-control-sm" name="items[${idx}][notes]" value="${notes}"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-item">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        itemsBody.append(row);
        const addedRow = itemsBody.find(`tr[data-index="${idx}"]`);
        if ($.fn.select2) {
            addedRow.find('.select2-item').select2({ width: '100%', placeholder: 'Search product…' });
        }
    }

    $(document).ready(function () {
        if ($.fn.select2) {
            $('.select2').select2({ width: '100%' });
        }

        $('#addItem').on('click', function () { addItemRow(); });

        $(document).on('click', '.remove-item', function () {
            $(this).closest('tr').remove();
        });

        if (savedItems.length) {
            savedItems.forEach(function (item) { addItemRow(item); });
        } else {
            addItemRow();
        }
    });
</script>
