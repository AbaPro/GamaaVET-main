<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('products.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$filterType = null;
if (isset($_GET['type']) && in_array($_GET['type'], ['material', 'final'], true)) {
    $filterType = $_GET['type'];
}

$customerFilter = null;
if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) && (int)$_GET['customer_id'] > 0) {
    $customerFilter = (int)$_GET['customer_id'];
}

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($customerRow = $customerResult->fetch_assoc()) {
        $customers[] = $customerRow;
    }
}

$page_title = $filterType === 'material'
    ? 'Raw Materials'
    : ($filterType === 'final' ? 'Final Products' : 'Products Management');
require_once '../../includes/header.php';

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);

    // Check if product exists in any inventory
    $check_sql = "SELECT COUNT(*) as count FROM inventory_products WHERE product_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $in_inventory = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();

    if ($in_inventory) {
        setAlert('danger', 'Cannot delete product as it exists in one or more inventories. Remove from inventories first.');
    } else {
        // Check if product is used as a component
        $component_sql = "SELECT COUNT(*) as count FROM product_components WHERE component_id = ?";
        $component_stmt = $conn->prepare($component_sql);
        $component_stmt->bind_param("i", $id);
        $component_stmt->execute();
        $component_result = $component_stmt->get_result();
        $is_component = $component_result->fetch_assoc()['count'] > 0;
        $component_stmt->close();

        if ($is_component) {
            setAlert('danger', 'Cannot delete product as it is used as a component in other products.');
        } else {
            $delete_sql = "DELETE FROM products WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $id);

            if ($delete_stmt->execute()) {
                setAlert('success', 'Product deleted successfully.');
                logActivity("Deleted product ID: $id");
            } else {
                setAlert('danger', 'Error deleting product: ' . $conn->error);
            }
            $delete_stmt->close();
        }
    }
    redirect('index.php');
}

// Fetch all products with category and customer info
$whereClauses = [];
$paramTypes = '';
$paramValues = [];

if ($filterType !== null) {
    $whereClauses[] = 'p.type = ?';
    $paramTypes .= 's';
    $paramValues[] = $filterType;
}

if ($customerFilter !== null) {
    $whereClauses[] = 'p.customer_id = ?';
    $paramTypes .= 'i';
    $paramValues[] = $customerFilter;
}

$sql = "SELECT p.*, c1.name as category_name, c2.name as subcategory_name, cust.name as customer_name,
               COALESCE(SUM(ip.quantity), 0) as total_stock
        FROM products p
        LEFT JOIN categories c1 ON p.category_id = c1.id
        LEFT JOIN categories c2 ON p.subcategory_id = c2.id
        LEFT JOIN customers cust ON p.customer_id = cust.id
        LEFT JOIN inventory_products ip ON p.id = ip.product_id
        GROUP BY p.id";

