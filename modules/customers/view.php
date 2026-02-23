<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.details.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid customer ID.');
    redirect('index.php');
}

$customer_id = sanitize($_GET['id']);
$page_title = 'Customer Details';
require_once '../../includes/header.php';

// Get customer info
$customer_sql = "SELECT c.*, ct.name as type_name 
                 FROM customers c 
                 JOIN customer_types ct ON c.type = ct.id 
                 WHERE c.id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();

if ($customer_result->num_rows === 0) {
    setAlert('danger', 'Customer not found.');
    redirect('index.php');
}

$customer = $customer_result->fetch_assoc();
$customer_stmt->close();

// Get primary contact
$contact_sql = "SELECT * FROM customer_contacts 
                WHERE customer_id = ? AND is_primary = 1 
                LIMIT 1";
$contact_stmt = $conn->prepare($contact_sql);
$contact_stmt->bind_param("i", $customer_id);
$contact_stmt->execute();
$primary_contact = $contact_stmt->get_result()->fetch_assoc();
$contact_stmt->close();

// Get primary address
$address_sql = "SELECT * FROM customer_addresses 
                WHERE customer_id = ? AND address_type = 'primary' 
                LIMIT 1";
$address_stmt = $conn->prepare($address_sql);
$address_stmt->bind_param("i", $customer_id);
$address_stmt->execute();
$primary_address = $address_stmt->get_result()->fetch_assoc();
$address_stmt->close();

// Get recent orders (limit 5)
$orders_sql = "SELECT o.id, o.internal_id, o.order_date, o.total_amount, o.paid_amount, o.status,
               (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) as total_quantity 
               FROM orders o 
               WHERE o.customer_id = ? 
               ORDER BY o.order_date DESC 
               LIMIT 5";
$orders_stmt = $conn->prepare($orders_sql);
$orders_stmt->bind_param("i", $customer_id);
$orders_stmt->execute();
$orders_result = $orders_stmt->get_result();

// Get customer products with inventory details
$customer_products_sql = "SELECT p.id, p.name, p.sku, p.type, 
                                 i.name as inventory_name, 
                                 l.name as location_name, 
                                 ip.quantity
                          FROM products p
                          LEFT JOIN inventory_products ip ON p.id = ip.product_id
                          LEFT JOIN inventories i ON ip.inventory_id = i.id
                          LEFT JOIN locations l ON i.location_id = l.id
                          WHERE p.customer_id = ?
                          ORDER BY p.name ASC, i.name ASC";
$cp_stmt = $conn->prepare($customer_products_sql);
$cp_stmt->bind_param("i", $customer_id);
$cp_stmt->execute();
$customer_products_result = $cp_stmt->get_result();

