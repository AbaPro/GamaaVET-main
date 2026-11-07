<?php
require_once '../../includes/auth.php';
require_once __DIR__ . '/lib.php';
if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || !hasPermission('manufacturing.view') || !canViewManufacturingAudit()) {
    http_response_code(403); exit('Audit and unlocked formula access required.');
}
// Read-only: historical breakage cannot safely be converted into material quantities.
$rows = $pdo->query("SELECT isl.id, isl.source_id AS order_id, mo.order_number, p.sku, p.name,
    isl.change_quantity, isl.created_at, i.name AS inventory_name
    FROM inventory_stock_logs isl JOIN inventories i ON i.id = isl.inventory_id
    JOIN products p ON p.id = isl.product_id AND p.type = 'final'
    JOIN manufacturing_orders mo ON mo.id = isl.source_id
    JOIN customers c ON c.id = mo.customer_id LEFT JOIN factories f ON f.id = c.factory_id
    WHERE isl.source_type = 'manufacturing_waste' AND i.system_key = 'manufacturing_waste'
    AND " . getCustomerChannelScopeSql('c', 'f') . ' ORDER BY isl.id')->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'Legacy waste reconciliation';
require_once '../../includes/header.php';
?>
<h2>Legacy waste reconciliation</h2>
<p>These historical movements posted final-product breakage to مخزن الهالك. Review the linked order and supporting material records before reversing or replacing a movement. This report does not change balances or infer material loss.</p>
<div class="table-responsive"><table class="table js-datatable"><thead><tr><th>Log ID</th><th>Order</th><th>SKU</th><th>Final product</th><th>Quantity</th><th>Warehouse</th><th>Date</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><?= (int)$row['id']; ?></td><td><a href="order.php?id=<?= (int)$row['order_id']; ?>"><?= htmlspecialchars($row['order_number']); ?></a></td>
<td><?= htmlspecialchars($row['sku']); ?></td><td><?= htmlspecialchars($row['name']); ?></td><td><?= htmlspecialchars($row['change_quantity']); ?></td><td><?= htmlspecialchars($row['inventory_name']); ?></td><td><?= htmlspecialchars($row['created_at']); ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php require_once '../../includes/footer.php'; ?>
