<?php
// logout.php - Secure logout handler with error handling

// 1. Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Include required files
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// 3. Logout logic
try {
    // Try to log the activity (with error handling)
    if (isset($_SESSION['user_id'])) {
        @logActivity("User logged out"); // @ suppresses errors if table doesn't exist
    }
} catch (Exception $e) {
    // Silently ignore logging errors to not interrupt logout process
    error_log("Logout activity logging failed: " . $e->getMessage());
}

// 4. Completely destroy the session
$_SESSION = []; // Clear all session variables

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// 5. Set success message and redirect
setAlert('success', 'You have been successfully logged out.');
redirect('index.php');
?>