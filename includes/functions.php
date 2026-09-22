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
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
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
    $filesToSave = [];

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

    // Validate the complete batch before moving any file so a bad second image
    // cannot leave the first image orphaned on disk.
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

        $tmpName = $_FILES[$fieldName]['tmp_name'][$idx] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $error = 'Failed to validate one or more uploaded images.';
            return [];
        }

        $imageInfo = @getimagesize($tmpName);
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($imageInfo === false || !in_array($imageInfo['mime'] ?? '', $allowedMimeTypes, true)) {
            $error = 'One or more files are not valid images.';
            return [];
        }

        $filesToSave[] = [
            'tmp_name' => $tmpName,
            'extension' => $ext,
            'original_name' => $originalName,
        ];
    }

    if ($minRequired > 0 && count($filesToSave) < $minRequired) {
        $error = 'At least ' . $minRequired . ' image(s) required.';
        return [];
    }

    if (empty($filesToSave)) {
        return [];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $error = 'Failed to prepare upload folder.';
        return [];
    }

    foreach ($filesToSave as $file) {
        $newFile = $safePrefix . '_' . uniqid('', true) . '.' . $file['extension'];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $newFile)) {
            foreach ($results as $saved) {
                $fullPath = ROOT_PATH . '/' . $saved['path'];
                if (is_file($fullPath)) {
                    unlink($fullPath);
                }
            }
            $error = 'Failed to save uploaded image.';
            return [];
        }

        $results[] = [
            'path' => $relativeDir . '/' . $newFile,
            'original_name' => $file['original_name'],
        ];
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

function tableHasColumn($tableName, $columnName) {
    global $conn;
    static $cache = [];

    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
    $columnName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$columnName);
    if ($tableName === '' || $columnName === '') {
        return false;
    }

    $cacheKey = $tableName . '.' . $columnName;
    if (!array_key_exists($cacheKey, $cache)) {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ");
        if (!$stmt) {
            $cache[$cacheKey] = false;
            return false;
        }
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        $cache[$cacheKey] = ((int)$count) > 0;
    }

    return $cache[$cacheKey];
}

function productsSupportArchiving() {
    return tableHasColumn('products', 'is_active');
}

function getActiveProductSql($productAlias = 'p') {
    $productAlias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$productAlias);
    return productsSupportArchiving() ? "$productAlias.is_active = 1" : '1 = 1';
}

function getSafeProductReturnUrl($url, $fallback = 'index.php') {
    $url = trim((string)$url);
    if ($url === '' || preg_match('/[\r\n]/', $url)) {
        return $fallback;
    }

    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }

    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($path !== 'index.php' && $path !== '') {
        return $fallback;
    }

    $safe = 'index.php';
    if (!empty($parts['query'])) {
        $safe .= '?' . $parts['query'];
    }
    if (!empty($parts['fragment']) && preg_match('/^[a-zA-Z0-9_-]+$/', $parts['fragment'])) {
        $safe .= '#' . $parts['fragment'];
    }
    return $safe;
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

// Canonical storage units supported by products.unit. Quantities are converted
// through each definition's base factor, never directly between incompatible families.
function getProductUnitDefinitions() {
    return [
        'each' => [
            'label' => 'Each (pcs)',
            'symbol' => 'pcs',
            'family' => 'count',
            'base_factor' => 1.0,
            'conversion' => '1 each = 1 piece',
        ],
        'gram' => [
            'label' => 'Gram (g)',
            'symbol' => 'g',
            'family' => 'mass',
            'base_factor' => 1.0,
            'conversion' => 'Base mass unit',
        ],
        'kilo' => [
            'label' => 'Kilogram (kg)',
            'symbol' => 'kg',
            'family' => 'mass',
            'base_factor' => 1000.0,
            'conversion' => '1 kilogram = 1,000 grams',
        ],
        'milliliter' => [
            'label' => 'Milliliter (ml)',
            'symbol' => 'ml',
            'family' => 'volume',
            'base_factor' => 1.0,
            'conversion' => 'Base volume unit',
        ],
        'liter' => [
            'label' => 'Liter (L)',
            'symbol' => 'L',
            'family' => 'volume',
            'base_factor' => 1000.0,
            'conversion' => '1 liter = 1,000 milliliters',
        ],
    ];
}

function getProductUnitOptions() {
    $options = [];
    foreach (getProductUnitDefinitions() as $unit => $definition) {
        $options[$unit] = $definition['label'];
    }
    return $options;
}

