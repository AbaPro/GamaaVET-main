<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('vendors.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Vendors Management';
require_once '../../includes/header.php';

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);
    
    // Check if vendor has purchase orders
    $check_sql = "SELECT COUNT(*) as count FROM purchase_orders WHERE vendor_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_orders = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($has_orders) {
        setAlert('danger', 'Cannot delete vendor as they have associated purchase orders.');
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete vendor contacts
            $delete_contacts_sql = "DELETE FROM vendor_contacts WHERE vendor_id = ?";
            $delete_contacts_stmt = $conn->prepare($delete_contacts_sql);
            $delete_contacts_stmt->bind_param("i", $id);
            $delete_contacts_stmt->execute();
            $delete_contacts_stmt->close();
            
            // Delete vendor addresses
            $delete_addresses_sql = "DELETE FROM vendor_addresses WHERE vendor_id = ?";
            $delete_addresses_stmt = $conn->prepare($delete_addresses_sql);
            $delete_addresses_stmt->bind_param("i", $id);
            $delete_addresses_stmt->execute();
            $delete_addresses_stmt->close();
            
            // Delete vendor documents
            $delete_docs_sql = "DELETE FROM vendor_documents WHERE vendor_id = ?";
            $delete_docs_stmt = $conn->prepare($delete_docs_sql);
            $delete_docs_stmt->bind_param("i", $id);
            $delete_docs_stmt->execute();
            $delete_docs_stmt->close();
            
            // Delete wallet transactions
            $delete_wallet_sql = "DELETE FROM vendor_wallet_transactions WHERE vendor_id = ?";
            $delete_wallet_stmt = $conn->prepare($delete_wallet_sql);
            $delete_wallet_stmt->bind_param("i", $id);
            $delete_wallet_stmt->execute();
            $delete_wallet_stmt->close();
            
            // Finally delete vendor
            $delete_vendor_sql = "DELETE FROM vendors WHERE id = ?";
            $delete_vendor_stmt = $conn->prepare($delete_vendor_sql);
            $delete_vendor_stmt->bind_param("i", $id);
            $delete_vendor_stmt->execute();
            
            if ($delete_vendor_stmt->affected_rows > 0) {
                setAlert('success', 'Vendor deleted successfully.');
                logActivity("Deleted vendor ID: $id");
                $conn->commit();
            } else {
                setAlert('danger', 'Error deleting vendor.');
                $conn->rollback();
            }
            $delete_vendor_stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            setAlert('danger', 'Error deleting vendor: ' . $e->getMessage());
        }
    }
    redirect('index.php');
}

// Fetch all vendors with their types
$sql = "SELECT v.*, vt.name as type_name 
        FROM vendors v 
        LEFT JOIN vendor_types vt ON v.type = vt.id 
        ORDER BY v.name";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Vendors</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVendorModal">
        <i class="fas fa-plus"></i> Add Vendor
    </button>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="vendorsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Type</th>
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
                                    <span class="badge bg-<?php echo $row['type'] == 1 ? 'info' : ($row['type'] == 2 ? 'primary' : 'warning'); ?>">
                                        <?php echo $row['type_name'] ? ucfirst($row['type_name']) : 'N/A'; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                <td><?php echo number_format($row['wallet_balance'], 2); ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="vendorActions" data-bs-toggle="dropdown">
                                            <i class="fas fa-cog"></i> Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="view.php?id=<?php echo $row['id']; ?>"><i class="fas fa-eye"></i> View</a></li>
                                            <li><a class="dropdown-item edit-vendor" href="#" data-id="<?php echo $row['id']; ?>"><i class="fas fa-edit"></i> Edit</a></li>
                                            <li><a class="dropdown-item" href="contacts.php?id=<?php echo $row['id']; ?>"><i class="fas fa-address-book"></i> Contacts</a></li>
                                            <li><a class="dropdown-item" href="wallet.php?id=<?php echo $row['id']; ?>"><i class="fas fa-wallet"></i> Wallet</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="index.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure you want to delete this vendor?')"><i class="fas fa-trash"></i> Delete</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No vendors found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Vendor Modal -->
