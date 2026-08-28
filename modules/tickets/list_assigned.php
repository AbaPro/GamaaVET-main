<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn() || (!hasPermission('tickets.manage') && !hasPermission('tickets.create')
    && !hasPermission('tickets.view') && !hasPermission('tickets.update_status'))) {
  echo json_encode([]);
  exit;
}
global $conn; if (!isset($_SESSION['role_id'])) loadUserAccessToSession($_SESSION['user_id']);
$roleId = $_SESSION['role_id'] ?? null; $userId = $_SESSION['user_id'];
if ($roleId === null) { echo json_encode([]); exit; }
$stmt = $conn->prepare("SELECT t.id, t.title, t.status, t.priority, r.name AS assigned_role
                        FROM tickets t
                        LEFT JOIN roles r ON r.id = t.assigned_to_role_id
                        WHERE t.status IN ('open','in_progress')
                          AND (t.assigned_to_role_id = ? OR t.assigned_to_user_id = ? OR t.created_by = ?)
                        ORDER BY FIELD(t.status,'open','in_progress'), t.priority DESC, t.created_at DESC
                        LIMIT 25");
$stmt->bind_param('iii', $roleId, $userId, $userId);
$stmt->execute();
$res = $stmt->get_result();
$out = [];
while ($t = $res->fetch_assoc()) {
  $out[] = [
    'id' => (int)$t['id'],
    'title' => $t['title'],
    'status' => $t['status'],
    'priority' => $t['priority'],
    'assigned_role' => $t['assigned_role'],
  ];
}
$stmt->close();
echo json_encode($out);
