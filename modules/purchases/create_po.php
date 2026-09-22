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
$edit_po = null;
$edit_po_items = [];
$edit_po_images = [];
if (isset($_GET['edit'])) {
    $e_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $stmt->execute([$e_id]);
    $edit_po = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_po && $edit_po['status'] !== 'new') {
        $_SESSION['error'] = "Only purchase orders in 'New' (draft) status can be edited.";
        header("Location: po_details.php?id=" . $e_id);
        exit();
    }
    if ($edit_po) {
        $stmt2 = $pdo->prepare("
            SELECT poi.*, p.name AS product_name, p.unit
            FROM purchase_order_items poi
            JOIN products p ON p.id = poi.product_id
            WHERE poi.purchase_order_id = ?
        ");
        $stmt2->execute([$e_id]);
        $edit_po_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $pdo->prepare("
            SELECT file_path, original_name
            FROM purchase_order_images
            WHERE purchase_order_id = ?
            ORDER BY created_at ASC, id ASC
        ");
        $stmt3->execute([$e_id]);
        $edit_po_images = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Purchase-order destinations are shared locations, but CureVet belongs only
// to its direct-sale channel and must not be exposed to the other channels.
$locationWhere = "is_active = 1";
if (($_SESSION['login_region'] ?? 'factory') !== 'curva') {
    $locationWhere .= " AND LOWER(REPLACE(TRIM(name), ' ', '')) NOT IN ('curevet', 'curevetinventory')";
}
$locations = $pdo->query("SELECT id, name FROM locations WHERE $locationWhere ORDER BY name")
    ->fetchAll(PDO::FETCH_ASSOC);
$allowedWarehouseLocations = array_column($locations, 'name', 'name');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uploadedPOImages = [];

    try {
        if (empty($_POST['items'])) {
            throw new Exception("Please add at least one item to the purchase order.");
        }

        $valid_items_count = 0;
        foreach ($_POST['items'] as $item) {
            if ($item['product_id'] && $item['quantity'] > 0) {
                $valid_items_count++;
            }
        }

        if ($valid_items_count === 0) {
            throw new Exception("Please ensure at least one item has a quantity greater than zero.");
        }

        $warehouseLocation = $_POST['warehouse_location'] ?? '';
        if ($warehouseLocation === '' || !isset($allowedWarehouseLocations[$warehouseLocation])) {
            throw new Exception("Please select a valid warehouse destination.");
        }

        $editing_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

        $pdo->beginTransaction();

        if ($editing_id) {
            $editCheck = $pdo->prepare("SELECT id FROM purchase_orders WHERE id = ? AND status = 'new' FOR UPDATE");
            $editCheck->execute([$editing_id]);
            if (!$editCheck->fetchColumn()) {
                throw new Exception("Only purchase orders in 'New' (draft) status can be edited.");
            }

            // Update existing draft PO
            $stmt = $pdo->prepare("
                UPDATE purchase_orders SET
                    vendor_id = ?, contact_id = ?, order_date = ?,
                    notes = ?, warehouse_location = ?
                WHERE id = ? AND status = 'new'
            ");
            $stmt->execute([
                $_POST['vendor_id'],
                $_POST['contact_id'],
                $_POST['order_date'],
                $_POST['notes'],
                $warehouseLocation,
                $editing_id,
            ]);
            $po_id = $editing_id;

            // Replace all items
            $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$po_id]);
        } else {
            // Insert new purchase order
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    vendor_id, contact_id, order_date, status,
                    total_amount, paid_amount, notes, warehouse_location, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['vendor_id'],
                $_POST['contact_id'],
                $_POST['order_date'],
                'new',
                0.00,
                0.00,
                $_POST['notes'],
                $warehouseLocation,
                $_SESSION['user_id']
            ]);
            $po_id = $pdo->lastInsertId();
        }

        $imageError = null;
        $uploadedPOImages = uploadImageAttachments(
            'po_images',
            'assets/uploads/purchase_orders',
            'po_' . (int)$po_id,
            0,
            $imageError
        );
        if ($imageError !== null) {
            throw new Exception($imageError);
        }

        if (!empty($uploadedPOImages)) {
            $imageStmt = $pdo->prepare("
                INSERT INTO purchase_order_images
                    (purchase_order_id, file_path, original_name, created_by)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($uploadedPOImages as $file) {
                $imageStmt->execute([
                    $po_id,
                    $file['path'],
                    $file['original_name'],
                    $_SESSION['user_id']
                ]);
            }
        }

        // Insert PO items
        $stmt = $pdo->prepare("
            INSERT INTO purchase_order_items (
                purchase_order_id, product_id, quantity, unit_price, total_price, unit
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($_POST['items'] as $item) {
            if ($item['product_id'] && $item['quantity'] > 0) {
                $stmt->execute([
                    $po_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    0.00,
                    $item['unit'] ?? null
                ]);
            }
        }

        // Update status if submitted (not draft)
        if ($_POST['action'] == 'submit') {
            $pdo->prepare("UPDATE purchase_orders SET status = 'ordered' WHERE id = ?")->execute([$po_id]);
            $_SESSION['success'] = "Purchase order submitted successfully!";
        } else {
            $_SESSION['success'] = $editing_id ? "Purchase order updated successfully!" : "Purchase order saved as draft!";
        }

        $pdo->commit();
        header("Location: po_details.php?id=" . $po_id);
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($uploadedPOImages as $file) {
            $fullPath = ROOT_PATH . '/' . $file['path'];
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        $_SESSION['error'] = "Error saving purchase order: " . $e->getMessage();
    }
}

// Get vendors for dropdown
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get products for dropdown (only material type for PO)
$products = $pdo->query("
    SELECT p.id, p.name, p.sku, p.cost_price, p.type, p.unit, p.customer_id, p.category_id, p.subcategory_id, c.name as category
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.type = 'material' AND " . getActiveProductSql('p') . "
    ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);
$purchaseProductCosts = getCalculatedProductCostDetails(array_column($products, 'id'));
foreach ($products as &$purchaseProduct) {
    $calculatedCost = $purchaseProductCosts[(int)$purchaseProduct['id']]['value'] ?? null;
    if ($calculatedCost !== null) {
        $purchaseProduct['cost_price'] = $calculatedCost;
    }
}
unset($purchaseProduct);

// Get customers for product filter
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get main categories for product filter
$categories = $pdo->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get subcategories for product filter
$subcategories = $pdo->query("SELECT id, name, parent_id FROM categories WHERE parent_id IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Set default date
$order_date = $edit_po ? $edit_po['order_date'] : date('Y-m-d');

require_once '../../includes/header.php';
?>

<div class="container mt-4">
    <h2><?= $edit_po ? 'Edit Purchase Order' : 'Create Purchase Order' ?></h2>

    <?php include '../../includes/messages.php'; ?>

    <form id="poForm" method="post" enctype="multipart/form-data">
        <?php if ($edit_po): ?>
            <input type="hidden" name="edit_id" value="<?= (int)$edit_po['id'] ?>">
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-header">Purchase Order Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="order_date" class="form-label">Order Date</label>
                            <input type="date" class="form-control" id="order_date" name="order_date"
                                value="<?= htmlspecialchars($order_date) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="vendor_id" class="form-label">Vendor</label>
                            <select class="form-select" id="vendor_id" name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $vendor) : ?>
                                    <option value="<?= $vendor['id'] ?>" <?= ($edit_po && $edit_po['vendor_id'] == $vendor['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vendor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="contact_id" class="form-label">Contact Person</label>
                            <select class="form-select" id="contact_id" name="contact_id" required <?= $edit_po ? '' : 'disabled' ?>>
                                <?php if ($edit_po): ?>
                                    <option value="<?= (int)$edit_po['contact_id'] ?>" selected>Loading...</option>
                                <?php else: ?>
                                    <option value="">Select Vendor First</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="warehouse_location" class="form-label">Warehouse Destination</label>
                            <select class="form-select" id="warehouse_location" name="warehouse_location" required>
                                <option value="">Select Warehouse</option>
                                <?php foreach ($locations as $loc) : ?>
                                    <option value="<?= htmlspecialchars($loc['name']) ?>" <?= ($edit_po && $edit_po['warehouse_location'] == $loc['name']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($loc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Where this PO should be delivered.</small>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"><?= $edit_po ? htmlspecialchars($edit_po['notes']) : '' ?></textarea>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="po_images" class="form-label">Purchase Order Images <span class="text-muted">(optional)</span></label>
                            <input type="file" class="form-control" id="po_images" name="po_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                            <small class="text-muted">Upload one or multiple JPG, PNG, GIF, or WEBP images. Maximum 5MB per image.</small>
                            <div id="po-images-preview" class="mt-2 d-flex flex-wrap gap-2"></div>
                            <?php if (!empty($edit_po_images)): ?>
                                <div class="mt-2">
                                    <small class="text-muted d-block mb-1">Already attached (new uploads will be added):</small>
                                    <?= renderAttachmentThumbnails($edit_po_images) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Purchase Order Items</span>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text" class="form-control form-control-sm" id="productSearch" placeholder="Search by product name or SKU" aria-label="Search by product name or SKU">
                    <button type="button" class="btn btn-sm btn-primary" id="addItemBtn">Add Item</button>
                </div>
            </div>
            <div class="card-body">
                <table class="table" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Unit</th>
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
                            <td colspan="4" class="text-end"><strong>Total:</strong></td>
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
                        <div class="col-md-3">
                            <label for="filter_category_modal" class="form-label small">Category</label>
                            <select class="form-select form-select-sm" id="filter_category_modal">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
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
                            <input type="text" id="productSearchModal" class="form-control form-control-sm" placeholder="Search by product name or SKU">
                        </div>
                    </div>
                </div>

                <table class="table table-striped table-hover" id="productsTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Unit</th>
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
                                <td><?= htmlspecialchars(getProductUnitLabel($product['unit'] ?? '') ?: 'Not set') ?></td>
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
                                        data-price="<?= $product['cost_price'] ?>"
                                        data-unit="<?= htmlspecialchars($product['unit'] ?? '') ?>">
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
        $('#po_images').on('change', function() {
            const preview = $('#po-images-preview');
            preview.empty();

            Array.from(this.files || []).forEach(function(file) {
                const imageUrl = URL.createObjectURL(file);
                const image = $('<img>', {
                    src: imageUrl,
                    alt: file.name,
                    title: file.name,
                    class: 'img-thumbnail'
                }).css({ height: '100px', width: 'auto', objectFit: 'cover' });
                image.on('load', function() {
                    URL.revokeObjectURL(imageUrl);
                });
                preview.append(image);
            });
        });

        // Escape untrusted values before injecting them into option markup
        function escapeContactHtml(s) {
            return s == null ? '' : String(s).replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[m]);
        }

        // Build the contact dropdown options for a vendor's contacts
        function renderContactOptions(contacts, selectedContactId) {
            let options = '<option value="">Select Contact</option>';
            contacts.forEach(function(contact) {
                const selected = (selectedContactId && contact.id == selectedContactId) ? ' selected' : '';
                options += `<option value="${contact.id}"${selected}>` +
                    `${escapeContactHtml(contact.name)} (${escapeContactHtml(contact.phone || '-')})` +
                    `</option>`;
            });
            return options;
        }

        // Load contacts when vendor changes
        $('#vendor_id').change(function() {
            const vendorId = $(this).val();
            if (vendorId) {
                $('#contact_id').prop('disabled', false);
                $.getJSON('../../ajax/get_vendor_details.php?id=' + vendorId, function(response) {
                    if (response.success && response.contacts && response.contacts.length) {
                        $('#contact_id').html(renderContactOptions(response.contacts)).prop('disabled', false);
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
        const productCategoryFilter = document.getElementById('filter_category_modal');
        const productSubcategoryFilter = document.getElementById('filter_subcategory_modal');
        const productCustomerFilter = document.getElementById('filter_customer_modal');

        const getProductRows = () => Array.from(document.querySelectorAll('#productsTable tbody tr'));

        const filterProducts = () => {
            const term = ((productSearchModal && productSearchModal.value) || (productSearch && productSearch.value) || '').toLowerCase();
            const category = (productCategoryFilter && productCategoryFilter.value) || '';
            const subcategory = (productSubcategoryFilter && productSubcategoryFilter.value) || '';
            const customer = (productCustomerFilter && productCustomerFilter.value) || '';
            const rows = getProductRows();
            rows.forEach(row => {
                const content = row.textContent.toLowerCase();
                const rowCategory = String(row.dataset.categoryId || '');
                const rowSubcategory = String(row.dataset.subcategoryId || '');
                const rowCustomer = String(row.dataset.customerId || '');

                const matchesTerm = term === '' || content.includes(term);
                const matchesCategory = category === '' || rowCategory === category;
                const matchesSubcategory = subcategory === '' || rowSubcategory === subcategory;
                const matchesCustomer = customer === '' || rowCustomer === customer;

                row.style.display = (matchesTerm && matchesCategory && matchesSubcategory && matchesCustomer) ? '' : 'none';
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
            const productUnit = $(this).data('unit') || '';
            const rowId = 'item_' + productId;

            if ($('#' + rowId).length) {
                // If product already exists in table, just increase quantity
                const qtyInput = $('#' + rowId).find('.item-qty');
                qtyInput.val(parseFloat(qtyInput.val()) + 1);
                updateRowTotal($('#' + rowId));
            } else {
                // Add new row
                const newRow = `
                <tr id="${rowId}">
                    <td>
                        ${productName}
                        <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                        <input type="hidden" name="items[${productId}][unit]" value="${productUnit}">
                    </td>
                    <td>${productUnit ? `<span class="badge bg-secondary">${productUnit}</span>` : '-'}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm item-qty"
                               name="items[${productId}][quantity]" value="1" min="0.01" step="0.01">
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

        // Validate form on submit
        $('#poForm').on('submit', function(e) {
            const itemCount = $('#itemsTable tbody tr').length;
            if (itemCount === 0) {
                e.preventDefault();
                alert('Please add at least one item to the purchase order.');
                return false;
            }

            let validQty = false;
            $('.item-qty').each(function() {
                if (parseFloat($(this).val()) > 0) {
                    validQty = true;
                }
            });

            if (!validQty) {
                e.preventDefault();
                alert('Please ensure at least one item has a quantity greater than zero.');
                return false;
            }
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

        function addItemRow(productId, productName, productUnit, qty, price) {
            const rowId = 'item_' + productId;
            if ($('#' + rowId).length) {
                const qtyInput = $('#' + rowId).find('.item-qty');
                qtyInput.val(parseFloat(qtyInput.val()) + qty);
                updateRowTotal($('#' + rowId));
                return;
            }
            const total = (qty * price).toFixed(2);
            const newRow = `
            <tr id="${rowId}">
                <td>
                    ${productName}
                    <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                    <input type="hidden" name="items[${productId}][unit]" value="${productUnit}">
                </td>
                <td>${productUnit ? `<span class="badge bg-secondary">${productUnit}</span>` : '-'}</td>
                <td>
                    <input type="number" class="form-control form-control-sm item-qty"
                           name="items[${productId}][quantity]" value="${qty}" min="0.01" step="0.01">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm item-price"
                           name="items[${productId}][price]" value="${price}" step="0.01" min="0">
                </td>
                <td class="item-total">${total}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-item">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
            $('#itemsTable tbody').append(newRow);
            updatePOTotal();
        }

        <?php if ($edit_po): ?>
        // Pre-load vendor contacts
        (function() {
            const vendorId = <?= (int)$edit_po['vendor_id'] ?>;
            const selectedContactId = <?= (int)$edit_po['contact_id'] ?>;
            if (vendorId) {
                $.getJSON('../../ajax/get_vendor_details.php?id=' + vendorId, function(response) {
                    if (response.success && response.contacts && response.contacts.length) {
                        $('#contact_id')
                            .html(renderContactOptions(response.contacts, selectedContactId))
                            .prop('disabled', false);
                    } else {
                        $('#contact_id').html('<option value="">No contacts found</option>').prop('disabled', true);
                    }
                });
            }
        })();

        // Pre-populate existing items
        <?php foreach ($edit_po_items as $item): ?>
        (function() {
            const productId   = <?= (int)$item['product_id'] ?>;
            const productName = <?= json_encode($item['product_name'] ?? '') ?>;
            const unit        = <?= json_encode($item['unit'] ?? '') ?>;
            const qty         = <?= (float)$item['quantity'] ?>;
            const price       = <?= (float)$item['unit_price'] ?>;
            // Try to get the name from the products table in the modal if not stored on the item
            const modalRow = $('#productsTable tbody tr').filter(function() {
                return $(this).find('.select-product').data('id') == productId;
            });
            const resolvedName = (modalRow.length && modalRow.find('td').eq(1).text().trim())
                ? modalRow.find('td').eq(1).text().trim()
                : (productName || 'Product #' + productId);
            const resolvedUnit = (modalRow.length && modalRow.find('.select-product').data('unit'))
                ? modalRow.find('.select-product').data('unit')
                : unit;
            addItemRow(productId, resolvedName, resolvedUnit, qty, price);
        })();
        <?php endforeach; ?>
        <?php endif; ?>

    });
</script>
