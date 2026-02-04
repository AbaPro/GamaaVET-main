<?php
session_start();
require_once __DIR__ . '/functions.php';

// Check if user is trying to login
if (isset($_POST['login'])) {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT u.*, r.id AS joined_role_id, r.slug AS role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? AND u.is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];

            // Prefer dynamic role from roles table; fall back to legacy column
            if (!empty($user['role_slug'])) {
                $_SESSION['role_id'] = (int)($user['joined_role_id'] ?? $user['role_id']);
                $_SESSION['role_slug'] = $user['role_slug'];
                // Backward compatibility for existing checks
                $_SESSION['user_role'] = $user['role_slug'];
            } else {
                // Legacy fallback
                $_SESSION['user_role'] = $user['role'];
            }

            // Load permission keys into session (if roles exist)
            if (function_exists('loadUserAccessToSession')) {
                loadUserAccessToSession($_SESSION['user_id']);
            }
            
            // Update last login
            $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $user['id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            logActivity("User logged in");
            //redirect('dashboard.php');
        } else {
            setAlert('danger', 'Invalid username or password');
        }
    } else {
        setAlert('danger', 'Invalid username or password');
    }
    $stmt->close();
}

// Check if user is trying to logout
if (isset($_GET['logout'])) {
    logActivity("User logged out");
    session_destroy();
    redirect(defined('BASE_URL') ? BASE_URL . 'index.php' : 'index.php');
}

// Protect pages that require authentication
$protected_pages = ['dashboard.php'];
$current_page = basename($_SERVER['PHP_SELF']);

if (in_array($current_page, $protected_pages) || strpos($_SERVER['REQUEST_URI'], 'modules/') !== false) {
    if (!isLoggedIn()) {
        setAlert('danger', 'Please login to access that page');
        redirect(defined('BASE_URL') ? BASE_URL . 'index.php' : 'index.php');
    }
}
?>
