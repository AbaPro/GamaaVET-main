<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Customers Management';
require_once '../../includes/header.php';

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);
    
    // Check if customer has orders
    $check_sql = "SELECT COUNT(*) as count FROM orders WHERE customer_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_orders = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($has_orders) {
        setAlert('danger', 'Cannot delete customer as they have associated orders.');
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete customer contacts
            $delete_contacts_sql = "DELETE FROM customer_contacts WHERE customer_id = ?";
            $delete_contacts_stmt = $conn->prepare($delete_contacts_sql);
            $delete_contacts_stmt->bind_param("i", $id);
            $delete_contacts_stmt->execute();
            $delete_contacts_stmt->close();
            
            // Delete customer addresses
            $delete_addresses_sql = "DELETE FROM customer_addresses WHERE customer_id = ?";
            $delete_addresses_stmt = $conn->prepare($delete_addresses_sql);
            $delete_addresses_stmt->bind_param("i", $id);
            $delete_addresses_stmt->execute();
            $delete_addresses_stmt->close();
            
            // Delete customer documents
            $delete_docs_sql = "DELETE FROM customer_documents WHERE customer_id = ?";
            $delete_docs_stmt = $conn->prepare($delete_docs_sql);
            $delete_docs_stmt->bind_param("i", $id);
            $delete_docs_stmt->execute();
            $delete_docs_stmt->close();
            
            // Delete wallet transactions
            $delete_wallet_sql = "DELETE FROM customer_wallet_transactions WHERE customer_id = ?";
            $delete_wallet_stmt = $conn->prepare($delete_wallet_sql);
            $delete_wallet_stmt->bind_param("i", $id);
            $delete_wallet_stmt->execute();
            $delete_wallet_stmt->close();
            
            // Finally delete customer
            $delete_customer_sql = "DELETE FROM customers WHERE id = ?";
            $delete_customer_stmt = $conn->prepare($delete_customer_sql);
            $delete_customer_stmt->bind_param("i", $id);
            $delete_customer_stmt->execute();
            
            if ($delete_customer_stmt->affected_rows > 0) {
                setAlert('success', 'Customer deleted successfully.');
                logActivity("Deleted customer ID: $id");
                $conn->commit();
            } else {
                setAlert('danger', 'Error deleting customer.');
                $conn->rollback();
            }
            $delete_customer_stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            setAlert('danger', 'Error deleting customer: ' . $e->getMessage());
        }
    }
    redirect('index.php');
}

// Fetch all customers with their types and factories
$sql = "SELECT c.*, ct.name AS type_name, f.name AS factory_name 
        FROM customers c 
        JOIN customer_types ct ON c.type = ct.id 
        LEFT JOIN factories f ON c.factory_id = f.id
        ORDER BY c.name";
$result = $conn->query($sql);

