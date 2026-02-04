<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasPermission('sales.orders.view_all') && !hasPermission('sales.orders.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order reference.']);
    exit;
}

$orderStmt = $pdo->prepare("
    SELECT o.id, o.internal_id, o.order_date, o.status,
           o.total_amount, o.paid_amount,
           c.name AS customer_name, c.phone AS customer_phone,
           cc.name AS contact_name, cc.phone AS contact_phone,
           f.name AS factory_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN customer_contacts cc ON o.contact_id = cc.id
    LEFT JOIN factories f ON o.factory_id = f.id
    WHERE o.id = ?
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

$itemsStmt = $pdo->prepare("
    SELECT p.name AS product_name, oi.quantity, oi.unit_price, oi.total_price
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$balance = (float)$order['total_amount'] - (float)$order['paid_amount'];
$statusLabel = ucwords(str_replace('-', ' ', $order['status']));

echo json_encode([
    'success' => true,
    'order' => [
        'id' => (int)$order['id'],
        'internal_id' => $order['internal_id'],
        'order_date' => date('d/m/Y', strtotime($order['order_date'])),
        'status_label' => $statusLabel,
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'],
        'contact_name' => $order['contact_name'],
        'contact_phone' => $order['contact_phone'],
        'factory_name' => $order['factory_name'],
        'total_amount' => number_format((float)$order['total_amount'], 2),
        'paid_amount' => number_format((float)$order['paid_amount'], 2),
        'balance' => number_format($balance, 2)
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
