<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Simple shared-secret guard
$provided = $_GET['key'] ?? '';
$secret = getenv('CRON_SECRET') ?: 'change-this-secret';
if ($provided !== $secret) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Do the same logic as CLI script
global $conn;
$purchaseRoleId = null;
$res = $conn->query("SELECT id FROM roles WHERE slug='purchasing_supervisor' LIMIT 1");
if ($res && $r = $res->fetch_assoc()) { $purchaseRoleId = (int)$r['id']; }
if (!$purchaseRoleId) {
    $res = $conn->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1");
    if ($res && $r = $res->fetch_assoc()) { $purchaseRoleId = (int)$r['id']; }
}

$sql = "SELECT p.id AS product_id, p.name, p.min_stock_level, COALESCE(SUM(ip.quantity),0) AS qty
        FROM products p
        LEFT JOIN inventory_products ip ON ip.product_id = p.id
        GROUP BY p.id, p.name, p.min_stock_level";
$result = $conn->query($sql);

$created = 0;
while ($row = $result->fetch_assoc()) {
    $min = (float)($row['min_stock_level'] ?? 0);
    if ($min <= 0) continue;
    $qty = (float)$row['qty'];
    if ($qty <= $min) {
        $pid = (int)$row['product_id'];
        $check = $conn->prepare("SELECT id FROM notifications WHERE type='low_stock' AND entity_type='product' AND entity_id=? AND is_read=0 LIMIT 1");
        $check->bind_param('i', $pid);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
        if ($exists) continue;
        $title = 'Low stock: ' . $row['name'];
        $msg = 'Available quantity ' . $qty . ' is at/below minimum stock ' . $min . '.';
        createNotification('low_stock', $title, $msg, 'inventories', 'product', $pid, $qty <= 0 ? 'danger' : 'warning', $purchaseRoleId, null, null);
        $created++;
    }
}

header('Content-Type: application/json');
echo json_encode(['created' => $created]);

