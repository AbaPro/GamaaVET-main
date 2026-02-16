<?php
// Enable error reporting at the top
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include files with proper error handling
try {
    require_once  '../../includes/auth.php';
    require_once  '../../includes/functions.php';
    require_once  '../../config/database.php';
} catch (Exception $e) {
    die("Error loading required files: " . $e->getMessage());
}

// Verify database connection
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Database connection failed");
}

// Helpers to generate unique SKU/barcode
function generateUniqueSku(mysqli $conn): string {
    $maxAttempts = 20;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = 'SKU-' . date('ymd') . '-' . generateRandomString(6);
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->bind_param("s", $candidate);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }
    throw new Exception("Unable to generate unique SKU. Please try again.");
}

function generateUniqueBarcode(mysqli $conn): string {
    $maxAttempts = 20;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = (string)random_int(100000000000, 999999999999);
        $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
        $stmt->bind_param("s", $candidate);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }
    throw new Exception("Unable to generate unique barcode. Please try again.");
}

// Permission check
if (!hasPermission('products.create')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect(BASE_URL . '/dashboard.php');
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required = ['name', 'type', 'category_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Required field '$field' is missing");
            }
        }

        // Sanitize inputs
        $name = sanitize($_POST['name']);
        $sku = sanitize($_POST['sku'] ?? '');
        $barcode = sanitize($_POST['barcode'] ?? '');
        $type = sanitize($_POST['type']);
        $category_id = (int)$_POST['category_id'];
        $subcategory_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : NULL;
        $customer_id = isset($_POST['customer_id']) && $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : NULL;
        $unit_price = isset($_POST['unit_price']) && $_POST['unit_price'] !== '' ? (float)$_POST['unit_price'] : NULL;
        $cost_price = !empty($_POST['cost_price']) ? (float)$_POST['cost_price'] : NULL;
        $min_stock_level = !empty($_POST['min_stock_level']) ? (int)$_POST['min_stock_level'] : 0;
        $description = sanitize($_POST['description'] ?? '');

        if ($sku === '') {
            $sku = generateUniqueSku($conn);
        } else {
            $check_sql = "SELECT id FROM products WHERE sku = ?";
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $check_stmt->bind_param("s", $sku);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                throw new Exception("Product with this SKU already exists");
            }
            $check_stmt->close();
        }

        if ($barcode === '') {
            $barcode = generateUniqueBarcode($conn);
        }

        // Handle file upload
        $image_name = NULL;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../assets/uploads/products/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory");
                }
            }

            $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $image_name = 'product_' . time() . '.' . $file_ext;
            $upload_path = $upload_dir . $image_name;
            
            // Validate image
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Only JPG, JPEG, PNG & GIF files are allowed");
            }

            $check = getimagesize($_FILES['image']['tmp_name']);
            if ($check === false) {
                throw new Exception("File is not an image");
            }

            if ($_FILES['image']['size'] > 2000000) {
                throw new Exception("File is too large. Max 2MB allowed");
            }

            if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                throw new Exception("Error uploading file");
            }
        }
        
        // Insert product
        $insert_sql = "INSERT INTO products 
                      (name, sku, barcode, type, category_id, subcategory_id, customer_id,
                       unit_price, cost_price, min_stock_level, description, image) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $insert_stmt->bind_param("ssssiiiddiss", 
            $name, $sku, $barcode, $type, $category_id, $subcategory_id, $customer_id,
            $unit_price, $cost_price, $min_stock_level, $description, $image_name);
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Error adding product: " . $insert_stmt->error);
        }
        
        $product_id = $insert_stmt->insert_id;
        setAlert('success', 'Product added successfully.');
        logActivity("Added new product: $name (ID: $product_id)");
        $insert_stmt->close();
        
    } catch (Exception $e) {
        setAlert('danger', $e->getMessage());
        
        // Clean up uploaded file if there was an error after upload
        if (isset($upload_path) && file_exists($upload_path)) {
            unlink($upload_path);
        }
    }
}

redirect(BASE_URL . '/modules/products/index.php');
?>
