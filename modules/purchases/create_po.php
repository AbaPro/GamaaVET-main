<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('purchases.create')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Block editing if the status is not 'new' (if the edit param is provided)
if (isset($_GET['edit'])) {
    $e_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
    $stmt->execute([$e_id]);
    $st = $stmt->fetchColumn();
    if ($st && $st !== 'new') {
        $_SESSION['error'] = "Only purchase orders in 'New' (draft) status can be edited.";
        header("Location: po_details.php?id=" . $e_id);
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        // Insert purchase order
        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders (
                vendor_id, contact_id, order_date, status, 
                total_amount, paid_amount, notes, warehouse_location, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $total_amount = array_sum(array_map(function ($item) {
            return $item['quantity'] * $item['price'];
        }, $_POST['items']));

        $stmt->execute([
            $_POST['vendor_id'],
            $_POST['contact_id'],
            $_POST['order_date'],
            'new',
            $total_amount,
            0.00,
            $_POST['notes'],
            $_POST['warehouse_location'] ?? null,
            $_SESSION['user_id']
        ]);

        $po_id = $pdo->lastInsertId();

        // Insert PO items
        $stmt = $pdo->prepare("
            INSERT INTO purchase_order_items (
                purchase_order_id, product_id, quantity, unit_price, total_price
            ) VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($_POST['items'] as $item) {
            if ($item['product_id'] && $item['quantity'] > 0) {
                $total_price = $item['quantity'] * $item['price'];
                $stmt->execute([
                    $po_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $total_price
                ]);
            }
        }

        // Update status if submitted (not draft)
        if ($_POST['action'] == 'submit') {
            $pdo->exec("UPDATE purchase_orders SET status = 'ordered' WHERE id = $po_id");
            $_SESSION['success'] = "Purchase order submitted successfully!";
        } else {
            $_SESSION['success'] = "Purchase order saved as draft!";
        }

        $pdo->commit();
        header("Location: po_details.php?id=" . $po_id);
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error creating purchase order: " . $e->getMessage();
    }
}

// Get vendors for dropdown
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get products for dropdown (include type and customer_id for filtering)
$products = $pdo->query("
    SELECT p.id, p.name, p.sku, p.cost_price, p.type, p.customer_id, p.category_id, p.subcategory_id, c.name as category 
    FROM products p
    JOIN categories c ON p.category_id = c.id
    ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get customers for product filter
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get main categories for product filter
$categories = $pdo->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get subcategories for product filter
$subcategories = $pdo->query("SELECT id, name, parent_id FROM categories WHERE parent_id IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Set default date
$order_date = date('Y-m-d');

require_once '../../includes/header.php';
?>

<div class="container mt-4">
    <h2>Create Purchase Order</h2>

    <?php include '../../includes/messages.php'; ?>

    <form id="poForm" method="post">
        <div class="card mb-4">
            <div class="card-header">Purchase Order Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="order_date" class="form-label">Order Date</label>
                            <input type="date" class="form-control" id="order_date" name="order_date"
                                value="<?= $order_date ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="vendor_id" class="form-label">Vendor</label>
                            <select class="form-select" id="vendor_id" name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $vendor) : ?>
                                    <option value="<?= $vendor['id'] ?>"><?= htmlspecialchars($vendor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="contact_id" class="form-label">Contact Person</label>
                            <select class="form-select" id="contact_id" name="contact_id" required disabled>
                                <option value="">Select Vendor First</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="warehouse_location" class="form-label">Warehouse Destination</label>
                            <input type="text" class="form-control" id="warehouse_location" name="warehouse_location" placeholder="e.g. Warehouse A / Dock 4">
                            <small class="text-muted">Where this PO should be delivered.</small>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Purchase Order Items</span>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text" class="form-control form-control-sm" id="productSearch" placeholder="Search products..." aria-label="Search products">
                    <button type="button" class="btn btn-sm btn-primary" id="addItemBtn">Add Item</button>
                </div>
            </div>
            <div class="card-body">
                <table class="table" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Items will be added dynamically -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Total:</strong></td>
                            <td><span id="poTotal">0.00</span></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <div>
                <button type="submit" name="action" value="save" class="btn btn-info">Save Draft</button>
                <button type="submit" name="action" value="submit" class="btn btn-primary">Submit PO</button>
            </div>
        </div>
    </form>
</div>

<!-- Product selection modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light p-3 rounded mb-3">
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label for="filter_type_modal" class="form-label small">Product Type</label>
                            <select class="form-select form-select-sm" id="filter_type_modal">
                                <option value="">All types</option>
                                <option value="primary">Primary</option>
                                <option value="final">Final</option>
                                <option value="material">Material</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_category_modal" class="form-label small">Category</label>
                            <select class="form-select form-select-sm" id="filter_category_modal">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_subcategory_modal" class="form-label small">Sub-Category</label>
                            <select class="form-select form-select-sm" id="filter_subcategory_modal">
                                <option value="">All sub-categories</option>
                                <?php foreach ($subcategories as $subcat) : ?>
                                    <option value="<?= (int)$subcat['id'] ?>" data-parent-id="<?= (int)$subcat['parent_id'] ?>"><?= htmlspecialchars($subcat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_customer_modal" class="form-label small">Customer</label>
                            <select class="form-select form-select-sm" id="filter_customer_modal">
                                <option value="">All customers</option>
                                <?php foreach ($customers as $cust) : ?>
                                    <option value="<?= (int)$cust['id'] ?>"><?= htmlspecialchars($cust['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="productSearchModal" class="form-label small">Search</label>
                            <input type="text" id="productSearchModal" class="form-control form-control-sm" placeholder="Type name or SKU...">
                        </div>
                    </div>
                </div>

                <table class="table table-striped table-hover" id="productsTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product) : ?>
                            <tr data-type="<?= htmlspecialchars($product['type'] ?? '') ?>" 
                                data-customer-id="<?= (int)($product['customer_id'] ?? 0) ?>"
                                data-category-id="<?= (int)($product['category_id'] ?? 0) ?>"
                                data-subcategory-id="<?= (int)($product['subcategory_id'] ?? 0) ?>">
                                <td><?= htmlspecialchars($product['sku']) ?></td>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td><?= htmlspecialchars($product['category']) ?></td>
                                <td>
                                    <?php
                                    $stock = $pdo->query(
                                        "
                                        SELECT SUM(quantity) 
                                        FROM inventory_products 
                                        WHERE product_id = " . (int)$product['id']
                                    )->fetchColumn();
                                    echo $stock ? number_format($stock) : '0';
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary select-product"
                                        data-id="<?= $product['id'] ?>"
                                        data-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-price="<?= $product['cost_price'] ?>">
                                        Select
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
    $(document).ready(function() {
        // Load contacts when vendor changes
        $('#vendor_id').change(function() {
            const vendorId = $(this).val();
            if (vendorId) {
                $('#contact_id').prop('disabled', false);
                $.getJSON('../../ajax/get_vendor_details.php?id=' + vendorId, function(response) {
                    if (response.success && response.vendor) {
                        let options = '<option value="">Select Contact</option>';
                        options += `<option value="${response.vendor.id}">
                        ${response.vendor.name} (${response.vendor.phone ?? '-'})
                    </option>`;
                        $('#contact_id').html(options).prop('disabled', false);
                    } else {
                        $('#contact_id').html('<option value="">No contacts found</option>').prop('disabled', true);
                    }
                });

            } else {
                $('#contact_id').prop('disabled', true).html('<option value="">Select Vendor First</option>');
            }
        });

        const productModalEl = document.getElementById('productModal');
        const productModal = new bootstrap.Modal(productModalEl);
        const productSearch = document.getElementById('productSearch');
        const productSearchModal = document.getElementById('productSearchModal');
        const productTypeFilter = document.getElementById('filter_type_modal');
        const productCategoryFilter = document.getElementById('filter_category_modal');
        const productSubcategoryFilter = document.getElementById('filter_subcategory_modal');
        const productCustomerFilter = document.getElementById('filter_customer_modal');

        const getProductRows = () => Array.from(document.querySelectorAll('#productsTable tbody tr'));

        const filterProducts = () => {
            const term = ((productSearchModal && productSearchModal.value) || (productSearch && productSearch.value) || '').toLowerCase();
            const type = (productTypeFilter && productTypeFilter.value) || '';
            const category = (productCategoryFilter && productCategoryFilter.value) || '';
            const subcategory = (productSubcategoryFilter && productSubcategoryFilter.value) || '';
            const customer = (productCustomerFilter && productCustomerFilter.value) || '';
            const rows = getProductRows();
            rows.forEach(row => {
                const content = row.textContent.toLowerCase();
                const rowType = (row.dataset.type || '').toLowerCase();
                const rowCategory = String(row.dataset.categoryId || '');
                const rowSubcategory = String(row.dataset.subcategoryId || '');
                const rowCustomer = String(row.dataset.customerId || '');

                const matchesTerm = term === '' || content.includes(term);
                const matchesType = type === '' || rowType === type;
                const matchesCategory = category === '' || rowCategory === category;
                const matchesSubcategory = subcategory === '' || rowSubcategory === subcategory;
                const matchesCustomer = customer === '' || rowCustomer === customer;

                row.style.display = (matchesTerm && matchesType && matchesCategory && matchesSubcategory && matchesCustomer) ? '' : 'none';
            });
        };

        if (productSearch) {
            productSearch.addEventListener('input', () => {
                if (productSearch.value.trim() !== '') {
                    productModal.show();
                }
                filterProducts();
            });
        }
        if (productSearchModal) {
            productSearchModal.addEventListener('input', filterProducts);
        }
        if (productTypeFilter) {
            productTypeFilter.addEventListener('change', filterProducts);
        }
        if (productCategoryFilter) {
            productCategoryFilter.addEventListener('change', function() {
                const parentId = this.value;
                $('#filter_subcategory_modal option').each(function() {
                    const optionParent = $(this).data('parentId');
                    if (parentId === '' || !optionParent || String(optionParent) === parentId) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
                
                // Reset subcategory if not valid
                const currentSub = $('#filter_subcategory_modal').val();
                if (currentSub) {
                    const currentOption = $('#filter_subcategory_modal option:selected');
                    const currentParent = String(currentOption.data('parentId') || '');
                    if (parentId !== '' && currentParent !== parentId) {
                        $('#filter_subcategory_modal').val('');
                    }
                }
                filterProducts();
            });
        }
        if (productSubcategoryFilter) {
            productSubcategoryFilter.addEventListener('change', filterProducts);
        }
        if (productCustomerFilter) {
            productCustomerFilter.addEventListener('change', filterProducts);
        }

        $('#addItemBtn').click(function() {
            if (productSearch) {
                productSearch.value = '';
            }
            if (productSearchModal) {
                productSearchModal.value = '';
            }
            if (productTypeFilter) productTypeFilter.value = '';
            if (productCategoryFilter) productCategoryFilter.value = '';
            $('#filter_subcategory_modal').val('').find('option').show();
            if (productCustomerFilter) productCustomerFilter.value = '';
            filterProducts();
            productModal.show();
        });

        // Filter products when modal is shown
        $('#productModal').on('shown.bs.modal', function() {
            filterProducts();
        });

        // Product selection
        $(document).on('click', '.select-product', function() {
            const productId = $(this).data('id');
            const productName = $(this).data('name');
            const productPrice = parseFloat($(this).data('price'));
            const rowId = 'item_' + productId;

            if ($('#' + rowId).length) {
                // If product already exists in table, just increase quantity
                const qtyInput = $('#' + rowId).find('.item-qty');
                qtyInput.val(parseInt(qtyInput.val()) + 1);
                updateRowTotal($('#' + rowId));
            } else {
                // Add new row
                const newRow = `
                <tr id="${rowId}">
                    <td>
                        ${productName}
                        <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm item-qty" 
                               name="items[${productId}][quantity]" value="1" min="1">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm item-price" 
                               name="items[${productId}][price]" value="${productPrice}" step="0.01" min="0">
                    </td>
                    <td class="item-total">${productPrice.toFixed(2)}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            $('#itemsTable tbody').append(newRow);
        }

        updatePOTotal();
        productModal.hide();
        });

        // Remove item
        $(document).on('click', '.remove-item', function() {
            $(this).closest('tr').remove();
            updatePOTotal();
        });

        // Update row total when quantity or price changes
        $(document).on('change', '.item-qty, .item-price', function() {
            updateRowTotal($(this).closest('tr'));
            updatePOTotal();
        });

        function updateRowTotal(row) {
            const qty = parseFloat(row.find('.item-qty').val()) || 0;
            const price = parseFloat(row.find('.item-price').val()) || 0;
            const total = (qty * price).toFixed(2);
            row.find('.item-total').text(total);
        }

        function updatePOTotal() {
            let total = 0;
            $('.item-total').each(function() {
                total += parseFloat($(this).text()) || 0;
            });
            $('#poTotal').text(total.toFixed(2));
        }
    });
</script>

