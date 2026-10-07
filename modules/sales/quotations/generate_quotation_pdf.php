<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';

// Permission check
if (!hasPermission('quotations.manage')) {
    die("You don't have permission to access this page");
}

// Get quotation ID
$quotation_id = $_GET['id'] ?? 0;
if (!canAccessQuotation($quotation_id)) {
    die('You do not have permission to print this quotation.');
}

// Fetch quotation details
$stmt = $pdo->prepare("
    SELECT q.*, c.name AS customer_name, c.tax_number, c.address,
           cc.name AS contact_name, cc.phone AS contact_phone,
           u.name AS created_by_name
    FROM quotations q
    JOIN customers c ON q.customer_id = c.id
    LEFT JOIN customer_contacts cc ON q.contact_id = cc.id
    LEFT JOIN users u ON q.created_by = u.id
    WHERE q.id = ?
");
$stmt->execute([$quotation_id]);
$quotation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    die("Quotation not found");
}

// Fetch quotation items
$stmt = $pdo->prepare("
    SELECT qi.*, p.name AS product_name, p.sku, p.barcode, p.type
    FROM quotation_items qi
    JOIN products p ON qi.product_id = p.id
    WHERE qi.quotation_id = ?
");
$stmt->execute([$quotation_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check permissions for price visibility
$canViewFinalPrices = canViewProductPrice('final');

// Clean output buffer and suppress warnings before PDF generation
if (ob_get_length()) ob_clean();
error_reporting(E_ERROR | E_PARSE);

// Include TCPDF library
require_once '../../../tcpdf/tcpdf.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor(getBrandName($_SESSION['login_region'] ?? 'factory'));
$pdf->SetTitle('Quotation #' . $quotation['id']);
$pdf->SetSubject('Quotation');

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setPrintHeader(false);
$primaryFont = 'aealarabiya';

// Add a page
$pdf->AddPage();

// Logo
$brandSlug = $_SESSION['login_region'] ?? 'factory';
$logoPath = ROOT_PATH . '/' . getBrandLogoFile($brandSlug);
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 15, 18, 15, '', 'PNG');
}

// Quotation title
$pdf->SetFont($primaryFont, 'B', 16);
$pdf->Ln(10);
$pdf->Cell(0, 10, 'QUOTATION', 0, 1, 'C');
$pdf->SetFont($primaryFont, '', 11);
$pdf->Cell(0, 6, getBrandName($brandSlug), 0, 1, 'C');
$pdf->Ln(5);

// Quotation details
$pdf->SetFont($primaryFont, '', 10);
$pdf->Cell(50, 5, 'Quotation Number:', 0, 0);
$pdf->Cell(0, 5, $quotation['id'], 0, 1);
$pdf->Cell(50, 5, 'Quotation Date:', 0, 0);
$pdf->Cell(0, 5, date('F j, Y', strtotime($quotation['quotation_date'])), 0, 1);
$pdf->Cell(50, 5, 'Expiry Date:', 0, 0);
$pdf->Cell(0, 5, (!empty($quotation['expiry_date']) ? date('F j, Y', strtotime($quotation['expiry_date'])) : 'Not specified'), 0, 1);
$pdf->Cell(50, 5, 'Customer:', 0, 0);
$pdf->Cell(0, 5, $quotation['customer_name'], 0, 1);
$pdf->Cell(50, 5, 'Tax Number:', 0, 0);
$pdf->Cell(0, 5, $quotation['tax_number'] ?? '', 0, 1);
$pdf->Cell(50, 5, 'Address:', 0, 0);
$pdf->MultiCell(0, 5, $quotation['address'] ?? '', 0, 1);
$pdf->Cell(50, 5, 'Contact:', 0, 0);
$pdf->Cell(0, 5, $quotation['contact_name'] . ' (' . $quotation['contact_phone'] . ')', 0, 1);
$pdf->Cell(50, 5, 'Status:', 0, 0);
$statusLabels = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
    'converted' => 'Converted'
];
$pdf->Cell(0, 5, $statusLabels[$quotation['status']] ?? ucfirst($quotation['status']), 0, 1);
$pdf->Ln(5);

// HTML cells wrap long names and repeat the column headings on new pages.
$pdf->SetFont($primaryFont, '', 10);
$html = '<table border="1" cellpadding="5"><thead><tr style="background-color:#e9ecef;font-weight:bold;">'
    . '<th width="7%">#</th><th width="43%">Product / SKU / Barcode</th>'
    . '<th width="12%">Quantity</th><th width="19%">Unit Price</th><th width="19%">Line Total</th>'
    . '</tr></thead><tbody>';
foreach ($items as $index => $item) {
    $product = htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8');
    foreach (['sku' => 'SKU', 'barcode' => 'Barcode'] as $key => $label) {
        if (!empty($item[$key])) $product .= '<br /><small>' . $label . ': ' . htmlspecialchars($item[$key], ENT_QUOTES, 'UTF-8') . '</small>';
    }
    $visible = canViewProductPrice($item['type']);
    $html .= '<tr><td width="7%">' . ($index + 1) . '</td><td width="43%">' . $product . '</td>'
        . '<td width="12%" align="right">' . htmlspecialchars((string)$item['quantity']) . '</td>'
        . '<td width="19%" align="right">' . ($visible ? number_format($item['unit_price'], 2) : 'Hidden') . '</td>'
        . '<td width="19%" align="right">' . ($visible ? number_format($item['total_price'], 2) : 'Hidden') . '</td></tr>';
}
$html .= '</tbody></table><br /><table border="1" cellpadding="5"><tr>'
    . '<td width="81%" align="right"><b>Quotation Total</b></td><td width="19%" align="right">'
    . ($canViewFinalPrices ? number_format($quotation['total_amount'], 2) : 'Hidden') . '</td></tr></table>';
$pdf->writeHTML($html, true, false, true, false, '');

// Notes
if (!empty($quotation['notes'])) {
    $pdf->Ln(10);
    $pdf->SetFont($primaryFont, 'I', 9);
    $pdf->MultiCell(0, 5, 'Notes: ' . $quotation['notes']);
}

// Footer
$pdf->Ln(8);
$pdf->SetFont($primaryFont, 'I', 8);
$pdf->Cell(0, 5, 'Generated by ' . $quotation['created_by_name'] . ' on ' . date('Y-m-d H:i:s'), 0, 1);
$pdf->Cell(0, 5, 'This quotation is valid until ' . (!empty($quotation['expiry_date']) ? date('F j, Y', strtotime($quotation['expiry_date'])) : 'Not specified'), 0, 1, 'C');

// Output PDF
$pdf->Output('quotation_' . $quotation['id'] . '.pdf', 'I');
?>
