<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Verify database connection
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Database connection failed");
}

if (!hasPermission('products.bulk_upload')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

// Initialize session variables for multi-step process
if (!isset($_SESSION['bulk_upload'])) {
    $_SESSION['bulk_upload'] = [
        'step' => 1,
        'csv_data' => null,
        'unique_customers' => [],
        'customer_mapping' => [],
        'unique_categories' => [],
        'category_mapping' => []
    ];
}

// Handle all POST requests and processing BEFORE including header.php

// Handle navigation buttons
if (isset($_POST['back_to_upload'])) {
    $_SESSION['bulk_upload']['step'] = 1;
    $_SESSION['bulk_upload']['csv_data'] = null;
    $_SESSION['bulk_upload']['unique_customers'] = [];
    $_SESSION['bulk_upload']['customer_mapping'] = [];
    $_SESSION['bulk_upload']['unique_categories'] = [];
    $_SESSION['bulk_upload']['category_mapping'] = [];
    redirect('upload.php');
}

if (isset($_POST['back_to_customer_mapping'])) {
    $_SESSION['bulk_upload']['step'] = 2;
    redirect('upload.php');
}

// Step 1: Upload CSV
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

        if (!$header) {
            setAlert('danger', 'The CSV file is empty.');
            redirect('upload.php');
        }

        // Remove UTF-8 BOM if present from the first header element
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        // Normalize header names to lowercase for comparison and trim whitespace/hidden chars
        $header = array_map(function($h) { 
            return strtolower(trim($h, " \t\n\r\0\x0B\xEF\xBB\xBF")); 
        }, $header);
        
        // Check required columns
        $required_columns = ['name', 'type', 'category', 'unit_price'];
        $missing_columns = array_diff($required_columns, $header);
        
        if (!empty($missing_columns)) {
            setAlert('danger', 'Missing required columns in CSV: ' . implode(', ', $missing_columns));
            redirect('upload.php');
        }
        
        $csv_data = [];
        $unique_categories = [];
        $unique_customers = [];
        $row_count = 0;
        
        while (($row = fgetcsv($file)) !== FALSE) {
            $row_count++;
            if ($row_count > 1000) { // Limit to 1000 rows for performance
                setAlert('warning', 'CSV file is too large. Only first 1000 rows will be processed.');
                break;
            }
            
            $data = array_combine($header, $row);
            
            // Skip empty rows
            if (empty(trim($data['name'] ?? ''))) {
                continue;
            }
            
            // Validate type
            $valid_types = ['primary', 'final', 'material'];
            if (!in_array(strtolower($data['type'] ?? ''), $valid_types)) {
                setAlert('danger', "Invalid product type '{$data['type']}' on row $row_count. Must be: primary, final, or material.");
                redirect('upload.php');
            }
            
            // Collect unique categories
            $category = trim($data['category'] ?? '');
            if (!empty($category) && !in_array($category, $unique_categories)) {
                $unique_categories[] = $category;
            }
            
            // Collect unique customers
            $customer_name = trim($data['customer_name'] ?? '');
            if (!empty($customer_name) && !in_array($customer_name, $unique_customers)) {
                $unique_customers[] = $customer_name;
            }
            
            $csv_data[] = $data;
        }
        
        fclose($file);
        
        if (empty($csv_data)) {
            setAlert('danger', 'No valid product data found in CSV file.');
            redirect('upload.php');
        }
        
        // Store data in session
        $_SESSION['bulk_upload']['csv_data'] = $csv_data;
        $_SESSION['bulk_upload']['unique_categories'] = $unique_categories;
        $_SESSION['bulk_upload']['unique_customers'] = $unique_customers;
        
        // If no customers in CSV, go directly to category mapping
        if (empty($unique_customers)) {
            $_SESSION['bulk_upload']['step'] = 3;
        } else {
            $_SESSION['bulk_upload']['step'] = 2;
        }
        
        redirect('upload.php');
    } else {
        setAlert('danger', 'Error uploading file. Please try again.');
        redirect('upload.php');
    }
}

