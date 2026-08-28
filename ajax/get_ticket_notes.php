<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Mirrors the entry gate in modules/tickets/view.php so the polling endpoint
// never exposes more than the page it backs.
if (!hasPermission('tickets.manage') && !hasPermission('tickets.create')
    && !hasPermission('tickets.view') && !hasPermission('tickets.update_status')) {
    echo json_encode(['success' => false]);
    exit;
}

global $conn;
$ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
if ($ticketId <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
// Without this the role scope check below compares against null and silently
// denies access to users whose session predates the role being loaded.
if (!isset($_SESSION['role_id']) && $userId) {
    loadUserAccessToSession($userId);
}
$roleId = $_SESSION['role_id'] ?? null;

if (!hasPermission('tickets.manage')) {
    $scopeStmt = $conn->prepare("SELECT assigned_to_role_id, assigned_to_user_id, created_by FROM tickets WHERE id = ?");
    $scopeStmt->bind_param('i', $ticketId);
    $scopeStmt->execute();
    $ticket = $scopeStmt->get_result()->fetch_assoc();
    $scopeStmt->close();
    if (!$ticket) { echo json_encode(['success' => false]); exit; }
    $isUnassigned = empty($ticket['assigned_to_role_id']) && empty($ticket['assigned_to_user_id']);
    $allowed = $isUnassigned
        || ((int)($ticket['assigned_to_role_id'] ?? 0) === (int)$roleId)
        || ((int)($ticket['assigned_to_user_id'] ?? 0) === (int)$userId)
        || ((int)($ticket['created_by'] ?? 0) === (int)$userId);
    if (!$allowed) { echo json_encode(['success' => false]); exit; }
}

$stmt = $conn->prepare("SELECT tn.*, u.name AS user_name FROM ticket_notes tn LEFT JOIN users u ON u.id = tn.user_id WHERE tn.ticket_id = ? ORDER BY tn.created_at DESC");
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$out = [];
foreach ($notes as $note) {
    $out[] = [
        'user_name'  => htmlspecialchars($note['user_name'] ?? 'System'),
        'created_at' => formatDateTime($note['created_at']),
        'note_html'  => nl2br(htmlspecialchars($note['note'])),
    ];
}

echo json_encode(['success' => true, 'count' => count($out), 'notes' => $out]);
