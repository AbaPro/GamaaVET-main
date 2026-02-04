<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

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

if (!hasPermission('products.bulk_upload')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Bulk Product Upload';
require_once '../../includes/header.php';

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_path = $_FILES['csv_file']['tmp_name'];
        
        // Check if file is CSV
        $file_ext = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);
        if (strtolower($file_ext) !== 'csv') {
            setAlert('danger', 'Please upload a valid CSV file.');
            redirect('upload.php');
        }
        
        // Process CSV file
        $file = fopen($file_path, 'r');
        $header = fgetcsv($file); // Get header row
        
        // Check required columns (sku and barcode are optional - will be auto-generated)
        $required_columns = ['name', 'type', 'category_id', 'unit_price'];
        $missing_columns = array_diff($required_columns, $header);
        
        if (!empty($missing_columns)) {
            setAlert('danger', 'Missing required columns in CSV: ' . implode(', ', $missing_columns));
            redirect('upload.php');
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            while (($row = fgetcsv($file)) !== FALSE) {
                $data = array_combine($header, $row);
                
                // Skip empty rows
                if (empty($data['name']) || empty($data['sku'])) {
                    continue;
                }
                
                // Prepare data
                $name = sanitize($data['name']);
                $sku = isset($data['sku']) && $data['sku'] !== '' ? sanitize($data['sku']) : '';
                $barcode = isset($data['barcode']) && $data['barcode'] !== '' ? sanitize($data['barcode']) : '';
                $type = sanitize($data['type']);
                $category_id = (int)$data['category_id'];
                $subcategory_id = isset($data['subcategory_id']) && $data['subcategory_id'] !== '' ? (int)$data['subcategory_id'] : NULL;
                $customer_id = isset($data['customer_id']) && $data['customer_id'] !== '' ? (int)$data['customer_id'] : NULL;
                $unit_price = (float)$data['unit_price'];
                $cost_price = isset($data['cost_price']) && $data['cost_price'] !== '' ? (float)$data['cost_price'] : NULL;
                $min_stock_level = isset($data['min_stock_level']) && $data['min_stock_level'] !== '' ? (int)$data['min_stock_level'] : 0;
                $description = isset($data['description']) ? sanitize($data['description']) : '';
                
                // Generate SKU if not provided
                if ($sku === '') {
                    $sku = generateUniqueSku($conn);
                } else {
                    // Check if SKU already exists
                    $check_sql = "SELECT id FROM products WHERE sku = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $sku);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $error_count++;
                        $errors[] = "SKU $sku already exists - $name";
                        $check_stmt->close();
                        continue;
                    }
                    $check_stmt->close();
                }
                
                // Generate barcode if not provided
                if ($barcode === '') {
                    $barcode = generateUniqueBarcode($conn);
                }
                
                // Insert product
                $insert_sql = "INSERT INTO products 
                               (name, sku, barcode, type, category_id, subcategory_id, customer_id, unit_price, 
                                cost_price, min_stock_level, description) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("ssssiiiddds", $name, $sku, $barcode, $type, $category_id, 
                                        $subcategory_id, $customer_id, $unit_price, $cost_price, $min_stock_level, $description);
                
                if ($insert_stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = "Error inserting product $sku - " . $conn->error;
                }
                $insert_stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $message = "Bulk upload completed. Success: $success_count, Errors: $error_count";
            if ($error_count > 0) {
                $message .= "<br><br>Errors:<br>" . implode("<br>", $errors);
                setAlert('warning', $message);
            } else {
                setAlert('success', $message);
            }
            
            logActivity("Bulk product upload: $success_count successful, $error_count errors");
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            setAlert('danger', 'Error during bulk upload: ' . $e->getMessage());
        }
        
        fclose($file);
        redirect('index.php');
    } else {
        setAlert('danger', 'Error uploading file. Please try again.');
        redirect('upload.php');
    }
}
?><i class="fas fa-info-circle me-2"></i>CSV File Instructions</h5>
            <p>Upload a CSV file with product data. The file must include the following columns:</p>
            <ul>
                <li><strong>name</strong> - Product name (required)</li>
                <li><strong>type</strong> - Product type: <code>primary</code>, <code>final</code>, or <code>material</code> (required)</li>
                <li><strong>category_id</strong> - ID of main category (required)</li>
                <li><strong>unit_price</strong> - Selling price (required)</li>
            </ul>
            <p><strong>Optional columns:</strong></p>
            <ul class="mb-0">
                <li><strong>sku</strong> - Unique SKU (auto-generated if empty)</li>
                <li><strong>barcode</strong> - Product barcode (auto-generated if empty)</li>
                <li><strong>subcategory_id</strong> - ID of subcategory</li>
                <li><strong>customer_id</strong> - Customer ID (for customer-specific products)</li>
                <li><strong>cost_price</strong> - Cost price</li>
                <li><strong>min_stock_level</strong> - Minimum stock alert level</li>
                <li><strong>description</strong> - Product description</li>
            </ul>
            <hr>
            <div class="alert alert-success mb-0">
                <i class="fas fa-magic me-2"></i><strong>Auto-Generation:</strong> SKU and barcode will be automatically generated if left empty in the CSV file.
            </div
            <ul>
                <li><strong>name</strong> - Product name (required)</li>
                <li><strong>sku</strong> - Unique SKU (required)</li>
                <li><strong>type</strong> - Product type (primary, final, material) (required)</li>
                <li><strong>category_id</strong> - ID of main category (required)</li>
                <li><strong>unit_price</strong> - Selling price (required)</li>
            </ul>
            <p>Optional columns: barcode, subcategory_id, customer_id, cost_price, min_stock_level, description</p>
            <hr>
            <p class="mb-0">Download <a href="sample_products.csv" download>sample CSV file</a> for reference.</p>
        </div>
        
        <form action="upload.php" method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="csv_file" class="form-label">CSV File</label>
                <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload and Process</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
