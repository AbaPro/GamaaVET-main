<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$providerId = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;

header('Content-Type: application/json');

if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || (!hasPermission('manufacturing.view') && !hasPermission('manufacturing.orders.create'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Factory access required.', 'formulas' => []]);
    exit;
}

if ($providerId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid provider selected',
        'formulas' => [],
    ]);
    exit;
}
if (!canAccessCustomer($providerId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Customer is outside Factory.', 'formulas' => []]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, description, batch_size, batch_unit, components_json, instructions, sample_images_json
    FROM manufacturing_formulas 
    WHERE customer_id = ? AND is_active = 1 
    ORDER BY created_at DESC
");
$stmt->bind_param('i', $providerId);
$stmt->execute();
$result = $stmt->get_result();
$formulas = [];
while ($row = $result->fetch_assoc()) {
    $components = json_decode($row['components_json'] ?? '[]', true);
    $sampleImages = json_decode($row['sample_images_json'] ?? '[]', true);
    $row['components'] = is_array($components) ? $components : [];
    $row['sample_images'] = is_array($sampleImages) ? $sampleImages : [];
    unset($row['components_json']);
    unset($row['sample_images_json']);
    $formulas[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'formulas' => $formulas,
], JSON_UNESCAPED_UNICODE);
exit;
