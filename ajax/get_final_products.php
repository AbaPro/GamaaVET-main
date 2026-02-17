<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$providerId = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;

header('Content-Type: application/json');

if ($providerId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid provider selected',
        'products' => [],
    ]);
    exit;
}

// Fetch products of type 'final' that belong to this customer
$stmt = $conn->prepare("
    SELECT id, name, sku 
    FROM products 
    WHERE customer_id = ? AND type = 'final'
    ORDER BY name ASC
");
$stmt->bind_param('i', $providerId);
$stmt->execute();
$result = $stmt->get_result();
$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'products' => $products,
], JSON_UNESCAPED_UNICODE);
exit;
