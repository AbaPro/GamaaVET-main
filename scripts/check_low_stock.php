<?php
// CLI/cron script to check low stock and push notifications
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow CLI
if (php_sapi_name() !== 'cli') {
    echo "CLI only"; exit;
}

global $conn;

// Notify every active role that can view low-stock inventory reports.
$recipientRoleIds = [];
$rolesSql = "SELECT DISTINCT r.id
             FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.`key` = 'inventories.low_stock.view'
               AND (r.is_active = 1 OR r.is_active IS NULL)";
$rolesResult = $conn->query($rolesSql);
if ($rolesResult) {
    while ($role = $rolesResult->fetch_assoc()) {
        $recipientRoleIds[] = (int)$role['id'];
    }
}

if (empty($recipientRoleIds)) {
    $res = $conn->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1");
    if ($res && $r = $res->fetch_assoc()) {
        $recipientRoleIds[] = (int)$r['id'];
    }
}

// Sum inventory per product and compare against min_stock_level
$sql = "SELECT p.id AS product_id, p.name, p.min_stock_level, COALESCE(SUM(ip.quantity),0) AS qty
        FROM products p
        LEFT JOIN inventory_products ip ON ip.product_id = p.id
        GROUP BY p.id, p.name, p.min_stock_level";
$result = $conn->query($sql);

$count = 0;
while ($row = $result->fetch_assoc()) {
    $min = (float)($row['min_stock_level'] ?? 0);
    if ($min <= 0) continue; // skip products without threshold
    $qty = (float)$row['qty'];
    if ($qty <= $min) {
        $pid = (int)$row['product_id'];
        $title = 'Low stock: ' . $row['name'];
        $msg = 'Available quantity ' . $qty . ' is at/below minimum stock ' . $min . '.';
        $severity = $qty <= 0 ? 'danger' : 'warning';

        foreach ($recipientRoleIds as $roleId) {
            // Avoid duplicate flooding per recipient role.
            $check = $conn->prepare("SELECT id FROM notifications WHERE type='low_stock' AND entity_type='product' AND entity_id=? AND created_for_role_id=? AND created_for_user_id IS NULL AND is_read=0 LIMIT 1");
            $check->bind_param('ii', $pid, $roleId);
            $check->execute();
            $check->store_result();
            $exists = $check->num_rows > 0;
            $check->close();
            if ($exists) continue;

            createNotification('low_stock', $title, $msg, 'inventories', 'product', $pid, $severity, $roleId, null, null);
            $count++;
        }
    }
}

echo "Low stock notifications created: $count\n";
