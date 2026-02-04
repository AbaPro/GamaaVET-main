<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasPermission('customers.whatsapp_portal')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Customer id is required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, name, phone, whatsapp_phone, portal_token, portal_token_expires, 
                               portal_password_hint, portal_password_hash
                        FROM customers WHERE id = ?");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Customer not found']);
    exit;
}

$now = new DateTime();
$expiresAt = !empty($customer['portal_token_expires']) ? new DateTime($customer['portal_token_expires']) : null;

if (empty($customer['portal_token']) || !$expiresAt || $expiresAt < $now) {
    $newToken = bin2hex(random_bytes(20));
    $expiresAt = (new DateTime('+30 days'));
    $tokenStmt = $conn->prepare("UPDATE customers SET portal_token = ?, portal_token_expires = ? WHERE id = ?");
    $expiresStr = $expiresAt->format('Y-m-d H:i:s');
    $tokenStmt->bind_param("ssi", $newToken, $expiresStr, $customer_id);
    $tokenStmt->execute();
    $tokenStmt->close();
    $customer['portal_token'] = $newToken;
    $customer['portal_token_expires'] = $expiresStr;
}

$portalUrl = BASE_URL . 'portal/customer_portal.php?token=' . urlencode($customer['portal_token']);
$rawPhone = !empty($customer['whatsapp_phone']) ? $customer['whatsapp_phone'] : $customer['phone'];
$digitsOnly = preg_replace('/\D+/', '', $rawPhone);

if (empty($digitsOnly)) {
    echo json_encode(['success' => false, 'message' => 'No WhatsApp number on file.']);
    exit;
}

$message = "مرحباً {$customer['name']}، يمكنك متابعة طلباتك ومدفوعاتك من خلال الرابط التالي: {$portalUrl}";
$whatsappUrl = 'https://wa.me/' . $digitsOnly . '?text=' . rawurlencode($message);

echo json_encode([
    'success' => true,
    'portal_url' => $portalUrl,
    'whatsapp_url' => $whatsappUrl,
    'expires_at' => $customer['portal_token_expires'],
    'password_required' => !empty($customer['portal_password_hash']),
    'password_hint' => $customer['portal_password_hint']
], JSON_UNESCAPED_UNICODE);
