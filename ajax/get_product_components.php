<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!hasPermission('products.view')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

$product_id = sanitize($_GET['product_id']);
$inventory_id = isset($_GET['inventory_id']) && is_numeric($_GET['inventory_id']) ? sanitize($_GET['inventory_id']) : null;

// Get product components
$components_sql = "SELECT pc.component_id, pc.quantity as required_quantity, p.name, p.sku 
                   FROM product_components pc 
                   JOIN products p ON pc.component_id = p.id 
                   WHERE pc.final_product_id = ?";
$components_stmt = $conn->prepare($components_sql);
$components_stmt->bind_param("i", $product_id);
$components_stmt->execute();
$components_result = $components_stmt->get_result();

$components = [];
while ($component = $components_result->fetch_assoc()) {
    // Get available quantity if inventory is specified
    if ($inventory_id) {
        $quantity_sql = "SELECT quantity FROM inventory_products 
                         WHERE inventory_id = ? AND product_id = ?";
        $quantity_stmt = $conn->prepare($quantity_sql);
        $quantity_stmt->bind_param("ii", $inventory_id, $component['component_id']);
        $quantity_stmt->execute();
        $quantity_result = $quantity_stmt->get_result();
        
        $component['available'] = $quantity_result->num_rows > 0 ? $quantity_result->fetch_assoc()['quantity'] : null;
        $quantity_stmt->close();
    } else {
        $component['available'] = null;
    }
    
    $components[] = $component;
}
$components_stmt->close();

echo json_encode(['success' => true, 'components' => $components]);
?>
