<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!hasPermission('inventories.view')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['inventory_id']) || !is_numeric($_GET['inventory_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid inventory ID']);
    exit;
}

$inventory_id = (int)$_GET['inventory_id'];

if (!canAccessInventory($inventory_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Inventory is outside the selected region']);
    exit;
}

$productScope = getProductChannelScopeSql('p', 'c', 'f');

// Get only products belonging to the selected Factory/direct-sales channel.
$sql = "SELECT p.id, p.name, p.sku, p.type, ip.quantity
        FROM inventory_products ip 
        JOIN products p ON ip.product_id = p.id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN factories f ON f.id = c.factory_id
        WHERE ip.inventory_id = ?
          AND $productScope
        ORDER BY p.name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventory_id);
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'products' => $products]);
?>
