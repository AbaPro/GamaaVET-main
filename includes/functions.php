<?php
require_once __DIR__ . '/../config/database.php';

// Function to sanitize input data
function sanitize($data) {
    global $conn;
    return htmlspecialchars(strip_tags($conn->real_escape_string(trim($data))));
}

// Safe HTML escape helper (handles null values)
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Renders a row of thumbnail links for a set of file attachment rows (each with a
// file path + original name key). Used to display multi-file uploads consistently
// across modules instead of each file re-implementing its own markup/prefix.
function renderAttachmentThumbnails($rows, $filePathKey = 'file_path', $originalNameKey = 'original_name', $basePrefix = '../../') {
    if (empty($rows)) {
        return '<span class="text-muted">-</span>';
    }
    $html = '<div class="d-flex flex-wrap gap-1">';
    foreach ($rows as $row) {
        $path = $row[$filePathKey] ?? '';
        if ($path === '') {
            continue;
        }
        $url = $basePrefix . $path;
        $label = $row[$originalNameKey] ?? 'Attachment';
        $html .= '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" title="' . htmlspecialchars($label) . '">';
        $html .= '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($label) . '" style="height:40px;width:auto;object-fit:cover;border-radius:4px;cursor:pointer;">';
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

function normalizeEgyptWhatsappNumber($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if (strpos($digits, '20') === 0) {
        return $digits;
    }

    if ($digits[0] === '0') {
        return '20' . substr($digits, 1);
    }

    return '20' . $digits;
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

function uploadImageAttachment($fieldName, $relativeDir, $filenamePrefix, $required = false, &$error = null) {
    $error = null;

    if (empty($_FILES[$fieldName]['name'])) {
        if ($required) {
            $error = 'Image upload is required.';
        }
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $error = 'Failed to upload image.';
        return null;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $error = 'Unsupported image type. Allowed: JPG, PNG, GIF, WEBP.';
        return null;
    }

    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        $error = 'Image exceeds the 5MB limit.';
        return null;
    }

    $relativeDir = trim($relativeDir, '/');
    $uploadDir = ROOT_PATH . '/' . $relativeDir;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        $error = 'Failed to prepare upload folder.';
        return null;
    }

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filenamePrefix);
    $newFile = $safePrefix . '_' . uniqid('', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $uploadDir . '/' . $newFile)) {
        $error = 'Failed to save uploaded image.';
        return null;
    }

    return $relativeDir . '/' . $newFile;
}

// Multi-file version of uploadImageAttachment(). Validates and saves every file in
// $_FILES[$fieldName] (expects the field to be a `name[]`-style array input).
// $minRequired = 0 means optional (zero or more files); 1+ enforces "at least N".
// Fails the whole batch (no partial saves) on the first invalid file, matching the
// single-file helper's all-or-nothing behavior.
function uploadImageAttachments($fieldName, $relativeDir, $filenamePrefix, $minRequired = 0, &$error = null) {
    $error = null;
    $results = [];

    if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name']) || !is_array($_FILES[$fieldName]['name'])) {
        if ($minRequired > 0) {
            $error = 'At least ' . $minRequired . ' image(s) required.';
        }
        return $results;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $relativeDir = trim($relativeDir, '/');
    $uploadDir = ROOT_PATH . '/' . $relativeDir;
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filenamePrefix);

    foreach ($_FILES[$fieldName]['name'] as $idx => $originalName) {
        if ($originalName === '' || $originalName === null) {
            continue; // empty slot in the multi-file input, skip silently
        }

        $fileError = $_FILES[$fieldName]['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
        if ($fileError !== UPLOAD_ERR_OK) {
            $error = 'Failed to upload one or more images.';
            return [];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $error = 'Unsupported image type. Allowed: JPG, PNG, GIF, WEBP.';
            return [];
        }

        $size = $_FILES[$fieldName]['size'][$idx] ?? 0;
        if ($size > 5 * 1024 * 1024) {
            $error = 'One or more images exceed the 5MB limit.';
            return [];
        }

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            $error = 'Failed to prepare upload folder.';
            return [];
        }

        $newFile = $safePrefix . '_' . uniqid('', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'][$idx], $uploadDir . '/' . $newFile)) {
            $error = 'Failed to save uploaded image.';
            return [];
        }

        $results[] = [
            'path' => $relativeDir . '/' . $newFile,
            'original_name' => $originalName,
        ];
    }

    if ($minRequired > 0 && count($results) < $minRequired) {
        foreach ($results as $saved) {
            $fullPath = ROOT_PATH . '/' . $saved['path'];
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        $error = 'At least ' . $minRequired . ' image(s) required.';
        return [];
    }

    return $results;
}

