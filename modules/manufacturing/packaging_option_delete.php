<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.delete')) {
    setAlert('danger', 'Access denied.');
    redirect('packaging_options.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setAlert('danger', 'Invalid packaging option.');
    redirect('packaging_options.php');
}

// Guard: refuse if any order references this option
$checkStmt = $conn->prepare("SELECT COUNT(*) FROM manufacturing_orders WHERE packaging_option_id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkStmt->bind_result($usageCount);
$checkStmt->fetch();
$checkStmt->close();

if ($usageCount > 0) {
    setAlert('danger', "Cannot delete: this packaging option is used by {$usageCount} manufacturing order(s).");
    redirect('packaging_options.php');
}

try {
    $stmt = $pdo->prepare("DELETE FROM packaging_options WHERE id = ?");
    $stmt->execute([$id]);
    setAlert('success', 'Packaging option deleted.');
} catch (Exception $e) {
    setAlert('danger', 'Error: ' . $e->getMessage());
}

redirect('packaging_options.php');
