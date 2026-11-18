<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/lib.php';

if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || !hasPermission('manufacturing.view') || !canAccessManufacturingStep('delivering')) {
    http_response_code(403);
    exit('Delivery access required.');
}

$search = trim((string)($_GET['search'] ?? ''));
$groups = manufacturing_delivery_groups($conn);
if ($search !== '') {
    $groups = array_filter($groups, function ($rows) use ($search) {
        foreach ($rows as $row) {
            $text = $row['customer_name'] . ' ' . $row['product_name'] . ' ' . $row['order_number'] . ' ' . $row['sales_order_id'];
            if (mb_stripos($text, $search) !== false) return true;
        }
        return false;
    });
}
$page_title = 'Delivery by Sales Order';
require_once '../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div><h2>Delivery by Sales Order</h2>
        <p class="text-muted">Verify all products in each sales order together. Counts match Dispatch Prep. Delivery details and status are recorded for each manufacturing order.</p>
    </div>
    <a href="index.php?view=manufacturing" class="btn btn-outline-secondary">Manufacturing Control</a>
</div>
<form method="get" class="d-flex gap-2 mb-4">
    <input type="search" class="form-control" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Search sales order, customer, product or manufacturing order" aria-label="Search deliveries">
    <button class="btn btn-primary">Search</button>
    <a class="btn btn-outline-secondary" href="delivery.php">Reset</a>
</form>
<?php foreach ($groups as $key => $rows): ?>
    <div class="card mb-4" id="<?= htmlspecialchars($key); ?>">
        <div class="card-header fw-bold">
            <?= $rows[0]['linked_sales_order_id'] ? 'Sales order #' . (int)$rows[0]['sales_order_id'] : htmlspecialchars($rows[0]['order_number']) . ' — No linked sales order'; ?>
            <span class="ms-2"><?= htmlspecialchars($rows[0]['customer_name']); ?></span>
        </div>
        <div class="card-body">
            <?= manufacturing_delivery_group_html($rows); ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="alert alert-info">No deliveries found.</div><?php endif; ?>
<?php require_once '../../includes/footer.php'; ?>
