<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
header('Content-Type: application/json; charset=utf-8');
$productId = (int)($_GET['product_id'] ?? 0);
$locationId = (int)($_GET['location_id'] ?? 0);
if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || !(hasPermission('manufacturing.view') || hasPermission('manufacturing.orders.create') || hasPermission('sales.orders.create') || hasPermission('sales.orders.edit') || hasPermission('sales.orders.view'))
    || !canAccessProduct($productId) || ($locationId > 0 && !canAccessLocation($locationId, true))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Product unavailable in this channel.']);
    exit;
}
echo json_encode(['success' => true] + manufacturing_primary_availability($pdo, $productId, $locationId), JSON_UNESCAPED_UNICODE);
