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

$inventory_id = sanitize($_GET['inventory_id']);

// Get inventory products
$sql = "SELECT p.id, p.name, p.sku, ip.quantity 
        FROM inventory_products ip 
        JOIN products p ON ip.product_id = p.id 
        WHERE ip.inventory_id = ? 
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
