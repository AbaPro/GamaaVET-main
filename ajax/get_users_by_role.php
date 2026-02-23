<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role_id = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;

if ($role_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid role ID']);
    exit;
}

$stmt = $conn->prepare("SELECT id, name, username FROM users WHERE role_id = ? AND is_active = 1 ORDER BY name ASC");
$stmt->bind_param("i", $role_id);
$stmt->execute();
$result = $stmt->get_result();
$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = [
        'id' => $row['id'],
        'name' => $row['name'] . ' (' . $row['username'] . ')'
    ];
}

$stmt->close();

echo json_encode(['success' => true, 'users' => $users]);
