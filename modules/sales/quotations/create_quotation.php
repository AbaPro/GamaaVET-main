<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';

// Permission check
if (!hasPermission('quotations.manage')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../../dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Insert quotation
        $stmt = $pdo->prepare("
            INSERT INTO quotations (
                customer_id, contact_id, quotation_date, expiry_date, 
                status, total_amount, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $total_amount = array_sum(array_map(function($item) {
            return $item['quantity'] * $item['price'];
        }, $_POST['items']));
        
        $stmt->execute([
            $_POST['customer_id'],
            $_POST['contact_id'],
            $_POST['quotation_date'],
            $_POST['expiry_date'],
            'draft',
            $total_amount,
            $_POST['notes'],
            $_SESSION['user_id']
        ]);
        
        $quotation_id = $pdo->lastInsertId();
        
        // Insert quotation items
        $stmt = $pdo->prepare("
            INSERT INTO quotation_items (
                quotation_id, product_id, quantity, unit_price, total_price
            ) VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($_POST['items'] as $item) {
            if ($item['product_id'] && $item['quantity'] > 0) {
                $total_price = $item['quantity'] * $item['price'];
                $stmt->execute([
                    $quotation_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $total_price
                ]);
            }
        }
        
        // Update status if submitted (not draft)
        if ($_POST['action'] == 'submit') {
            $pdo->exec("UPDATE quotations SET status = 'sent' WHERE id = $quotation_id");
            $_SESSION['success'] = "Quotation submitted successfully!";
        } else {
            $_SESSION['success'] = "Quotation saved as draft!";
        }
        
        $pdo->commit();
        header("Location: quotation_details.php?id=" . $quotation_id);
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error creating quotation: " . $e->getMessage();
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

// Set default dates
$quotation_date = date('Y-m-d');
$expiry_date = date('Y-m-d', strtotime('+30 days'));
?>
<?php require_once '../../../includes/header.php'; ?>


<div class="container mt-4">
    <h2>Create New Quotation</h2>
    
    <?php include '../../../includes/messages.php'; ?>
    
    <form id="quotationForm" method="post">
        <div class="card mb-4">
            <div class="card-header">Quotation Information</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="quotation_date" class="form-label">Quotation Date</label>
                            <input type="date" class="form-control" id="quotation_date" name="quotation_date" 
                                   value="<?= $quotation_date ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="expiry_date" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                   value="<?= $expiry_date ?>" required>
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
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="contact_id" class="form-label">Contact Person</label>
                            <select class="form-select" id="contact_id" name="contact_id" required disabled>
                                <option value="">Select Customer First</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
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
                <span>Quotation Items</span>
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
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Items will be added dynamically -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Total:</strong></td>
                            <td><span id="quotationTotal">0.00</span></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <div class="d-flex justify-content-between">
            <a href="quotation_list.php" class="btn btn-secondary">Cancel</a>
            <div>
                <button type="submit" name="action" value="save" class="btn btn-info">Save Draft</button>
                <button type="submit" name="action" value="submit" class="btn btn-primary">Submit Quotation</button>
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
                <table class="table table-striped table-hover" id="productsTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
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
            // Load contacts when customer changes
    $("#customer_id").on('change', function() {
        const customerId = $(this).val();
        if (customerId) {
            $("#contact_id").prop('disabled', false).html('<option>Loading...</option>');
            $.getJSON('../../../ajax/get_customer_details.php', { customer_id: customerId })
             .done(function(resp){
                if (!resp || resp.success !== true) {
                    $("#contact_id").html('<option value="">No contacts found</option>');
                    return;
                }
                let options = '<option value="">Select Contact</option>';
                if (Array.isArray(resp.contacts) && resp.contacts.length) {
                    resp.contacts.forEach(function(contact){
                        const selected = contact.is_primary ? 'selected' : '';
                        const phone = contact.phone ? ' ('+contact.phone+')' : '';
                        options += '<option value="'+contact.id+'" '+selected+'>' + contact.name + phone + '</option>';
                    });
                    $("#contact_id").html(options);
                } else {
                    $("#contact_id").html('<option value="">No contacts found</option>');
                }
             })
             .fail(function(){
                $("#contact_id").html('<option value="">Failed to load contacts</option>');
             });
        } else {
            $("#contact_id").prop('disabled', true).html('<option value="">Select Customer First</option>');
        }
    });// Add item button
    $('#addItemBtn').click(function() {
        $('#productModal').modal('show');
    });
    
    // Product selection
    $(document).on('click', '.select-product', function() {
        const productId = $(this).data('id');
        const productName = $(this).data('name');
        const productPrice = parseFloat($(this).data('price')) || 0;
        
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
        
        updateQuotationTotal();
        $('#productModal').modal('hide');
    });
    
    // Remove item
    $(document).on('click', '.remove-item', function() {
        $(this).closest('tr').remove();
        updateQuotationTotal();
    });
    
    // Update row total when quantity or price changes
    $(document).on('change', '.item-qty, .item-price', function() {
        updateRowTotal($(this).closest('tr'));
        updateQuotationTotal();
    });
    
    function updateRowTotal(row) {
        const qty = parseFloat(row.find('.item-qty').val()) || 0;
        const price = parseFloat(row.find('.item-price').val()) || 0;
        const total = (qty * price).toFixed(2);
        row.find('.item-total').text(total);
    }
    
    function updateQuotationTotal() {
        let total = 0;
        $('.item-total').each(function() {
            total += parseFloat($(this).text()) || 0;
        });
        $('#quotationTotal').text(total.toFixed(2));
    }
});
</script>

<?php require_once '../../../includes/footer.php'; ?>






