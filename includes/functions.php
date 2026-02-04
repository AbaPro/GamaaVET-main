<?php
require_once __DIR__ . '/../config/database.php';

// Function to sanitize input data
function sanitize($data) {
    global $conn;
    return htmlspecialchars(strip_tags($conn->real_escape_string(trim($data))));
}

// Function to generate random string
function generateRandomString($length = 10) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

// Function to generate unique ID
function generateUniqueId($prefix = 'ORD') {
    return $prefix . '-' . date('Ymd') . '-' . generateRandomString(6);
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Load the current user's role + permissions from DB into session (idempotent)
function loadUserAccessToSession($userId) {
    global $conn;
    if (!isset($userId)) return;

    // Fetch role info
    $stmt = $conn->prepare("SELECT u.role_id, r.slug AS role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['role_slug'])) {
            $_SESSION['role_id'] = (int)$row['role_id'];
            $_SESSION['role_slug'] = $row['role_slug'];
            // Backward compatibility: keep user_role as slug
            $_SESSION['user_role'] = $row['role_slug'];
        }
    }
    $stmt->close();

    // Fetch permission keys for the role
    if (isset($_SESSION['role_id'])) {
        $permStmt = $conn->prepare("SELECT p.`key` FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?");
        $permStmt->bind_param("i", $_SESSION['role_id']);
        $permStmt->execute();
        $permRes = $permStmt->get_result();
        $perms = [];
        while ($row = $permRes->fetch_assoc()) {
            $perms[] = $row['key'];
        }
        $_SESSION['permissions'] = $perms;
        $permStmt->close();
    }
}

// Function alias: treat hasRole as permission check
function hasRole($permissionKey) {
    return hasPermission($permissionKey);
}

// Function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

// Function to display alert messages
function displayAlert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo '<div class="alert alert-' . $alert['type'] . ' alert-dismissible fade show" role="alert">
                ' . $alert['message'] . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>';
        unset($_SESSION['alert']);
    }
}

// Function to set alert message
function setAlert($type, $message) {
    $_SESSION['alert'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Function to log activity
function logActivity($action, $details = null) {
    global $conn;

    $action = trim((string)$action);
    if (strlen($action) > 250) {
        $action = substr($action, 0, 250);
    }

    $detailsPayload = $details !== null
        ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if ($userId === null) {
        $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (NULL, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $action, $detailsPayload, $ipAddress);
    } else {
        $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isss", $userId, $action, $detailsPayload, $ipAddress);
    }

    $stmt->execute();
    $stmt->close();
}

// Get status color for badges
function getStatusColor($status) {
    switch ($status) {
        case 'new':
            return 'primary';
        case 'in-production':
            return 'info';
        case 'in-packing':
            return 'warning';
        case 'delivering':
            return 'primary';
        case 'delivered':
            return 'success';
        case 'returned':
        case 'returned-refunded':
            return 'danger';
        case 'partially-returned':
        case 'partially-returned-refunded':
            return 'warning';
        default:
            return 'secondary';
    }
}

// Get product type color for badges
function getProductTypeColor($type) {
    switch ($type) {
        case 'primary':
            return 'primary';
        case 'final':
            return 'success';
        case 'material':
            return 'info';
        default:
            return 'secondary';
    }
}


function getProductById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getCategoryById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getInventoryQuantitiesForProduct($product_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT ip.quantity, i.name as inventory_name, i.location 
                           FROM inventory_products ip 
                           JOIN inventories i ON ip.inventory_id = i.id 
                           WHERE ip.product_id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getProductComponents($product_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT pc.quantity, p.name as component_name 
                           FROM product_components pc 
                           JOIN products p ON pc.component_id = p.id 
                           WHERE pc.final_product_id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function formatCurrency($amount) {
    return number_format($amount, 2) . ' EGP';
}

function formatDateTime($datetime) {
    return date('M j, Y g:i A', strtotime($datetime));
}

function hasPermission($permissionKey) {
    if (!isLoggedIn()) return false;

    // Ensure role/permissions are loaded
    if (!isset($_SESSION['role_slug']) || !isset($_SESSION['permissions'])) {
        loadUserAccessToSession($_SESSION['user_id']);
    }

    $roleSlug = $_SESSION['role_slug'] ?? ($_SESSION['user_role'] ?? null);
    if ($roleSlug === 'admin') {
        return true;
    }

    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
        return false;
    }

    return in_array($permissionKey, $_SESSION['permissions'], true);
}

function hasExplicitPermission($permissionKey) {
    if (!isLoggedIn()) return false;

    if (!isset($_SESSION['role_slug']) || !isset($_SESSION['permissions'])) {
        loadUserAccessToSession($_SESSION['user_id']);
    }

    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
        return false;
    }

    return in_array($permissionKey, $_SESSION['permissions'], true);
}

function canViewProductPrice($productType) {
    if ($productType === 'material') {
        return hasExplicitPermission('products.material.price.view');
    }
    if ($productType === 'final') {
        return hasExplicitPermission('products.final.price.view');
    }
    return hasExplicitPermission('products.material.price.view') || hasExplicitPermission('products.final.price.view');
}

function canViewProductCost($productType) {
    if ($productType === 'material') {
        return hasExplicitPermission('products.material.cost.view');
    }
    if ($productType === 'final') {
        return hasExplicitPermission('products.final.cost.view');
    }
    return hasExplicitPermission('products.material.cost.view') || hasExplicitPermission('products.final.cost.view');
}

// Notifications helpers
function getUnreadNotificationsCount() {
    global $conn;
    if (!isLoggedIn()) return 0;
    if (!isset($_SESSION['role_id'])) {
        loadUserAccessToSession($_SESSION['user_id']);
    }
    $roleId = $_SESSION['role_id'] ?? null;
    $userId = $_SESSION['user_id'];
    if ($roleId === null) return 0;

    $sql = "SELECT COUNT(*) AS c FROM notifications 
            WHERE is_read = 0 AND (created_for_role_id = ? OR created_for_user_id = ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $roleId, $userId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($res['c'] ?? 0);
}

function createNotification($type, $title, $message, $module = null, $entity_type = null, $entity_id = null, $severity = 'warning', $for_role_id = null, $for_user_id = null, $created_by = null) {
    global $conn;
    $sql = "INSERT INTO notifications (type,title,message,module,entity_type,entity_id,severity,created_for_role_id,created_for_user_id,created_by) 
            VALUES (?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssiissi', $type,$title,$message,$module,$entity_type,$entity_id,$severity,$for_role_id,$for_user_id,$created_by);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

function createTicket($notification_id, $title, $description, $priority = 'medium', $assigned_to_role_id = null, $assigned_to_user_id = null) {
    global $conn;
    $created_by = $_SESSION['user_id'] ?? null;
    $sql = "INSERT INTO tickets (notification_id,title,description,priority,assigned_to_role_id,assigned_to_user_id,created_by) 
            VALUES (?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssiii', $notification_id, $title, $description, $priority, $assigned_to_role_id, $assigned_to_user_id, $created_by);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

function displayMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        echo '<div class="alert alert-' . $message['type'] . ' alert-dismissible fade show" role="alert">';
        echo $message['text'];
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['message']);
    }
}

?>
