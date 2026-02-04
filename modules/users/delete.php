<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/users_functions.php';

// Check permission
if (!hasPermission('users.manage')) {
    header("Location: /dashboard.php");
    exit();
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$id = intval($_GET['id']);

// Delete user
if (deleteUser($id)) {
    $_SESSION['message'] = ['type' => 'success', 'text' => 'User deleted successfully'];
} else {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to delete user. Make sure there is at least one admin user remaining.'];
}

header("Location: index.php");
exit();
?>
