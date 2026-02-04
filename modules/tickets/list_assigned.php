<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn() || (!hasPermission('tickets.manage') && !hasPermission('tickets.create') && !hasPermission('tickets.view'))) {
  echo json_encode([]);
  exit;
}
global $conn; if (!isset($_SESSION['role_id'])) loadUserAccessToSession($_SESSION['user_id']);
$roleId = $_SESSION['role_id'] ?? null; $userId = $_SESSION['user_id'];
if ($roleId === null) { echo json_encode([]); exit; }
$stmt = $conn->prepare("SELECT id, title, status, priority, assigned_to_role_id FROM tickets WHERE status IN ('open','in_progress') AND (assigned_to_role_id = ? OR assigned_to_user_id = ? OR created_by = ?) ORDER BY FIELD(status,'open','in_progress'), priority DESC, created_at DESC LIMIT 25");
$stmt->bind_param('iii', $roleId, $userId, $userId);
$stmt->execute();
$res = $stmt->get_result();
$out = [];
while ($t = $res->fetch_assoc()) {
  $roleName = null;
  if (!empty($t['assigned_to_role_id'])) {
    $rid = (int)$t['assigned_to_role_id'];
    $r = $conn->query('SELECT name FROM roles WHERE id=' . $rid)->fetch_assoc();
    $roleName = $r['name'] ?? null;
  }
  $out[] = [
    'id' => (int)$t['id'],
    'title' => $t['title'],
    'status' => $t['status'],
    'priority' => $t['priority'],
    'assigned_role' => $roleName,
  ];
}
$stmt->close();
echo json_encode($out);
