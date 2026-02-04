<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('sales.orders.print_invoice')) {
    die("You don't have permission to access this page");
}

// Get order ID
$order_id = $_GET['id'] ?? 0;

// Fetch order details
$stmt = $pdo->prepare("
    SELECT o.*, c.name AS customer_name, c.tax_number, c.address, 
           cc.name AS contact_name, cc.phone AS contact_phone,
           u.name AS created_by_name, f.name AS factory_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    JOIN customer_contacts cc ON o.contact_id = cc.id
    JOIN users u ON o.created_by = u.id
    LEFT JOIN factories f ON o.factory_id = f.id
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found");
}
$canViewInvoiceContactPhone = hasExplicitPermission('sales.invoice.contact_phone.view');

// Fetch order items
$stmt = $pdo->prepare("
    SELECT oi.*, p.name AS product_name, p.sku, p.barcode
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT r.*, p.name AS product_name
    FROM order_returns r
    JOIN products p ON r.product_id = p.id
    WHERE r.order_id = ?
");
$stmt->execute([$order_id]);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$viewMode = $_GET['view'] ?? 'invoice';
$viewMode = $viewMode === 'statement' ? 'statement' : 'invoice';
$itemsSubtotal = array_reduce($items, function ($carry, $item) {
    return $carry + (float)$item['total_price'];
}, 0);
$shippingAmount = $order['shipping_cost_type'] === 'manual' ? (float)$order['shipping_cost'] : 0;
$discountBasisMap = [
    'none' => 'No Discount',
    'product_quantity' => 'By Quantity',
    'cash' => 'Cash Discount',
    'free_sample' => 'Free Samples',
    'mixed' => 'Mixed'
];
$discountBasisLabel = $discountBasisMap[$order['discount_basis']] ?? ucwords(str_replace('-', ' ', $order['discount_basis']));
$shippingLabel = $order['shipping_cost_type'] === 'manual' ? 'Manual' : 'No Shipping';
$freeSampleCount = (int)$order['free_sample_count'];

// Include TCPDF library
require_once '../../tcpdf/tcpdf.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Your Company Name');
$pdf->SetTitle(($viewMode === 'statement' ? 'Statement #' : 'Invoice #') . $order['internal_id']);
$pdf->SetSubject($viewMode === 'statement' ? 'Order Statement' : 'Order Invoice');

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);
$primaryFont = 'aealarabiya';

