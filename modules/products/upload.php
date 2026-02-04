<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

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
        
        // Check required columns
        $required_columns = ['name', 'sku', 'type', 'category_id', 'unit_price'];
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
                $sku = sanitize($data['sku']);
                $barcode = isset($data['barcode']) ? sanitize($data['barcode']) : NULL;
                $type = sanitize($data['type']);
                $category_id = sanitize($data['category_id']);
                $subcategory_id = isset($data['subcategory_id']) ? sanitize($data['subcategory_id']) : NULL;
                $customer_id = isset($data['customer_id']) && $data['customer_id'] !== '' ? (int)$data['customer_id'] : NULL;
                $unit_price = sanitize($data['unit_price']);
                $cost_price = isset($data['cost_price']) ? sanitize($data['cost_price']) : NULL;
                $min_stock_level = isset($data['min_stock_level']) ? sanitize($data['min_stock_level']) : 0;
                $description = isset($data['description']) ? sanitize($data['description']) : NULL;
                
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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Bulk Product Upload</h2>
    <a href="index.php" class="btn btn-secondary">Back to Products</a>
</div>

<div class="card">
    <div class="card-body">
        <div class="alert alert-info">
            <h5 class="alert-heading">CSV File Instructions</h5>
            <p>Upload a CSV file with product data. The file must include the following columns:</p>
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
