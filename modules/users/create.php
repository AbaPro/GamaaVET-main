<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/users_functions.php';

// Check permission
if (!hasPermission('users.manage')) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $required = ['name', 'username', 'email', 'password', 'confirm_password'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'All fields are required'];
            header("Location: index.php");
            exit();
        }
    }
    
    // Check if passwords match
    if ($_POST['password'] !== $_POST['confirm_password']) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Passwords do not match'];
        header("Location: index.php");
        exit();
    }
    
    // Check if username or email already exists
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $_POST['username'], $_POST['email']);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Username or email already exists'];
        header("Location: index.php");
        exit();
    }
    
    // Create user (accept role_id from form)
    if (createUser($_POST)) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User created successfully'];
    } else {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to create user'];
    }
    
    header("Location: index.php");
    exit();
}

header("Location: index.php");
exit();
?>