if (!empty($whereClauses)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$sql .= " ORDER BY p.name";

if (!empty($whereClauses)) {
    $stmt = $conn->prepare($sql);
    $bindParams = array_merge([$paramTypes], $paramValues);
    $bindRefs = [];
    foreach ($bindParams as $key => $value) {
        $bindRefs[$key] = &$bindParams[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindRefs);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$products = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

$canViewAnyUnitPrice = hasExplicitPermission('products.final.price.view') || hasExplicitPermission('products.material.price.view');
$canViewAnyCostPrice = hasExplicitPermission('products.final.cost.view') || hasExplicitPermission('products.material.cost.view');
$showUnitPriceColumn = false;
$showCostPriceColumn = false;
foreach ($products as $productRow) {
    if (!$showUnitPriceColumn && $canViewAnyUnitPrice && canViewProductPrice($productRow['type'])) {
        $showUnitPriceColumn = true;
    }
    if (!$showCostPriceColumn && $canViewAnyCostPrice && canViewProductCost($productRow['type'])) {
        $showCostPriceColumn = true;
    }
    if ($showUnitPriceColumn && $showCostPriceColumn) {
        break;
    }
}
$productsTableColspan = 8 + ($showUnitPriceColumn ? 1 : 0) + ($showCostPriceColumn ? 1 : 0);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        <?php if ($filterType === 'material'): ?>
            Raw Materials
        <?php elseif ($filterType === 'final'): ?>
            Final Products
        <?php else: ?>
            Products
        <?php endif; ?>
    </h2>
    <div>
        <a href="upload.php" class="btn btn-info me-2">
            <i class="fas fa-upload"></i> Bulk Upload
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="fas fa-plus"></i> Add Product
        </button>
    </div>
</div>

<div class="mb-3">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
        <?php if ($filterType !== null): ?>
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <?php endif; ?>
        <div class="me-1">
            <label class="form-label mb-1 small text-muted">Customer</label>
            <select class="form-select form-select-sm" name="customer_id">
                <option value="">All Customers</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?php echo (int)$customer['id']; ?>" <?php echo ($customerFilter !== null && $customerFilter === (int)$customer['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
        <?php if ($customerFilter !== null): ?>
            <a href="index.php<?php echo $filterType !== null ? '?type=' . urlencode($filterType) : ''; ?>" class="btn btn-sm btn-outline-secondary">
                Reset Customer
            </a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="productsTable">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Subcategory</th>
                        <th>Customer</th>
                        <th>Stock</th>
                        <?php if ($showUnitPriceColumn): ?>
                        <th>Unit Price</th>
                        <?php endif; ?>
                        <?php if ($showCostPriceColumn): ?>
                        <th>Cost Price</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($products) > 0): ?>
                        <?php foreach ($products as $row): ?>
                            <tr>
                                <?php
                                $imageName = $row['image'] ?? '';
                                $productImagePath = $imageName ? __DIR__ . '/../../assets/uploads/products/' . $imageName : '';
                                $hasProductImage = $imageName && file_exists($productImagePath);
                                $productImageUrl = $hasProductImage ? '../../assets/uploads/products/' . rawurlencode($imageName) : '';
                                ?>
                                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                                <td class="text-center align-middle">
                                    <?php if ($hasProductImage): ?>
                                        <img src="<?php echo $productImageUrl; ?>"
                                             alt="<?php echo htmlspecialchars($row['name']); ?> image"
                                             style="width: 52px; height: 52px; object-fit: cover; border-radius: 6px; border: 1px solid #dee2e6;">
                                    <?php else: ?>
                                        <span class="text-muted small">No image</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo getProductTypeColor($row['type']); ?>">
                                        <?php echo ucfirst($row['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                                <td><?php echo $row['subcategory_name'] ? htmlspecialchars($row['subcategory_name']) : '-'; ?></td>
                                <td><?php echo $row['customer_name'] ? htmlspecialchars($row['customer_name']) : '-'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $row['total_stock'] > 0 ? 'success' : 'danger'; ?>">
                                        <?php echo number_format((float)$row['total_stock'], 2); ?>
                                    </span>
                                </td>
                                <?php if ($showUnitPriceColumn): ?>
                                <td>
                                    <?php if (canViewProductPrice($row['type'])): ?>
                                            <?php echo isset($row['unit_price']) && $row['unit_price'] !== '' ? number_format((float)$row['unit_price'], 2) : '-'; ?>
                                        <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php if ($showCostPriceColumn): ?>
                                <td>
                                    <?php if (canViewProductCost($row['type'])): ?>
                                        <?php echo $row['cost_price'] ? number_format($row['cost_price'], 2) : '-'; ?>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button class="btn btn-sm btn-outline-warning edit-product"
                                        data-id="<?php echo $row['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                        data-sku="<?php echo htmlspecialchars($row['sku']); ?>"
                                        data-barcode="<?php echo htmlspecialchars($row['barcode'] ?? ''); ?>"
                                        data-type="<?php echo htmlspecialchars($row['type']); ?>"
                                        data-category="<?php echo $row['category_id'] ?? ''; ?>"
                                        data-subcategory="<?php echo $row['subcategory_id'] ?? ''; ?>"
                                        data-customer="<?php echo isset($row['customer_id']) ? (int)$row['customer_id'] : ''; ?>"
                                        data-unit_price="<?php echo canViewProductPrice($row['type']) ? $row['unit_price'] : ''; ?>"
                                        data-cost_price="<?php echo canViewProductCost($row['type']) ? ($row['cost_price'] ?? '') : ''; ?>"
                                        data-min_stock="<?php echo $row['min_stock_level'] ?? 0; ?>"
                                        data-description="<?php echo htmlspecialchars($row['description'] ?? ''); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <a href="index.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Are you sure you want to delete this product?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($products) === 0): ?>
            <div class="text-center text-muted py-3">No products found</div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="create.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="sku" class="form-label">SKU</label>
                            <input type="text" class="form-control" id="sku" name="sku" placeholder="Auto-generated" readonly>
                            <small class="text-muted">SKU is generated automatically.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="barcode" class="form-label">Barcode</label>
                            <input type="text" class="form-control" id="barcode" name="barcode" placeholder="Auto-generated" readonly>
                            <small class="text-muted">Barcode is generated automatically.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="type" class="form-label">Product Type</label>
                            <select class="form-select js-product-type" id="type" name="type" required>
                                <option value="">-- Select Type --</option>
                                <option value="primary">Primary Product</option>
                                <option value="final">Final Product</option>
                                <option value="material">Material</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category_id" class="form-label">Category</label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php
                                $categories = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
                                while ($cat = $categories->fetch_assoc()) {
                                    echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="subcategory_id" class="form-label">Subcategory</label>
                            <select class="form-select" id="subcategory_id" name="subcategory_id">
                                <option value="">-- Select Subcategory --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3" data-customer-group="customer">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select js-searchable-select" id="customer_id" name="customer_id">
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo (int)$customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3" data-pricing-group="unit">
                            <label for="unit_price" class="form-label">Unit Price</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price" min="0" step="0.01" data-role="unit-price">
                        </div>
                        <div class="col-md-6 mb-3" data-pricing-group="cost">
                            <label for="cost_price" class="form-label">Cost Price</label>
                            <input type="number" class="form-control" id="cost_price" name="cost_price" min="0" step="0.01" data-role="cost-price">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="min_stock_level" class="form-label">Minimum Stock Level</label>
                            <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" min="0" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="image" class="form-label">Product Image</label>
                            <input type="file" class="form-control" id="image" name="image" accept="image/*">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="edit.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductModalLabel">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_sku" class="form-label">SKU</label>
                            <input type="text" class="form-control" id="edit_sku" name="sku" required readonly>
                            <small class="text-muted">SKU is auto-generated and cannot be changed here.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_barcode" class="form-label">Barcode</label>
                            <input type="text" class="form-control" id="edit_barcode" name="barcode">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_type" class="form-label">Product Type</label>
                            <select class="form-select js-product-type" id="edit_type" name="type" required>
                                <option value="primary">Primary Product</option>
                                <option value="final">Final Product</option>
                                <option value="material">Material</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_category_id" class="form-label">Category</label>
                            <select class="form-select" id="edit_category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php
                                $categories = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
                                while ($cat = $categories->fetch_assoc()) {
                                    echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_subcategory_id" class="form-label">Subcategory</label>
                            <select class="form-select" id="edit_subcategory_id" name="subcategory_id">
                                <option value="">-- Select Subcategory --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3" data-customer-group="customer">
                            <label for="edit_customer_id" class="form-label">Customer</label>
                            <select class="form-select js-searchable-select" id="edit_customer_id" name="customer_id">
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo (int)$customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3" data-pricing-group="unit">
                            <label for="edit_unit_price" class="form-label">Unit Price</label>
                            <input type="number" class="form-control" id="edit_unit_price" name="unit_price" min="0" step="0.01" data-role="unit-price">
                        </div>
                        <div class="col-md-6 mb-3" data-pricing-group="cost">
                            <label for="edit_cost_price" class="form-label">Cost Price</label>
                            <input type="number" class="form-control" id="edit_cost_price" name="cost_price" min="0" step="0.01" data-role="cost-price">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_min_stock_level" class="form-label">Minimum Stock Level</label>
                            <input type="number" class="form-control" id="edit_min_stock_level" name="min_stock_level" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_image" class="form-label">Product Image</label>
                            <input type="file" class="form-control" id="edit_image" name="image" accept="image/*">
                            <div id="current_image" class="mt-2"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
    const generateSkuPreview = () => {
        const chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let randomPart = '';
        for (let i = 0; i < 6; i++) {
            randomPart += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        const now = new Date();
        const yy = String(now.getFullYear()).slice(-2);
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return `SKU-${yy}${mm}${dd}-${randomPart}`;
    };

    const generateBarcodePreview = () => {
        let result = '';
        for (let i = 0; i < 12; i++) {
            result += Math.floor(Math.random() * 10).toString();
        }
        return result;
    };

    const toggleProductPricingGroups = (selectEl) => {
        const type = selectEl.value;
        const form = selectEl.closest('form');
        if (!form) return;
        const showUnit = type !== 'material';
        const showCost = type !== 'final';
        const showCustomer = type !== 'material';
        const unitGroup = form.querySelector('[data-pricing-group="unit"]');
        const costGroup = form.querySelector('[data-pricing-group="cost"]');
        const unitInput = form.querySelector('[data-role="unit-price"]');
        const costInput = form.querySelector('[data-role="cost-price"]');
        if (unitGroup) unitGroup.classList.toggle('d-none', !showUnit);
        if (costGroup) costGroup.classList.toggle('d-none', !showCost);
        const customerGroup = form.querySelector('[data-customer-group="customer"]');
        if (customerGroup) customerGroup.classList.toggle('d-none', !showCustomer);
        if (unitInput) unitInput.required = showUnit;
        if (costInput) costInput.required = showCost;
    };

    const initProductPricingControls = () => {
        document.querySelectorAll('.js-product-type').forEach(select => {
            toggleProductPricingGroups(select);
            select.addEventListener('change', function () {
                toggleProductPricingGroups(this);
            });
        });
    };

    const initSearchableSelects = () => {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') {
            return;
        }

        $('.js-searchable-select').each(function () {
            const $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) {
                return;
            }

            const $modal = $select.closest('.modal');
            $select.select2({
                width: '100%',
                dropdownParent: $modal.length ? $modal : $(document.body)
            });
        });
    };

    $(document).ready(function() {
        if ($.fn.DataTable && $('#productsTable').length && !$.fn.DataTable.isDataTable('#productsTable')) {
            $('#productsTable').DataTable({
                order: [],
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100]
            });
        }

        initProductPricingControls();
        initSearchableSelects();

        const addProductModal = document.getElementById('addProductModal');
        if (addProductModal) {
            addProductModal.addEventListener('shown.bs.modal', () => {
                const skuInput = document.getElementById('sku');
                const barcodeInput = document.getElementById('barcode');
                if (skuInput && !skuInput.value) {
                    skuInput.value = generateSkuPreview();
                }
                if (barcodeInput && !barcodeInput.value) {
                    barcodeInput.value = generateBarcodePreview();
                }
            });
            addProductModal.addEventListener('hidden.bs.modal', () => {
                const skuInput = document.getElementById('sku');
                const barcodeInput = document.getElementById('barcode');
                if (skuInput) skuInput.value = '';
                if (barcodeInput) barcodeInput.value = '';
            });
        }

        // Load subcategories when category changes
        $('#category_id').change(function() {
            var category_id = $(this).val();
            if (category_id) {
                $.ajax({
                    url: '../../ajax/get_subcategories.php',
                    type: 'GET',
                    data: {
                        category_id: category_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        var options = '<option value="">-- Select Subcategory --</option>';
                        $.each(response.subcategories, function(index, subcategory) {
                            options += '<option value="' + subcategory.id + '">' + subcategory.name + '</option>';
                        });
                        $('#subcategory_id').html(options);
                    }
                });
            } else {
                $('#subcategory_id').html('<option value="">-- Select Subcategory --</option>');
            }
        });

        // Handle edit button click using event delegation
        $(document).on('click', '.edit-product', function() {

            const id = $(this).data('id');
            const name = $(this).data('name');
            const sku = $(this).data('sku');
            const barcode = $(this).data('barcode');
            const type = $(this).data('type');
            const category = $(this).data('category');
            const subcategory = $(this).data('subcategory');
            const unit_price = $(this).data('unit_price');
            const cost_price = $(this).data('cost_price');
            const min_stock = $(this).data('min_stock') || $(this).data('min_stock_level') || 0;
            const description = $(this).data('description');

            $('#edit_id').val(id);
            $('#edit_name').val(name);
            $('#edit_sku').val(sku);
            $('#edit_barcode').val(barcode);
            $('#edit_type').val(type);
            $('#edit_unit_price').val(unit_price);
            $('#edit_cost_price').val(cost_price);
            $('#edit_min_stock_level').val(min_stock);
            $('#edit_description').val(description);
            const customer = $(this).data('customer') || '';
            $('#edit_customer_id').val(customer).trigger('change');
            $('#edit_category_id').val(category).trigger('change');

            const editTypeField = document.getElementById('edit_type');
            if (editTypeField) {
                toggleProductPricingGroups(editTypeField);
            }

            setTimeout(function() {
                $('#edit_subcategory_id').val(subcategory);
            }, 500);

            // Load product image via AJAX
            $.ajax({
                url: '../../ajax/get_product_image.php',
                type: 'GET',
                data: {
                    product_id: id
                },
                dataType: 'json',
                success: function(response) {
                    if (response.image) {
                        $('#current_image').html('<img src="../../assets/uploads/products/' + response.image + '" style="max-height: 100px;">');
                    } else {
                        $('#current_image').html('<p>No image uploaded</p>');
                    }
                }
            });

            // Show the modal properly with Bootstrap 5
            const editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
            editModal.show();
        });


        // Also handle subcategory loading for edit modal
            $('#edit_category_id').change(function() {
                var category_id = $(this).val();
                if (category_id) {
                $.ajax({
                    url: '../../ajax/get_subcategories.php',
                    type: 'GET',
                    data: {
                        category_id: category_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        var options = '<option value="">-- Select Subcategory --</option>';
                        $.each(response.subcategories, function(index, subcategory) {
                            options += '<option value="' + subcategory.id + '">' + subcategory.name + '</option>';
                        });
                        $('#edit_subcategory_id').html(options);
                    }
                });
            } else {
                $('#edit_subcategory_id').html('<option value="">-- Select Subcategory --</option>');
            }
        });
        $('#addProductModal, #editProductModal').on('shown.bs.modal', function () {
            initSearchableSelects();
        });
    });
</script>

