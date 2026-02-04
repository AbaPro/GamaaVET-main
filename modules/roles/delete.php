<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('users.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { redirect('index.php'); }

// prevent delete if assigned to users
$cnt = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role_id = " . $id)->fetch_assoc();
if ($cnt && (int)$cnt['c'] > 0) {
    setAlert('danger','Cannot delete a role assigned to users.');
    redirect('index.php');
}

$conn->begin_transaction();
try {
    $conn->query("DELETE FROM role_permissions WHERE role_id = " . $id);
    $conn->query("DELETE FROM roles WHERE id = " . $id);
    $conn->commit();
    setAlert('success','Role deleted.');
} catch (Throwable $e) {
    $conn->rollback();
    setAlert('danger','Failed to delete role: ' . $e->getMessage());
}
redirect('index.php');

