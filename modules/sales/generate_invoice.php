<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('sales.orders.print_invoice')) {
    die("You don't have permission to access this page");
}

// Get order ID
$order_id = $_GET['id'] ?? 0;
if (!canAccessOrder($order_id)) {
    die('You do not have permission to print this order.');
}

// Fetch order details
$stmt = $pdo->prepare("
    SELECT o.*, c.name AS customer_name, c.tax_number, c.address, 
           cc.name AS contact_name, cc.phone AS contact_phone,
           u.name AS created_by_name, f.name AS factory_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN customer_contacts cc ON o.contact_id = cc.id
    LEFT JOIN users u ON o.created_by = u.id
    LEFT JOIN factories f ON o.factory_id = f.id
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found");
}
$order['internal_id'] = trim((string)($order['internal_id'] ?? '')) !== '' ? $order['internal_id'] : 'Order #' . $order['id'];
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

// Clean output buffer and suppress warnings before PDF generation
if (ob_get_length()) ob_clean();
error_reporting(E_ERROR | E_PARSE);

// Include TCPDF library
require_once '../../tcpdf/tcpdf.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor(getBrandName($_SESSION['login_region'] ?? 'factory'));
$pdf->SetTitle(($viewMode === 'statement' ? 'Statement #' : 'Invoice #') . $order['internal_id']);
$pdf->SetSubject($viewMode === 'statement' ? 'Order Statement' : 'Order Invoice');

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setPrintHeader(false);
$primaryFont = 'aealarabiya';

if ($viewMode === 'statement') {
    $pdf->setRTL(true);
    $pdf->SetFont('aealarabiya', '', 18);
    $pdf->AddPage();

    $logoPath = ROOT_PATH . '/' . getBrandLogoFile($_SESSION['login_region'] ?? 'factory');
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 180, 10, 15, '', 'PNG');
    }

    $pdf->Cell(0, 10, 'بيان الطلب', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('aealarabiya', '', 12);
    $pdf->Cell(40, 6, 'رقم الطلب:', 0, 0, 'L');
    $pdf->Cell(0, 6, $order['internal_id'], 0, 1, 'R');
    $pdf->Cell(40, 6, 'تاريخ الطلب:', 0, 0, 'L');
    $pdf->Cell(0, 6, date('Y-m-d', strtotime($order['order_date'])), 0, 1, 'R');
    $pdf->Cell(40, 6, 'اسم العميل:', 0, 0, 'L');
    $pdf->Cell(0, 6, $order['customer_name'], 0, 1, 'R');
    $pdf->Ln(5);
    $pdf->SetFont('aealarabiya', '', 11);
    $table = '<table border="1" cellpadding="5"><thead><tr style="font-weight:bold;background-color:#e9ecef;"><th width="75%">المنتج</th><th width="25%">الكمية</th></tr></thead><tbody>';
    foreach ($items as $item) {
        $label = $item['product_name'] . (!empty($item['is_free_sample']) ? ' (عينة مجانية)' : '');
        $table .= '<tr><td width="75%">' . htmlspecialchars($label) . '<br /><small>SKU: ' . htmlspecialchars($item['sku'] ?? '') . '</small></td>'
            . '<td width="25%">' . htmlspecialchars((string)$item['quantity']) . '</td></tr>';
    }
    $table .= '</tbody></table>';
    $pdf->writeHTML($table, true, false, true, false, '');
    // Dispatch Prep section
    $pdf->Ln(8);
    $pdf->SetFont('aealarabiya', 'B', 13);
    $pdf->Cell(0, 8, 'تجهيز الشحن (Dispatch Prep)', 0, 1, 'C');
    $pdf->Ln(3);

    // Details
    $pdf->SetFont('aealarabiya', 'B', 11);
    $pdf->Cell(0, 7, 'تفاصيل الشحن', 0, 1, 'R');
    $pdf->SetFont('aealarabiya', '', 11);
    $detailFields = ['عدد المنتج النهائي', 'عدد الكراتين', 'وحدات/كرتونة'];
    foreach ($detailFields as $field) {
        $pdf->Cell(100, 7, $field . ':', 0, 0, 'R');
        $pdf->Cell(60, 7, '', 'B', 1, 'L');
    }

    $pdf->Ln(8);
    $pdf->SetFont('aealarabiya', 'B', 13);
    $pdf->setRTL(false);
    $pdf->Output('statement_' . $order['internal_id'] . '.pdf', 'I');
    exit;
}

// Add a page for the detailed invoice
$pdf->AddPage();

// Logo
$brandSlug = $_SESSION['login_region'] ?? 'factory';
$logoPath = ROOT_PATH . '/' . getBrandLogoFile($brandSlug);
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 15, 18, 15, '', 'PNG');
}

// Invoice title
$pdf->SetFont($primaryFont, 'B', 16);
$pdf->Ln(10);
$pdf->Cell(0, 10, 'INVOICE', 0, 1, 'C');
$pdf->SetFont($primaryFont, '', 11);
$pdf->Cell(0, 6, getBrandName($brandSlug), 0, 1, 'C');
$pdf->Ln(5);

