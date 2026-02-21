<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('inventories.products.add')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

// Initialize session if not set
if (!isset($_SESSION['inventory_bulk_upload'])) {
    $_SESSION['inventory_bulk_upload'] = [
        'step' => 1,
        'csv_data' => null,
        'inventory_id' => null,
        'missing_products' => []
    ];
}

// Handle back button
if (isset($_POST['back_to_upload'])) {
    unset($_SESSION['inventory_bulk_upload']);
    redirect('upload.php');
}

// Step 1: Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $inventory_id = (int)$_POST['inventory_id'];
    if (!$inventory_id) {
        setAlert('danger', 'Please select a valid inventory.');
        redirect('upload.php');
    }

    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_path = $_FILES['csv_file']['tmp_name'];
        $file = fopen($file_path, 'r');
        $header = fgetcsv($file);

        if (!$header) {
            setAlert('danger', 'The CSV file is empty.');
            redirect('upload.php');
        }

        // Normalize header names to lowercase for comparison
        $header = array_map(function($h) { return strtolower(trim($h)); }, $header);
        
        $name_idx = array_search('product name', $header);
        if ($name_idx === false) $name_idx = array_search('name', $header);
        
        $qty_idx = array_search('quantity', $header);
        if ($qty_idx === false) $qty_idx = array_search('qty', $header);

        if ($name_idx === false || $qty_idx === false) {
            setAlert('danger', 'CSV must contain "Product Name" and "Quantity" columns.');
            redirect('upload.php');
        }

        $csv_data = [];
        $missing_products = [];
        $all_products = [];

        // Fetch all products for matching
        $prod_res = $conn->query("SELECT id, name FROM products");
        while ($p = $prod_res->fetch_assoc()) {
            $all_products[strtolower(trim($p['name']))] = $p['id'];
        }

        while (($row = fgetcsv($file)) !== FALSE) {
            // Check if row has enough columns
            if (count($row) <= max($name_idx, $qty_idx)) continue;

            $raw_name = trim($row[$name_idx]);
            $qty = (float)$row[$qty_idx];
            
            if (empty($raw_name)) continue;

            $matched_id = $all_products[strtolower($raw_name)] ?? null;
            
            $csv_data[] = [
                'name' => $raw_name,
                'quantity' => $qty,
                'product_id' => $matched_id
            ];

            if (!$matched_id && !in_array($raw_name, $missing_products)) {
                $missing_products[] = $raw_name;
            }
        }
        fclose($file);

        if (empty($csv_data)) {
            setAlert('danger', 'No valid data found in CSV.');
            redirect('upload.php');
        }

        $_SESSION['inventory_bulk_upload'] = [
            'step' => empty($missing_products) ? 3 : 2,
            'csv_data' => $csv_data,
            'inventory_id' => $inventory_id,
            'missing_products' => $missing_products
        ];

        // If no missing products, we can proceed to processing immediately (handled below)
        if (empty($missing_products)) {
           $_SESSION['inventory_bulk_upload']['step'] = 3;
        }
        
        redirect('upload.php');
    } else {
        setAlert('danger', 'Error uploading CSV file.');
        redirect('upload.php');
    }
}

// Step 2: Handle product remapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_mapping'])) {
    $mapping = [];
    foreach ($_SESSION['inventory_bulk_upload']['missing_products'] as $missing_name) {
        $key = 'map_' . md5($missing_name);
        if (isset($_POST[$key]) && !empty($_POST[$key])) {
            $mapping[$missing_name] = (int)$_POST[$key];
        }
    }

    // Update csv_data with mapped IDs
    foreach ($_SESSION['inventory_bulk_upload']['csv_data'] as &$row) {
        if (!$row['product_id'] && isset($mapping[$row['name']])) {
            $row['product_id'] = $mapping[$row['name']];
        }
    }

    $_SESSION['inventory_bulk_upload']['step'] = 3;
    // Fall through to Step 3 processing
}

