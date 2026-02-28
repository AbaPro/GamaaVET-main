<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.delete')) {
    setAlert('danger', 'Access denied.');
    redirect('bottle_sizes.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setAlert('danger', 'Invalid bottle size ID.');
    redirect('bottle_sizes.php');
}

try {
    // Unlink from orders before deleting (FK is ON DELETE SET NULL, but be explicit)
    $stmt = $pdo->prepare("DELETE FROM bottle_sizes WHERE id = ?");
    $stmt->execute([$id]);
    setAlert('success', 'Bottle size deleted successfully.');
} catch (Exception $e) {
    setAlert('danger', 'Error deleting bottle size: ' . $e->getMessage());
}

redirect('bottle_sizes.php');
