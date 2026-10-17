<?php
require_once '../../includes/auth.php';
require_once __DIR__ . '/pricing_helpers.php';

$pricingId = (int)($_GET['id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);
$selfUrl = 'pricing_edit.php' . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

pricingRequireAccess($pricingId > 0 ? 'finance.pricing.view' : 'finance.pricing.manage', $selfUrl);
$canManage = hasPermission('finance.pricing.manage');

if (!pricingStorageReady()) {
    setAlert('danger', 'Apply migration 20261016_order_pricing.sql before using order pricing.');
    redirect('index.php');
}

$saved = null;
if ($pricingId > 0) {
    $saved = pricingLoadSaved($pricingId);
    if (!$saved) {
        setAlert('danger', 'Pricing sheet not found.');
        redirect('pricing.php');
    }
    $orderId = (int)$saved['order_id'];
}

$order = $orderId > 0 ? pricingLoadOrder($orderId) : null;
if ($orderId > 0 && !$order) {
    setAlert('danger', 'Order not found.');
    redirect('pricing.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pricing'])) {
    if (!$canManage || !pricingValidFormToken()) {
        setAlert('danger', 'Invalid request. Refresh the page and try again.');
        redirect($selfUrl);
    }
    $marginPercent = pricingNumber($_POST['margin_percent'] ?? 0);
    $lines = pricingLinesFromRequest($order, $_POST['lines'] ?? []);
    if (empty($lines)) {
        setAlert('danger', 'Select at least one product to price.');
        redirect($selfUrl);
    }
    [$lines, $totals] = pricingCalculate($lines, $marginPercent);
    try {
        $savedId = pricingSave($pricingId, $order, $lines, $totals, $marginPercent, sanitize($_POST['notes'] ?? ''));
    } catch (Exception $e) {
        error_log('Order pricing save failed: ' . $e->getMessage());
        setAlert('danger', 'Unable to save the pricing sheet. Please try again.');
        redirect($selfUrl);
    }
    logActivity($pricingId > 0 ? 'Updated order pricing' : 'Created order pricing', [
        'order_id' => $order['id'],
        'margin_percent' => $marginPercent,
        'total_cost' => round($totals['total_cost'], 2),
        'selling_price' => round($totals['selling_price'], 2),
    ], $pricingId > 0 ? 'update' : 'create', 'order_pricing', $savedId);
    setAlert('success', 'Pricing sheet saved.');
    redirect('pricing_edit.php?id=' . $savedId);
}

// Decide which products are on the sheet.
$lines = [];
$marginPercent = 35.0;
$notes = '';
$selectedProductIds = array_values(array_unique(array_filter(array_map('intval', (array)($_GET['product_ids'] ?? [])))));
if ($saved) {
    $marginPercent = (float)$saved['margin_percent'];
    $notes = (string)$saved['notes'];
    foreach ($saved['lines'] as $line) {
        // Saved rows keep their prices; the kit list is current so another kit can be picked.
        $line['packaging_options'] = pricingBuildLine((int)$order['customer_id'], $line['product_id'], $line['product_name'], $line['quantity'])['packaging_options'];
        $lines[] = $line;
    }
} elseif ($order && $selectedProductIds) {
    foreach ($selectedProductIds as $productId) {
        if (isset($order['items'][$productId])) {
            $item = $order['items'][$productId];
            $lines[] = pricingBuildLine((int)$order['customer_id'], $productId, $item['name'], (float)$item['quantity']);
        }
    }
}
[$lines, $totals] = pricingCalculate($lines, $marginPercent);

$orders = [];
if (!$order) {
    $scope = getCustomerChannelScopeSql('c', 'f');
    $result = $conn->query("
        SELECT o.id, o.internal_id, o.order_date, c.name AS customer_name
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN factories f ON f.id = c.factory_id
        WHERE $scope
        ORDER BY o.order_date DESC, o.id DESC
        LIMIT 1000
    ");
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
}

$readOnly = !$canManage;
$currency = $order['currency'] ?? 'EGP';
$page_title = $saved ? 'Order Pricing #' . $saved['id'] : 'New Order Pricing';
require_once '../../includes/header.php';

function pricingFmt($value, $decimals = 2) {
    return number_format((float)$value, $decimals);
}
function pricingQty($value) {
    return rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
}
?>
<style>
    .pricing-input { background: #fff3cd !important; }
    .pricing-calc { background: #fde5dc; }
    .pricing-margin { background: #d1e7dd !important; }
    .pricing-sheet td, .pricing-sheet th { vertical-align: middle; }
    .pricing-sheet input.form-control-sm { min-width: 90px; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h2 class="mb-0">Order Pricing</h2>
        <?php if ($order): ?>
            <div class="text-muted">
                Order <a href="../sales/order_details.php?id=<?= (int)$order['id']; ?>"><?= e($order['internal_id'] ?: '#' . $order['id']); ?></a>
                · <?= e($order['order_date']); ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if ($saved): ?>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <?php endif; ?>
        <a href="pricing.php" class="btn btn-secondary">Back to Pricing</a>
    </div>
</div>

<?php if (!$order): ?>
    <!-- Step 1: choose the order -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0">1. Select an order</h5></div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="order_id">Order</label>
                    <select name="order_id" id="order_id" class="form-select" required>
                        <option value="">— Select order —</option>
                        <?php foreach ($orders as $row): ?>
                            <option value="<?= (int)$row['id']; ?>"><?= e(($row['internal_id'] ?: '#' . $row['id']) . ' — ' . $row['customer_name'] . ' — ' . $row['order_date']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">Next</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        $(function () {
            if ($.fn.select2) $('#order_id').select2({ placeholder: '— Select order —', width: '100%' });
        });
    </script>

<?php elseif (!$saved && empty($lines)): ?>
    <!-- Step 2: choose which order products to price -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">2. Select products to price</h5>
            <span class="fw-semibold">Customer: <?= e($order['customer_name']); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($order['items'])): ?>
                <div class="alert alert-warning mb-0">This order has no products.</div>
            <?php else: ?>
                <form method="get">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id']; ?>">
                    <p class="text-muted">Only the selected products are added to the sheet. Leave out products that already have a price.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th style="width:40px;"><input type="checkbox" class="form-check-input" id="select_all_products"></th><th>Product</th><th>SKU</th><th class="text-end">Quantity</th></tr></thead>
                            <tbody>
                                <?php foreach ($order['items'] as $item): ?>
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input product-check" name="product_ids[]" value="<?= (int)$item['product_id']; ?>" id="product_<?= (int)$item['product_id']; ?>"></td>
                                        <td><label for="product_<?= (int)$item['product_id']; ?>"><?= e($item['name']); ?></label>
                                            <?php if ($item['type'] !== 'final'): ?><span class="badge bg-secondary ms-1"><?= e($item['type'] ?: 'product'); ?></span><?php endif; ?></td>
                                        <td><?= e($item['sku'] ?? ''); ?></td>
                                        <td class="text-end"><?= pricingQty($item['quantity']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="pricing_edit.php" class="btn btn-outline-secondary">Change order</a>
                        <button type="submit" class="btn btn-primary">Build pricing sheet</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('select_all_products')?.addEventListener('change', function () {
            document.querySelectorAll('.product-check').forEach(cb => { cb.checked = this.checked; });
        });
    </script>

<?php else: ?>
    <!-- Step 3: the pricing sheet -->
    <form method="post" id="pricing-form">
        <input type="hidden" name="csrf_token" value="<?= e(pricingFormToken()); ?>">
        <input type="hidden" name="save_pricing" value="1">

        <div class="card mb-3">
            <div class="card-body row g-3 align-items-center">
                <div class="col-md-5">
                    <div class="text-muted small">Customer Name</div>
                    <div class="fs-5 fw-semibold"><?= e($order['customer_name']); ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-0" for="margin_percent">Margin %</label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" class="form-control pricing-margin fw-bold" id="margin_percent" name="margin_percent" value="<?= e(pricingQty($marginPercent)); ?>" <?= $readOnly ? 'readonly' : ''; ?>>
                        <span class="input-group-text" id="margin_multiplier"><?= pricingQty(100 + $marginPercent); ?>%</span>
                    </div>
                </div>
                <div class="col-md-2 text-md-end">
                    <div class="text-muted small">Order Total Cost</div>
                    <div class="fs-5 fw-bold" id="order_total_cost"><?= pricingFmt($totals['total_cost']); ?></div>
                </div>
                <div class="col-md-2 text-md-end">
                    <div class="text-muted small">Selling Price with Profit</div>
                    <div class="fs-5 fw-bold text-success" id="order_total_selling"><?= pricingFmt($totals['selling_price']); ?></div>
                </div>
            </div>
            <div class="card-footer small text-muted">
                <span class="pricing-input px-2 border">Yellow</span> = editable (last price; change it if you will buy at a new price) ·
                <span class="pricing-calc px-2 border">Red</span> = calculated by the system ·
                <span class="pricing-margin px-2 border">Green</span> = margin input. Currency: <?= e($currency); ?>
            </div>
        </div>

        <?php foreach ($lines as $i => $line): ?>
            <?php $prefix = "lines[$i]"; ?>
            <div class="card mb-3 pricing-line" data-index="<?= $i; ?>">
                <input type="hidden" name="<?= $prefix; ?>[product_id]" value="<?= (int)$line['product_id']; ?>">
                <input type="hidden" name="<?= $prefix; ?>[formula_id]" value="<?= e((string)($line['formula_id'] ?? '')); ?>">
                <input type="hidden" name="<?= $prefix; ?>[formula_label]" value="<?= e($line['formula_label']); ?>">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="mb-0"><?= e($line['product_name']); ?></h5>
                        <div class="small text-muted">Formula: <?= e($line['formula_label'] ?: '—'); ?></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-end">
                        <div>
                            <label class="form-label small mb-0">Quantity</label>
                            <input type="number" step="any" min="0" class="form-control form-control-sm pricing-input js-qty" name="<?= $prefix; ?>[quantity]" value="<?= e(pricingQty($line['quantity'])); ?>" <?= $readOnly ? 'readonly' : ''; ?>>
                        </div>
                        <div>
                            <label class="form-label small mb-0" title="How many finished units the formula quantities produce">Formula basis (units)</label>
                            <input type="number" step="any" min="0" class="form-control form-control-sm pricing-input js-basis" name="<?= $prefix; ?>[basis_units]" value="<?= e(pricingQty($line['basis_units'])); ?>" <?= $readOnly ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php foreach ($line['warnings'] ?? [] as $warning): ?>
                        <div class="alert alert-warning py-2"><?= e($warning); ?></div>
                    <?php endforeach; ?>
                    <div class="row g-3">
                        <div class="col-xl-7">
                            <h6>Raw materials</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered pricing-sheet mb-0">
                                    <thead class="table-light"><tr><th>Formula</th><th class="text-end">Formula qty</th><th class="text-end">Quantity</th><th>Item Cost</th><th class="text-end">Total item cost</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($line['materials'] as $m => $material): ?>
                                            <?php $mp = "{$prefix}[materials][$m]"; ?>
                                            <tr class="js-material">
                                                <td>
                                                    <?= e($material['name']); ?>
                                                    <input type="hidden" name="<?= $mp; ?>[product_id]" value="<?= e((string)($material['product_id'] ?? '')); ?>">
                                                    <input type="hidden" name="<?= $mp; ?>[name]" value="<?= e($material['name']); ?>">
                                                    <input type="hidden" name="<?= $mp; ?>[unit]" value="<?= e($material['unit']); ?>">
                                                    <input type="hidden" name="<?= $mp; ?>[price_source]" value="<?= e($material['price_source'] ?? ''); ?>">
                                                    <input type="hidden" class="js-formula-qty" name="<?= $mp; ?>[quantity]" value="<?= e((string)$material['quantity']); ?>">
                                                </td>
                                                <td class="text-end text-nowrap"><?= pricingQty($material['quantity']); ?> <?= e($material['unit']); ?></td>
                                                <td class="text-end text-nowrap pricing-calc"><span class="js-order-qty"><?= pricingQty($material['order_quantity']); ?></span> <?= e($material['unit']); ?></td>
                                                <td>
                                                    <input type="number" step="any" min="0" class="form-control form-control-sm pricing-input js-cost" name="<?= $mp; ?>[unit_cost]" value="<?= e(pricingQty($material['unit_cost'])); ?>" title="<?= e(($material['price_source'] ?? '') . ' · per ' . $material['unit']); ?>" <?= $readOnly ? 'readonly' : ''; ?>>
                                                    <div class="small text-muted"><?= e($material['price_source'] ?? ''); ?></div>
                                                </td>
                                                <td class="text-end pricing-calc js-row-total"><?= pricingFmt($material['total_cost']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($line['materials'])): ?>
                                            <tr><td colspan="5" class="text-muted text-center">No raw materials.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot><tr><th colspan="4" class="text-end">Total Raw material cost</th><th class="text-end pricing-calc js-raw-total"><?= pricingFmt($line['raw_material_cost']); ?></th></tr></tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="col-xl-5">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input js-pack-enabled" type="checkbox" role="switch" id="pack_<?= $i; ?>" name="<?= $prefix; ?>[packaging_enabled]" value="1" <?= $line['packaging_enabled'] ? 'checked' : ''; ?> <?= $readOnly ? 'disabled' : ''; ?>>
                                    <label class="form-check-label fw-semibold" for="pack_<?= $i; ?>">Company pays for packaging</label>
                                </div>
                                <?php if (count($line['packaging_options']) > 1 && !$readOnly): ?>
                                    <select class="form-select form-select-sm w-auto js-pack-option" name="<?= $prefix; ?>[packaging_option_id]">
                                        <?php foreach ($line['packaging_options'] as $option): ?>
                                            <option value="<?= (int)$option['id']; ?>" <?= (int)$option['id'] === (int)$line['packaging_option_id'] ? 'selected' : ''; ?>><?= e($option['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="<?= $prefix; ?>[packaging_option_id]" value="<?= e((string)($line['packaging_option_id'] ?? '')); ?>">
                                <?php endif; ?>
                            </div>
                            <div class="js-pack-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered pricing-sheet mb-0">
                                        <thead class="table-light"><tr><th>Packaging</th><th class="text-end">Qty / unit</th><th>Packaging price</th></tr></thead>
                                        <tbody class="js-pack-rows">
                                            <?php foreach ($line['packaging'] as $p => $item): ?>
                                                <?php $pp = "{$prefix}[packaging][$p]"; ?>
                                                <tr class="js-pack-item">
                                                    <td>
                                                        <?= e($item['name']); ?>
                                                        <input type="hidden" name="<?= $pp; ?>[product_id]" value="<?= (int)$item['product_id']; ?>">
                                                        <input type="hidden" name="<?= $pp; ?>[name]" value="<?= e($item['name']); ?>">
                                                        <input type="hidden" name="<?= $pp; ?>[unit]" value="<?= e($item['unit']); ?>">
                                                        <input type="hidden" name="<?= $pp; ?>[price_source]" value="<?= e($item['price_source'] ?? ''); ?>">
                                                        <input type="hidden" class="js-pack-qty" name="<?= $pp; ?>[quantity]" value="<?= e((string)$item['quantity']); ?>">
                                                    </td>
                                                    <td class="text-end text-nowrap"><?= pricingQty($item['quantity']); ?> <?= e($item['unit']); ?></td>
                                                    <td>
                                                        <input type="number" step="any" min="0" class="form-control form-control-sm pricing-input js-pack-cost" name="<?= $pp; ?>[unit_cost]" value="<?= e(pricingQty($item['unit_cost'])); ?>" <?= $readOnly ? 'readonly' : ''; ?>>
                                                        <div class="small text-muted"><?= e($item['price_source'] ?? ''); ?></div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($line['packaging'])): ?>
                                                <tr><td colspan="3" class="text-muted text-center">No packaging option is linked to this product.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr><th colspan="2" class="text-end">Packaging per unit</th><th class="text-end pricing-calc js-pack-unit"><?= pricingFmt($line['packaging_unit_cost'], 4); ?></th></tr>
                                            <tr><th colspan="2" class="text-end">Packaging cost</th><th class="text-end pricing-calc js-pack-total"><?= pricingFmt($line['packaging_cost']); ?></th></tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="row text-center g-2">
                        <div class="col-6 col-md-3"><div class="small text-muted">Total Cost with Packaging</div><div class="fw-bold js-line-total"><?= pricingFmt($line['total_cost']); ?></div></div>
                        <div class="col-6 col-md-3"><div class="small text-muted">Selling price with Profit</div><div class="fw-bold js-line-selling"><?= pricingFmt($line['selling_price']); ?></div></div>
                        <div class="col-6 col-md-3"><div class="small text-muted">Unit Selling Price</div><div class="fw-bold fs-5 text-success js-line-unit"><?= pricingFmt($line['unit_selling_price'], 4); ?></div></div>
                        <div class="col-6 col-md-3"><div class="small text-muted">Unit Cost</div><div class="fw-bold js-line-unit-cost"><?= pricingFmt($line['quantity'] > 0 ? $line['total_cost'] / $line['quantity'] : 0, 4); ?></div></div>
                    </div>
                </div>
                <script type="application/json" class="js-pack-options"><?= json_encode(array_map(function ($option) {
                    return ['id' => $option['id'], 'items' => $option['items']];
                }, $line['packaging_options']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
            </div>
        <?php endforeach; ?>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Summary</h5></div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 pricing-sheet">
                    <thead class="table-light">
                        <tr><th>Product</th><th class="text-end">Quantity</th><th class="text-end">Total Raw material cost</th><th class="text-end">Packaging cost</th><th class="text-end">Total Cost with Packaging</th><th class="text-end">Selling price with Profit</th><th class="text-end">Unit Selling Price</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $i => $line): ?>
                            <tr data-summary="<?= $i; ?>">
                                <td><?= e($line['product_name']); ?></td>
                                <td class="text-end js-s-qty"><?= pricingQty($line['quantity']); ?></td>
                                <td class="text-end pricing-calc js-s-raw"><?= pricingFmt($line['raw_material_cost']); ?></td>
                                <td class="text-end pricing-calc js-s-pack"><?= pricingFmt($line['packaging_cost']); ?></td>
                                <td class="text-end pricing-calc js-s-total"><?= pricingFmt($line['total_cost']); ?></td>
                                <td class="text-end pricing-calc js-s-selling"><?= pricingFmt($line['selling_price']); ?></td>
                                <td class="text-end pricing-calc fw-bold js-s-unit"><?= pricingFmt($line['unit_selling_price'], 4); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold"><td colspan="4" class="text-end">Order Total Cost</td><td class="text-end pricing-calc" id="summary_total_cost"><?= pricingFmt($totals['total_cost']); ?></td><td class="text-end pricing-calc" id="summary_total_selling"><?= pricingFmt($totals['selling_price']); ?></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label" for="notes">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="2" <?= $readOnly ? 'readonly' : ''; ?>><?= e($notes); ?></textarea>
            </div>
        </div>

        <?php if (!$readOnly): ?>
            <div class="d-flex justify-content-end gap-2 mb-4">
                <?php if (!$saved): ?><a href="pricing_edit.php?order_id=<?= (int)$order['id']; ?>" class="btn btn-outline-secondary">Change products</a><?php endif; ?>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Pricing</button>
            </div>
        <?php endif; ?>
    </form>

    <script>
    (function () {
        const form = document.getElementById('pricing-form');
        const marginInput = document.getElementById('margin_percent');
        const num = v => { const n = parseFloat(v); return isFinite(n) && n > 0 ? n : 0; };
        const fmt = (v, d = 2) => v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
        const qty = v => String(parseFloat(v.toFixed(4)));
        const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        function recalc() {
            const factor = 1 + num(marginInput.value) / 100;
            document.getElementById('margin_multiplier').textContent = qty(factor * 100) + '%';
            let orderCost = 0, orderSelling = 0;

            form.querySelectorAll('.pricing-line').forEach(card => {
                const i = card.dataset.index;
                const quantity = num(card.querySelector('.js-qty').value);
                const basis = num(card.querySelector('.js-basis').value);
                const scale = basis > 0 ? quantity / basis : 0;

                let raw = 0;
                card.querySelectorAll('.js-material').forEach(row => {
                    const orderQty = num(row.querySelector('.js-formula-qty').value) * scale;
                    const total = orderQty * num(row.querySelector('.js-cost').value);
                    row.querySelector('.js-order-qty').textContent = qty(orderQty);
                    row.querySelector('.js-row-total').textContent = fmt(total);
                    raw += total;
                });

                let packUnit = 0;
                card.querySelectorAll('.js-pack-item').forEach(row => {
                    packUnit += num(row.querySelector('.js-pack-qty').value) * num(row.querySelector('.js-pack-cost').value);
                });
                const enabled = card.querySelector('.js-pack-enabled').checked;
                card.querySelector('.js-pack-body').classList.toggle('opacity-50', !enabled);
                const pack = enabled ? packUnit * quantity : 0;
                const total = raw + pack;
                const selling = total * factor;

                card.querySelector('.js-raw-total').textContent = fmt(raw);
                card.querySelector('.js-pack-unit').textContent = fmt(packUnit, 4);
                card.querySelector('.js-pack-total').textContent = fmt(pack);
                card.querySelector('.js-line-total').textContent = fmt(total);
                card.querySelector('.js-line-selling').textContent = fmt(selling);
                card.querySelector('.js-line-unit').textContent = fmt(quantity > 0 ? selling / quantity : 0, 4);
                card.querySelector('.js-line-unit-cost').textContent = fmt(quantity > 0 ? total / quantity : 0, 4);

                const s = form.querySelector('[data-summary="' + i + '"]');
                s.querySelector('.js-s-qty').textContent = qty(quantity);
                s.querySelector('.js-s-raw').textContent = fmt(raw);
                s.querySelector('.js-s-pack').textContent = fmt(pack);
                s.querySelector('.js-s-total').textContent = fmt(total);
                s.querySelector('.js-s-selling').textContent = fmt(selling);
                s.querySelector('.js-s-unit').textContent = fmt(quantity > 0 ? selling / quantity : 0, 4);

                orderCost += total;
                orderSelling += selling;
            });

            document.getElementById('order_total_cost').textContent = fmt(orderCost);
            document.getElementById('order_total_selling').textContent = fmt(orderSelling);
            document.getElementById('summary_total_cost').textContent = fmt(orderCost);
            document.getElementById('summary_total_selling').textContent = fmt(orderSelling);
        }

        // Switching packaging kit replaces the packaging rows with that kit's items and last prices.
        form.querySelectorAll('.js-pack-option').forEach(select => {
            select.addEventListener('change', function () {
                const card = this.closest('.pricing-line');
                const i = card.dataset.index;
                const options = JSON.parse(card.querySelector('.js-pack-options').textContent || '[]');
                const option = options.find(o => String(o.id) === this.value);
                const rows = (option ? option.items : []).map((item, p) => {
                    const name = `lines[${i}][packaging][${p}]`;
                    return `<tr class="js-pack-item"><td>${escapeHtml(item.name)}
                        <input type="hidden" name="${name}[product_id]" value="${escapeHtml(item.product_id)}">
                        <input type="hidden" name="${name}[name]" value="${escapeHtml(item.name)}">
                        <input type="hidden" name="${name}[unit]" value="${escapeHtml(item.unit)}">
                        <input type="hidden" name="${name}[price_source]" value="${escapeHtml(item.price_source)}">
                        <input type="hidden" class="js-pack-qty" name="${name}[quantity]" value="${escapeHtml(item.quantity)}"></td>
                        <td class="text-end text-nowrap">${qty(num(item.quantity))} ${escapeHtml(item.unit)}</td>
                        <td><input type="number" step="any" min="0" class="form-control form-control-sm pricing-input js-pack-cost" name="${name}[unit_cost]" value="${qty(num(item.unit_cost))}">
                        <div class="small text-muted">${escapeHtml(item.price_source)}</div></td></tr>`;
                });
                card.querySelector('.js-pack-rows').innerHTML = rows.join('') || '<tr><td colspan="3" class="text-muted text-center">This packaging option has no items.</td></tr>';
                recalc();
            });
        });

        form.addEventListener('input', recalc);
        form.addEventListener('change', recalc);
        recalc();
    })();
    </script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