function normalizeProductUnit($unit) {
    $unit = strtolower(trim((string)$unit));
    $aliases = [
        'each' => 'each',
        'piece' => 'each',
        'pieces' => 'each',
        'pc' => 'each',
        'pcs' => 'each',
        'gram' => 'gram',
        'grams' => 'gram',
        'g' => 'gram',
        'kilo' => 'kilo',
        'kilos' => 'kilo',
        'kilogram' => 'kilo',
        'kilograms' => 'kilo',
        'kg' => 'kilo',
        'milliliter' => 'milliliter',
        'milliliters' => 'milliliter',
        'millilitre' => 'milliliter',
        'millilitres' => 'milliliter',
        'ml' => 'milliliter',
        'liter' => 'liter',
        'liters' => 'liter',
        'litre' => 'liter',
        'litres' => 'liter',
        'l' => 'liter',
    ];
    return $aliases[$unit] ?? null;
}

function getProductUnitLabel($unit) {
    $unit = normalizeProductUnit($unit);
    return $unit !== null ? getProductUnitOptions()[$unit] : '';
}

function getProductUnitConversionText($unit) {
    $unit = normalizeProductUnit($unit);
    if ($unit === null) return '';
    return getProductUnitDefinitions()[$unit]['conversion'];
}

function getProductFormulaUnit($unit) {
    $unit = normalizeProductUnit($unit);
    if ($unit === null) return null;
    return getProductUnitDefinitions()[$unit]['symbol'];
}

/**
 * Convert between supported count, mass, or volume units. Returns null when a unit is
 * unknown or when the units belong to different families (for example pcs to g).
 */
function convertProductUnitQuantity($quantity, $fromUnit, $toUnit) {
    $from = normalizeProductUnit($fromUnit);
    $to = normalizeProductUnit($toUnit);
    if ($from === null || $to === null) return null;

    $definitions = getProductUnitDefinitions();
    if ($definitions[$from]['family'] !== $definitions[$to]['family']) return null;

    return (float)$quantity * $definitions[$from]['base_factor'] / $definitions[$to]['base_factor'];
}

/**
 * Calculate the weighted purchase cost for material products from quantities
 * that were actually received. PO lines without a historical unit inherit the
 * current catalog unit, which lets legacy materials become costed after their
 * unit is assigned.
 */
function getReceivedProductCostDetails(array $productIds, array $productRows = []) {
    global $conn;

    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), function ($id) {
        return $id > 0;
    })));
    if (empty($ids) || !tableExists('purchase_order_items')) {
        return [];
    }

    $idList = implode(',', $ids);
    if (empty($productRows)) {
        $productResult = $conn->query("SELECT id, unit FROM products WHERE id IN ($idList)");
        if ($productResult) {
            while ($row = $productResult->fetch_assoc()) {
                $productRows[(int)$row['id']] = $row;
            }
        }
    }

    $unitSelect = tableHasColumn('purchase_order_items', 'unit') ? 'unit' : 'NULL AS unit';
    $result = $conn->query("
        SELECT product_id, unit_price, received_quantity, $unitSelect
        FROM purchase_order_items
        WHERE product_id IN ($idList)
          AND received_quantity > 0
          AND unit_price >= 0
    ");
    if (!$result) {
        return [];
    }

    $totals = [];
    while ($row = $result->fetch_assoc()) {
        $productId = (int)$row['product_id'];
        $receivedQuantity = (float)$row['received_quantity'];
        $catalogUnit = normalizeProductUnit($productRows[$productId]['unit'] ?? '');
        $purchaseUnit = normalizeProductUnit($row['unit'] ?? '') ?: $catalogUnit;
        $quantityInCatalogUnit = $receivedQuantity;

        if ($catalogUnit !== null && $purchaseUnit !== null) {
            $converted = convertProductUnitQuantity($receivedQuantity, $purchaseUnit, $catalogUnit);
            if ($converted === null) {
                continue;
            }
            $quantityInCatalogUnit = $converted;
        }

        if ($quantityInCatalogUnit <= 0) {
            continue;
        }
        if (!isset($totals[$productId])) {
            $totals[$productId] = ['value' => 0.0, 'quantity' => 0.0];
        }
        $totals[$productId]['value'] += $receivedQuantity * (float)$row['unit_price'];
        $totals[$productId]['quantity'] += $quantityInCatalogUnit;
    }

    $costs = [];
    foreach ($totals as $productId => $total) {
        if ($total['quantity'] <= 0) {
            continue;
        }
        $costs[$productId] = [
            'value' => $total['value'] / $total['quantity'],
            'source' => 'received_average',
            'basis_unit' => getProductFormulaUnit($productRows[$productId]['unit'] ?? ''),
            'received_quantity' => $total['quantity'],
        ];
    }
    return $costs;
}

/**
 * Return the effective catalog cost for materials and final products.
 * Materials use a received-quantity weighted purchase average. Final products
 * use the most recently updated active linked formula and current material costs.
 */
