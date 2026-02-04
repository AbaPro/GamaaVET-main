<?php
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('sales.orders.create')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Generate internal ID
$internal_id = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $rawItems = $_POST['items'] ?? [];
        $factoryId = !empty($_POST['factory_id']) ? (int)$_POST['factory_id'] : null;
        $orderItems = [];
        $itemsSubtotal = 0;
        $freeSampleCount = 0;

        foreach ($rawItems as $item) {
            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            $unitPrice = isset($item['price']) ? (float)$item['price'] : 0;
            $isFreeSample = !empty($item['is_free_sample']) ? 1 : 0;

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $lineTotal = $quantity * $unitPrice;

            if ($isFreeSample) {
                $freeSampleCount += $quantity;
                $lineTotal = 0;
            } else {
                $itemsSubtotal += $lineTotal;
            }

            $orderItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'is_free_sample' => $isFreeSample
            ];
        }

        if (empty($orderItems)) {
            throw new Exception('Please select at least one valid product to create an order.');
        }

        $discount_percentage = isset($_POST['discount_percentage']) ? max(0, min(100, (float)$_POST['discount_percentage'])) : 0;
        $discount_basis_options = ['none', 'product_quantity', 'cash', 'free_sample', 'mixed'];
        $discount_basis = $_POST['discount_basis'] ?? 'none';
        if (!in_array($discount_basis, $discount_basis_options, true)) {
            $discount_basis = 'none';
        }
        $discount_product_count = isset($_POST['discount_product_count']) ? max(0, (int)$_POST['discount_product_count']) : 0;
        $manualDiscount = isset($_POST['discount_cash_amount']) ? max(0, (float)$_POST['discount_cash_amount']) : 0;

        $percentageDiscountValue = $discount_percentage > 0 ? ($itemsSubtotal * ($discount_percentage / 100)) : 0;
        $discount_amount = round(min($itemsSubtotal, $percentageDiscountValue + $manualDiscount), 2);

        $shipping_cost_type = $_POST['shipping_cost_type'] ?? 'none';
        $shippingCost = 0;
        if ($shipping_cost_type === 'manual') {
            $shippingCost = isset($_POST['shipping_cost']) ? max(0, (float)$_POST['shipping_cost']) : 0;
        } else {
            $shipping_cost_type = 'none';
        }

        $total_amount = max(0, $itemsSubtotal - $discount_amount) + $shippingCost;
        $notes = $_POST['notes'] ?? null;

        // Insert order
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                internal_id, customer_id, factory_id, contact_id, order_date, status, 
                total_amount, paid_amount, discount_percentage, discount_basis, discount_amount,
                discount_product_count, free_sample_count, shipping_cost_type, shipping_cost,
                notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $_POST['internal_id'],
            $_POST['customer_id'],
            $factoryId,
            $_POST['contact_id'],
            $_POST['order_date'],
            'new',
            $total_amount,
            0.00,
            $discount_percentage,
            $discount_basis,
            $discount_amount,
            $discount_product_count,
            $freeSampleCount,
            $shipping_cost_type,
            $shippingCost,
            $notes,
            $_SESSION['user_id']
        ]);

        $order_id = $pdo->lastInsertId();

        // Insert order items
        $stmt = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, is_free_sample)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($orderItems as $item) {
            $stmt->execute([
                $order_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
                $item['is_free_sample']
            ]);

            // Update inventory (if final product)
            // This would need to be enhanced based on your inventory logic
            $product_type = $pdo->query("SELECT type FROM products WHERE id = " . $item['product_id'])->fetchColumn();
            if ($product_type == 'final') {
                $pdo->exec(
                    "
                    UPDATE inventory_products 
                    SET quantity = quantity - " . $item['quantity'] . " 
                    WHERE product_id = " . $item['product_id'] . " 
                    AND inventory_id = 1" // Assuming main inventory is ID 1
                );
            }
        }

        $pdo->commit();

        $_SESSION['success'] = "Order created successfully!";
        header("Location: order_details.php?id=" . $order_id);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error creating order: " . $e->getMessage();
    }
}