// Step 2: Process Customer Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_customer_mapping'])) {
    $customer_mapping = [];
    $errors = [];
    
    foreach ($_SESSION['bulk_upload']['unique_customers'] as $customer_name) {
        $mapped_id = $_POST['customer_' . md5($customer_name)] ?? '';
        if (empty($mapped_id)) {
            $errors[] = "Please map customer: '$customer_name'";
        } else {
            $customer_mapping[$customer_name] = (int)$mapped_id;
        }
    }
    
    if (!empty($errors)) {
        setAlert('danger', 'Please complete all customer mappings:<br>' . implode('<br>', $errors));
        redirect('upload.php');
    }
    
    // Store mapping and proceed to category mapping
    $_SESSION['bulk_upload']['customer_mapping'] = $customer_mapping;
    $_SESSION['bulk_upload']['step'] = 3;
    redirect('upload.php');
}

// Step 3: Process Category Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_upload'])) {
    $category_mapping = [];
    $errors = [];
    
    foreach ($_SESSION['bulk_upload']['unique_categories'] as $category_name) {
        $mapped_id = $_POST['category_' . md5($category_name)] ?? '';
        if (empty($mapped_id)) {
            $errors[] = "Please map category: '$category_name'";
        } else {
            $category_mapping[$category_name] = (int)$mapped_id;
        }
    }
    
    if (!empty($errors)) {
        setAlert('danger', 'Please complete all category mappings:<br>' . implode('<br>', $errors));
        redirect('upload.php');
    }
    
    // Store mapping and proceed to processing
    $_SESSION['bulk_upload']['category_mapping'] = $category_mapping;
    $_SESSION['bulk_upload']['step'] = 4;
    redirect('upload.php');
}

// Step 4: Process Upload (handle this before header include)
if (isset($_SESSION['bulk_upload']['step']) && $_SESSION['bulk_upload']['step'] == 4) {
    $csv_data = $_SESSION['bulk_upload']['csv_data'];
    $customer_mapping = $_SESSION['bulk_upload']['customer_mapping'];
    $category_mapping = $_SESSION['bulk_upload']['category_mapping'];
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        foreach ($csv_data as $row) {
            // Prepare data
            $name = sanitize($row['name']);
            $sku = isset($row['sku']) && $row['sku'] !== '' ? sanitize($row['sku']) : '';
            $barcode = isset($row['barcode']) && $row['barcode'] !== '' ? sanitize($row['barcode']) : '';
            $type = sanitize(strtolower($row['type']));
            $category_name = trim($row['category']);
            $category_id = $category_mapping[$category_name] ?? null;
            
            if (!$category_id) {
                $error_count++;
                $errors[] = "Category mapping not found for: $category_name - $name";
                continue;
            }
            
            // Get customer_id from mapping if customer_name is provided
            $customer_id = null;
            if (isset($row['customer_name']) && $row['customer_name'] !== '') {
                $customer_name = trim($row['customer_name']);
                $customer_id = $customer_mapping[$customer_name] ?? null;
                if (!$customer_id) {
                    $error_count++;
                    $errors[] = "Customer mapping not found for: $customer_name - $name";
                    continue;
                }
            }
            
            $subcategory_id = null;
            if (isset($row['subcategory']) && $row['subcategory'] !== '') {
                // Try to find subcategory by name
                $subcat_name = sanitize($row['subcategory']);
                $subcat_stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND parent_id = ?");
                $subcat_stmt->bind_param("si", $subcat_name, $category_id);
                $subcat_stmt->execute();
                $subcat_result = $subcat_stmt->get_result();
                if ($subcat_result->num_rows > 0) {
                    $subcategory_id = $subcat_result->fetch_assoc()['id'];
                }
                $subcat_stmt->close();
            }
            
            $unit_price = isset($row['unit_price']) && $row['unit_price'] !== '' ? (float)$row['unit_price'] : 0.00;
            $cost_price = isset($row['cost_price']) && $row['cost_price'] !== '' ? (float)$row['cost_price'] : null;
            $min_stock_level = isset($row['min_stock_level']) && $row['min_stock_level'] !== '' ? (int)$row['min_stock_level'] : 0;
            $description = isset($row['description']) ? sanitize($row['description']) : '';
            $unit = isset($row['unit']) && in_array(strtolower($row['unit']), ['each', 'gram', 'kilo', '']) ? strtolower($row['unit']) : '';
            
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
                            cost_price, min_stock_level, description, unit) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssssiiidddss", $name, $sku, $barcode, $type, $category_id, 
                                    $subcategory_id, $customer_id, $unit_price, $cost_price, $min_stock_level, $description, $unit);
            
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
        
        // Clear session data
        unset($_SESSION['bulk_upload']);
        
        // Set success message
        $message = "Bulk upload completed. Success: $success_count, Errors: $error_count";
        if ($error_count > 0) {
            $message .= "<br><br>Errors:<br>" . implode("<br>", $errors);
            setAlert('warning', $message);
        } else {
            setAlert('success', $message);
        }
        
        logActivity("Bulk product upload: $success_count successful, $error_count errors");
        redirect('index.php');
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        setAlert('danger', 'Error during bulk upload: ' . $e->getMessage());
        redirect('upload.php');
    }
}

