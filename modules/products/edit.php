<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('products.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        setAlert('danger', 'Invalid product reference.');
        redirect('index.php');
    }
    
    $name = sanitize($_POST['name']);
    $sku = sanitize($_POST['sku']);

    if (empty($sku)) {
        $sku = generateUniqueSku();
    }

    $barcode = sanitize($_POST['barcode']);
    $type = sanitize($_POST['type']);
    $category_id = sanitize($_POST['category_id']);
    $subcategory_id = !empty($_POST['subcategory_id']) ? sanitize($_POST['subcategory_id']) : null;
    $customer_id = isset($_POST['customer_id']) && $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
    $unit_price = sanitize($_POST['unit_price']);
    $cost_price = isset($_POST['cost_price']) && $_POST['cost_price'] !== '' ? sanitize($_POST['cost_price']) : null;
    $min_stock_level = !empty($_POST['min_stock_level']) ? sanitize($_POST['min_stock_level']) : 0;
    $description = sanitize($_POST['description']);

    // Check if the SKU already exists for OTHER products
    $check_sql = "SELECT id FROM products WHERE sku = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $sku, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $duplicate = $check_result->fetch_assoc();
        setAlert('danger', 'Another product with SKU "' . $sku . '" already exists (ID: ' . $duplicate['id'] . ').');
        $check_stmt->close();
        redirect('index.php');
    }
    $check_stmt->close();

    $image_name = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = rtrim(__DIR__ . '/../../assets/uploads/products/', '/\\') . DIRECTORY_SEPARATOR;

        if (!file_exists($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            setAlert('danger', 'Could not create upload directory.');
            redirect('index.php');
        }
        $file_ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = 'product_' . time() . '.' . strtolower($file_ext);
        $upload_path = $upload_dir . $image_name;

        $check = getimagesize($_FILES['image']['tmp_name']);
        if ($check === false) {
            setAlert('danger', 'File is not an image.');
            redirect('index.php');
        }

        if ($_FILES['image']['size'] > 2000000) {
            setAlert('danger', 'Sorry, your file is too large. Max 2MB allowed.');
            redirect('index.php');
        }

        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array(strtolower($file_ext), $allowed_ext, true)) {
            setAlert('danger', 'Sorry, only JPG, JPEG, PNG & GIF files are allowed.');
            redirect('index.php');
        }

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
            setAlert('danger', 'Sorry, there was an error uploading your file.');
            redirect('index.php');
        }

        $old_image_sql = "SELECT image FROM products WHERE id = ?";
        $old_image_stmt = $conn->prepare($old_image_sql);
        $old_image_stmt->bind_param("i", $id);
        $old_image_stmt->execute();
        $old_image_result = $old_image_stmt->get_result();
        $old_image = $old_image_result->fetch_assoc()['image'] ?? null;
        $old_image_stmt->close();

        if ($old_image && file_exists($upload_dir . $old_image)) {
            unlink($upload_dir . $old_image);
        }
    } else {
        $image_sql = "SELECT image FROM products WHERE id = ?";
        $image_stmt = $conn->prepare($image_sql);
        $image_stmt->bind_param("i", $id);
        $image_stmt->execute();
        $image_result = $image_stmt->get_result();
        $image_name = $image_result->fetch_assoc()['image'] ?? null;
        $image_stmt->close();
    }

    $update_sql = "UPDATE products SET 
                   name = ?, sku = ?, barcode = ?, type = ?, category_id = ?, subcategory_id = ?, customer_id = ?,
                   unit_price = ?, cost_price = ?, min_stock_level = ?, description = ?, image = ? 
                   WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "ssssiiidddssi",
        $name,
        $sku,
        $barcode,
        $type,
        $category_id,
        $subcategory_id,
        $customer_id,
        $unit_price,
        $cost_price,
        $min_stock_level,
        $description,
        $image_name,
        $id
    );

    if ($update_stmt->execute()) {
        setAlert('success', 'Product updated successfully.');
        logActivity("Updated product ID: $id");
    } else {
        setAlert('danger', 'Error updating product: ' . $conn->error);
    }
    $update_stmt->close();

    redirect('index.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setAlert('danger', 'Invalid product reference.');
    redirect('index.php');
}

$product = getProductById($id);
if (!$product) {
    setAlert('danger', 'Product not found.');
    redirect('index.php');
}

$categories = [];
$catResult = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
if ($catResult) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($customerRow = $customerResult->fetch_assoc()) {
        $customers[] = $customerRow;
    }
}

