<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasPermission('purchases.view_all') && !hasPermission('purchases.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$poId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($poId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid PO reference.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT po.id, po.order_date, po.status, po.total_amount, po.paid_amount, po.notes,
           v.name AS vendor_name, vc.name AS contact_name, vc.phone AS contact_phone,
           u.name AS created_by_name
    FROM purchase_orders po
    JOIN vendors v ON po.vendor_id = v.id
    LEFT JOIN vendor_contacts vc ON po.contact_id = vc.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.id = ?
");
$stmt->execute([$poId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Purchase order not found.']);
    exit;
}

$itemsStmt = $pdo->prepare("
    SELECT poi.quantity, poi.unit_price, poi.total_price, p.name AS product_name
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.purchase_order_id = ?
");
$itemsStmt->execute([$poId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$balance = (float)$order['total_amount'] - (float)$order['paid_amount'];
$statusLabel = ucwords(str_replace('-', ' ', $order['status']));

echo json_encode([
    'success' => true,
    'order' => [
        'id' => (int)$order['id'],
        'label' => 'PO-' . (int)$order['id'],
        'order_date' => date('d/m/Y', strtotime($order['order_date'])),
        'status_label' => $statusLabel,
        'vendor_name' => $order['vendor_name'],
        'contact_name' => $order['contact_name'],
        'contact_phone' => $order['contact_phone'],
        'created_by' => $order['created_by_name'],
        'total_amount' => number_format((float)$order['total_amount'], 2),
        'paid_amount' => number_format((float)$order['paid_amount'], 2),
        'balance' => number_format($balance, 2),
        'notes' => $order['notes'] ?? ''
    ],
    'items' => array_map(function ($item) {
        return [
            'product_name' => $item['product_name'],
            'quantity' => (float)$item['quantity'],
            'unit_price' => number_format((float)$item['unit_price'], 2),
            'total_price' => number_format((float)$item['total_price'], 2)
        ];
    }, $items)
]);