$page_title = 'Bulk Product Upload';
require_once '../../includes/header.php';
?>

<script>
$(document).ready(function() {
    if ($.fn.select2) {
        $('.js-searchable-select').select2({
            placeholder: "-- Select --",
            allowClear: true,
            width: '100%'
        });
    }
});
</script>

<?php
// Display logic based on current step
$current_step = $_SESSION['bulk_upload']['step'] ?? 1;

// Step 1: Upload CSV
if ($current_step == 1) {
    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Step 1: Upload CSV File</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="mb-3">CSV File Instructions</h6>
                        <p>Upload a CSV file with product data. The file must include the following columns:</p>
                        <ul>
                            <li><strong>name</strong> - Product name (required)</li>
                            <li><strong>type</strong> - Product type: <code>primary</code>, <code>final</code>, or <code>material</code> (required)</li>
                            <li><strong>category</strong> - Category name (required - will be mapped to existing categories)</li>
                            <li><strong>selling_price</strong> - Selling price (required)</li>
                        </ul>
                        <p><strong>Optional columns:</strong></p>
                        <ul class="mb-0">
                            <li><strong>customer_name</strong> - Customer name (will be mapped to existing customers)</li>
                            <li><strong>sku</strong> - Unique SKU identifier (auto-generated if empty)</li>
                            <li><strong>barcode</strong> - Product barcode number (auto-generated if empty)</li>
                            <li><strong>subcategory</strong> - Subcategory name (e.g., "استيكر", "نشرة", "علبة" - must exist under the selected category)</li>
                            <li><strong>cost_price</strong> - Purchase/manufacturing cost (decimal)</li>
                            <li><strong>min_stock_level</strong> - Minimum stock alert threshold (integer, default: 0)</li>
                            <li><strong>description</strong> - Detailed product description (supports Arabic)</li>
                            <li><strong>unit</strong> - Unit of measurement: <code>each</code>, <code>gram</code>, <code>kilo</code>, or leave empty</li>
                        </ul>
                        <hr>
                        <div class="alert alert-success mb-3">
                            <i class="fas fa-magic me-2"></i><strong>Auto-Generation:</strong> SKU and barcode will be automatically generated if left empty in the CSV file.
                        </div>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i><strong>Tips:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Leave optional columns empty if you don't have the data</li>
                                <li>Category names must match existing categories in your system</li>
                                <li>Customer names will be mapped to existing customers (leave empty for products without specific customers)</li>
                                <li>Subcategories help organize products within categories (e.g., "استيكر" under "مطبوعات")</li>
                                <li>Selling prices and costs should be decimal numbers (e.g., 25.99)</li>
                                <li>You can use Arabic product names and descriptions</li>
                                <li>For final products with components, list the components separately as material type</li>
                                <li>Download the sample CSV file below to see the correct format</li>
                            </ul>
                        </div>
                        <p class="mb-3"><strong>Sample File:</strong> <a href="sample_products.csv" download class="btn btn-sm btn-outline-primary">Download Sample CSV</a></p>
                        
                        <form action="upload.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="csv_file" class="form-label">CSV File</label>
                                <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Upload and Review</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Step 2: Customer Mapping (only if customers found in CSV)
elseif ($current_step == 2) {
    $csv_data = $_SESSION['bulk_upload']['csv_data'];
    $unique_customers = $_SESSION['bulk_upload']['unique_customers'];
    
    // Get all existing customers
    $customers_sql = "SELECT id, name, type FROM customers ORDER BY name";
    $customers_result = $conn->query($customers_sql);
    $existing_customers = [];
    if ($customers_result) {
        while ($row = $customers_result->fetch_assoc()) {
            $existing_customers[] = $row;
        }
    }
    
    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Step 2: Map Customers</h5>
                        <form method="POST" action="upload.php" class="d-inline">
                            <button type="submit" name="back_to_upload" class="btn btn-outline-secondary btn-sm">Back to Upload</button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <strong>Products to upload:</strong> <?php echo count($csv_data); ?><br>
                            <strong>Unique customers found:</strong> <?php echo count($unique_customers); ?>
                        </div>
                        
                        <p class="mb-3">Please map each customer from your CSV file to an existing customer in the system:</p>
                        
                        <form method="POST" action="upload.php">
                            <?php foreach ($unique_customers as $customer_name): ?>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">CSV Customer: <strong><?php echo htmlspecialchars($customer_name); ?></strong></label>
                                    </div>
                                    <div class="col-md-8">
                                        <select class="form-select js-searchable-select" name="customer_<?php echo md5($customer_name); ?>" required>
                                            <option value="">Select existing customer...</option>
                                            <?php foreach ($existing_customers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?> 
                                                    (<?php echo ucfirst($customer['type']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="mt-4">
                                <button type="submit" name="process_customer_mapping" class="btn btn-primary">Next: Map Categories</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Step 3: Category Mapping
elseif ($current_step == 3) {
    $csv_data = $_SESSION['bulk_upload']['csv_data'];
    $unique_categories = $_SESSION['bulk_upload']['unique_categories'];
    $unique_customers = $_SESSION['bulk_upload']['unique_customers'] ?? [];
    
    // Get all existing categories
    $categories_sql = "SELECT id, name FROM categories ORDER BY name";
    $categories_result = $conn->query($categories_sql);
    $existing_categories = [];
    if ($categories_result) {
        while ($row = $categories_result->fetch_assoc()) {
            $existing_categories[] = $row;
        }
    }
    
    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Step 3: Map Categories</h5>
                        <form method="POST" action="upload.php" class="d-inline">
                            <?php if (!empty($unique_customers)): ?>
                                <button type="submit" name="back_to_customer_mapping" class="btn btn-outline-secondary btn-sm">Back to Customer Mapping</button>
                            <?php else: ?>
                                <button type="submit" name="back_to_upload" class="btn btn-outline-secondary btn-sm">Back to Upload</button>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <strong>Products to upload:</strong> <?php echo count($csv_data); ?><br>
                            <strong>Unique categories found:</strong> <?php echo count($unique_categories); ?>
                            <?php if (!empty($unique_customers)): ?>
                                <br><strong>Customers mapped:</strong> <?php echo count($unique_customers); ?>
                            <?php endif; ?>
                        </div>
                        
                        <p class="mb-3">Please map each category from your CSV file to an existing category in the system:</p>
                        
                        <form method="POST" action="upload.php">
                            <?php foreach ($unique_categories as $category_name): ?>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">CSV Category: <strong><?php echo htmlspecialchars($category_name); ?></strong></label>
                                    </div>
                                    <div class="col-md-8">
                                        <select class="form-select js-searchable-select" name="category_<?php echo md5($category_name); ?>" required>
                                            <option value="">Select existing category...</option>
                                            <?php foreach ($existing_categories as $category): ?>
                                                <option value="<?php echo $category['id']; ?>">
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="mt-4">
                                <button type="submit" name="process_upload" class="btn btn-success">Process Upload</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

require_once '../../includes/footer.php';
?>