<?php
// CLI/cron script to check low stock and push notifications
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow CLI
if (php_sapi_name() !== 'cli') {
    echo "CLI only"; exit;
}

global $conn; 

// Find purchasing_supervisor role id; fallback to admin
$purchaseRoleId = null;
$res = $conn->query("SELECT id FROM roles WHERE slug='purchasing_supervisor' LIMIT 1");
if ($res && $r = $res->fetch_assoc()) { $purchaseRoleId = (int)$r['id']; }
if (!$purchaseRoleId) {
    $res = $conn->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1");
    if ($res && $r = $res->fetch_assoc()) { $purchaseRoleId = (int)$r['id']; }
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
        // Avoid duplicate flooding: check if there is an unread recent notification for same product
        $check = $conn->prepare("SELECT id FROM notifications WHERE type='low_stock' AND entity_type='product' AND entity_id=? AND is_read=0 LIMIT 1");
        $pid = (int)$row['product_id'];
        $check->bind_param('i', $pid);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
        if ($exists) continue;

        $title = 'Low stock: ' . $row['name'];
        $msg = 'Available quantity ' . $qty . ' is at/below minimum stock ' . $min . '.';
        createNotification('low_stock', $title, $msg, 'inventories', 'product', $pid, $qty <= 0 ? 'danger' : 'warning', $purchaseRoleId, null, null);
        $count++;
    }
}

echo "Low stock notifications created: $count\n";