$factories_data = [];
$factories_result = $conn->query("SELECT id, name FROM factories ORDER BY name");
if ($factories_result) {
    while ($factory = $factories_result->fetch_assoc()) {
        $factories_data[] = $factory;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customers</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="factories.php" class="btn btn-outline-secondary">
            <i class="fas fa-industry"></i> Manage Factories
        </a>
        <a href="sample_customers.csv" class="btn btn-outline-info" download>
            <i class="fas fa-file-csv"></i> Sample CSV
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="fas fa-plus"></i> Add Customer
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body h-100 d-flex flex-column" style="min-height:400px;">
        <div class="table-responsive flex-grow-1">
            <table class="table table-hover mb-0" id="customersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Factory</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Wallet Balance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $row['type'] == 1 ? 'info' : 'primary'; ?>">
                                        <?php echo ucfirst($row['type_name']); ?>
                                    </span>
                                </td>
                                <td><?php echo !empty($row['factory_name']) ? htmlspecialchars($row['factory_name']) : '<span class="text-muted">N/A</span>'; ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                <td><?php echo number_format($row['wallet_balance'], 2); ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="customerActions" data-bs-toggle="dropdown">
                                            <i class="fas fa-cog"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="view.php?id=<?php echo $row['id']; ?>"><i class="fas fa-eye"></i> View</a></li>
                                            <li><a class="dropdown-item edit-customer" href="#" data-id="<?php echo $row['id']; ?>"><i class="fas fa-edit"></i> Edit</a></li>
                                            <li><a class="dropdown-item" href="contacts.php?id=<?php echo $row['id']; ?>"><i class="fas fa-address-book"></i> Contacts</a></li>
                                            <li><a class="dropdown-item" href="wallet.php?id=<?php echo $row['id']; ?>"><i class="fas fa-wallet"></i> Wallet</a></li>
                                            <li><a class="dropdown-item" href="portal_access.php?id=<?php echo $row['id']; ?>"><i class="fas fa-lock"></i> Portal Access</a></li>
                                            <li>
                                                <button type="button"
                                                        class="dropdown-item send-portal-link"
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-phone="<?php echo htmlspecialchars(!empty($row['whatsapp_phone']) ? $row['whatsapp_phone'] : $row['phone']); ?>"
                                                        data-name="<?php echo htmlspecialchars($row['name']); ?>">
                                                    <i class="fab fa-whatsapp"></i> WhatsApp Portal Link
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="index.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure you want to delete this customer?')"><i class="fas fa-trash"></i> Delete</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">No customers found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="create.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCustomerModalLabel">Add New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="customerTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button">Basic Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact" type="button">Primary Contact</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="address-tab" data-bs-toggle="tab" data-bs-target="#address" type="button">Address</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button">Documents</button>
                        </li>
                    </ul>
                    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="customerTabsContent">
                        <div class="tab-pane fade show active" id="basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="name" class="form-label">Customer Name*</label>
                                    <input type="text" class="form-control" id="name" name="name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="type" class="form-label">Customer Type*</label>
                                    <select class="form-select" id="type" name="type" required>
                                        <option value="">-- Select Type --</option>
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
                                    <label for="factory_id" class="form-label">Factory</label>
                                    <select class="form-select" id="factory_id" name="factory_id">
                                        <option value="">-- Not Assigned --</option>
                                        <?php foreach ($factories_data as $factory): ?>
                                            <option value="<?= $factory['id']; ?>"><?= htmlspecialchars($factory['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Controls which factory appears on invoices.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="whatsapp_phone" class="form-label">WhatsApp Number</label>
                                    <input type="text" class="form-control" id="whatsapp_phone" name="whatsapp_phone" placeholder="+201234567890">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone*</label>
                                    <input type="text" class="form-control" id="phone" name="phone" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tax_number" class="form-label">Tax Number</label>
                                    <input type="text" class="form-control" id="tax_number" name="tax_number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="wallet_balance" class="form-label">Initial Wallet Balance</label>
                                    <input type="number" class="form-control" id="wallet_balance" name="wallet_balance" min="0" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="contact" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_name" class="form-label">Contact Name*</label>
                                    <input type="text" class="form-control" id="contact_name" name="contact_name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contact_position" class="form-label">Position</label>
                                    <input type="text" class="form-control" id="contact_position" name="contact_position">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_email" class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" id="contact_email" name="contact_email">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contact_phone" class="form-label">Contact Phone*</label>
                                    <input type="text" class="form-control" id="contact_phone" name="contact_phone" required>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="address" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="address_line1" class="form-label">Address Line 1*</label>
                                    <input type="text" class="form-control" id="address_line1" name="address_line1" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="address_line2" class="form-label">Address Line 2</label>
                                    <input type="text" class="form-control" id="address_line2" name="address_line2">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="city" class="form-label">City*</label>
                                    <input type="text" class="form-control" id="city" name="city" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="state" class="form-label">State/Province*</label>
                                    <input type="text" class="form-control" id="state" name="state" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="postal_code" class="form-label">Postal Code*</label>
                                    <input type="text" class="form-control" id="postal_code" name="postal_code" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="country" class="form-label">Country*</label>
                                <input type="text" class="form-control" id="country" name="country" required>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_default_address" name="is_default_address" checked>
                                <label class="form-check-label" for="is_default_address">Set as default address</label>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="documents" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tax_document" class="form-label">Tax Registration Scan (PDF/Image)</label>
                                    <input type="file" class="form-control" id="tax_document" name="tax_document" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted">Optional upload; links to the tax number field.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="commercial_document" class="form-label">Commercial Registration Scan</label>
                                    <input type="file" class="form-control" id="commercial_document" name="commercial_document" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                            </div>
                            <p class="text-muted mb-0">Files are stored securely under customer documents for later reference.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Customer</button>
                </div>
            </form>
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
                                            <option value="<?= $factory['id']; ?>"><?= htmlspecialchars($factory['name']); ?></option>
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
    // Initialize DataTable
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#customersTable')) {
        $('#customersTable').DataTable({
            responsive: true,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            order: [],
            columnDefs: [
                { orderable: false, targets: [7] } // Disable sorting on actions column
            ]
        });
    }
    
    // Handle edit button click
    $('.edit-customer').click(function(e) {
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
                    
                    $('#editCustomerModal').modal('show');
                } else {
                    alert('Error loading customer data: ' + response.message);
                }
            }
        });
    });

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