function getCalculatedProductCostDetails(array $productIds) {
    global $conn;

    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), function ($id) {
        return $id > 0;
    })));
    if (empty($ids)) {
        return [];
    }

    $idList = implode(',', $ids);
    $products = [];
    $result = $conn->query("SELECT id, name, type, unit, cost_price FROM products WHERE id IN ($idList)");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[(int)$row['id']] = $row;
        }
    }

    $details = [];
    foreach ($products as $productId => $product) {
        $manualCost = $product['cost_price'] !== null && $product['cost_price'] !== ''
            ? (float)$product['cost_price']
            : null;
        $details[$productId] = [
            'value' => $manualCost,
            'source' => $manualCost !== null ? 'manual' : 'missing',
            'basis_unit' => getProductFormulaUnit($product['unit'] ?? ''),
        ];
    }

    $materialIds = [];
    $finalIds = [];
    foreach ($products as $productId => $product) {
        if ($product['type'] === 'material') {
            $materialIds[] = $productId;
        } elseif ($product['type'] === 'final') {
            $finalIds[] = $productId;
        }
    }

    foreach (getReceivedProductCostDetails($materialIds, $products) as $productId => $receivedCost) {
        $details[$productId] = $receivedCost;
    }

    if (empty($finalIds) || !tableExists('manufacturing_formulas') || !tableHasColumn('manufacturing_formulas', 'product_id')) {
        return $details;
    }

    $finalIdList = implode(',', $finalIds);
    $formulaResult = $conn->query("
        SELECT id, product_id, name, batch_size, batch_unit, components_json, updated_at
        FROM manufacturing_formulas
        WHERE product_id IN ($finalIdList) AND is_active = 1
        ORDER BY product_id, updated_at DESC, id DESC
    ");
    if (!$formulaResult) {
        return $details;
    }

    $formulas = [];
    $componentIds = [];
    while ($formula = $formulaResult->fetch_assoc()) {
        $productId = (int)$formula['product_id'];
        if (isset($formulas[$productId])) {
            continue;
        }
        $formula['components'] = json_decode($formula['components_json'] ?? '[]', true) ?: [];
        $formulas[$productId] = $formula;
        foreach ($formula['components'] as $component) {
            $componentId = (int)($component['product_id'] ?? 0);
            if ($componentId > 0) {
                $componentIds[] = $componentId;
            }
        }
    }

    $componentIds = array_values(array_unique($componentIds));
    $componentProducts = [];
    if (!empty($componentIds)) {
        $componentIdList = implode(',', $componentIds);
        $componentResult = $conn->query("SELECT id, name, type, unit, cost_price FROM products WHERE id IN ($componentIdList)");
        if ($componentResult) {
            while ($row = $componentResult->fetch_assoc()) {
                $componentProducts[(int)$row['id']] = $row;
            }
        }
    }
    $componentReceivedCosts = getReceivedProductCostDetails($componentIds, $componentProducts);

    foreach ($formulas as $productId => $formula) {
        $batchCost = 0.0;
        $missingComponents = [];
        $pricedComponentCount = 0;

        foreach ($formula['components'] as $component) {
            $componentId = (int)($component['product_id'] ?? 0);
            $quantity = is_numeric($component['quantity'] ?? null) ? (float)$component['quantity'] : null;
            $componentProduct = $componentProducts[$componentId] ?? null;
            if ($componentId <= 0 || $quantity === null || $quantity < 0 || !$componentProduct) {
                $missingComponents[] = (string)($component['name'] ?? 'Unlinked component');
                continue;
            }

            $catalogUnit = $componentProduct['unit'] ?? '';
            $formulaUnit = $component['unit'] ?? $catalogUnit;
            $convertedQuantity = convertProductUnitQuantity($quantity, $formulaUnit, $catalogUnit);
            if ($convertedQuantity === null) {
                $missingComponents[] = (string)($component['name'] ?? $componentProduct['name']);
                continue;
            }

            $componentCost = $componentReceivedCosts[$componentId]['value']
                ?? (($componentProduct['cost_price'] !== null && $componentProduct['cost_price'] !== '')
                    ? (float)$componentProduct['cost_price']
                    : null);
            if ($componentCost === null) {
                $missingComponents[] = (string)($component['name'] ?? $componentProduct['name']);
                continue;
            }

            $batchCost += $convertedQuantity * $componentCost;
            $pricedComponentCount++;
        }

        if ($pricedComponentCount === 0 || !empty($missingComponents)) {
            $details[$productId]['formula_id'] = (int)$formula['id'];
            $details[$productId]['formula_name'] = $formula['name'];
            $details[$productId]['missing_components'] = array_values(array_unique($missingComponents));
            continue;
        }

        $batchSize = (float)($formula['batch_size'] ?? 0);
        $basisUnit = trim((string)($formula['batch_unit'] ?? ''));
        $divisor = 1.0;
        if ($batchSize > 0) {
            $convertedOutput = convertProductUnitQuantity($batchSize, $formula['batch_unit'] ?? '', $products[$productId]['unit'] ?? '');
            if ($convertedOutput !== null && $convertedOutput > 0) {
                $divisor = $convertedOutput;
                $basisUnit = getProductFormulaUnit($products[$productId]['unit'] ?? '') ?: $basisUnit;
            } else {
                $divisor = $batchSize;
            }
        } else {
            $basisUnit = 'batch';
        }

        $details[$productId] = [
            'value' => $batchCost / $divisor,
            'source' => 'formula',
            'basis_unit' => $basisUnit,
            'batch_cost' => $batchCost,
            'formula_id' => (int)$formula['id'],
            'formula_name' => $formula['name'],
            'missing_components' => [],
        ];
    }

    return $details;
}

