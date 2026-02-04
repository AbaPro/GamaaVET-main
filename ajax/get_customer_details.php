<?php
// ajax/get_customer_details.php
require_once '../config/database.php';   // must define $conn (MySQLi)
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

// authz
require_once '../includes/functions.php';
if (!(hasPermission('customers.details.view') || hasPermission('quotations.manage') || hasPermission('sales.orders.create') || hasPermission('sales.orders.view_all'))) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

// accept both ?customer_id= and ?id=
$customer_id = 0;
if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
    $customer_id = (int) $_GET['customer_id'];
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $customer_id = (int) $_GET['id'];
}

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID', 'currentId' => $customer_id]); exit;
}

// (optional) verify exists
$chk = $conn->prepare("SELECT id FROM customers WHERE id=?");
$chk->bind_param("i", $customer_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Customer not found']); exit;
}
$chk->close();

// Fetch customer details
$custStmt = $conn->prepare("SELECT c.*, f.name AS factory_name 
                            FROM customers c 
                            LEFT JOIN factories f ON c.factory_id = f.id 
                            WHERE c.id = ?");
$custStmt->bind_param("i", $customer_id);
$custStmt->execute();
$customer = $custStmt->get_result()->fetch_assoc();
$custStmt->close();

// fetch contacts
$stmt = $conn->prepare("SELECT id, name, phone, is_primary
                        FROM customer_contacts
                        WHERE customer_id = ?
                        ORDER BY is_primary DESC, name");
$stmt->bind_param("i", $customer_id);
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
    'customer' => $customer,
    'contacts' => $contacts
], JSON_UNESCAPED_UNICODE);
exit;



