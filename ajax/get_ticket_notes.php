<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!hasPermission('tickets.manage') && !hasPermission('tickets.create') && !hasPermission('tickets.view')) {
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
$roleId = $_SESSION['role_id'] ?? null;

if (!hasPermission('tickets.manage')) {
    $ticket = $conn->query("SELECT assigned_to_role_id, assigned_to_user_id, created_by FROM tickets WHERE id = " . $ticketId)->fetch_assoc();
    if (!$ticket) { echo json_encode(['success' => false]); exit; }
    $allowed = ((int)($ticket['assigned_to_role_id'] ?? 0) === (int)$roleId)
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
