<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

header('Content-Type: application/json');

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer.', 'options' => []]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, description
    FROM packaging_options
    WHERE customer_id = ? AND is_active = 1
    ORDER BY name ASC
");

if (!$stmt) {
    echo json_encode(['success' => true, 'options' => []]);
    exit;
}

$stmt->bind_param('i', $customerId);
$stmt->execute();
$result = $stmt->get_result();

$options = [];
while ($row = $result->fetch_assoc()) {
    $options[] = ['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description']];
}
$stmt->close();

echo json_encode(['success' => true, 'options' => $options], JSON_UNESCAPED_UNICODE);
exit;
