<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/functions.php';

// Reject revoked sessions before processing any login, logout, or page action.
if (isset($_SESSION['user_id']) && !isLoggedIn()) {
    setAlert('danger', 'Your account is no longer available. Please login again.');
    redirect(defined('BASE_URL') ? BASE_URL . 'index.php' : 'index.php');
    exit;
}

// Check if user is trying to login
if (isset($_POST['login'])) {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT u.*, r.id AS joined_role_id, r.slug AS role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            // Do not carry another account's role or permissions into this login.
            unset($_SESSION['role_id'], $_SESSION['role_slug'], $_SESSION['user_role'], $_SESSION['permissions'], $_SESSION['login_region']);
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

            // Region Permission Check
            $selected_region = $_POST['region'] ?? 'factory';
            $allowed_regions = ['factory', 'curva', 'primer', 'naturous', 'activita'];
            if (!is_string($selected_region)
                || !in_array($selected_region, $allowed_regions, true)
                || !hasPermission('region.' . $selected_region)) {
                    session_unset();
                    session_destroy();
                    session_start();
                    setAlert('danger', 'You do not have permission to access the selected company.');
                    redirect('index.php');
                    exit;
            }
            $_SESSION['login_region'] = $selected_region;
            
            // Update last login
            $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $user['id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            logActivity("User logged in", ['region' => $selected_region], 'login', 'user', (int)$user['id']);
            //redirect('dashboard.php');
        } else {
            logActivity("Failed login: wrong password", ['username' => $username], 'login_failed', 'user', (int)$user['id']);
            setAlert('danger', 'Invalid username or password');
        }
    } else {
        logActivity("Failed login: unknown or inactive user", ['username' => $username], 'login_failed');
        setAlert('danger', 'Invalid username or password');
    }
    $stmt->close();
}

// Revalidate company access for existing sessions as well as new logins.
if (isLoggedIn() && !isset($_GET['logout'])) {
    loadUserAccessToSession($_SESSION['user_id']);
    $sessionRegion = $_SESSION['login_region'] ?? 'factory';
    if (!is_string($sessionRegion)
        || !in_array($sessionRegion, ['factory', 'curva', 'primer', 'naturous', 'activita'], true)
        || !hasPermission('region.' . $sessionRegion)) {
        session_unset();
        setAlert('danger', 'You do not have permission to access the selected company.');
        redirect(defined('BASE_URL') ? BASE_URL . 'index.php' : 'index.php');
        exit;
    }
}

// Check if user is trying to logout
if (isset($_GET['logout'])) {
    logActivity("User logged out", null, 'logout', 'user', $_SESSION['user_id'] ?? null);
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

// Reject direct URL and POST access to Factory-only operations; hiding their
// navigation links alone would not enforce the channel boundary.
if (isLoggedIn() && ($_SESSION['login_region'] ?? 'factory') !== 'factory') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $requestBasename = basename($requestPath);
    $isCureVetFinanceRoute = ($_SESSION['login_region'] ?? '') === 'curva'
        && strpos($requestPath, '/modules/finance/') !== false;
    $isCustomerTypeRoute = strpos($requestPath, '/modules/customers/') !== false
        && in_array($requestBasename, ['types.php', 'types_create.php', 'types_edit.php'], true);
    $isFactoryFinanceRoute = strpos($requestPath, '/modules/finance/') !== false
        && in_array($requestBasename, ['po.php', 'vendors.php', 'categories.php'], true);
    $isFactoryOnlyRoute = strpos($requestPath, '/modules/manufacturing/') !== false
        || strpos($requestPath, '/modules/purchases/') !== false
        || strpos($requestPath, '/modules/vendors/') !== false
        || strpos($requestPath, '/modules/categories/') !== false
        || strpos($requestPath, '/modules/analysis/') !== false
        || strpos($requestPath, '/modules/tickets/') !== false
        || strpos($requestPath, '/modules/roles/') !== false
        || $isCustomerTypeRoute
        || $isFactoryFinanceRoute
        || $isCureVetFinanceRoute
        || (strpos($requestPath, '/modules/users/') !== false && $requestBasename !== 'profile.php');
    if ($isFactoryOnlyRoute) {
        setAlert('danger', 'This operation is available only in the Factory channel.');
        redirect(defined('BASE_URL') ? BASE_URL . 'modules/sales/' : '../sales/');
    }
}
?>