$factories_data = [];
$factories_result = $conn->query("SELECT id, name FROM factories ORDER BY name");
if ($factories_result) {
    while ($factory = $factories_result->fetch_assoc()) {
        $factories_data[] = $factory;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Customer: <?php echo e($customer['name']); ?>
        <span class="badge bg-<?php echo $customer['type'] == 1 ? 'info' : 'primary'; ?>">
            <?php echo ucfirst($customer['type_name']); ?>
        </span>
    </h2>
    <div class="d-flex gap-2">
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" id="customerActions" data-bs-toggle="dropdown">
                <i class="fas fa-cog"></i> Actions
            </button>
            <ul class="dropdown-menu shadow-sm">
                <?php if (hasPermission('customers.edit')): ?>
                    <li><a class="dropdown-item edit-customer" href="#" data-id="<?php echo $customer['id']; ?>"><i class="fas fa-edit"></i> Edit</a></li>
                <?php endif; ?>
                <?php if (hasPermission('customers.contacts.manage')): ?>
                    <li><a class="dropdown-item" href="contacts.php?id=<?php echo $customer['id']; ?>"><i class="fas fa-address-book"></i> Contacts</a></li>
                <?php endif; ?>
                <?php if (hasPermission('customers.wallet')): ?>
                    <li><a class="dropdown-item" href="wallet.php?id=<?php echo $customer['id']; ?>"><i class="fas fa-wallet"></i> Wallet</a></li>
                <?php endif; ?>
                <?php if (hasPermission('customers.whatsapp_portal')): ?>
                    <li><a class="dropdown-item" href="portal_access.php?id=<?php echo $customer['id']; ?>"><i class="fas fa-lock"></i> Portal Access</a></li>
                    <li>
                        <button type="button"
                                class="dropdown-item send-portal-link"
                                data-id="<?php echo $customer['id']; ?>"
                                data-phone="<?php echo e(!empty($customer['whatsapp_phone']) ? $customer['whatsapp_phone'] : $customer['phone']); ?>"
                                data-name="<?php echo e($customer['name']); ?>">
                            <i class="fab fa-whatsapp"></i> WhatsApp Portal Link
                        </button>
                    </li>
                <?php endif; ?>
                <?php if (hasPermission('customers.delete')): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="index.php?delete=<?php echo $customer['id']; ?>" onclick="return confirm('Are you sure you want to delete this customer?')"><i class="fas fa-trash"></i> Delete</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <a href="index.php" class="btn btn-secondary">Back to Customers</a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Customer Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Email:</strong> <?php echo $customer['email'] ? e($customer['email']) : '-'; ?></p>
                <p><strong>Phone:</strong> <?php echo e($customer['phone']); ?></p>
                <p><strong>Tax Number:</strong> <?php echo $customer['tax_number'] ? e($customer['tax_number']) : '-'; ?></p>
                <p><strong>Wallet Balance:</strong> <?php echo number_format($customer['wallet_balance'], 2); ?></p>
                <p><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($customer['created_at'])); ?></p>
                <p><strong>Last Updated:</strong> <?php echo date('M d, Y H:i', strtotime($customer['updated_at'])); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Primary Contact</h5>
            </div>
            <div class="card-body">
                <?php if ($primary_contact): ?>
                    <p><strong>Name:</strong> <?php echo e($primary_contact['name']); ?></p>
                    <p><strong>Position:</strong> <?php echo $primary_contact['position'] ? e($primary_contact['position']) : '-'; ?></p>
                    <p><strong>Email:</strong> <?php echo $primary_contact['email'] ? e($primary_contact['email']) : '-'; ?></p>
                    <p><strong>Phone:</strong> <?php echo e($primary_contact['phone']); ?></p>
                <?php else: ?>
                    <p class="text-muted">No primary contact found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Primary Address</h5>
            </div>
            <div class="card-body">
                <?php if ($primary_address): ?>
                    <p><?php echo e($primary_address['address_line1']); ?></p>
                    <?php if ($primary_address['address_line2']): ?>
                        <p><?php echo e($primary_address['address_line2']); ?></p>
                    <?php endif; ?>
                    <p>
                        <?php echo e($primary_address['city']); ?>, 
                        <?php echo e($primary_address['state']); ?> 
                        <?php echo e($primary_address['postal_code']); ?>
                    </p>
                    <p><?php echo e($primary_address['country']); ?></p>
                    <?php if ($primary_address['is_default']): ?>
                        <span class="badge bg-success">Default Address</span>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">No primary address found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Recent Orders</h5>
                <a href="../sales/orders/create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> New Order
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Date</th>
                                <th>Quantity</th>
                                <th>Total Amount</th>
                                <th>Paid Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($orders_result->num_rows > 0): ?>
                                <?php while ($order = $orders_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo e($order['internal_id']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                        <td><?php echo number_format($order['total_quantity'] ?? 0); ?></td>
                                        <td><?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td><?php echo number_format($order['paid_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo getStatusColor($order['status']); ?>">
                                                <?php echo ucfirst(str_replace('-', ' ', $order['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="../sales/order_details.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">No recent orders found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4 mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Customer Products & Inventory</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>SKU</th>
                                <th>Type</th>
                                <th>Inventory</th>
                                <th>Location</th>
                                <th>Quantity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customer_products_result->num_rows > 0): ?>
                                <?php while ($item = $customer_products_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo e($item['name']); ?></td>
                                        <td><?php echo e($item['sku'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo getProductTypeColor($item['type']); ?>">
                                                <?php echo ucfirst($item['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $item['inventory_name'] ? e($item['inventory_name']) : '<span class="text-muted">No inventory records</span>'; ?></td>
                                        <td><?php echo $item['location_name'] ? e($item['location_name']) : '-'; ?></td>
                                        <td><?php echo $item['quantity'] !== null ? number_format($item['quantity']) : '-'; ?></td>
                                        <td>
                                            <a href="../products/index.php?search=<?php echo urlencode($item['sku']); ?>" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-search"></i> Find
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">No linked products found for this customer</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="edit.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCustomerModalLabel">Edit Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="editCustomerTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="edit-basic-tab" data-bs-toggle="tab" data-bs-target="#edit-basic" type="button">Basic Info</button>
                        </li>
                    </ul>
                    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="editCustomerTabsContent">
                        <div class="tab-pane fade show active" id="edit-basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_name" class="form-label">Customer Name*</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_type" class="form-label">Customer Type*</label>
                                    <select class="form-select" id="edit_type" name="type" required>
                                        <?php
                                        $types = $conn->query("SELECT * FROM customer_types");
                                        while ($type = $types->fetch_assoc()) {
                                            echo '<option value="' . $type['id'] . '">' . ucfirst($type['name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_factory_id" class="form-label">Factory</label>
                                    <select class="form-select" id="edit_factory_id" name="factory_id">
                                        <option value="">-- Not Assigned --</option>
                                        <?php foreach ($factories_data as $factory): ?>
                                            <option value="<?= $factory['id']; ?>"><?= e($factory['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_whatsapp_phone" class="form-label">WhatsApp Number</label>
                                    <input type="text" class="form-control" id="edit_whatsapp_phone" name="whatsapp_phone">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="edit_email" name="email">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_phone" class="form-label">Phone*</label>
                                    <input type="text" class="form-control" id="edit_phone" name="phone" required>
                                </div>
                            </div>
                            <div class="row">
                                <?php if ($login_region !== 'factory'): ?>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_region" class="form-label">Region</label>
                                    <select class="form-select" id="edit_region" name="region">
                                        <option value="">-- None --</option>
                                        <?php
                                        $regions_query = $conn->query("SELECT name FROM regions ORDER BY name ASC");
                                        while ($r = $regions_query->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($r['name']) . '">' . htmlspecialchars($r['name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_direct_sale" class="form-label">Direct Sale</label>
                                    <select class="form-select" id="edit_direct_sale" name="direct_sale">
                                        <option value="">-- None (Factory) --</option>
                                        <option value="curva">Curva</option>
                                        <option value="primer">Primer</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_tax_number" class="form-label">Tax Number</label>
                                    <input type="text" class="form-control" id="edit_tax_number" name="tax_number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_wallet_balance" class="form-label">Wallet Balance</label>
                                    <input type="number" class="form-control" id="edit_wallet_balance" name="wallet_balance" min="0" step="0.01" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Handle edit customer click
    $(document).on('click', '.edit-customer', function(e) {
        e.preventDefault();
        var customer_id = $(this).data('id');
        
        $.ajax({
            url: '../../ajax/get_customer_details.php',
            type: 'GET',
            data: { id: customer_id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit_id').val(response.customer.id);
                    $('#edit_name').val(response.customer.name);
                    $('#edit_type').val(response.customer.type);
                    $('#edit_email').val(response.customer.email);
                    $('#edit_phone').val(response.customer.phone);
                    $('#edit_factory_id').val(response.customer.factory_id);
                    $('#edit_whatsapp_phone').val(response.customer.whatsapp_phone);
                    $('#edit_tax_number').val(response.customer.tax_number);
                    $('#edit_wallet_balance').val(response.customer.wallet_balance);
                    $('#edit_region').val(response.customer.region);
                    $('#edit_direct_sale').val(response.customer.direct_sale);
                    
                    $('#editCustomerModal').modal('show');
                } else {
                    alert('Error loading customer data: ' + response.message);
                }
            }
        });
    });

    // Handle WhatsApp portal link click
    $(document).on('click', '.send-portal-link', function() {
        var customerId = $(this).data('id');
        var phone = ($(this).data('phone') || '').toString().trim();

        if (!phone.length) {
            alert('Please add a WhatsApp number before sending the portal link.');
            return;
        }

        $.ajax({
            url: '../../ajax/generate_portal_link.php',
            method: 'POST',
            data: { customer_id: customerId },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    var passwordMessage = resp.password_required
                        ? 'Password protection is enabled for this portal.' + (resp.password_hint ? '\nHint: ' + resp.password_hint : '')
                        : 'No portal password is configured yet.';
                    alert('Portal link generated successfully.\n' + passwordMessage);
                    window.open(resp.whatsapp_url, '_blank');
                } else {
                    alert(resp.message || 'Unable to send the portal link.');
                }
            },
            error: function() {
                alert('Unable to reach the server. Please try again.');
            }
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
