<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid inventory ID.');
    redirect('index.php');
}

$inventory_id = sanitize($_GET['id']);
$page_title = 'Inventory Details';
require_once '../../includes/header.php';

// Get inventory info
$inventory_sql = "SELECT i.*, l.name AS location_name, l.address AS location_address FROM inventories i LEFT JOIN locations l ON l.id = i.location_id WHERE i.id = ?";
$inventory_stmt = $conn->prepare($inventory_sql);
$inventory_stmt->bind_param("i", $inventory_id);
$inventory_stmt->execute();
$inventory_result = $inventory_stmt->get_result();

if ($inventory_result->num_rows === 0) {
    setAlert('danger', 'Inventory not found.');
    redirect('index.php');
}

$inventory = $inventory_result->fetch_assoc();
$inventory_stmt->close();

// Get inventory products
$products_sql = "SELECT p.id, p.name, p.sku, p.barcode, ip.quantity, p.min_stock_level, c.name AS customer_name 
                 FROM inventory_products ip 
                 JOIN products p ON ip.product_id = p.id 
                 LEFT JOIN customers c ON p.customer_id = c.id
                 WHERE ip.inventory_id = ? 
                 ORDER BY p.name";
$products_stmt = $conn->prepare($products_sql);
$products_stmt->bind_param("i", $inventory_id);
$products_stmt->execute();
$products_result = $products_stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        <?php echo e($inventory['name']); ?>
        <span class="badge bg-<?php echo $inventory['is_active'] ? 'success' : 'secondary'; ?>">
            <?php echo $inventory['is_active'] ? 'Active' : 'Inactive'; ?>
        </span>
    </h2>
    <div>
        <a href="upload.php?inventory_id=<?php echo $inventory_id; ?>" class="btn btn-outline-primary">
            <i class="fas fa-file-upload"></i> Bulk Upload
        </a>
        <a href="print.php?id=<?php echo $inventory_id; ?>" class="btn btn-info" target="_blank">
            <i class="fas fa-print"></i> Print
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="fas fa-plus"></i> Add Product
        </button>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Inventory Information</h5>
                <p><strong>Location:</strong> <?php echo $inventory['location_name'] ? e($inventory['location_name']) . ' - ' . e($inventory['location_address']) : '<span class="text-muted">Not set</span>'; ?></p>
                <p><strong>Description:</strong> <?php echo e($inventory['description']); ?></p>
                <p><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($inventory['created_at'])); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Quick Stats</h5>
                <?php
                $stats_sql = "SELECT 
                                COUNT(*) as total_products, 
                                SUM(quantity) as total_quantity,
                                SUM(CASE WHEN quantity <= p.min_stock_level THEN 1 ELSE 0 END) as low_stock
                              FROM inventory_products ip
                              JOIN products p ON ip.product_id = p.id
                              WHERE ip.inventory_id = ?";
                $stats_stmt = $conn->prepare($stats_sql);
                $stats_stmt->bind_param("i", $inventory_id);
                $stats_stmt->execute();
                $stats = $stats_stmt->get_result()->fetch_assoc();
                $stats_stmt->close();
                ?>
                <p><strong>Total Products:</strong> <?php echo $stats['total_products']; ?></p>
                <p><strong>Total Quantity:</strong> <?php echo $stats['total_quantity']; ?></p>
                <p><strong>Low Stock Items:</strong> <span class="badge bg-<?php echo $stats['low_stock'] > 0 ? 'warning' : 'success'; ?>"><?php echo $stats['low_stock']; ?></span></p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Inventory Products</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Customer</th>
                        <th>Barcode</th>
                        <th>Quantity</th>
                        <th>Min Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products_result->num_rows > 0): ?>
                        <?php while ($product = $products_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo $product['customer_name'] ? htmlspecialchars($product['customer_name']) : '<span class="text-muted">N/A</span>'; ?></td>
                                <td><?php echo htmlspecialchars($product['barcode']); ?></td>
                                <td><?php echo $product['quantity']; ?></td>
                                <td><?php echo $product['min_stock_level']; ?></td>
                                <td>
                                    <?php if ($product['quantity'] <= $product['min_stock_level']): ?>
                                        <span class="badge bg-warning">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary edit-product" 
                                            data-id="<?php echo $product['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                            data-quantity="<?php echo $product['quantity']; ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="delete_product.php?inventory_id=<?php echo $inventory_id; ?>&product_id=<?php echo $product['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Are you sure you want to remove this product from inventory?')">
                                        <i class="fas fa-trash"></i> Remove
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">No products found in this inventory</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="add_product.php" method="POST">
                <input type="hidden" name="inventory_id" value="<?php echo $inventory_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductModalLabel">Add Product to Inventory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Filter Bar -->
                    <div class="bg-light p-3 rounded mb-4">
                        <h6 class="text-muted mb-3"><i class="fas fa-filter me-2"></i>Quick Filters</h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label for="filter_type" class="form-label small">Product Type</label>
                                <select class="form-select form-select-sm" id="filter_type">
                                    <option value="">All types</option>
                                    <option value="primary">Primary</option>
                                    <option value="final">Final</option>
                                    <option value="material">Material</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="filter_customer" class="form-label small">Customer</label>
                                <select class="form-select form-select-sm" id="filter_customer">
                                    <option value="">All customers</option>
                                    <?php
                                    $customers_result = $conn->query("SELECT id, name FROM customers ORDER BY name");
                                    if ($customers_result) {
                                        while ($customer = $customers_result->fetch_assoc()) {
                                            echo '<option value="' . (int)$customer['id'] . '">' . htmlspecialchars($customer['name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="product_search" class="form-label">Search Product</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" class="form-control" id="product_search" placeholder="Type name or SKU to search...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="product_id" class="form-label">Select Product</label>
                        <select class="form-select" id="product_id" name="product_id" required size="5" style="height: auto;">
                            <option value="">-- Select Product --</option>
                            <?php
                            $all_products = $conn->query("SELECT id, name, sku, type, customer_id FROM products ORDER BY name");
                            while ($prod = $all_products->fetch_assoc()) {
                                $type = isset($prod['type']) ? htmlspecialchars($prod['type']) : '';
                                $customer_id = isset($prod['customer_id']) ? (int)$prod['customer_id'] : 0;
                                echo '<option value="' . $prod['id'] . '" data-type="' . $type . '" data-customer-id="' . $customer_id . '">' . htmlspecialchars($prod['name']) . ' (' . htmlspecialchars($prod['sku']) . ')</option>';
                            }
                            ?>
                        </select>
                        <div id="product_count" class="form-text mt-1 text-end">0 products found</div>
                    </div>
                    <div class="mb-3">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="0" step="0.01" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Quantity Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="update_product.php" method="POST">
                <input type="hidden" name="inventory_id" value="<?php echo $inventory_id; ?>">
                <input type="hidden" id="edit_product_id" name="product_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductModalLabel">Update Product Quantity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_product_name" class="form-label">Product</label>
                        <input type="text" class="form-control" id="edit_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="edit_quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="edit_quantity" name="quantity" min="0" step="0.01" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Quantity</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    var allProductOptions = $('#product_id option').map(function() {
        return {
            value: $(this).val(),
            text: $(this).text(),
            type: $(this).data('type') || '',
            customerId: $(this).data('customer-id') ? String($(this).data('customer-id')) : ''
        };
    }).get();

    function applyProductFilters() {
        var selectedType = $('#filter_type').val();
        var selectedCustomer = $('#filter_customer').val();
        var searchQuery = $('#product_search').val().toLowerCase();
        var $productSelect = $('#product_id');

        $productSelect.empty();
        $productSelect.append(new Option('-- Select Product --', ''));

        var foundCount = 0;
        allProductOptions.forEach(function(option) {
            if (!option.value) {
                return;
            }

            var typeMatch = selectedType === '' || option.type === selectedType;
            var customerMatch = selectedCustomer === '' || option.customerId === selectedCustomer;
            var searchMatch = searchQuery === '' || option.text.toLowerCase().indexOf(searchQuery) !== -1;

            if (typeMatch && customerMatch && searchMatch) {
                var newOption = new Option(option.text, option.value);
                $(newOption).attr('data-type', option.type);
                $(newOption).attr('data-customer-id', option.customerId);
                $productSelect.append(newOption);
                foundCount++;
            }
        });

        $('#product_count').text(foundCount + ' products found');
        $productSelect.val('');
    }

    // Handle edit button click
    $('.edit-product').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var quantity = $(this).data('quantity');
        
        $('#edit_product_id').val(id);
        $('#edit_product_name').val(name);
        $('#edit_quantity').val(quantity);
        
        $('#editProductModal').modal('show');
    });

    $('#filter_type, #filter_customer, #product_search').on('change keyup', applyProductFilters);

    $('#addProductModal').on('show.bs.modal', function() {
        $('#filter_type').val('');
        $('#filter_customer').val('');
        $('#product_search').val('');
        applyProductFilters();
        setTimeout(() => $('#product_search').focus(), 500);
    });
});
</script>