// Function to generate unique SKU
function generateUniqueSku($db = null) {
    global $conn;
    $c = $db ?? $conn;
    $maxAttempts = 50;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = 'SKU-' . date('ymd') . '-' . generateRandomString(6);
        $stmt = $c->prepare("SELECT id FROM products WHERE sku = ?");
        if ($stmt) {
            $stmt->bind_param("s", $candidate);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();
            if (!$exists) {
                return $candidate;
            }
        }
    }
    throw new Exception("Unable to generate unique SKU. Please try again.");
}

// Function to generate unique barcode
function generateUniqueBarcode($db = null) {
    global $conn;
    $c = $db ?? $conn;
    $maxAttempts = 50;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = (string)random_int(100000000000, 999999999999);
        $stmt = $c->prepare("SELECT id FROM products WHERE barcode = ?");
        if ($stmt) {
            $stmt->bind_param("s", $candidate);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();
            if (!$exists) {
                return $candidate;
            }
        }
    }
    throw new Exception("Unable to generate unique barcode. Please try again.");
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
    $target = (string)$url;

    if (!headers_sent()) {
        header("Location: $target");
        exit();
    }

    // Fallback when output already started (avoid header warning).
    $escapedTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
    echo '<script>window.location.href="' . $escapedTarget . '";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $escapedTarget . '"></noscript>';
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

function tableExists($tableName) {
    global $conn;
    static $cache = [];

    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
    if ($tableName === '') {
        return false;
    }

    if (!array_key_exists($tableName, $cache)) {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }
        $stmt->bind_param("s", $tableName);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        $cache[$tableName] = ((int)$count) > 0;
    }

    return $cache[$tableName];
}