<div class="modal fade" id="addVendorModal" tabindex="-1" aria-labelledby="addVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="create.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addVendorModalLabel">Add New Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="vendorTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button">Basic Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact" type="button">Primary Contact</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="address-tab" data-bs-toggle="tab" data-bs-target="#address" type="button">Address</button>
                        </li>
                    </ul>
                    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="vendorTabsContent">
                        <div class="tab-pane fade show active" id="basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="name" class="form-label">Vendor Name*</label>
                                    <input type="text" class="form-control" id="name" name="name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="type" class="form-label">Vendor Type*</label>
                                    <select class="form-select" id="type" name="type" required>
                                        <option value="">-- Select Type --</option>
                                        <?php
                                        $types = $conn->query("SELECT * FROM vendor_types");
                                        while ($type = $types->fetch_assoc()) {
                                            echo '<option value="' . $type['id'] . '">' . ucfirst($type['name']) . '</option>';
                                        }
                                        ?>
                                    </select>
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
                                    <label for="city" class="form-label">City (optional)</label>
                                    <input type="text" class="form-control" id="city" name="city">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="state" class="form-label">State/Province (optional)</label>
                                    <input type="text" class="form-control" id="state" name="state">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="postal_code" class="form-label">Postal Code (optional)</label>
                                    <input type="text" class="form-control" id="postal_code" name="postal_code">
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
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Vendor Modal -->
<div class="modal fade" id="editVendorModal" tabindex="-1" aria-labelledby="editVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="edit.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editVendorModalLabel">Edit Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="editVendorTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="edit-basic-tab" data-bs-toggle="tab" data-bs-target="#edit-basic" type="button">Basic Info</button>
                        </li>
                    </ul>
                    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="editVendorTabsContent">
                        <div class="tab-pane fade show active" id="edit-basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_name" class="form-label">Vendor Name*</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="edit_type" class="form-label">Vendor Type*</label>
                                    <select class="form-select" id="edit_type" name="type" required>
                                        <?php
                                        $types = $conn->query("SELECT * FROM vendor_types");
                                        while ($type = $types->fetch_assoc()) {
                                            echo '<option value="' . $type['id'] . '">' . ucfirst($type['name']) . '</option>';
                                        }
                                        ?>
                                    </select>
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
                    <button type="submit" class="btn btn-primary">Update Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#vendorsTable')) {
        $('#vendorsTable').DataTable({
            responsive: true,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            order: [],
            columnDefs: [
                { orderable: false, targets: [6] } // Disable sorting on actions column
            ]
        });
    }
    
    // Handle edit button click
    $('.edit-vendor').on('click', function(e) {
        e.preventDefault();
        var vendor_id = $(this).data('id');
        
        $.ajax({
            url: '../../ajax/get_vendor_details.php',
            type: 'GET',
            data: { id: vendor_id },
            dataType: 'json'
        }).done(function(response) {
            if (response && response.success) {
                $('#edit_id').val(response.vendor.id);
                $('#edit_name').val(response.vendor.name);
                $('#edit_type').val(response.vendor.type);
                $('#edit_email').val(response.vendor.email);
                $('#edit_phone').val(response.vendor.phone);
                $('#edit_tax_number').val(response.vendor.tax_number);
                $('#edit_wallet_balance').val(response.vendor.wallet_balance);
                
                // Use Bootstrap 5 Modal API when available
                var editModalEl = document.getElementById('editVendorModal');
                if (typeof bootstrap !== 'undefined' && editModalEl) {
                    var editModal = new bootstrap.Modal(editModalEl);
                    editModal.show();
                } else {
                    $('#editVendorModal').modal('show');
                }
            } else {
                var msg = (response && response.message) ? response.message : 'Unknown error';
                alert('Error loading vendor data: ' + msg);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            alert('AJAX error loading vendor data: ' + textStatus + ' - ' + errorThrown);
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
