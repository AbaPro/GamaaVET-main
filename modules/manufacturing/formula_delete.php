<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.delete')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        // Check if formula is used in any orders
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM manufacturing_orders WHERE formula_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $count = $checkStmt->get_result()->fetch_assoc()['count'];
        
        if ($count > 0) {
            setAlert('danger', 'Cannot delete formula because it is requested by ' . $count . ' manufacturing order(s).');
        } else {
            $stmt = $conn->prepare("DELETE FROM manufacturing_formulas WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                setAlert('success', 'Formula deleted successfully.');
            } else {
                setAlert('danger', 'Failed to delete formula.');
            }
        }
    } catch (Exception $e) {
        setAlert('danger', 'Error: ' . $e->getMessage());
    }
}

redirect('formulas.php');
