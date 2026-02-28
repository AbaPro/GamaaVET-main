<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

// Check permission
if (!hasPermission('manufacturing.view_report')) {
    setAlert('danger', 'You do not have permission to view this report.');
    redirect('index.php');
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    setAlert('danger', 'Invalid manufacturing order specified.');
    redirect('index.php');
}

// Check if order exists
$orderStmt = $conn->prepare("SELECT id, order_number, status FROM manufacturing_orders WHERE id = ?");
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();
$orderStmt->close();

if (!$order) {
    setAlert('danger', 'Manufacturing order not found.');
    redirect('index.php');
}

// Generate the PDF
try {
    $pdfPath = manufacturing_generate_full_order_pdf($conn, $orderId);
    
    if (file_exists($pdfPath)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdfPath) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: bytes');
        @readfile($pdfPath);
        exit;
    } else {
        throw new Exception("PDF file could not be generated.");
    }
} catch (Exception $e) {
    setAlert('danger', 'Error generating report: ' . $e->getMessage());
    redirect('order.php?id=' . $orderId);
}
