<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

header('Content-Type: application/json');

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer.', 'options' => []]);
    exit;
}

$hasPackagingProductColumn = $conn->query("SHOW COLUMNS FROM packaging_options LIKE 'product_id'")->num_rows > 0;

$sql = "
    SELECT id, name, description" . ($hasPackagingProductColumn ? ", product_id" : "") . "
    FROM packaging_options
    WHERE customer_id = ? AND is_active = 1
";
$params = [$customerId];
$types = 'i';

if ($hasPackagingProductColumn && $productId > 0) {
    $sql .= " AND (product_id = ? OR product_id IS NULL)";
    $params[] = $productId;
    $types .= 'i';
}

$sql .= " ORDER BY name ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => true, 'options' => []]);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$options = [];
while ($row = $result->fetch_assoc()) {
    $option = ['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description']];
    if ($hasPackagingProductColumn) {
        $option['product_id'] = $row['product_id'];
    }
    $options[] = $option;
}
$stmt->close();

echo json_encode(['success' => true, 'options' => $options], JSON_UNESCAPED_UNICODE);
exit;