if ($viewMode === 'statement') {
    $pdf->setRTL(true);
    $pdf->SetFont('aealarabiya', '', 18);
    $pdf->AddPage();
    $pdf->Cell(0, 10, 'بيان الطلب', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('aealarabiya', '', 12);
    $pdf->Cell(40, 6, 'رقم الطلب:', 0, 0, 'L');
    $pdf->Cell(0, 6, $order['internal_id'], 0, 1, 'R');
    $pdf->Cell(40, 6, 'تاريخ الطلب:', 0, 0, 'L');
    $pdf->Cell(0, 6, date('Y-m-d', strtotime($order['order_date'])), 0, 1, 'R');
    $pdf->Cell(40, 6, 'المصنع:', 0, 0, 'L');
    $pdf->Cell(0, 6, $order['factory_name'] ? $order['factory_name'] : 'غير محدد', 0, 1, 'R');
    $pdf->Ln(5);
    $pdf->SetFont('aealarabiya', 'B', 12);
    $pdf->Cell(120, 7, 'المنتج', 1, 0, 'C');
    $pdf->Cell(40, 7, 'الكمية', 1, 1, 'C');
    $pdf->SetFont('aealarabiya', '', 11);
    foreach ($items as $item) {
        $label = $item['product_name'];
        if (!empty($item['is_free_sample'])) {
            $label .= ' (عينة مجانية)';
        }
        $pdf->Cell(120, 7, $label, 1, 0, 'C');
        $pdf->Cell(40, 7, $item['quantity'], 1, 1, 'C');
    }
    $pdf->Ln(8);
    $pdf->SetFont('aealarabiya', 'B', 13);
    $pdf->setRTL(false);
    $pdf->Output('statement_' . $order['internal_id'] . '.pdf', 'I');
    exit;
}

// Add a page for the detailed invoice
$pdf->AddPage();

// Company information
$pdf->SetFont($primaryFont, 'B', 12);
$pdf->Cell(0, 0, 'Your Company Name', 0, 1);
$pdf->SetFont($primaryFont, '', 10);
$pdf->Cell(0, 5, '123 Business Street', 0, 1);
$pdf->Cell(0, 5, 'City, State, ZIP', 0, 1);
$pdf->Cell(0, 5, 'Phone: (123) 456-7890', 0, 1);
$pdf->Cell(0, 5, 'Email: info@yourcompany.com', 0, 1);
$pdf->Cell(0, 5, 'Tax ID: 123456789', 0, 1);

// Invoice title
$pdf->SetFont($primaryFont, 'B', 16);
$pdf->Ln(10);
$pdf->Cell(0, 10, 'INVOICE', 0, 1, 'C');
$pdf->Ln(5);

// Invoice details
$pdf->SetFont($primaryFont, '', 10);
$pdf->Cell(50, 5, 'Invoice Number:', 0, 0);
$pdf->Cell(0, 5, $order['internal_id'], 0, 1);
$pdf->Cell(50, 5, 'Invoice Date:', 0, 0);
$pdf->Cell(0, 5, date('F j, Y', strtotime($order['order_date'])), 0, 1);
$pdf->Cell(50, 5, 'Customer:', 0, 0);
$pdf->Cell(0, 5, $order['customer_name'], 0, 1);
$pdf->Cell(50, 5, 'Tax Number:', 0, 0);
$pdf->Cell(0, 5, $order['tax_number'], 0, 1);
$pdf->Cell(50, 5, 'Address:', 0, 0);
$pdf->MultiCell(0, 5, $order['address'], 0, 1);
$pdf->Cell(50, 5, 'Contact:', 0, 0);
$contactLine = $order['contact_name'];
if ($canViewInvoiceContactPhone && !empty($order['contact_phone'])) {
    $contactLine .= ' (' . $order['contact_phone'] . ')';
}
$pdf->Cell(0, 5, $contactLine, 0, 1);
$pdf->Cell(50, 5, 'Factory:', 0, 0);
$pdf->Cell(0, 5, $order['factory_name'] ? $order['factory_name'] : 'Not assigned', 0, 1);
$pdf->Ln(5);

// Items table
$pdf->SetFont($primaryFont, 'B', 10);
$pdf->Cell(15, 7, '#', 1, 0, 'C');
$pdf->Cell(75, 7, 'Product', 1, 0);
$pdf->Cell(20, 7, 'Qty', 1, 0, 'C');
$pdf->Cell(30, 7, 'Unit Price', 1, 0, 'R');
$pdf->Cell(30, 7, 'Total', 1, 1, 'R');

$pdf->SetFont($primaryFont, '', 10);
$counter = 1;
foreach ($items as $item) {
    $pdf->Cell(15, 7, $counter++, 1, 0, 'C');
    $productLabel = $item['product_name'];
    if (!empty($item['is_free_sample'])) {
        $productLabel .= ' (Free Sample)';
    }
    $pdf->Cell(75, 7, $productLabel, 1, 0);
    $pdf->Cell(20, 7, $item['quantity'], 1, 0, 'C');
    $pdf->Cell(30, 7, number_format($item['unit_price'], 2), 1, 0, 'R');
    $pdf->Cell(30, 7, number_format($item['total_price'], 2), 1, 1, 'R');
}

$hasDiscountSection = (float)$order['discount_amount'] > 0
    || (float)$order['discount_percentage'] > 0
    || (int)$order['discount_product_count'] > 0
    || $freeSampleCount > 0;

if ($hasDiscountSection) {
    $pdf->Ln(6);
    $pdf->SetFont($primaryFont, 'B', 10);
    $pdf->Cell(0, 6, 'Discount Details', 0, 1);
    $pdf->SetFont($primaryFont, '', 10);
    $pdf->Cell(50, 6, 'Discount %:', 0, 0);
    $pdf->Cell(0, 6, number_format($order['discount_percentage'], 2) . '%', 0, 1);
    $pdf->Cell(50, 6, 'Discount Type:', 0, 0);
    $pdf->Cell(0, 6, $discountBasisLabel, 0, 1);
    $pdf->Cell(50, 6, 'Discount Amount:', 0, 0);
    $pdf->Cell(0, 6, number_format($order['discount_amount'], 2), 0, 1);
    $pdf->Cell(50, 6, 'Discounted Products:', 0, 0);
    $pdf->Cell(0, 6, (int)$order['discount_product_count'], 0, 1);
    $pdf->Cell(50, 6, 'Free Samples:', 0, 0);
    $pdf->Cell(0, 6, $freeSampleCount, 0, 1);
}

$pdf->SetFont($primaryFont, '', 10);
$pdf->Cell(50, 6, 'Shipping:', 0, 0);
$pdf->Cell(0, 6, $shippingLabel . ' - ' . number_format($shippingAmount, 2), 0, 1);

// Summary
$pdf->SetFont($primaryFont, 'B', 10);
$pdf->Cell(140, 7, 'Items Subtotal:', 1, 0, 'R');
$pdf->Cell(30, 7, number_format($itemsSubtotal, 2), 1, 1, 'R');
$pdf->Cell(140, 7, 'Discount Amount:', 1, 0, 'R');
$pdf->Cell(30, 7, '-' . number_format($order['discount_amount'], 2), 1, 1, 'R');
$pdf->Cell(140, 7, 'Shipping (' . $shippingLabel . '):', 1, 0, 'R');
$pdf->Cell(30, 7, number_format($shippingAmount, 2), 1, 1, 'R');
$pdf->Cell(140, 7, 'Total:', 1, 0, 'R');
$pdf->Cell(30, 7, number_format($order['total_amount'], 2), 1, 1, 'R');
$pdf->Cell(140, 7, 'Paid Amount:', 1, 0, 'R');
$pdf->Cell(30, 7, number_format($order['paid_amount'], 2), 1, 1, 'R');

$balance = $order['total_amount'] - $order['paid_amount'];
$pdf->Cell(140, 7, 'Balance Due:', 1, 0, 'R');
$pdf->Cell(30, 7, number_format($balance, 2), 1, 1, 'R');

if (!empty($returns)) {
    $pdf->Ln(8);
    $pdf->SetFont($primaryFont, 'B', 11);
    $pdf->Cell(0, 7, 'Return Details', 0, 1);
    $pdf->SetFont($primaryFont, 'B', 10);
    $pdf->Cell(80, 7, 'Product', 1, 0);
    $pdf->Cell(25, 7, 'Qty', 1, 0, 'C');
    $pdf->Cell(65, 7, 'Reason', 1, 1);
    $pdf->SetFont($primaryFont, '', 10);
    foreach ($returns as $return) {
        $pdf->Cell(80, 7, $return['product_name'], 1, 0);
        $pdf->Cell(25, 7, $return['returned_quantity'], 1, 0, 'C');
        $reasonText = $return['reason'] ?? '';
        $pdf->MultiCell(65, 7, $reasonText, 1, 'L', 0, 1);
    }
}

// Notes
$pdf->Ln(10);
$pdf->SetFont($primaryFont, 'I', 9);
$pdf->MultiCell(0, 5, 'Notes: ' . $order['notes']);

// Footer
$pdf->SetY(-30);
$pdf->SetFont($primaryFont, 'I', 8);
$pdf->Cell(0, 5, 'Generated by ' . $order['created_by_name'] . ' on ' . date('Y-m-d H:i:s'), 0, 1);
$pdf->Cell(0, 5, 'Thank you for your business!', 0, 1, 'C');

// Output PDF
$pdf->Output('invoice_' . $order['internal_id'] . '.pdf', 'I');
