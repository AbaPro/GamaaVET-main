<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

// Check permission
if (!hasPermission('manufacturing.delete')) {
    $_SESSION['error'] = "You don't have permission to delete manufacturing orders.";
    header("Location: index.php");
    exit();
}

// Get order ID
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id <= 0) {
    $_SESSION['error'] = "Invalid order ID.";
    header("Location: index.php");
    exit();
}

try {
    // Check if order exists
    $stmt = $pdo->prepare("SELECT order_number FROM manufacturing_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        $_SESSION['error'] = "Manufacturing order not found.";
        header("Location: index.php");
        exit();
    }

    // Get all document paths before deleting the order
    $docStmt = $pdo->prepare("
        SELECT d.file_path 
        FROM manufacturing_step_documents d
        JOIN manufacturing_order_steps s ON d.manufacturing_order_step_id = s.id
        WHERE s.manufacturing_order_id = ?
    ");
    $docStmt->execute([$order_id]);
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

    // Delete the order. cascade delete should take care of steps, documents, and related checklists in DB.
    $deleteStmt = $pdo->prepare("DELETE FROM manufacturing_orders WHERE id = ?");
    $deleteStmt->execute([$order_id]);

    // Delete physical files
    foreach ($documents as $doc) {
        $fullPath = ROOT_PATH . '/' . $doc['file_path'];
        if (file_exists($fullPath) && is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    $_SESSION['success'] = "Manufacturing order #{$order['order_number']} and its associated data have been deleted successfully.";
} catch (PDOException $e) {
    $_SESSION['error'] = "Error deleting order: " . $e->getMessage();
}

header("Location: index.php");
exit();