function getProductUsageReasons($productId) {
    global $conn;

    $productId = (int)$productId;
    if ($productId <= 0) {
        return ['invalid product'];
    }

    $reasons = [];
    if (tableExists('inventory_products')) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS rows_count, COALESCE(SUM(quantity), 0) AS total_quantity FROM inventory_products WHERE product_id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $inventory = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($inventory['rows_count'] ?? 0) > 0) {
            $reasons[] = (float)($inventory['total_quantity'] ?? 0) != 0.0
                ? 'current inventory stock'
                : 'inventory history';
        }
    }

    $references = [
        ['product_components', 'component_id', 'a product component'],
        ['product_components', 'final_product_id', 'a product recipe'],
        ['purchase_order_items', 'product_id', 'purchase orders'],
        ['order_items', 'product_id', 'sales orders'],
        ['quotation_items', 'product_id', 'quotations'],
        ['transfer_items', 'product_id', 'inventory transfers'],
        ['order_returns', 'product_id', 'returns'],
        ['packaging_option_items', 'product_id', 'packaging options'],
        ['packaging_options', 'product_id', 'packaging options'],
        ['manufacturing_orders', 'product_id', 'manufacturing orders'],
        ['manufacturing_sourcing_components', 'product_id', 'manufacturing sourcing'],
        ['inventory_stock_logs', 'product_id', 'inventory audit history'],
        ['product_price_logs', 'product_id', 'price history'],
        ['portal_order_items', 'product_id', 'portal orders'],
    ];
    foreach ($references as [$table, $column, $label]) {
        if (!tableExists($table) || !tableHasColumn($table, $column)) {
            continue;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        if ((int)$count > 0) {
            $reasons[] = $label;
        }
    }

    if (tableExists('manufacturing_formulas')
        && tableHasColumn('manufacturing_formulas', 'product_id')
        && tableHasColumn('manufacturing_formulas', 'components_json')) {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM manufacturing_formulas
            WHERE product_id = ? OR JSON_CONTAINS(components_json, JSON_OBJECT('product_id', ?))
        ");
        $stmt->bind_param('ii', $productId, $productId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        if ((int)$count > 0) {
            $reasons[] = 'manufacturing formulas';
        }
    }

    return array_values(array_unique($reasons));
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

/**
 * Return a strict YYYY-MM-DD transaction date, or null when the submitted
 * value is not a real calendar date. Transaction dates are deliberately kept
 * separate from created_at, which remains the immutable audit timestamp.
 */
function normalizeTransactionDate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }

    return $date->format('Y-m-d') === $value ? $value : null;
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
 * SQL condition for data visible in the currently selected login channel.
 * Factory customers have no direct_sale slug; direct-sales customers must match
 * the selected region. Salespeople are additionally restricted to their
 * effective customer assignment.
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

/**
 * SQL condition for products visible in the selected channel.
 * Factory owns raw/primary products and final products for Factory customers;
 * Direct Sale owns only final products for customers in its selected region.
 */
function getProductChannelScopeSql($productAlias = 'p', $customerAlias = 'c', $factoryAlias = 'f') {
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $customerScope = getCustomerChannelScopeSql($customerAlias, $factoryAlias);

    if ($loginRegion === 'factory' && !isSalesPersonUser()) {
        return "($productAlias.type IN ('primary', 'material') OR "
            . "($productAlias.type = 'final' AND $customerScope))";
    }

    return "$productAlias.type = 'final' AND $customerScope";
}

/**
 * Customer receivables from both supported sources:
 *  - unpaid order balances; and
 *  - legacy/manual negative customer wallet balances.
 *
 * Negative wallets have historically been used as customer debt. Taking the
 * larger value per customer prevents the same debt being counted twice when a
 * negative wallet mirrors that customer's open orders.
 */
function getCustomerReceivablesSummary() {
    global $conn;

    $scope = getCustomerChannelScopeSql('receivable_customer', 'receivable_factory');
    $sql = "
        SELECT
            COALESCE(SUM(GREATEST(customer_orders_due, wallet_debt)), 0) AS total,
            COALESCE(SUM(GREATEST(customer_orders_due, wallet_debt) > 0), 0) AS customer_count,
            COALESCE(SUM(customer_orders_due), 0) AS order_balances,
            COALESCE(SUM(wallet_debt), 0) AS wallet_debt
        FROM (
            SELECT
                receivable_customer.id,
                COALESCE(SUM(GREATEST(COALESCE(receivable_order.total_amount, 0) - COALESCE(receivable_order.paid_amount, 0), 0)), 0) AS customer_orders_due,
                GREATEST(-COALESCE(receivable_customer.wallet_balance, 0), 0) AS wallet_debt
            FROM customers receivable_customer
            LEFT JOIN factories receivable_factory ON receivable_factory.id = receivable_customer.factory_id
            LEFT JOIN orders receivable_order ON receivable_order.customer_id = receivable_customer.id
            WHERE $scope
            GROUP BY receivable_customer.id, receivable_customer.wallet_balance
        ) customer_receivables
    ";
    $result = $conn->query($sql);
    $summary = $result ? $result->fetch_assoc() : [];

    return [
        'total' => (float)($summary['total'] ?? 0),
        'customer_count' => (int)($summary['customer_count'] ?? 0),
        'order_balances' => (float)($summary['order_balances'] ?? 0),
        'wallet_debt' => (float)($summary['wallet_debt'] ?? 0),
    ];
}

function getInventoryChannelScopeSql($inventoryAlias = 'i') {
    global $conn;

    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    // Keep the legacy CUREVET inventory out of the factory channel even if its
    // direct_sale value has not been backfilled yet. The matching migration
    // fixes the stored value; this fallback protects existing deployments too.
    $legacyCureVetInventory = "LOWER(REPLACE(TRIM($inventoryAlias.name), ' ', '')) IN ('curevet', 'curevetinventory')";

    if ($loginRegion === 'factory') {
        return "$inventoryAlias.direct_sale IS NULL AND NOT ($legacyCureVetInventory)";
    }

    $escapedRegion = $conn->real_escape_string($loginRegion);
    if ($loginRegion === 'curva') {
        return "($inventoryAlias.direct_sale = '$escapedRegion' OR "
            . "($inventoryAlias.direct_sale IS NULL AND $legacyCureVetInventory))";
    }

    return "$inventoryAlias.direct_sale = '$escapedRegion'";
}

/**
 * Row-level check for the Customers area. Factory and every direct-sales region
 * are isolated from one another. Salesperson ownership is enforced separately
 * by canAccessCustomer().
 */
function isCustomerInCurrentChannel($customerId) {
    global $conn;

    $customerId = (int)$customerId;
    if ($customerId <= 0 || !isLoggedIn()) return false;

    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $cond = $loginRegion === 'factory'
        ? "direct_sale IS NULL"
        : "direct_sale = '" . $conn->real_escape_string($loginRegion) . "'";

    $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND $cond LIMIT 1");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

/**
 * The `accounts` row (brand) matching the current login_region, cached per request.
 */
function getCurrentAccountId() {
    global $conn;
    static $cached = null;
    if ($cached !== null) return $cached;

    $slug = $_SESSION['login_region'] ?? 'factory';
    $stmt = $conn->prepare("SELECT id FROM accounts WHERE slug = ? LIMIT 1");
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cached = $result ? (int)$result['id'] : 0;
    return $cached;
}

/**
 * WHERE fragment scoping safes/bank_accounts rows to the current brand.
 * NULL account_id is treated as Factory (mirrors expenses.account_id convention).
 */
function getAccountScopeSql($alias = '') {
    $prefix = $alias ? "$alias." : '';
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $accountId = getCurrentAccountId();

    if ($loginRegion === 'factory') {
        return "({$prefix}account_id IS NULL OR {$prefix}account_id = $accountId)";
    }
    return "{$prefix}account_id = $accountId";
}

/**
 * True when a finance account belongs to the currently selected brand.
 * Legacy NULL account_id rows belong to the Factory channel only.
 */
function isFinanceAccountInCurrentAccount($type, $accountId) {
    global $conn;

    $tables = [
        'safe' => 'safes',
        'bank' => 'bank_accounts',
        'personal' => 'personal_accounts',
    ];
    $accountId = (int)$accountId;
    if (!isset($tables[$type]) || $accountId <= 0) return false;

    $scope = getAccountScopeSql();
    $stmt = $conn->prepare("SELECT id FROM `{$tables[$type]}` WHERE id = ? AND $scope LIMIT 1");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

/**
 * True when the given safe id is visible in the current brand scope.
 */
function isSafeInCurrentAccount($safeId) {
    return isFinanceAccountInCurrentAccount('safe', $safeId);
}

/**
 * True when the given bank account id is visible in the current brand scope.
 */
function isBankAccountInCurrentAccount($bankAccountId) {
    return isFinanceAccountInCurrentAccount('bank', $bankAccountId);
}

function isPersonalAccountInCurrentAccount($personalAccountId) {
    return isFinanceAccountInCurrentAccount('personal', $personalAccountId);
}

/**
 * Brand logo filename for the given (or current) login_region, falling back
 * to the default GammaVet logo when the brand-specific file isn't on disk.
 */
function getBrandLogoFile($slug = null) {
    $slug = $slug ?? ($_SESSION['login_region'] ?? 'factory');
    $map = [
        'factory'  => 'logo.png',
        'curva'    => 'logo_curva.png',
        'primer'   => 'logo_primer.png',
        'naturous' => 'logo_naturous.png',
        'activita' => 'logo_activita.png',
    ];
    $file = $map[$slug] ?? 'logo.png';
    return file_exists(ROOT_PATH . '/' . $file) ? $file : 'logo.png';
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
 * Inventory additions follow the same strict product channel boundary:
 * Factory may use its internal/raw products and Factory final products, while
 * Direct Sale may use only final products for its own customers.
 */
function canAddProductToInventory($inventoryId, $productId) {
    global $conn;

    $inventoryId = (int)$inventoryId;
    $productId = (int)$productId;
    if ($inventoryId <= 0 || $productId <= 0 || !isLoggedIn()) return false;

    $inventoryScope = getInventoryChannelScopeSql('i');
    $productScope = getProductChannelScopeSql('p', 'c', 'f');
    $sql = "SELECT p.id
            FROM products p
            LEFT JOIN customers c ON c.id = p.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            JOIN inventories i ON i.id = ?
            WHERE p.id = ?
              AND $inventoryScope
              AND $productScope
              AND " . getActiveProductSql('p') . "
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
 * Append a row to the inventory transfer audit trail. One row per state change,
 * and one row per changed field on an edit. No-ops when the migration that adds
 * the table hasn't been applied yet (same guard style as logInventoryStockChange).
 */
function logTransferHistory($transferId, $action, $fieldName = null, $oldValue = null, $newValue = null, $note = null) {
    global $conn;

    if (!tableExists('inventory_transfer_history')) {
        return;
    }

    $transferId = (int)$transferId;
    $action = substr((string)$action, 0, 50);
    $fieldName = $fieldName !== null ? substr((string)$fieldName, 0, 100) : null;
    $oldValue = $oldValue !== null ? (string)$oldValue : null;
    $newValue = $newValue !== null ? (string)$newValue : null;
    $note = $note !== null ? (string)$note : null;
    $createdBy = $_SESSION['user_id'] ?? null;

    $stmt = $conn->prepare("
        INSERT INTO inventory_transfer_history
            (inventory_transfer_id, action, field_name, old_value, new_value, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param("isssssi", $transferId, $action, $fieldName, $oldValue, $newValue, $note, $createdBy);
    $stmt->execute();
    $stmt->close();
}

/**
 * Active users whose role grants the given permission key. Admins are always
 * included, mirroring the role-slug short-circuit in hasPermission().
 */
function getUsersWithPermission($permissionKey) {
    global $conn;

    $sql = "SELECT DISTINCT u.id, u.name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE u.is_active = 1
              AND (p.`key` = ? OR r.slug = 'admin')
            ORDER BY u.name";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $permissionKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * True when the given user id may act as a transfer receiver/verifier. Used to
 * validate posted receiver ids server-side instead of trusting the dropdown.
 */
function userHasPermissionKey($userId, $permissionKey) {
    global $conn;

    $userId = (int)$userId;
    if ($userId <= 0) return false;

    $sql = "SELECT u.id
            FROM users u
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = ? AND u.is_active = 1
              AND (p.`key` = ? OR r.slug = 'admin')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $userId, $permissionKey);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

/**
 * Role ids holding the given permission key (plus the admin role), for
 * role-targeted notifications via createNotification($for_role_id).
 */
function getRoleIdsWithPermission($permissionKey) {
    global $conn;

    $sql = "SELECT DISTINCT r.id
            FROM roles r
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE p.`key` = ? OR r.slug = 'admin'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $permissionKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $stmt->close();

    return $ids;
}

/**
 * Move the stock for every line of a transfer, in one direction.
 *
 * $direction 'out' deducts the source and credits the destination (receiving);
 * 'in' does the reverse (sending a verified/transferred row back, or deleting one).
 *
 * Must be called inside an open transaction with the transfer row locked. Throws
 * when a deduction would take stock negative, so the caller rolls the whole
 * transfer back rather than leaving a partial movement behind. Returns the log
 * entries the caller should hand to logInventoryStockChange() AFTER committing.
 */
function applyTransferStockMovement($transfer, $direction = 'out') {
    global $conn;

    $transferId = (int)$transfer['id'];
    $sourceId = (int)$transfer['from_inventory_id'];
    $destinationId = (int)$transfer['to_inventory_id'];

    // 'out' takes from source and gives to destination; 'in' reverses that.
    $takeFrom = $direction === 'out' ? $sourceId : $destinationId;
    $giveTo = $direction === 'out' ? $destinationId : $sourceId;

    $itemsStmt = $conn->prepare("
        SELECT ti.product_id, ti.quantity, p.name AS product_name
        FROM transfer_items ti
        JOIN products p ON p.id = ti.product_id
        WHERE ti.transfer_id = ?
    ");
    $itemsStmt->bind_param('i', $transferId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();

    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        if (!canAddProductToInventory($sourceId, $productId)
            || !canAddProductToInventory($destinationId, $productId)) {
            throw new Exception('Transfer contains a product outside the current channel.');
        }
    }

    // Collect every shortage first so the error names all short products at once.
    $shortages = [];
    foreach ($items as $item) {
        $quantity = (float)$item['quantity'];
        if ($quantity <= 0) {
            continue;
        }
        $available = getInventoryProductQuantity($takeFrom, (int)$item['product_id']);
        if ($available < $quantity) {
            $shortages[] = $item['product_name'] . ' (need ' . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.')
                . ', available ' . rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.') . ')';
        }
    }
    if (!empty($shortages)) {
        throw new Exception('Insufficient stock for: ' . implode('; ', $shortages));
    }

    $stockLogs = [];
    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        $quantity = (float)$item['quantity'];
        if ($quantity <= 0) {
            continue;
        }

        $takeBefore = getInventoryProductQuantity($takeFrom, $productId);
        $deduct = $conn->prepare("
            UPDATE inventory_products
            SET quantity = quantity - ?
            WHERE inventory_id = ? AND product_id = ? AND quantity >= ?
        ");
        $deduct->bind_param('diid', $quantity, $takeFrom, $productId, $quantity);
        $deduct->execute();
        $deducted = $deduct->affected_rows;
        $deduct->close();

        // Re-check under the lock: stock may have moved since the pre-flight scan.
        if ($deducted === 0) {
            throw new Exception('Insufficient stock for ' . $item['product_name'] . '. The transfer was not applied.');
        }

        $giveBefore = getInventoryProductQuantity($giveTo, $productId);
        $add = $conn->prepare("
            INSERT INTO inventory_products (inventory_id, product_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        $add->bind_param('iid', $giveTo, $productId, $quantity);
        $add->execute();
        $add->close();

        $stockLogs[] = [
            'inventory_id' => $takeFrom,
            'product_id' => $productId,
            'change' => -$quantity,
            'before' => $takeBefore,
            'after' => $takeBefore - $quantity,
            'peer_inventory_id' => $giveTo,
            'outbound' => true,
        ];
        $stockLogs[] = [
            'inventory_id' => $giveTo,
            'product_id' => $productId,
            'change' => $quantity,
            'before' => $giveBefore,
            'after' => $giveBefore + $quantity,
            'peer_inventory_id' => $takeFrom,
            'outbound' => false,
        ];
    }

    return $stockLogs;
}

/**
 * Write the entries returned by applyTransferStockMovement() to the stock ledger.
 * Call this after the transaction commits.
 */
function writeTransferStockLogs($stockLogs, $sourceType, $transferId) {
    foreach ($stockLogs as $log) {
        logInventoryStockChange(
            $log['inventory_id'],
            $log['product_id'],
            $log['change'],
            $log['before'],
            $log['after'],
            $sourceType,
            $transferId,
            null,
            null,
            ($log['outbound'] ? 'Transfer out to inventory ID: ' : 'Transfer in from inventory ID: ') . $log['peer_inventory_id']
        );
    }
}

/**
 * All roles are restricted to the selected channel. Salespeople are further
 * restricted to customers explicitly assigned to them, or inherited from the
 * customer's factory.
 */
function canAccessCustomer($customerId) {
    global $conn;

    $customerId = (int)$customerId;
    if ($customerId <= 0 || !isLoggedIn()) return false;

    $channelScope = getCustomerChannelScopeSql('c', 'f');
    $sql = "SELECT c.id
            FROM customers c
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE c.id = ?
              AND $channelScope";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessFactory($factoryId) {
    global $conn;

    $factoryId = (int)$factoryId;
    if ($factoryId <= 0 || !isLoggedIn()) return false;
    if (($_SESSION['login_region'] ?? 'factory') !== 'factory') return false;
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

    $channelScope = getProductChannelScopeSql('p', 'c', 'f');
    $sql = "SELECT p.id
            FROM products p
            LEFT JOIN customers c ON c.id = p.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE p.id = ?
              AND $channelScope";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessOrder($orderId) {
    global $conn;

    $orderId = (int)$orderId;
    if ($orderId <= 0 || !isLoggedIn()) return false;

    $channelScope = getCustomerChannelScopeSql('c', 'f');
    $sql = "SELECT o.id
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE o.id = ?
              AND $channelScope";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessPortalOrder($portalOrderId) {
    global $conn;

    $portalOrderId = (int)$portalOrderId;
    if ($portalOrderId <= 0 || !isLoggedIn()) return false;

    $channelScope = getCustomerChannelScopeSql('c', 'f');
    $sql = "SELECT po.id
            FROM portal_orders po
            JOIN customers c ON c.id = po.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE po.id = ?
              AND $channelScope";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $portalOrderId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    return $allowed;
}

function canAccessQuotation($quotationId) {
    global $conn;

    $quotationId = (int)$quotationId;
    if ($quotationId <= 0 || !isLoggedIn()) return false;

    $channelScope = getCustomerChannelScopeSql('c', 'f');
    $sql = "SELECT q.id
            FROM quotations q
            JOIN customers c ON c.id = q.customer_id
            LEFT JOIN factories f ON f.id = c.factory_id
            WHERE q.id = ?
              AND $channelScope";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $quotationId);
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
function isNotificationVisibleInCurrentChannel(array $notification) {
    global $conn;

    if (($_SESSION['login_region'] ?? 'factory') === 'factory') {
        return true;
    }

    $entityType = (string)($notification['entity_type'] ?? '');
    $entityId = (int)($notification['entity_id'] ?? 0);
    if ($entityId <= 0) {
        return false;
    }

    if ($entityType === 'order') {
        return canAccessOrder($entityId);
    }
    if ($entityType === 'product') {
        return canAccessProduct($entityId);
    }
    if ($entityType === 'inventory_transfer') {
        return canAccessInventoryTransfer($entityId);
    }
    if ($entityType === 'expense') {
        $scope = getAccountScopeSql();
        $stmt = $conn->prepare("SELECT id FROM expenses WHERE id = ? AND $scope LIMIT 1");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $visible = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $visible;
    }
    if ($entityType === 'finance_transfer') {
        $stmt = $conn->prepare('SELECT from_type, from_id, to_type, to_id FROM finance_transfers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $transfer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$transfer) return false;

        foreach (['from', 'to'] as $side) {
            $type = $transfer[$side . '_type'];
            $id = (int)$transfer[$side . '_id'];
            if ($type === 'personal' && $id === 0) continue;
            if (!isFinanceAccountInCurrentAccount($type, $id)) return false;
        }
        return (int)$transfer['from_id'] > 0 || (int)$transfer['to_id'] > 0;
    }

    // Notifications without a channel-resolvable entity may contain Factory
    // names, amounts, references, or operational details.
    return false;
}

function getUnreadNotificationsCount() {
    global $conn;
    if (!isLoggedIn()) return 0;
    if (!isset($_SESSION['role_id'])) {
        loadUserAccessToSession($_SESSION['user_id']);
    }
    $roleId = $_SESSION['role_id'] ?? null;
    $userId = $_SESSION['user_id'];
    $roleSlug = $_SESSION['role_slug'] ?? null;
    
    if (($_SESSION['login_region'] ?? 'factory') !== 'factory') {
        if ($roleSlug === 'admin') {
            $stmt = $conn->prepare('SELECT type, module, entity_type, entity_id FROM notifications WHERE is_read = 0');
        } else {
            if ($roleId === null) return 0;
            $stmt = $conn->prepare('SELECT type, module, entity_type, entity_id FROM notifications WHERE is_read = 0 AND (created_for_role_id = ? OR created_for_user_id = ?)');
            $stmt->bind_param('ii', $roleId, $userId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return count(array_filter($rows, 'isNotificationVisibleInCurrentChannel'));
    }

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

// Valid ticket workflow statuses. Single source of truth for the status
// dropdown and the server-side allowlist that validates submissions.
if (!defined('TICKET_STATUSES')) {
    define('TICKET_STATUSES', ['open', 'in_progress', 'resolved', 'closed']);
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

// Deletes a ticket along with its notes and attachments (DB rows + uploaded
// files). Returns true on success, false on failure (transaction rolled back).
function deleteTicket($ticketId) {
    global $conn;
    $ticketId = (int)$ticketId;
    if ($ticketId <= 0) return false;

    $attStmt = $conn->prepare("SELECT file_path FROM ticket_attachments WHERE ticket_id = ?");
    $attStmt->bind_param('i', $ticketId);
    $attStmt->execute();
    $filesToDelete = $attStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $attStmt->close();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM ticket_notes WHERE ticket_id = ?");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM ticket_attachments WHERE ticket_id = ?");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }

    foreach ($filesToDelete as $file) {
        $fullPath = __DIR__ . '/../' . $file['file_path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    return true;
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
