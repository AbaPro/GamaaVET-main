<?php
require_once '../includes/auth.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!hasPermission('purchases.view')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$vendor_id = $_GET['vendor_id'] ?? 0;

try {
    $stmt = $pdo->prepare("SELECT id, order_date, total_amount, status FROM purchase_orders WHERE vendor_id = ? ORDER BY order_date DESC");
    $stmt->execute([$vendor_id]);
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'pos' => $pos]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
