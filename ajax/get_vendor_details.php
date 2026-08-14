<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!hasPermission('vendors.view')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid vendor ID']);
    exit;
}

$vendor_id = sanitize($_GET['id']);

// Get vendor details
$sql = "SELECT * FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Vendor not found']);
    exit;
}

$vendor = $result->fetch_assoc();
$stmt->close();

// Get vendor contacts
$stmt = $conn->prepare("SELECT id, name, phone, position, is_primary
                        FROM vendor_contacts
                        WHERE vendor_id = ?
                        ORDER BY is_primary DESC, name");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();

$contacts = [];
while ($r = $res->fetch_assoc()) {
    $r['is_primary'] = (int)$r['is_primary'] === 1;
    $contacts[] = $r;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'vendor' => $vendor,
    'contacts' => $contacts
], JSON_UNESCAPED_UNICODE);
?>
