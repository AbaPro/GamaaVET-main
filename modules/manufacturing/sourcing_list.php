<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/sourcing_planning.php';

if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || !hasPermission('manufacturing.view') || !hasPermission('manufacturing.view_step_sourcing')) {
    http_response_code(403);
    exit('Access denied.');
}
$filters = [
    'customer_id' => max(0, (int)($_GET['customer_id'] ?? 0)),
    'location_id' => max(0, (int)($_GET['location_id'] ?? 0)),
    'source' => in_array($_GET['source'] ?? '', ['formula', 'packaging'], true) ? $_GET['source'] : '',
    'search' => trim((string)($_GET['search'] ?? '')),
];
$canViewNames = hasPermission('manufacturing.component.name.view');
$plan = manufacturing_sourcing_plan($filters, $canViewNames);
$customers = $pdo->query('SELECT c.id, c.name FROM customers c LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id WHERE '
    . getCustomerChannelScopeSql('c', 'customer_factory') . ' ORDER BY c.name')->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
function sourcing_plan_quantity(float $value): string {
    return rtrim(rtrim(number_format($value, 7), '0'), '.');
}
$page_title = 'Sourcing & Procurement';
require_once '../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h2>Sourcing &amp; Procurement</h2>
        <p class="text-muted mb-0">One combined material list for manufacturing orders awaiting sourcing.</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Orders</a>
</div>
<div class="card mb-4"><div class="card-body">
    <form method="get" class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="customer_id">Customer</label>
            <select class="form-select" id="customer_id" name="customer_id">
                <option value="">All customers</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int)$customer['id']; ?>" <?= $filters['customer_id'] === (int)$customer['id'] ? 'selected' : ''; ?>><?= e($customer['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="location_id">Location</label>
            <select class="form-select" id="location_id" name="location_id">
                <option value="">All locations</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int)$location['id']; ?>" <?= $filters['location_id'] === (int)$location['id'] ? 'selected' : ''; ?>><?= e($location['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="source">Materials</label>
            <select class="form-select" id="source" name="source">
                <option value="">All materials</option>
                <option value="formula" <?= $filters['source'] === 'formula' ? 'selected' : ''; ?>>Formula materials</option>
                <option value="packaging" <?= $filters['source'] === 'packaging' ? 'selected' : ''; ?>>Packaging</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="search">Find a material</label>
            <input class="form-control" id="search" name="search" value="<?= e($filters['search']); ?>" placeholder="Material, SKU or barcode">
        </div>
        <div class="col-md-2 d-flex align-items-end gap-2">
            <button class="btn btn-primary" type="submit">Apply</button>
            <a href="sourcing_list.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div></div>
<div class="d-flex flex-wrap gap-3 mb-3">
    <div class="card"><div class="card-body py-2 px-4"><strong class="fs-4" id="planOrderCount"><?= $plan['order_count']; ?></strong> <span class="text-muted">order<?= $plan['order_count'] === 1 ? '' : 's'; ?> needing materials</span></div></div>
    <div class="card"><div class="card-body py-2 px-4"><strong class="fs-4" id="planMaterialCount"><?= count($plan['materials']); ?></strong> <span class="text-muted">material line<?= count($plan['materials']) === 1 ? '' : 's'; ?></span></div></div>
</div>
<div class="alert alert-info small">
    Totals include outstanding formula and packaging requirements across the orders shown.
    Completed or cancelled orders, completed sourcing steps, and materials already received or deducted from stock are excluded.
    Use the order breakdown to see how much each product needs. This list does not change stock or distribute materials.
</div>
<?php if ($plan['warnings']): ?>
<details class="alert alert-warning small">
    <summary>Some orders need a setup check (<?= count($plan['warnings']); ?>)</summary>
    <ul class="mt-2 mb-0">
        <?php foreach ($plan['warnings'] as $warning): ?>
            <li><a href="order.php?id=<?= (int)$warning['order']['id']; ?>"><?= e($warning['order']['order_number']); ?></a>: <?= e($warning['message']); ?></li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<div class="card"><div class="table-responsive">
<table class="table align-middle mb-0" id="sourcingSummary">
    <thead class="table-light"><tr>
        <th scope="col">Material</th><th scope="col" class="text-end">Total required</th>
        <th scope="col">Unit</th><th scope="col">Source</th><th scope="col">Orders</th>
    </tr></thead>
    <tbody>
    <?php foreach ($plan['materials'] as $index => $material): ?>
        <tr class="material-row">
            <td class="py-3"><strong><?= e($material['name']); ?></strong>
                <?php if ($material['sku']): ?><div class="text-muted small"><?= e($material['sku']); ?></div><?php endif; ?>
                <?php if (!$material['product_id']): ?><div class="text-warning small">Unlinked formula item — listed separately</div><?php endif; ?>
            </td>
            <td class="text-end fw-bold fs-5"><?= sourcing_plan_quantity($material['quantity']); ?></td>
            <td><?= e($material['unit']); ?></td>
            <td><?php foreach (array_keys($material['sources']) as $source): ?><span class="badge bg-light text-dark border me-1"><?= $source === 'packaging' ? 'Packaging' : 'Formula'; ?></span><?php endforeach; ?></td>
            <td><button type="button" class="btn btn-sm btn-outline-primary js-toggle-orders" aria-expanded="false" aria-controls="material-orders-<?= $index; ?>"><?= count($material['orders']); ?> order<?= count($material['orders']) === 1 ? '' : 's'; ?> <i class="fas fa-chevron-down ms-1" aria-hidden="true"></i></button></td>
        </tr>
        <tr class="order-breakdown" id="material-orders-<?= $index; ?>" hidden><td colspan="5" class="bg-light p-3">
            <div class="fw-semibold mb-2">Order breakdown: <?= e($material['name']); ?></div>
            <table class="table table-sm bg-white mb-0">
                <thead><tr><th>Order / Customer</th><th>Final product</th><th>Location</th><th class="text-end">Required quantity</th></tr></thead>
                <tbody><?php foreach ($material['orders'] as $order): ?>
                    <tr>
                        <td><a href="order.php?id=<?= $order['id']; ?>"><?= e($order['order_number']); ?></a><div class="small text-muted"><?= e($order['customer_name']); ?></div></td>
                        <td><?= e($order['product_name'] ?? '—'); ?></td><td><?= e($order['location_name'] ?? '—'); ?></td>
                        <td class="text-end"><?= sourcing_plan_quantity($order['quantity']); ?> <?= e($material['unit']); ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </td></tr>
    <?php endforeach; ?>
    <?php if (!$plan['materials']): ?><tr><td colspan="5" class="text-center text-muted py-5">No outstanding materials match these filters.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>
<script>
    document.querySelectorAll('.js-toggle-orders').forEach(function (button) {
        button.addEventListener('click', function () {
            const row = document.getElementById(button.getAttribute('aria-controls'));
            row.hidden = !row.hidden;
            button.setAttribute('aria-expanded', String(!row.hidden));
        });
    });
</script>
<?php require_once '../../includes/footer.php'; ?>