$subcategories = [];
if (!empty($product['category_id'])) {
    $subStmt = $conn->prepare("SELECT id, name FROM categories WHERE parent_id = ? ORDER BY name");
    $subStmt->bind_param("i", $product['category_id']);
    $subStmt->execute();
    $subRes = $subStmt->get_result();
    $subcategories = $subRes ? $subRes->fetch_all(MYSQLI_ASSOC) : [];
    $subStmt->close();
}

$page_title = 'Edit Product';
require_once '../../includes/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Edit Product: <?= htmlspecialchars($product['name']) ?></h2>
        <a href="index.php" class="btn btn-secondary">Back to Products</a>
    </div>

    <form action="edit.php" method="POST" enctype="multipart/form-data" class="card p-4">
        <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Product Name</label>
                <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">SKU</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($product['sku']) ?>" readonly>
                <input type="hidden" name="sku" value="<?= htmlspecialchars($product['sku']) ?>">
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Barcode</label>
                <input type="text" class="form-control" name="barcode" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Type</label>
                <select class="form-select js-product-type" name="type" required>
                    <option value="primary" <?= $product['type'] === 'primary' ? 'selected' : '' ?>>Primary</option>
                    <option value="final" <?= $product['type'] === 'final' ? 'selected' : '' ?>>Final</option>
                    <option value="material" <?= $product['type'] === 'material' ? 'selected' : '' ?>>Material</option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Category</label>
                <select class="form-select" name="category_id" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= (int)$category['id'] === (int)$product['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Subcategory</label>
                <select class="form-select" name="subcategory_id">
                    <option value="">-- Select Subcategory --</option>
                    <?php foreach ($subcategories as $subcategory): ?>
                        <option value="<?= (int)$subcategory['id'] ?>" <?= (int)$subcategory['id'] === (int)$product['subcategory_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($subcategory['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12 mb-3">
                <label class="form-label">Customer</label>
                <select class="form-select js-searchable-select" name="customer_id">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int)$customer['id'] ?>" <?= (int)$customer['id'] === (int)$product['customer_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3" data-pricing-group="unit">
                <label class="form-label">Selling Price</label>
                <input type="number" class="form-control" name="unit_price" min="0" step="0.01" value="<?= htmlspecialchars($product['unit_price']) ?>" data-role="unit-price">
            </div>
            <div class="col-md-6 mb-3" data-pricing-group="cost">
                <label class="form-label">Cost Price</label>
                <input type="number" class="form-control" name="cost_price" min="0" step="0.01" value="<?= htmlspecialchars($product['cost_price']) ?>" data-role="cost-price">
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Minimum Stock Level</label>
                <input type="number" class="form-control" name="min_stock_level" min="0" value="<?= htmlspecialchars($product['min_stock_level']) ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Product Image</label>
                <input type="file" class="form-control" name="image" accept="image/*">
                <?php if (!empty($product['image'])): ?>
                    <small class="text-muted d-block mt-1">Current Image: <?= htmlspecialchars($product['image']) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Product</button>
        </div>
    </form>
</div>

<script>
    const toggleStandalonePricingGroups = (selectEl) => {
        const type = selectEl.value;
        const form = selectEl.closest('form');
        if (!form) return;
        const showUnit = type !== 'material';
        const showCost = type !== 'final';
        const unitGroup = form.querySelector('[data-pricing-group=\"unit\"]');
        const costGroup = form.querySelector('[data-pricing-group=\"cost\"]');
        const unitInput = form.querySelector('[data-role=\"unit-price\"]');
        const costInput = form.querySelector('[data-role=\"cost-price\"]');
        if (unitGroup) unitGroup.classList.toggle('d-none', !showUnit);
        if (costGroup) costGroup.classList.toggle('d-none', !showCost);
        if (unitInput) unitInput.required = showUnit;
        if (costInput) costInput.required = showCost;
    };

    const initStandaloneSearchableSelects = () => {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') {
            return;
        }
        $('.js-searchable-select').each(function () {
            const $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) {
                return;
            }
            $select.select2({
                width: '100%'
            });
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        const typeSelect = document.querySelector('.js-product-type');
        if (typeSelect) {
            toggleStandalonePricingGroups(typeSelect);
            typeSelect.addEventListener('change', function () {
                toggleStandalonePricingGroups(this);
            });
        }
        initStandaloneSearchableSelects();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>