// Get customers for dropdown
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get products for dropdown
$products = $pdo->query("
    SELECT p.id, p.name, p.sku, p.unit_price, c.name as category 
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.type = 'final'
    ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>Create New Order</h2>

    <?php include '../../includes/messages.php'; ?>

    <form id="orderForm" method="post">
        <div class="card mb-4">
            <div class="card-header">Order Information</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="internal_id" class="form-label">Order ID</label>
                            <input type="text" class="form-control" id="internal_id" name="internal_id"
                                value="<?= $internal_id ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="order_date" class="form-label">Order Date</label>
                            <input type="date" class="form-control" id="order_date" name="order_date"
                                value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" id="customer_id" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer) : ?>
                                    <option value="<?= $customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="contact_id" class="form-label">Contact Person</label>
                            <select class="form-select" id="contact_id" name="contact_id" required disabled>
                                <option value="">Select Customer First</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Factory</label>
                            <input type="text" class="form-control" id="factory_display" value="Select customer" readonly>
                            <input type="hidden" name="factory_id" id="factory_id">
                        </div>
                    </div>
                    <div class="col-md-4">
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
                <span>Order Items</span>
                <button type="button" class="btn btn-sm btn-primary" id="addItemBtn">Add Item</button>
            </div>
            <div class="card-body">
                <table class="table" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Free Sample?</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Items will be added dynamically -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Discounts &amp; Shipping</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="discount_percentage" class="form-label">Discount %</label>
                        <input type="number" class="form-control" id="discount_percentage" name="discount_percentage"
                            value="0" step="0.01" min="0" max="100">
                    </div>
                    <div class="col-md-3">
                        <label for="discount_basis" class="form-label">Discount Type</label>
                        <select class="form-select" id="discount_basis" name="discount_basis">
                            <option value="none">No Discount</option>
                            <option value="product_quantity">Product Count</option>
                            <option value="cash">Cash Discount</option>
                            <option value="free_sample">Free Samples</option>
                            <option value="mixed">Mixed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="discount_cash_amount" class="form-label">Discount (Cash)</label>
                        <input type="number" class="form-control" id="discount_cash_amount" name="discount_cash_amount"
                            value="0" step="0.01" min="0">
                    </div>
                    <div class="col-md-3">
                        <label for="discount_product_count" class="form-label">Discounted Products</label>
                        <input type="number" class="form-control" id="discount_product_count" name="discount_product_count"
                            value="0" min="0" step="1">
                    </div>
                    <div class="col-md-3">
                        <label for="shipping_cost_type" class="form-label">Shipping Cost</label>
                        <select class="form-select" id="shipping_cost_type" name="shipping_cost_type">
                            <option value="none">No Shipping (لا يوجد)</option>
                            <option value="manual">Manual (يدوي)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="shipping_cost" class="form-label">Shipping Amount</label>
                        <input type="number" class="form-control" id="shipping_cost" name="shipping_cost"
                            value="0" step="0.01" min="0" disabled>
                        <small class="text-muted">Enabled only when shipping is manual.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Financial Summary</div>
            <div class="card-body">
                <div class="row text-center text-md-start">
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Items Subtotal</small>
                        <div class="fs-5 fw-semibold" id="itemsSubtotal">0.00</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Discount Amount</small>
                        <div class="fs-5 text-danger" id="calculatedDiscount">0.00</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Shipping Cost</small>
                        <div class="fs-5" id="calculatedShipping">0.00</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Free Samples</small>
                        <div class="fs-5" id="freeSampleCounter">0</div>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Order Total</small>
                    <div class="fs-3 fw-bold text-primary" id="orderTotal">0.00</div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Order</button>
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
                <table class="table table-striped table-hover" id="productsTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product) : ?>
                            <tr>
                                <td><?= htmlspecialchars($product['sku']) ?></td>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td><?= htmlspecialchars($product['category']) ?></td>
                                <td><?= number_format($product['unit_price'], 2) ?></td>
                                <td>
                                    <?php
                                    $stock = $pdo->query(
                                        "
                                        SELECT SUM(quantity) 
                                        FROM inventory_products 
                                        WHERE product_id = " . $product['id']
                                    )->fetchColumn();
                                    echo $stock ? number_format($stock) : '0';
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary select-product"
                                        data-id="<?= $product['id'] ?>"
                                        data-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-price="<?= $product['unit_price'] ?>">
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

<script>
    $(document).ready(function() {
        console.log("Document ready");
        $('#customer_id').select2({
            placeholder: 'Select Customer',
            allowClear: true,
            width: '100%'
        });

        // Load contacts when customer changes
        $('#customer_id').on('change', function() {
            const customerId = $(this).val();

            if (!customerId) {
                $('#contact_id').prop('disabled', true)
                    .html('<option value="">Select Customer First</option>');
                $('#factory_id').val('');
                $('#factory_display').val('Select customer');
                return;
            }

            $('#contact_id').prop('disabled', false)
                .html('<option>Loading...</option>');

            $.getJSON('../../ajax/get_customer_details.php', {
                    customer_id: customerId
                })
                .done(function(resp) {
                    if (!resp || resp.success !== true) {
                        console.error('Unexpected response:', resp);
                        $('#contact_id').html('<option value="">No contacts found</option>');
                        $('#factory_id').val('');
                        $('#factory_display').val('Not assigned');
                        return;
                    }

                    const factoryName = resp.customer && resp.customer.factory_name
                        ? resp.customer.factory_name
                        : 'Not assigned';
                    $('#factory_display').val(factoryName);
                    $('#factory_id').val(resp.customer ? resp.customer.factory_id : '');

                    let options = '<option value="">Select Contact</option>';
                    if (Array.isArray(resp.contacts) && resp.contacts.length) {
                        resp.contacts.forEach(c => {
                            const selected = c.is_primary ? 'selected' : '';
                            const phone = c.phone ? ` (${c.phone})` : '';
                            options += `<option value="${c.id}" ${selected}>${c.name}${phone}</option>`;
                        });
                        $('#contact_id').html(options);
                    } else {
                        $('#contact_id').html('<option value="">No contacts found</option>');
                    }
                })
                .fail(function(xhr) {
                    console.error('Contacts load failed:', xhr.status, xhr.responseText);
                    $('#factory_id').val('');
                    $('#factory_display').val('Failed to load');
                    $('#contact_id').html('<option value="">Failed to load contacts</option>');
                });
        });


        // Add item button
        $('#addItemBtn').click(function() {
            console.log("Add item button clicked");
            $('#productModal').modal('show');
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
                    <td class="item-total" data-value="${productPrice.toFixed(2)}">${productPrice.toFixed(2)}</td>
                    <td>
                        <input type="hidden" class="free-sample-flag" name="items[${productId}][is_free_sample]" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input item-free-sample">
                        </div>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
                $('#itemsTable tbody').append(newRow);
            }

            const $newRow = $('#' + rowId);
            updateRowTotal($newRow);
            updateSummary();
            $('#productModal').modal('hide');
        });

        // Remove item
        $(document).on('click', '.remove-item', function() {
            $(this).closest('tr').remove();
            updateFreeSampleCounter();
            updateSummary();
        });

        // Update row total when quantity or price changes
        $(document).on('change', '.item-qty, .item-price', function() {
            updateRowTotal($(this).closest('tr'));
            updateSummary();
        });

        function updateRowTotal(row) {
            const qty = parseFloat(row.find('.item-qty').val()) || 0;
            const price = parseFloat(row.find('.item-price').val()) || 0;
            const isFree = row.find('.free-sample-flag').val() === '1';
            const total = isFree ? 0 : qty * price;
            const totalCell = row.find('.item-total');
            totalCell.data('value', total);
            totalCell.text(isFree ? `${total.toFixed(2)} (Free)` : total.toFixed(2));
            updateFreeSampleCounter();
        }

        $(document).on('change', '.item-free-sample', function() {
            const row = $(this).closest('tr');
            row.find('.free-sample-flag').val(this.checked ? '1' : '0');
            updateRowTotal(row);
            updateSummary();
        });

        function updateFreeSampleCounter() {
            let counter = 0;
            $('#itemsTable tbody tr').each(function() {
                if ($(this).find('.free-sample-flag').val() === '1') {
                    counter += parseFloat($(this).find('.item-qty').val()) || 0;
                }
            });
            $('#freeSampleCounter').text(counter);
        }

        function getItemsSubtotal() {
            let total = 0;
            $('.item-total').each(function() {
                total += parseFloat($(this).data('value')) || 0;
            });
            return total;
        }

        function calculateDiscount(subtotal) {
            const percentage = parseFloat($('#discount_percentage').val()) || 0;
            const cash = parseFloat($('#discount_cash_amount').val()) || 0;
            let discount = subtotal * (Math.min(percentage, 100) / 100);
            discount += Math.max(0, cash);
            if (discount > subtotal) {
                discount = subtotal;
            }
            return discount;
        }

        function getShippingCost() {
            if ($('#shipping_cost_type').val() === 'manual') {
                return Math.max(0, parseFloat($('#shipping_cost').val()) || 0);
            }
            return 0;
        }

        function updateSummary() {
            const subtotal = getItemsSubtotal();
            const discount = calculateDiscount(subtotal);
            const shipping = getShippingCost();
            const total = Math.max(0, subtotal - discount) + shipping;

            $('#itemsSubtotal').text(subtotal.toFixed(2));
            $('#calculatedDiscount').text(discount.toFixed(2));
            $('#calculatedShipping').text(shipping.toFixed(2));
            $('#orderTotal').text(total.toFixed(2));
        }

        $('#discount_percentage, #discount_cash_amount').on('input', updateSummary);
        $('#shipping_cost').on('input', updateSummary);
        $('#shipping_cost_type').on('change', function() {
            const manual = $(this).val() === 'manual';
            $('#shipping_cost').prop('disabled', !manual);
            if (!manual) {
                $('#shipping_cost').val(0);
            }
            updateSummary();
        });

        $('#discount_product_count').on('input', function() {
            if (parseInt($(this).val(), 10) < 0) {
                $(this).val(0);
            }
        });

        // Initialize summary on first load
        updateFreeSampleCounter();
        updateSummary();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>