// Invoice details
$pdf->SetFont($primaryFont, '', 10);
$pdf->Cell(50, 5, 'Invoice Number:', 0, 0);
$pdf->Cell(0, 5, $order['internal_id'], 0, 1);
$pdf->Cell(50, 5, 'Invoice Date:', 0, 0);
$pdf->Cell(0, 5, date('F j, Y', strtotime($order['order_date'])), 0, 1);
$pdf->Cell(50, 5, 'Customer:', 0, 0);
$pdf->Cell(0, 5, $order['customer_name'], 0, 1);
$pdf->Cell(50, 5, 'Address:', 0, 0);
$pdf->MultiCell(0, 5, $order['address'] ?? '', 0, 1);
$pdf->Cell(50, 5, 'Contact:', 0, 0);
$contactLine = $order['contact_name'];
if ($canViewInvoiceContactPhone && !empty($order['contact_phone'])) {
    $contactLine .= ' (' . $order['contact_phone'] . ')';
}
$pdf->Cell(0, 5, $contactLine, 0, 1);
$pdf->Ln(5);

// Repeat headings and wrap long product names across pages.
$pdf->SetFont($primaryFont, '', 10);
$pdf->Cell(0, 6, 'Currency: ' . ($order['currency'] ?? 'EGP'), 0, 1);
$table = '<table border="1" cellpadding="5"><thead><tr style="font-weight:bold;background-color:#e9ecef;">'
    . '<th width="7%">#</th><th width="43%">Product / SKU / Barcode</th><th width="12%">Quantity</th><th width="19%">Unit Price</th><th width="19%">Line Total</th></tr></thead><tbody>';
foreach ($items as $index => $item) {
    $label = htmlspecialchars($item['product_name'] . (!empty($item['is_free_sample']) ? ' (Free Sample)' : ''));
    foreach (['sku' => 'SKU', 'barcode' => 'Barcode'] as $key => $field) {
        if (!empty($item[$key])) $label .= '<br /><small>' . $field . ': ' . htmlspecialchars($item[$key]) . '</small>';
    }
    $table .= '<tr><td width="7%">' . ($index + 1) . '</td><td width="43%">' . $label . '</td>'
        . '<td width="12%" align="right">' . htmlspecialchars((string)$item['quantity']) . '</td>'
        . '<td width="19%" align="right">' . number_format($item['unit_price'], 2) . '</td>'
        . '<td width="19%" align="right">' . number_format($item['total_price'], 2) . '</td></tr>';
}
$table .= '</tbody></table>';
$pdf->writeHTML($table, true, false, true, false, '');

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
$pdf->Cell(0, 6, number_format($shippingAmount, 2), 0, 1);

// Summary
$pdf->SetFont($primaryFont, 'B', 10);
$pdf->Cell(140, 7, 'Items Subtotal:', 1, 0, 'R');
$pdf->Cell(30, 7, number_format($itemsSubtotal, 2), 1, 1, 'R');
if ((float)$order['discount_amount'] > 0) {
    // Only display the cash discount row if an amount was subtracted
    $pdf->Cell(140, 7, 'Discount Amount:', 1, 0, 'R');
    $pdf->Cell(30, 7, '-' . number_format($order['discount_amount'], 2), 1, 1, 'R');
}
$pdf->Cell(140, 7, 'Shipping:', 1, 0, 'R');
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
    $pdf->SetFont($primaryFont, '', 10);
    $table = '<table border="1" cellpadding="5"><thead><tr style="font-weight:bold;background-color:#e9ecef;"><th width="45%">Product</th><th width="15%">Quantity</th><th width="40%">Reason</th></tr></thead><tbody>';
    foreach ($returns as $return) {
        $table .= '<tr><td width="45%">' . htmlspecialchars($return['product_name']) . '</td>'
            . '<td width="15%">' . htmlspecialchars((string)$return['returned_quantity']) . '</td>'
            . '<td width="40%">' . nl2br(htmlspecialchars($return['reason'] ?? '')) . '</td></tr>';
    }
    $table .= '</tbody></table>';
    $pdf->writeHTML($table, true, false, true, false, '');
}

// Notes
$pdf->Ln(10);
$pdf->SetFont($primaryFont, 'I', 9);
$pdf->MultiCell(0, 5, 'Notes: ' . ($order['notes'] ?? ''));

// Footer
$pdf->Ln(8);
$pdf->SetFont($primaryFont, 'I', 8);
$pdf->Cell(0, 5, 'Generated by ' . $order['created_by_name'] . ' on ' . date('Y-m-d H:i:s'), 0, 1);
$pdf->Cell(0, 5, 'Thank you for your business!', 0, 1, 'C');

// Output PDF
$pdf->Output('invoice_' . $order['internal_id'] . '.pdf', 'I');
