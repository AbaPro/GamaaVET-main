<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';

header('Content-Type: application/json');

if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || !hasPermission('manufacturing.orders.create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $formulaId = isset($_POST['formula_id']) ? (int)$_POST['formula_id'] : 0;
    $locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
    $batchSize = isset($_POST['batch_size']) ? (float)$_POST['batch_size'] : 0;
    $priority = $_POST['priority'] ?? 'normal';
    $dueDate = $_POST['due_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $salesOrderId = isset($_POST['sales_order_id']) ? (int)$_POST['sales_order_id'] : null;

    if ($customerId <= 0 || $productId <= 0 || $formulaId <= 0 || $locationId <= 0 || $batchSize <= 0) {
        throw new Exception('Please fill in all required fields with valid values.');
    }
    if (!canAccessCustomer($customerId) || !canAccessProduct($productId)) {
        throw new Exception('Customer or product is outside the Factory channel.');
    }

    $productStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE id = ? AND customer_id = ? AND type = 'final' AND " . getActiveProductSql('p'));
    $productStmt->execute([$productId, $customerId]);
    if ((int)$productStmt->fetchColumn() !== 1) {
        throw new Exception('Selected final product does not belong to this Factory customer.');
    }

    $formulaStmt = $pdo->prepare("SELECT COUNT(*) FROM manufacturing_formulas WHERE id = ? AND customer_id = ? AND is_active = 1");
    $formulaStmt->execute([$formulaId, $customerId]);
    if ((int)$formulaStmt->fetchColumn() !== 1) {
        throw new Exception('Selected formula does not belong to this Factory customer.');
    }

    if ($salesOrderId) {
        if (!canAccessOrder($salesOrderId)) {
            throw new Exception('Linked sales order is outside the Factory channel.');
        }
        $salesOrderStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.id = ? AND o.customer_id = ? AND oi.product_id = ? AND o.status <> 'delivered'
        ");
        $salesOrderStmt->execute([$salesOrderId, $customerId, $productId]);
        if ((int)$salesOrderStmt->fetchColumn() === 0) {
            throw new Exception('Delivered or mismatched sales orders cannot start manufacturing.');
        }
    }

    $orderNumber = generateUniqueId('MAN');
    $createdBy = $_SESSION['user_id'] ?? null;

    $pdo->beginTransaction();

    // Insert manufacturing order
    $stmt = $pdo->prepare("
        INSERT INTO manufacturing_orders
            (order_number, customer_id, product_id, formula_id, location_id, batch_size, due_date, priority, notes, sales_order_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $orderNumber,
        $customerId,
        $productId,
        $formulaId,
        $locationId,
        $batchSize,
        $dueDate ?: null,
        $priority,
        $notes,
        $salesOrderId ?: null,
        $createdBy
    ]);
    
    $orderId = $pdo->lastInsertId();

    // Create steps
    $stepStmt = $pdo->prepare("
        INSERT INTO manufacturing_order_steps (manufacturing_order_id, step_key, label) 
        VALUES (?, ?, ?)
    ");
    foreach (manufacturing_get_step_definitions() as $stepKey => $stepMeta) {
        $stepStmt->execute([$orderId, $stepKey, $stepMeta['label']]);
    }

    $pdo->commit();

    logActivity("Created manufacturing order $orderNumber from Sales Order details", ['order_id' => $orderId, 'sales_order_id' => $salesOrderId]);

    echo json_encode([
        'success' => true, 
        'message' => "Manufacturing order $orderNumber has been created successfully.",
        'order_id' => $orderId
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