// Step 3: Final Processing
if (isset($_SESSION['inventory_bulk_upload']) && $_SESSION['inventory_bulk_upload']['step'] == 3) {
    $inventory_id = $_SESSION['inventory_bulk_upload']['inventory_id'];
    $csv_data = $_SESSION['inventory_bulk_upload']['csv_data'];
    
    $success_count = 0;
    $error_count = 0;
    
    $conn->begin_transaction();
    try {
        foreach ($csv_data as $row) {
            $product_id = $row['product_id'];
            $qty = $row['quantity'];
            
            // Skip products that were not matched/mapped
            if (!$product_id) {
                $error_count++;
                continue;
            }

            // Check if product already exists in this inventory
            $check = $conn->prepare("SELECT id FROM inventory_products WHERE inventory_id = ? AND product_id = ?");
            $check->bind_param("ii", $inventory_id, $product_id);
            $check->execute();
            $res = $check->get_result();
            
            if ($res->num_rows > 0) {
                // Update existing record
                $stmt = $conn->prepare("UPDATE inventory_products SET quantity = ? WHERE inventory_id = ? AND product_id = ?");
                $stmt->bind_param("dii", $qty, $inventory_id, $product_id);
            } else {
                // Insert new record
                $stmt = $conn->prepare("INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $inventory_id, $product_id, $qty);
            }
            
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
            }
            $stmt->close();
            $check->close();
        }
        $conn->commit();
        setAlert('success', "Bulk upload complete. $success_count products processed, $error_count skipped.");
        logActivity("Bulk inventory upload to ID: $inventory_id. $success_count products updated.");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', "Error processing upload: " . $e->getMessage());
    }
    
    unset($_SESSION['inventory_bulk_upload']);
    redirect("view.php?id=$inventory_id");
}

$page_title = 'Bulk Inventory Upload';
require_once '../../includes/header.php';
$step = $_SESSION['inventory_bulk_upload']['step'] ?? 1;
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Bulk Inventory Upload</h2>
        <a href="index.php" class="btn btn-secondary">Back to Inventories</a>
    </div>

    <?php if ($step == 1): ?>
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">Step 1: Upload CSV and Select Inventory</h5>
                </div>
                <div class="card-body">
                    <form action="upload.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="form-label fw-bold">1. Target Inventory</label>
                            <select class="form-select" name="inventory_id" required>
                                <option value="">-- Select Inventory --</option>
                                <?php
                                $inv_res = $conn->query("SELECT id, name FROM inventories WHERE is_active = 1 ORDER BY name");
                                while ($inv = $inv_res->fetch_assoc()) {
                                    $selected = (isset($_GET['inventory_id']) && $_GET['inventory_id'] == $inv['id']) ? 'selected' : '';
                                    echo "<option value='{$inv['id']}' $selected>".htmlspecialchars($inv['name'])."</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold">2. CSV File</label>
                            <div class="alert alert-info">
                                <p class="mb-1 small text-dark"><i class="fas fa-info-circle me-1"></i> Required columns (case-insensitive):</p>
                                <ul class="mb-0 small text-dark">
                                    <li><strong>Product Name</strong> (or "Name")</li>
                                    <li><strong>Quantity</strong> (or "Qty")</li>
                                </ul>
                            </div>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Continue to Review</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php elseif ($step == 2): ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Step 2: Remap Missing Products</h5>
                    <form method="POST"><button type="submit" name="back_to_upload" class="btn btn-sm btn-outline-dark">Start Over</button></form>
                </div>
                <div class="card-body">
                    <p>The following product names from your CSV couldn't be automatically matched. Please select the correct system product or leave as "Skip" to ignore that row.</p>
                    <form action="upload.php" method="POST">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40%;">Name in CSV</th>
                                        <th>System Product Match</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $all_prods_res = $conn->query("SELECT id, name, sku FROM products ORDER BY name");
                                    $all_prods = $all_prods_res->fetch_all(MYSQLI_ASSOC);
                                    
                                    foreach ($_SESSION['inventory_bulk_upload']['missing_products'] as $missing_name): ?>
                                    <tr>
                                        <td class="align-middle"><strong><?php echo htmlspecialchars($missing_name); ?></strong></td>
                                        <td>
                                            <select class="form-select select2" name="map_<?php echo md5($missing_name); ?>">
                                                <option value="">-- Skip this product --</option>
                                                <?php foreach ($all_prods as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['sku']); ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-grid mt-3">
                            <button type="submit" name="process_mapping" class="btn btn-success">Confirm Mapping and Process Upload</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    if ($.fn.select2) {
        $('.select2').select2({
            placeholder: "-- Search and select product --",
            allowClear: true,
            width: '100%'
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
