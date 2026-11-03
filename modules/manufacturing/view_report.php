<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

$stepKey = $_GET['step'] ?? '';
$definitions = manufacturing_get_step_definitions();
if (!is_string($stepKey) || ($stepKey !== '' && !isset($definitions[$stepKey]))) {
    setAlert('danger', 'Invalid manufacturing step.');
    redirect('index.php');
}
// Step downloads are rendered for the viewer rather than reusing another user's export.
if ($stepKey === '' ? !hasPermission('manufacturing.view_report') : !hasPermission('manufacturing.view_step_' . $stepKey)) {
    setAlert('danger', 'You do not have permission to view this report.');
    redirect('index.php');
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    setAlert('danger', 'Invalid manufacturing order specified.');
    redirect('index.php');
}

// Check if order exists
$orderStmt = $conn->prepare("SELECT id, order_number, status, customer_id FROM manufacturing_orders WHERE id = ?");
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();
$orderStmt->close();

if (!$order || !canAccessCustomer((int)$order['customer_id'])) {
    setAlert('danger', 'Manufacturing order not found.');
    redirect('index.php');
}

// Generate the PDF
try {
    if ($stepKey === '') {
        $pdfPath = manufacturing_generate_full_order_pdf($conn, $orderId);
    } else {
        $stmt = $conn->prepare('SELECT mo.*, c.name AS customer_name, f.name AS formula_name, f.components_json, f.batch_size AS formula_batch_size, f.batch_unit, bs.size AS bottle_size_value, bs.unit AS bottle_size_unit FROM manufacturing_orders mo JOIN customers c ON c.id = mo.customer_id JOIN manufacturing_formulas f ON f.id = mo.formula_id LEFT JOIN bottle_sizes bs ON bs.id = mo.bottle_size_id WHERE mo.id = ?');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $stmt = $conn->prepare('SELECT * FROM manufacturing_order_steps WHERE manufacturing_order_id = ? AND step_key = ?');
        $stmt->bind_param('is', $orderId, $stepKey);
        $stmt->execute();
        $step = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$step) throw new RuntimeException('Step not found.');
        $components = manufacturing_recalculate_components($order, json_decode($order['components_json'] ?? '[]', true));
        $html = manufacturing_build_step_document_html($order, $step, ['name' => $order['formula_name']], $components, $step['notes'], manufacturing_get_step_label($stepKey), $conn);
        $payload = manufacturing_create_pdf_document($order, $stepKey, $html);
        $pdfPath = __DIR__ . '/../../' . $payload['relative_path'];
    }
    
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
