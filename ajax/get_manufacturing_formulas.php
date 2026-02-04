<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$providerId = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;

header('Content-Type: application/json');

if ($providerId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid provider selected',
        'formulas' => [],
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, description, batch_size, components_json, instructions 
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
    $row['components'] = is_array($components) ? $components : [];
    unset($row['components_json']);
    $formulas[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'formulas' => $formulas,
], JSON_UNESCAPED_UNICODE);
exit;