function getInventoryProductQuantity($inventoryId, $productId) {
    global $conn;

    $stmt = $conn->prepare("SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? LIMIT 1");
    if (!$stmt) {
        return 0.0;
    }
    $stmt->bind_param("ii", $inventoryId, $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (float)($row['quantity'] ?? 0);
}

function logInventoryStockChange($inventoryId, $productId, $changeQuantity, $quantityBefore, $quantityAfter, $sourceType, $sourceId = null, $unitPrice = null, $sellPrice = null, $notes = null) {
    global $conn;

    if (!tableExists('inventory_stock_logs')) {
        return;
    }

    $createdBy = $_SESSION['user_id'] ?? null;
    $sourceType = substr((string)$sourceType, 0, 50);
    $sourceId = $sourceId !== null ? (int)$sourceId : null;
    $unitPrice = ($unitPrice !== null && $unitPrice !== '') ? (float)$unitPrice : null;
    $sellPrice = ($sellPrice !== null && $sellPrice !== '') ? (float)$sellPrice : null;

    $stmt = $conn->prepare("
        INSERT INTO inventory_stock_logs
            (inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id, unit_price, sell_price, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        "iidddsiddsi",
        $inventoryId,
        $productId,
        $changeQuantity,
        $quantityBefore,
        $quantityAfter,
        $sourceType,
        $sourceId,
        $unitPrice,
        $sellPrice,
        $notes,
        $createdBy
    );
    $stmt->execute();
    $stmt->close();
}

function logProductPrice($productId, $priceType, $price, $quantity = 1, $sourceType = null, $sourceId = null, $notes = null) {
    global $conn;

    $price = (float)$price;
    $quantity = (float)$quantity;
    if ($price <= 0 || $quantity <= 0 || !in_array($priceType, ['unit', 'sell'], true) || !tableExists('product_price_logs')) {
        return;
    }

    $createdBy = $_SESSION['user_id'] ?? null;
    $sourceType = $sourceType !== null ? substr((string)$sourceType, 0, 50) : null;
    $sourceId = $sourceId !== null ? (int)$sourceId : null;

    $stmt = $conn->prepare("
        INSERT INTO product_price_logs
            (product_id, price_type, price, quantity, source_type, source_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param("isddsisi", $productId, $priceType, $price, $quantity, $sourceType, $sourceId, $notes, $createdBy);
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
    $stmt = $conn->prepare("SELECT ip.quantity, i.name as inventory_name, 
                           CONCAT(l.name, ' - ', l.address) as location 
                           FROM inventory_products ip 
                           JOIN inventories i ON ip.inventory_id = i.id 
                           LEFT JOIN locations l ON i.location_id = l.id
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

function formatCurrency($amount, $currency = 'EGP') {
    $symbols = ['EGP' => 'ج.م', 'USD' => '$', 'EUR' => '€', 'SAR' => 'ر.س'];
    $symbol = $symbols[$currency] ?? $currency;
    return number_format($amount, 2) . ' ' . $symbol;
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

function getCurrentUserRoleSlug() {
    if (!isLoggedIn()) return null;

    if (!isset($_SESSION['role_slug'])) {
        loadUserAccessToSession($_SESSION['user_id']);
    }

    return $_SESSION['role_slug'] ?? ($_SESSION['user_role'] ?? null);
}

function isAdminUser() {
    return getCurrentUserRoleSlug() === 'admin';
}

function isSalesPersonUser() {
    return in_array(getCurrentUserRoleSlug(), ['salesman', 'factory_sales', 'representative_sales'], true);
}

/**
 * SQL condition for data that belongs to the currently selected login channel.
 * Factory data has a NULL direct_sale value; direct-sales data stores its region slug.
 * Salespeople are additionally restricted to their effective customer assignment.
 */
function getCustomerChannelScopeSql($customerAlias = 'c', $factoryAlias = 'f') {
    global $conn;

    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $condition = $loginRegion === 'factory'
        ? "$customerAlias.direct_sale IS NULL"
        : "$customerAlias.direct_sale = '" . $conn->real_escape_string($loginRegion) . "'";

    if (isSalesPersonUser()) {
        $condition .= " AND COALESCE($customerAlias.sales_person_id, $factoryAlias.sales_person_id) = "
            . (int)$_SESSION['user_id'];
    }

    return $condition;
}

function getInventoryChannelScopeSql($inventoryAlias = 'i') {
    global $conn;

    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    return $loginRegion === 'factory'
        ? "$inventoryAlias.direct_sale IS NULL"
        : "$inventoryAlias.direct_sale = '" . $conn->real_escape_string($loginRegion) . "'";
}

/**
 * Check whether an inventory belongs to the currently selected login channel.
 */
function canAccessInventory($inventoryId) {
    global $conn;

    $inventoryId = (int)$inventoryId;
    if ($inventoryId <= 0 || !isLoggedIn()) return false;

    $scope = getInventoryChannelScopeSql('i');
    $stmt = $conn->prepare("SELECT i.id FROM inventories i WHERE i.id = ? AND $scope LIMIT 1");
    $stmt->bind_param('i', $inventoryId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

/**
 * Inventory additions are limited to final products whose customer belongs to
 * the same selected channel (and salesperson assignment, when applicable).
 */
function canAddProductToInventory($inventoryId, $productId) {
    global $conn;

    $inventoryId = (int)$inventoryId;
    $productId = (int)$productId;
    if ($inventoryId <= 0 || $productId <= 0 || !isLoggedIn()) return false;

    $inventoryScope = getInventoryChannelScopeSql('i');
    $customerScope = getCustomerChannelScopeSql('c', 'f');
    $sql = "SELECT p.id
            FROM products p
            JOIN customers c ON c.id = p.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            JOIN inventories i ON i.id = ?
            WHERE p.id = ?
              AND p.type = 'final'
              AND $inventoryScope
              AND $customerScope
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $inventoryId, $productId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

/**
 * A transfer is visible only when both inventories belong to the current channel.
 */
function canAccessInventoryTransfer($transferId) {
    global $conn;

    $transferId = (int)$transferId;
    if ($transferId <= 0 || !isLoggedIn()) return false;

    $sourceScope = getInventoryChannelScopeSql('source_inventory');
    $destinationScope = getInventoryChannelScopeSql('destination_inventory');
    $sql = "SELECT it.id
            FROM inventory_transfers it
            JOIN inventories source_inventory ON source_inventory.id = it.from_inventory_id
            JOIN inventories destination_inventory ON destination_inventory.id = it.to_inventory_id
            WHERE it.id = ? AND $sourceScope AND $destinationScope
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

/**
 * Salespeople are restricted to customers explicitly assigned to them, or
 * inherited from the customer's factory. Admins and non-sales operational
 * roles retain their permission-based access.
 */
function canAccessCustomer($customerId) {
    global $conn;

    $customerId = (int)$customerId;
    if ($customerId <= 0 || !isLoggedIn()) return false;
    if (!isSalesPersonUser()) return true;

    $userId = (int)$_SESSION['user_id'];
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $sql = "SELECT c.id
            FROM customers c
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE c.id = ?
              AND COALESCE(c.sales_person_id, f.sales_person_id) = ?
              AND ((? = 'factory' AND c.direct_sale IS NULL) OR c.direct_sale = ?)
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $customerId, $userId, $loginRegion, $loginRegion);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessFactory($factoryId) {
    global $conn;

    $factoryId = (int)$factoryId;
    if ($factoryId <= 0 || !isLoggedIn()) return false;
    if (!isSalesPersonUser()) return true;

    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT id FROM factories WHERE id = ? AND sales_person_id = ? LIMIT 1");
    $stmt->bind_param('ii', $factoryId, $userId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessProduct($productId) {
    global $conn;

    $productId = (int)$productId;
    if ($productId <= 0 || !isLoggedIn()) return false;
    if (!isSalesPersonUser()) return true;

    $userId = (int)$_SESSION['user_id'];
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $sql = "SELECT p.id
            FROM products p
            JOIN customers c ON c.id = p.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE p.id = ?
              AND p.type = 'final'
              AND COALESCE(c.sales_person_id, f.sales_person_id) = ?
              AND ((? = 'factory' AND c.direct_sale IS NULL) OR c.direct_sale = ?)
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $productId, $userId, $loginRegion, $loginRegion);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessOrder($orderId) {
    global $conn;

    $orderId = (int)$orderId;
    if ($orderId <= 0 || !isLoggedIn()) return false;
    if (!isSalesPersonUser()) return true;

    $userId = (int)$_SESSION['user_id'];
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $sql = "SELECT o.id
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE o.id = ?
              AND COALESCE(c.sales_person_id, f.sales_person_id) = ?
              AND ((? = 'factory' AND c.direct_sale IS NULL) OR c.direct_sale = ?)
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $orderId, $userId, $loginRegion, $loginRegion);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessQuotation($quotationId) {
    global $conn;

    $quotationId = (int)$quotationId;
    if ($quotationId <= 0 || !isLoggedIn()) return false;
    if (!isSalesPersonUser()) return true;

    $userId = (int)$_SESSION['user_id'];
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $sql = "SELECT q.id
            FROM quotations q
            JOIN customers c ON c.id = q.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE q.id = ?
              AND COALESCE(c.sales_person_id, f.sales_person_id) = ?
              AND ((? = 'factory' AND c.direct_sale IS NULL) OR c.direct_sale = ?)
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $quotationId, $userId, $loginRegion, $loginRegion);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function getActiveSalesPersons() {
    global $conn;

    $sql = "SELECT u.id, u.name, u.region, COALESCE(r.slug, u.role) AS role_slug
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.is_active = 1
              AND COALESCE(r.slug, u.role) IN ('salesman', 'factory_sales', 'representative_sales')
            ORDER BY u.name";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function isValidSalesPersonId($userId) {
    global $conn;

    $userId = (int)$userId;
    if ($userId <= 0) return false;

    $stmt = $conn->prepare("SELECT u.id
                            FROM users u
                            LEFT JOIN roles r ON r.id = u.role_id
                            WHERE u.id = ? AND u.is_active = 1
                              AND COALESCE(r.slug, u.role) IN ('salesman', 'factory_sales', 'representative_sales')
                            LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $valid = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $valid;
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
        return false; // Materials are non-sellable raw ingredients
    }
    if ($productType === 'final') {
        return hasExplicitPermission('products.final.price.view');
    }
    return hasExplicitPermission('products.final.price.view');
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
    $roleSlug = $_SESSION['role_slug'] ?? null;
    
    if ($roleSlug === 'admin') {
        $sql = "SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0";
        $res = $conn->query($sql)->fetch_assoc();
        return (int)($res['c'] ?? 0);
    }

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
    $stmt->bind_param('sssssisiii', $type, $title, $message, $module, $entity_type, $entity_id, $severity, $for_role_id, $for_user_id, $created_by);
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

function getBrandName($slug) {
    $map = [
        'factory'   => 'GammaVet',
        'curva'     => 'CureVet',
        'primer'    => 'PremiumVet',
        'naturous'  => 'Naturous',
        'activita'  => 'Activita',
    ];
    return $map[$slug] ?? ucfirst($slug);
}

function getDirectSaleOptions($selected = null) {
    $options = [
        ['value' => '', 'label' => '-- None (Factory) --'],
        ['value' => 'curva', 'label' => 'CureVet'],
        ['value' => 'primer', 'label' => 'PremiumVet'],
        ['value' => 'naturous', 'label' => 'Naturous'],
        ['value' => 'activita', 'label' => 'Activita'],
    ];
    $html = '';
    foreach ($options as $opt) {
        $sel = ($opt['value'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($opt['value']) . '"' . $sel . '>' . htmlspecialchars($opt['label']) . '</option>';
    }
    return $html;
}

function getRegionPermissionOptions($selected = null) {
    $options = [
        ['value' => '', 'label' => '-- Factory (None) --'],
        ['value' => 'curva', 'label' => 'CureVet'],
        ['value' => 'primer', 'label' => 'PremiumVet'],
        ['value' => 'naturous', 'label' => 'Naturous'],
        ['value' => 'activita', 'label' => 'Activita'],
    ];
    $html = '';
    foreach ($options as $opt) {
        $sel = ($opt['value'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($opt['value']) . '"' . $sel . '>' . htmlspecialchars($opt['label']) . '</option>';
    }
    return $html;
}

?>
